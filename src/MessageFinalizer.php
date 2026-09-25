<?php
declare(strict_types=1);

namespace Salvest;

/**
 * Fase 21: un correo con algo pendiente de revisar se queda en la bandeja de entrada hasta que
 * todo lo suyo esté clasificado. Worker ya lo mueve a "Facturas" cuando lo clasifica solo; esto
 * cubre el otro camino — una persona confirmando en /Revisar la última factura pendiente de ese
 * correo.
 *
 * Nunca deshace la clasificación: se llama DESPUÉS de haber guardado la factura (y subido a
 * Drive), así que si el movimiento IMAP falla, la factura sigue archivada y solo queda anotado
 * que el correo no se pudo mover — mismo criterio que Worker con 'completed' + 'failed'.
 */
final class MessageFinalizer
{
    public const DESTINATION = 'facturgerman/Facturas';

    /** @param array<string,mixed> $config
     * @param (callable(array<string,mixed>,string):object{connect():void,findUidsByMessageId(string):array,move(string,string):void,close():void})|null $imapClientFactory
     *   Mismo punto de inyección que InboxRequeue: los tests pasan un doble sin red. */
    public function __construct(private Database $db, private Crypto $crypto, private array $config, private $imapClientFactory = null) {}

    /** @return array{moved:bool,message:string} */
    public function finalizeIfComplete(int $attachmentId): array
    {
        $attachment = $this->db->one('SELECT * FROM processed_attachments WHERE id=?', [$attachmentId]);
        if (!$attachment) return ['moved' => false, 'message' => 'no_attachment'];
        return $this->finalizeMessage((int)$attachment['mailbox_id'], (string)$attachment['uidvalidity'], (string)$attachment['message_uid']);
    }

    /** Mismo criterio, a partir del correo — para cuando el adjunto ya no existe (tras "Eliminar
     * factura": caso típico, el aviso de privacidad que llega junto a un parte ya archivado). Hace
     * falta al menos una factura clasificada: un correo sin ninguna nunca va a "Facturas".
     * @return array{moved:bool,message:string} */
    public function finalizeMessage(int $mailboxId, string $uidvalidity, string $messageUid): array
    {
        $key = [$mailboxId, $uidvalidity, $messageUid];
        $siblings = $this->db->all('SELECT status FROM processed_attachments WHERE mailbox_id=? AND uidvalidity=? AND message_uid=?', $key);
        $pending = array_filter($siblings, static fn(array $row): bool => !in_array($row['status'], ['classified', 'duplicate'], true));
        if ($pending) return ['moved' => false, 'message' => 'pending_siblings'];
        if (!array_filter($siblings, static fn(array $row): bool => $row['status'] === 'classified')) return ['moved' => false, 'message' => 'nothing_classified'];

        $message = $this->db->one('SELECT * FROM processed_messages WHERE mailbox_id=? AND uidvalidity=? AND message_uid=?', $key);
        $mailbox = $this->db->one('SELECT * FROM mailboxes WHERE id=?', [$mailboxId]);
        if (!$message || !$mailbox) return ['moved' => false, 'message' => 'no_message'];
        $messageIdHeader = trim((string)$message['message_id_header']);

        // Correos procesados antes de Fase 21 ya se movieron a "Pendientes de revisión"/"Sin
        // clasificar": se buscan ahí. Los nuevos siguen en la carpeta de entrada del buzón.
        $currentFolder = (string)$message['imap_move_status'] === 'moved' && (string)$message['imap_destination'] !== ''
            ? (string)$message['imap_destination']
            : (string)$mailbox['input_folder'];

        try {
            if ($messageIdHeader === '') throw new \RuntimeException('el correo no tiene cabecera Message-ID; no se puede localizar sin ambigüedad');
            $client = $this->imapClientFactory !== null
                ? ($this->imapClientFactory)($mailbox, $currentFolder)
                : new ImapClient((string)$mailbox['imap_host'], (int)$mailbox['imap_port'], (string)$mailbox['username'],
                    $this->crypto->decrypt((string)$mailbox['encrypted_password']), $currentFolder, (int)$this->config['imap']['timeout_seconds']);
            try {
                $client->connect();
                $matches = $client->findUidsByMessageId($messageIdHeader);
                if (count($matches) !== 1) throw new \RuntimeException(count($matches) === 0
                    ? 'no se encontró el correo en '.$currentFolder.' (puede haberse movido o borrado a mano)'
                    : 'hay varios correos con el mismo Message-ID en '.$currentFolder);
                $client->move($matches[0], self::DESTINATION);
            } finally {
                $client->close();
            }
        } catch (\Throwable $error) {
            $this->db->execute("UPDATE processed_messages SET status='completed',imap_destination=?,imap_move_status='failed',error_message=? WHERE id=?",
                [self::DESTINATION, mb_substr($error->getMessage(), 0, 2000), $message['id']]);
            return ['moved' => false, 'message' => $error->getMessage()];
        }
        $this->db->execute("UPDATE processed_messages SET status='completed',imap_destination=?,imap_move_status='moved',error_message=NULL WHERE id=?",
            [self::DESTINATION, $message['id']]);
        return ['moved' => true, 'message' => 'moved'];
    }
}

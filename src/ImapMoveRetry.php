<?php
declare(strict_types=1);

namespace Salvest;

/**
 * Fase 24: reintenta, en cada pasada, mover los correos cuyo movimiento IMAP falló.
 *
 * Caso real: "todas estas facturas me las ha puesto en su sitio, pero no me ha quitado el correo
 * de la bandeja de entrada" — diez facturas de IBERDROLA archivadas en Drive, con sus correos
 * todavía en INBOX. Worker intentaba mover cada correo una única vez, justo después de archivar;
 * si ese intento fallaba (corte de la conexión con Gmail, rechazo puntual del servidor...) quedaba
 * imap_move_status='failed' con status='completed', y como Worker se salta para siempre los
 * correos 'completed', nadie volvía a intentarlo: factura en su sitio, correo atascado.
 *
 * Aquí se recogen esos correos (solo los del UIDVALIDITY actual: con otro, sus UID ya no
 * significan nada) y se vuelven a mover:
 * - si todo lo suyo ya está archivado (clasificado o duplicado — también cuando lo confirmó una
 *   persona en /Revisar después del fallo), a 'facturgerman/Facturas', y pasa a 'completed';
 * - si todavía queda algo pendiente, a la carpeta de revisión que ya tenía como destino.
 * Si el correo ya no está en la bandeja de entrada (alguien lo movió o lo borró a mano), no se
 * toca nada en IMAP: se anota 'not_in_inbox' para no volver a buscarlo.
 */
final class ImapMoveRetry
{
    public const CLASSIFIED_FOLDER = 'facturgerman/Facturas';

    public function __construct(private Database $db) {}

    /**
     * @param object $client conectado a la bandeja de entrada del buzón; solo se usan
     *   uidValidity(), markSeen(string) y move(string,string) — ImapClient en producción, un
     *   doble sencillo en los tests.
     * @param list<string> $inboxUids todos los UID que hay ahora mismo en la bandeja de entrada.
     * @return array{moved:int,not_in_inbox:int,failed:int}
     */
    public function retry(int $mailboxId, object $client, array $inboxUids): array
    {
        $result = ['moved' => 0, 'not_in_inbox' => 0, 'failed' => 0];
        $pending = $this->db->all("SELECT id,message_uid,status,imap_destination FROM processed_messages
            WHERE mailbox_id=? AND uidvalidity=? AND imap_move_status='failed' AND status IN ('completed','needs_review') ORDER BY id LIMIT 100",
            [$mailboxId, $client->uidValidity()]);
        if (!$pending) return $result;
        $inbox = array_flip(array_map('strval', $inboxUids));
        foreach ($pending as $message) {
            $uid = (string)$message['message_uid'];
            if (!isset($inbox[$uid])) {
                $this->db->execute("UPDATE processed_messages SET imap_move_status='not_in_inbox' WHERE id=?", [$message['id']]);
                $result['not_in_inbox']++;
                continue;
            }
            $archived = $this->allArchived($mailboxId, (string)$client->uidValidity(), $uid);
            $destination = $archived ? self::CLASSIFIED_FOLDER : (string)$message['imap_destination'];
            if ($destination === '') continue;
            try {
                if ($archived) $client->markSeen($uid);
                $client->move($uid, $destination);
                $this->db->execute("UPDATE processed_messages SET status=?,imap_destination=?,imap_move_status='moved',error_message=NULL WHERE id=?",
                    [$archived ? 'completed' : (string)$message['status'], $destination, $message['id']]);
                $result['moved']++;
            } catch (\Throwable $error) {
                $this->db->execute('UPDATE processed_messages SET error_message=? WHERE id=?', [mb_substr($error->getMessage(), 0, 2000), $message['id']]);
                error_log('mailbox_id='.$mailboxId.' uid='.$uid.' status=move_retry_failed error='.$error->getMessage());
                $result['failed']++;
            }
        }
        return $result;
    }

    /** ¿Están ya archivadas todas las facturas de este correo? Los documentos que no son factura
     * no cuentan, pero tiene que quedar al menos una factura archivada. */
    private function allArchived(int $mailboxId, string $uidValidity, string $uid): bool
    {
        $statuses = array_column($this->db->all('SELECT status FROM processed_attachments WHERE mailbox_id=? AND uidvalidity=? AND message_uid=?',
            [$mailboxId, $uidValidity, $uid]), 'status');
        $invoices = array_values(array_diff($statuses, ['ignored_not_invoice']));
        return $invoices && !array_diff($invoices, ['classified', 'duplicate']);
    }
}

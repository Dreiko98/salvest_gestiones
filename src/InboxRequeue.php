<?php
declare(strict_types=1);

namespace Salvest;

/**
 * "Volver a procesar": undoes a needs_review attachment's outcome so its email can be picked up
 * fresh by the next Worker run — without ever deleting anything. The previous attempt's row
 * (extraction_json, decision_json, debug_trace_json, output_path, everything) is kept exactly as
 * it was, just relabelled status='requeued' + requeued_at=NOW(). That status is deliberately
 * absent from every existing allow-list query (Worker's global SHA-256 dedupe, /Revisar's
 * listing, the dashboard's attention count) — see each of those for the exact list — so a
 * requeued row stops being visible or dedupe-relevant without a single line changing there.
 *
 * A message is a single IMAP object shared by every attachment inside it, so "return to inbox"
 * necessarily affects the whole email: every sibling attachment that hasn't already succeeded
 * (classified/duplicate) gets requeued together, never just the one clicked. Siblings that did
 * succeed are never touched — their SHA-256 already exists in the dedupe allow-list, so the next
 * Worker run recognises them as a 'duplicate' immediately, without re-extracting or re-archiving
 * anything.
 */
final class InboxRequeue
{
    /** Statuses that already succeeded and must never be touched by a requeue. */
    private const UNTOUCHABLE_STATUSES = ['classified', 'duplicate'];

    /** @param array<string,mixed> $config
     * @param (callable(array<string,mixed>,string):object{connect():void,findUidsByMessageId(string):array,move(string,string):void,close():void})|null $imapClientFactory
     *   Builds the client used to relocate a moved message — defaults to a real ImapClient (a
     *   live socket connection to $mailbox, SELECTing $currentFolder). Tests supply a factory
     *   returning a lightweight stub with just those four methods instead — the same seam Worker
     *   already uses for the restricted OpenAI retry ($restrictedResolver). Never invoked at all
     *   when the original move never actually happened (imap_move_status !== 'moved'). */
    public function __construct(private Database $db, private Crypto $crypto, private array $config, private $imapClientFactory = null) {}

    /**
     * @return array{ok:bool,message:string}
     */
    public function requeue(int $attachmentId): array
    {
        $attachment = $this->db->one('SELECT * FROM processed_attachments WHERE id=?', [$attachmentId]);
        if (!$attachment) return ['ok' => false, 'message' => 'Esa factura ya no existe.'];
        // Server-side revalidation — the button is only ever rendered for needs_review, but the
        // state could have changed since the page was loaded (another tab, a concurrent run).
        if ($attachment['status'] !== 'needs_review') {
            return ['ok' => false, 'message' => 'Esta factura ya no está pendiente de revisión (puede que ya se haya vuelto a procesar); no se ha hecho nada.'];
        }

        $mailboxId = (int)$attachment['mailbox_id'];
        $uidvalidity = (string)$attachment['uidvalidity'];
        $messageUid = (string)$attachment['message_uid'];

        $mailbox = $this->db->one('SELECT * FROM mailboxes WHERE id=?', [$mailboxId]);
        if (!$mailbox) return ['ok' => false, 'message' => 'El buzón de este correo ya no existe.'];

        $message = $this->db->one('SELECT * FROM processed_messages WHERE mailbox_id=? AND uidvalidity=? AND message_uid=?', [$mailboxId, $uidvalidity, $messageUid]);
        if (!$message) return ['ok' => false, 'message' => 'No se encontró el registro del correo original; no se ha hecho nada.'];

        $siblings = $this->db->all('SELECT * FROM processed_attachments WHERE mailbox_id=? AND uidvalidity=? AND message_uid=?', [$mailboxId, $uidvalidity, $messageUid]);
        $toRequeue = array_values(array_filter($siblings, static fn(array $row): bool => !in_array($row['status'], self::UNTOUCHABLE_STATUSES, true)));
        if (!$toRequeue) return ['ok' => false, 'message' => 'No hay nada pendiente que reencolar en este correo.'];

        // Defence in depth: a needs_review row should never have a Drive upload (only
        // status='classified' ever triggers DriveInvoiceArchiver — see Worker::processAttachment()),
        // but refuse rather than silently ignore if that assumption is ever wrong.
        foreach ($toRequeue as $row) {
            if ($row['drive_status'] !== null || $row['drive_file_id'] !== null || $row['drive_path'] !== null) {
                return ['ok' => false, 'message' => 'Una de las facturas de este correo tiene un archivo en Drive asociado; no se puede reencolar automáticamente.'];
            }
        }

        $messageIdHeader = trim((string)($message['message_id_header'] ?? ''));
        // If the original IMAP move never actually happened, the message is still sitting in the
        // mailbox's input folder — there is nothing to search for or move, only DB state to fix.
        $needsImapMove = (string)($message['imap_move_status'] ?? '') === 'moved';
        if ($needsImapMove && $messageIdHeader === '') {
            return ['ok' => false, 'message' => 'No se puede localizar el correo de forma inequívoca (falta la cabecera Message-ID); no se ha movido ni modificado nada.'];
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            foreach ($toRequeue as $row) {
                $this->db->execute("UPDATE processed_attachments SET status='requeued', requeued_at=NOW() WHERE id=?", [$row['id']]);
            }
            $this->db->execute("UPDATE processed_messages SET status='requeued' WHERE id=?", [$message['id']]);

            if ($needsImapMove) {
                $this->moveBackToInbox($mailbox, (string)$message['imap_destination'], $messageIdHeader);
            }

            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'message' => 'No se pudo completar: ' . $error->getMessage() . '. No se ha modificado nada.'];
        }

        $othersRequeued = count($toRequeue) - 1;
        $resultMessage = $othersRequeued > 0
            ? 'El correo ha vuelto a la bandeja de entrada. Se reprocesarán ' . count($toRequeue) . ' facturas pendientes; las ya clasificadas de este mismo correo no se han tocado.'
            : 'La factura ha vuelto a la bandeja de entrada y Salvest intentará procesarla de nuevo en la próxima ejecución. El historial técnico del intento anterior se ha conservado.';
        return ['ok' => true, 'message' => $resultMessage];
    }

    /** @param array<string,mixed> $mailbox */
    private function moveBackToInbox(array $mailbox, string $currentFolder, string $messageIdHeader): void
    {
        $client = $this->imapClientFactory !== null
            ? ($this->imapClientFactory)($mailbox, $currentFolder)
            : new ImapClient((string)$mailbox['imap_host'], (int)$mailbox['imap_port'], (string)$mailbox['username'],
                $this->crypto->decrypt((string)$mailbox['encrypted_password']), $currentFolder, (int)$this->config['imap']['timeout_seconds']);
        try {
            $client->connect();
            $matches = $client->findUidsByMessageId($messageIdHeader);
            if (count($matches) === 0) {
                throw new \RuntimeException('no se encontró el correo en su carpeta actual (puede haberse movido o borrado manualmente)');
            }
            if (count($matches) > 1) {
                throw new \RuntimeException('hay varios correos con el mismo Message-ID en esa carpeta; no se puede identificar cuál mover');
            }
            $client->move($matches[0], (string)$mailbox['input_folder']);
        } finally {
            $client->close();
        }
    }
}

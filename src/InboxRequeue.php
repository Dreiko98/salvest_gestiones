<?php
declare(strict_types=1);

namespace Salvest;

/**
 * Two related /Revisar actions on a needs_review attachment, both of which return the email to
 * INBOX and both of which never delete anything — only relabel status, keeping extraction_json,
 * decision_json, debug_trace_json, output_path exactly as they were:
 *
 * - requeue(): "Volver a procesar" — status='requeued'. Every non-succeeded sibling in the same
 *   email is requeued together (siblings that already succeeded are left untouched).
 * - dismiss(): "Esto no es una factura" — status='dismissed_not_invoice'. Stricter: only allowed
 *   when the clicked attachment is the *only* row for that email — see dismiss()'s own docblock.
 *
 * Both statuses are deliberately absent from every existing allow-list query (Worker's global
 * SHA-256 dedupe, /Revisar's listing, the dashboard's attention count), so either one stops being
 * visible or dedupe-relevant without a single line changing in any of those queries.
 */
final class InboxRequeue
{
    /** Statuses that already succeeded and must never be touched by either action. */
    private const UNTOUCHABLE_STATUSES = ['classified', 'duplicate'];

    /** @param array<string,mixed> $config
     * @param (callable(array<string,mixed>,string):object{connect():void,findUidsByMessageId(string):array,move(string,string):void,close():void})|null $imapClientFactory
     *   Builds the client used to relocate a moved message — defaults to a real ImapClient (a
     *   live socket connection to $mailbox, SELECTing $currentFolder). Tests supply a factory
     *   returning a lightweight stub with just those four methods instead — the same seam Worker
     *   already uses for the restricted OpenAI retry ($restrictedResolver). Never invoked at all
     *   when the original move never actually happened (imap_move_status !== 'moved'). */
    public function __construct(private Database $db, private Crypto $crypto, private array $config, private $imapClientFactory = null) {}

    /** @return array{ok:bool,message:string} */
    public function requeue(int $attachmentId): array
    {
        $context = $this->loadContext($attachmentId);
        if (!$context['ok']) return $context;
        $mailbox = $context['mailbox']; $message = $context['message']; $siblings = $context['siblings'];

        $toRequeue = array_values(array_filter($siblings, static fn(array $row): bool => !in_array($row['status'], self::UNTOUCHABLE_STATUSES, true)));
        if (!$toRequeue) return ['ok' => false, 'message' => 'No hay nada pendiente que reencolar en este correo.'];

        $driveError = $this->guardDriveEffects($toRequeue);
        if ($driveError !== null) return ['ok' => false, 'message' => $driveError];

        $located = $this->locateMessage($message);
        if (!$located['ok']) return $located;

        $result = $this->runTransactional($mailbox, $located, static function (Database $db) use ($toRequeue, $message): void {
            foreach ($toRequeue as $row) {
                $db->execute("UPDATE processed_attachments SET status='requeued', requeued_at=NOW() WHERE id=?", [$row['id']]);
            }
            $db->execute("UPDATE processed_messages SET status='requeued' WHERE id=?", [$message['id']]);
        });
        if (!$result['ok']) return $result;

        $othersRequeued = count($toRequeue) - 1;
        $resultMessage = $othersRequeued > 0
            ? 'El correo ha vuelto a la bandeja de entrada. Se reprocesarán ' . count($toRequeue) . ' facturas pendientes; las ya clasificadas de este mismo correo no se han tocado.'
            : 'La factura ha vuelto a la bandeja de entrada y Salvest intentará procesarla de nuevo en la próxima ejecución. El historial técnico del intento anterior se ha conservado.';
        return ['ok' => true, 'message' => $resultMessage];
    }

    /**
     * "Esto no es una factura": the email returns to INBOX and Worker::isDismissedNotInvoice()
     * makes sure it's never looked at again — recognised by (mailbox_id, message_id_header), not
     * by the attachment's SHA-256, deliberately: the exact same file could legitimately arrive
     * attached to a genuinely different, real invoice email later, and that must still get a
     * fair, independent extraction — dismissing one email must never become a global "never
     * process this PDF" rule.
     *
     * Stricter sibling rule than requeue(): this only proceeds when the clicked attachment is the
     * *only* row for this email. Any other row at all — whether it already succeeded
     * (classified/duplicate, proof the email does contain a real invoice) or is simply still
     * unresolved (needs_review/unclassified/error, an attachment nobody has actually looked at
     * yet) — blocks the action outright. "This email contains no invoice" is only a safe claim to
     * make when it is equivalent to "every reviewable thing in this email is this one attachment".
     * @return array{ok:bool,message:string}
     */
    public function dismiss(int $attachmentId): array
    {
        $context = $this->loadContext($attachmentId);
        if (!$context['ok']) return $context;
        $attachment = $context['attachment']; $mailbox = $context['mailbox']; $message = $context['message']; $siblings = $context['siblings'];

        $others = array_values(array_filter($siblings, static fn(array $row): bool => (int)$row['id'] !== $attachmentId));
        if ($others) {
            $provenInvoice = array_filter($others, static fn(array $row): bool => in_array($row['status'], self::UNTOUCHABLE_STATUSES, true));
            return ['ok' => false, 'message' => $provenInvoice
                ? 'Este correo contiene al menos una factura ya clasificada; no se puede marcar como que no contiene ninguna.'
                : 'Este correo tiene otros adjuntos todavía sin resolver; revísalos antes de descartar el correo entero.'];
        }

        $driveError = $this->guardDriveEffects([$attachment]);
        if ($driveError !== null) return ['ok' => false, 'message' => $driveError];

        $located = $this->locateMessage($message);
        if (!$located['ok']) return $located;

        $result = $this->runTransactional($mailbox, $located, static function (Database $db) use ($attachment, $message): void {
            $db->execute("UPDATE processed_attachments SET status='dismissed_not_invoice' WHERE id=?", [$attachment['id']]);
            $db->execute("UPDATE processed_messages SET status='dismissed_not_invoice' WHERE id=?", [$message['id']]);
        });
        if (!$result['ok']) return $result;

        return ['ok' => true, 'message' => 'El correo ha vuelto a la bandeja de entrada. Salvest no volverá a procesarlo en próximas ejecuciones. El historial técnico de este intento se ha conservado.'];
    }

    /** Loads and revalidates everything both actions need: the clicked attachment (must still be
     * needs_review — the button is only ever rendered for that, but state may have changed since
     * the page loaded), its mailbox, its message record, and every sibling attachment sharing the
     * same email. @return array{ok:bool,message?:string,attachment?:array<string,mixed>,mailbox?:array<string,mixed>,message?:array<string,mixed>,siblings?:list<array<string,mixed>>} */
    private function loadContext(int $attachmentId): array
    {
        $attachment = $this->db->one('SELECT * FROM processed_attachments WHERE id=?', [$attachmentId]);
        if (!$attachment) return ['ok' => false, 'message' => 'Esa factura ya no existe.'];
        if ($attachment['status'] !== 'needs_review') {
            return ['ok' => false, 'message' => 'Esta factura ya no está pendiente de revisión (puede que ya se haya actuado sobre ella); no se ha hecho nada.'];
        }

        $mailboxId = (int)$attachment['mailbox_id'];
        $uidvalidity = (string)$attachment['uidvalidity'];
        $messageUid = (string)$attachment['message_uid'];

        $mailbox = $this->db->one('SELECT * FROM mailboxes WHERE id=?', [$mailboxId]);
        if (!$mailbox) return ['ok' => false, 'message' => 'El buzón de este correo ya no existe.'];

        $message = $this->db->one('SELECT * FROM processed_messages WHERE mailbox_id=? AND uidvalidity=? AND message_uid=?', [$mailboxId, $uidvalidity, $messageUid]);
        if (!$message) return ['ok' => false, 'message' => 'No se encontró el registro del correo original; no se ha hecho nada.'];

        $siblings = $this->db->all('SELECT * FROM processed_attachments WHERE mailbox_id=? AND uidvalidity=? AND message_uid=?', [$mailboxId, $uidvalidity, $messageUid]);
        return ['ok' => true, 'attachment' => $attachment, 'mailbox' => $mailbox, 'message' => $message, 'siblings' => $siblings];
    }

    /** Defence in depth: a needs_review row should never have a Drive upload (only
     * status='classified' ever triggers DriveInvoiceArchiver — see Worker::processAttachment()),
     * but refuse rather than silently ignore if that assumption is ever wrong.
     * @param list<array<string,mixed>> $rows */
    private function guardDriveEffects(array $rows): ?string
    {
        foreach ($rows as $row) {
            if ($row['drive_status'] !== null || $row['drive_file_id'] !== null || $row['drive_path'] !== null) {
                return 'Una de las facturas de este correo tiene un archivo en Drive asociado; no se puede completar la acción automáticamente.';
            }
        }
        return null;
    }

    /** Works out whether an IMAP move is even needed and whether it can be done unambiguously —
     * pure validation, no I/O of its own. @param array<string,mixed> $message
     * @return array{ok:bool,message?:string,needsImapMove?:bool,messageIdHeader?:string,currentFolder?:string} */
    private function locateMessage(array $message): array
    {
        $messageIdHeader = trim((string)($message['message_id_header'] ?? ''));
        // If the original IMAP move never actually happened, the message is still sitting in the
        // mailbox's input folder — there is nothing to search for or move, only DB state to fix.
        $needsImapMove = (string)($message['imap_move_status'] ?? '') === 'moved';
        if ($needsImapMove && $messageIdHeader === '') {
            return ['ok' => false, 'message' => 'No se puede localizar el correo de forma inequívoca (falta la cabecera Message-ID); no se ha movido ni modificado nada.'];
        }
        return ['ok' => true, 'needsImapMove' => $needsImapMove, 'messageIdHeader' => $messageIdHeader, 'currentFolder' => (string)($message['imap_destination'] ?? '')];
    }

    /** Stages $dbChanges inside a transaction, performs the real IMAP move (if needed) *before*
     * committing, and rolls the whole transaction back — DB changes included — if the IMAP side
     * fails for any reason. Nothing is left half-done either way.
     * @param array<string,mixed> $mailbox @param array{needsImapMove:bool,messageIdHeader:string,currentFolder:string} $located
     * @param callable(Database):void $dbChanges @return array{ok:bool,message?:string} */
    private function runTransactional(array $mailbox, array $located, callable $dbChanges): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $dbChanges($this->db);
            if ($located['needsImapMove']) {
                $this->moveBackToInbox($mailbox, $located['currentFolder'], $located['messageIdHeader']);
            }
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'message' => 'No se pudo completar: ' . $error->getMessage() . '. No se ha modificado nada.'];
        }
        return ['ok' => true];
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

<?php
declare(strict_types=1);

namespace Salvest;

/**
 * "Eliminar factura": permanently forgets one processed_attachments row, as if it had never been
 * processed. Unlike InboxRequeue's two actions, this never touches IMAP at all — the physical
 * email stays exactly wherever it currently is — and never touches processed_messages or any
 * sibling attachment in the same email, regardless of their status. It's scoped to exactly the
 * one row the button was clicked on.
 *
 * Because the row disappears entirely (not relabelled, unlike 'requeued'/'dismissed_not_invoice'),
 * this is the one action with real, deliberate memory loss: Worker's global SHA-256 dedupe
 * (Worker::processAttachment()) can no longer find it, so if the exact same document ever arrives
 * again — a different email, a resend, or the same email refetched some other way — it will be
 * extracted and classified fresh, with no memory of ever having been seen before. That's the
 * explicit, confirmed intent: "como si nunca hubiera entrado".
 */
final class AttachmentPurge
{
    /** Every status /Revisar itself lists as pending — mirrors InboxRequeue::REVIEWABLE_STATUSES.
     * Deleting an already-succeeded (classified/duplicate) or already-relabelled
     * (requeued/dismissed_not_invoice) row is never allowed through this action. */
    private const REVIEWABLE_STATUSES = ['unclassified', 'needs_review', 'error'];

    public function __construct(private Database $db) {}

    /** @return array{ok:bool,message:string,deleted?:array<string,mixed>} */
    public function purge(int $attachmentId): array
    {
        $attachment = $this->db->one('SELECT * FROM processed_attachments WHERE id=?', [$attachmentId]);
        if (!$attachment) return ['ok' => false, 'message' => 'Esa factura ya no existe.'];
        // Server-side revalidation — the button is only ever rendered for a pending row, but the
        // state could have changed since the page was loaded (another tab, a concurrent run).
        if (!in_array($attachment['status'], self::REVIEWABLE_STATUSES, true)) {
            return ['ok' => false, 'message' => 'Esta factura ya no está pendiente de revisión (puede que ya se haya actuado sobre ella); no se ha hecho nada.'];
        }
        // Defence in depth: a pending row should never have a Drive upload (only
        // status='classified' ever triggers DriveInvoiceArchiver — see Worker::processAttachment()),
        // but refuse rather than silently ignore if that assumption is ever wrong — deleting the
        // only local reference to a file that still exists in Drive would leave it orphaned there.
        if ($attachment['drive_status'] !== null || $attachment['drive_file_id'] !== null || $attachment['drive_path'] !== null) {
            return ['ok' => false, 'message' => 'Esta factura tiene un archivo en Drive asociado; no se puede eliminar automáticamente.'];
        }

        $this->db->execute('DELETE FROM processed_attachments WHERE id=?', [$attachmentId]);

        // Best-effort: a leftover file on disk is harmless clutter, never worth reporting the
        // whole action as failed over — the database row (the thing that actually matters for
        // dedupe/visibility) is already gone regardless of whether this succeeds.
        $outputPath = (string)($attachment['output_path'] ?? '');
        if ($outputPath !== '' && is_file($outputPath)) {
            try { @unlink($outputPath); } catch (\Throwable) {}
        }

        return ['ok' => true, 'message' => 'La factura se ha eliminado. Si el mismo documento vuelve a llegar, Salvest lo procesará como si fuera nuevo.', 'deleted' => $attachment];
    }
}

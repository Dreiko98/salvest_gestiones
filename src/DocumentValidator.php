<?php
declare(strict_types=1);

namespace Salvest;

/**
 * Fase 4 (PDF-only): content decides, never MIME nor filename. MimeParser already excludes
 * anything that isn't plausibly a PDF before it ever reaches here (see its own docblock for
 * exactly what "plausibly" means there), but this is deliberate defense in depth — a manually
 * constructed attachment (tests, or a future bug in MimeParser) must still be rejected here on
 * its own merits. The only thing that matters is the literal "%PDF-" signature at the start of
 * the decoded payload; a declared MIME of "image/png" or a ".jpg" filename changes nothing, and
 * neither does a genuinely PDF payload mislabeled with either of those — content always wins.
 */
final class DocumentValidator
{
    /** @param array<string,mixed> $attachment
     * @throws NotPdfException the content isn't a real PDF — Worker excludes the attachment
     *   from processing entirely, it never reaches OpenAI and never becomes a processed_attachments row.
     * @throws \RuntimeException any other problem (empty, too large) — unrelated to Fase 4,
     *   Worker keeps treating these as a real per-email failure exactly as before.
     */
    public static function validate(array $attachment, int $maximumBytes): void
    {
        $payload = (string)($attachment['payload'] ?? '');
        $size = strlen($payload);
        if ($size === 0) throw new \RuntimeException('El adjunto está vacío');
        if ($size > $maximumBytes) throw new \RuntimeException("Adjunto demasiado grande ($size bytes; máximo $maximumBytes)");
        if (!str_starts_with($payload, '%PDF-')) throw new NotPdfException('El adjunto no es un PDF real (firma %PDF- ausente)');
    }
}

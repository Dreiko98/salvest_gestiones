<?php
declare(strict_types=1);

namespace Salvest;

/**
 * Thrown by DocumentValidator::validate() when an attachment is a genuine PDF (has the "%PDF-"
 * signature) but is password-protected/encrypted — neither Claude nor OpenAI can read it, so
 * there is nothing useful the pipeline can do with it. Kept as its own type, mirroring
 * NotPdfException exactly: Worker excludes the attachment from processing entirely (never
 * counted, never reaches the extractor, never becomes a processed_attachments row, never fails
 * the whole email) — see Worker::processMailbox()'s attachment loop. The real, technical reason
 * (an actual invoice the pipeline simply cannot open) is still worth a line in error_log(), which
 * Worker adds right where it catches this — silent to the end user, not silent to a developer
 * chasing down why a specific invoice never showed up anywhere.
 */
final class EncryptedPdfException extends \RuntimeException
{
}

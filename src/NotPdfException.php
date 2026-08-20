<?php
declare(strict_types=1);

namespace Salvest;

/**
 * Thrown by DocumentValidator::validate() specifically when an attachment's content is not a
 * real PDF (missing the "%PDF-" signature) — kept as its own type so Worker can tell "this
 * attachment simply isn't a processable document" apart from every other validation failure
 * (empty payload, oversized attachment) without matching on the exception message text.
 *
 * The distinction matters: a non-PDF attachment is silently excluded from the set Worker
 * processes (Fase 4, PDF-only) — it must never make the whole email fail as 'error' the way a
 * genuine problem (empty/oversized attachment, or a failure once real PDF processing starts)
 * still does. See Worker::processMailbox()'s attachment loop.
 */
final class NotPdfException extends \RuntimeException
{
}

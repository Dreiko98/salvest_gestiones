<?php
declare(strict_types=1);

namespace Salvest;

/**
 * Thrown by Worker::run() when the MySQL advisory lock is already held by
 * another run (cron, CLI or the manual "Ejecutar bot ahora" button). Kept as
 * its own type so callers can tell "someone else is already running" apart
 * from a real failure without matching on the exception message text.
 */
final class WorkerBusyException extends \RuntimeException
{
}

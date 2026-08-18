<?php
declare(strict_types=1);

namespace Salvest;

/**
 * Terminal-only instrumentation for `php bin/worker.php --debug`. This class never influences
 * any decision — every call site in Worker/Classifier/InvoiceRouter passes it (or a trace
 * callback backed by it) purely to observe, never to branch on. When disabled every method is
 * a no-op, so passing a disabled DebugTracer through the normal cron/manual-trigger paths has
 * zero behavioural or performance impact — this is the same object either way, just muted.
 *
 * Nothing here is persisted: it only ever writes to STDOUT. No new table, no log file.
 */
final class DebugTracer
{
    /** Key names that must never reach the terminal even if a caller passes them by mistake —
     * matched case-insensitively against array keys before anything is printed. */
    private const REDACT_PATTERN = '/password|secret|token|api[_-]?key|cookie|authorization|encrypted_|oauth/i';

    /** Explicit exceptions to REDACT_PATTERN: legitimate functional data whose key happens to
     * contain "token" (OpenAI's usage counters) but is never a credential — the user explicitly
     * asked to see these. Checked before the pattern, never after, so it can only ever widen
     * what's shown for these two exact keys, never narrow the redaction of anything else. */
    private const SAFE_KEYS = ['input_tokens', 'output_tokens'];

    public function __construct(private bool $enabled) {}

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function line(string $tag, string $message): void
    {
        if (!$this->enabled) return;
        // echo, not fwrite(STDOUT, ...): CLI's own output buffering (or none, by default — still
        // flushed promptly for live tailing) writes to the same terminal either way, but going
        // through echo is what lets tests capture this with ob_start() instead of needing a
        // real subprocess just to see the output.
        echo $this->timestamp().' ['.$tag.'] '.$message.PHP_EOL;
    }

    /** One line per key, "clave=valor", redacted and stringified. @param array<string,mixed> $pairs */
    public function fields(string $tag, array $pairs): void
    {
        if (!$this->enabled) return;
        foreach ($pairs as $key => $value) {
            $this->line($tag, $key.'='.$this->scalar((string)$key, $value));
        }
    }

    /** Pretty-printed, redacted JSON block, one physical line per line of output so every line
     * still carries its own timestamp as requested. */
    public function json(string $tag, string $label, mixed $data): void
    {
        if (!$this->enabled) return;
        $this->line($tag, $label.':');
        $pretty = json_encode($this->redact($data), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR);
        foreach (explode("\n", (string)$pretty) as $jsonLine) $this->line($tag, '  '.$jsonLine);
    }

    /** Renders one value for a "clave=valor" line: redacts by key name, stringifies arrays as
     * compact redacted JSON, turns null/bool into readable tokens instead of PHP's own casts. */
    public function scalar(string $key, mixed $value): string
    {
        if (!in_array($key, self::SAFE_KEYS, true) && preg_match(self::REDACT_PATTERN, $key)) return '[redacted]';
        if ($value === null) return '(vacío)';
        if (is_bool($value)) return $value ? 'true' : 'false';
        if (is_array($value)) return (string)json_encode($this->redact($value), JSON_UNESCAPED_UNICODE);
        return (string)$value;
    }

    private function redact(mixed $data): mixed
    {
        if (!is_array($data)) return $data;
        $out = [];
        foreach ($data as $key => $value) {
            $isSafe = is_string($key) && in_array($key, self::SAFE_KEYS, true);
            $out[$key] = !$isSafe && is_string($key) && preg_match(self::REDACT_PATTERN, $key) ? '[redacted]' : $this->redact($value);
        }
        return $out;
    }

    private function timestamp(): string
    {
        $now = microtime(true);
        $ms = (int)round(($now - floor($now)) * 1000);
        if ($ms >= 1000) $ms = 999; // rounding edge case, never let it overflow into the seconds field
        return '['.date('H:i:s', (int)$now).'.'.str_pad((string)$ms, 3, '0', STR_PAD_LEFT).']';
    }
}

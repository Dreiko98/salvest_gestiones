<?php
declare(strict_types=1);

namespace Salvest;

/**
 * In-memory technical trace of one attachment's trip through the pipeline, built during
 * Worker::processAttachment() and persisted (as JSON, in processed_attachments.debug_trace_json)
 * only when the attachment ends up somewhere a human will actually see it on /Revisar
 * (unclassified/needs_review/error — see REVIEWABLE_STATUSES). classified/duplicate outcomes
 * simply discard whatever was collected: no extra DB write happens mid-process, and building
 * the trace itself never touches the database.
 *
 * Every add() is defensive: a failure to build/redact one step's data can never throw back into
 * the caller, so a bug in here can never break real invoice processing.
 */
final class ReviewTrace
{
    private const REDACT_PATTERN = '/password|secret|token|api[_-]?key|cookie|authorization|encrypted_|oauth|session|credential/i';

    /** Legitimate functional data whose key happens to contain "token" (OpenAI's usage
     * counters) but is never a credential. Checked before REDACT_PATTERN, only for these keys. */
    private const SAFE_KEYS = ['input_tokens', 'output_tokens'];

    /** @var list<array{timestamp:string,step:string,data:mixed}> */
    private array $steps = [];

    /** @param array<string,mixed> $data */
    public function add(string $step, array $data): void
    {
        try {
            $this->steps[] = ['timestamp' => self::now(), 'step' => $step, 'data' => self::redact($data)];
        } catch (\Throwable) {
            // A trace failure must never affect real invoice processing — just skip this step.
        }
    }

    /** @return list<array{timestamp:string,step:string,data:mixed}> */
    public function toArray(): array
    {
        return $this->steps;
    }

    /** Every status that ever lands on /Revisar's pending list — see WebApp::reviews()'s SELECT
     * ...WHERE status IN (...). Kept here, next to the one method that needs it, rather than
     * duplicated at each call site. */
    private const REVIEWABLE_STATUSES = ['unclassified', 'needs_review', 'error'];

    /** Serialises the trace to JSON only when $status is one a human will actually see on
     * /Revisar (unclassified/needs_review/error) — classified and duplicate explicitly return
     * null, so a technical trace is only ever kept for a document someone will actually have to
     * open and debug. Never throws: a JSON failure (e.g. invalid UTF-8 slipping through from
     * somewhere in the PDF/extraction) falls back to null, logged, so real invoice processing
     * can continue regardless. */
    public function persistForReview(string $status): ?string
    {
        if (!in_array($status, self::REVIEWABLE_STATUSES, true)) return null;
        try {
            return json_encode($this->steps, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            error_log('review_trace status=failed ' . $error->getMessage());
            return null;
        }
    }

    /** Real timestamp taken at the moment this is called, with milliseconds and the app's UTC
     * offset — e.g. "2026-08-18T16:12:03.418+02:00" — so gaps between steps show real latency. */
    private static function now(): string
    {
        $dt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true)));
        if ($dt === false) $dt = new \DateTimeImmutable();
        return $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d\TH:i:s.vP');
    }

    private static function redact(mixed $data): mixed
    {
        if (!is_array($data)) return $data;
        $out = [];
        foreach ($data as $key => $value) {
            $isSafe = is_string($key) && in_array($key, self::SAFE_KEYS, true);
            $out[$key] = (!$isSafe && is_string($key) && preg_match(self::REDACT_PATTERN, $key)) ? '[redacted]' : self::redact($value);
        }
        return $out;
    }
}

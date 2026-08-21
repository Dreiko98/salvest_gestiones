<?php
declare(strict_types=1);

namespace Salvest;

/**
 * Fase 8: Claude is the primary extractor; any failure of a *specific* call (network error,
 * non-2xx, missing/malformed structured output — anything that surfaces as a Throwable from
 * ExtractorProvider::extract()/resolveSupplierAmongCandidates()) transparently falls back to
 * OpenAI for that same call. Never a silent swallow: the primary's failure is always
 * error_log()'d before the fallback attempt runs, so a persistent Claude outage stays visible in
 * logs even though every invoice keeps classifying normally via the fallback. If the fallback
 * ALSO fails, its exception is what propagates — exactly the same failure mode Worker already
 * handles today (per-attachment/per-mailbox try/catch), unchanged; this class never adds a new
 * failure path, only a transparent retry-with-a-different-provider in front of the existing one.
 *
 * Mirrors OpenAIExtractor/ClaudeExtractor's public shape (`inputTokens`/`outputTokens` as public
 * properties, `version()`) so Worker.php's existing call sites keep working unchanged.
 * `inputTokens`/`outputTokens` are the sum of both providers' own running totals — whichever one
 * actually served a given call is the one whose counters moved, so the sum is always the real,
 * accumulated cost across the whole run regardless of how many calls fell back. `version()`
 * reflects whichever provider served the *last* call (extract or resolveSupplierAmongCandidates),
 * which is exactly what Worker needs it for: recording `processed_attachments.extractor_version`
 * for that specific attachment right after calling extract() for it.
 */
final class FallbackExtractor implements ExtractorProvider
{
    public int $inputTokens = 0;
    public int $outputTokens = 0;
    private string $lastVersion = '';

    public function __construct(private ExtractorProvider $primary, private ExtractorProvider $fallback) {}

    public function version(): string { return $this->lastVersion; }

    public function extract(string $path, string $mimeType, string $context, string $reasoningEffort = 'low'): array
    {
        try {
            $result = $this->primary->extract($path, $mimeType, $context, $reasoningEffort);
            $this->lastVersion = self::versionOf($this->primary);
            return $result;
        } catch (\Throwable $error) {
            error_log('extractor_fallback status=primary_failed tier=extract error='.$error->getMessage());
            $result = $this->fallback->extract($path, $mimeType, $context, $reasoningEffort);
            $this->lastVersion = self::versionOf($this->fallback);
            return $result;
        } finally {
            $this->syncTokens();
        }
    }

    public function resolveSupplierAmongCandidates(string $path, string $mimeType, string $context, string $communityName, array $candidates): ?int
    {
        try {
            $result = $this->primary->resolveSupplierAmongCandidates($path, $mimeType, $context, $communityName, $candidates);
            $this->lastVersion = self::versionOf($this->primary);
            return $result;
        } catch (\Throwable $error) {
            error_log('extractor_fallback status=primary_failed tier=restricted error='.$error->getMessage());
            $result = $this->fallback->resolveSupplierAmongCandidates($path, $mimeType, $context, $communityName, $candidates);
            $this->lastVersion = self::versionOf($this->fallback);
            return $result;
        } finally {
            $this->syncTokens();
        }
    }

    private function syncTokens(): void
    {
        $this->inputTokens = (int)($this->primary->inputTokens ?? 0) + (int)($this->fallback->inputTokens ?? 0);
        $this->outputTokens = (int)($this->primary->outputTokens ?? 0) + (int)($this->fallback->outputTokens ?? 0);
    }

    private static function versionOf(ExtractorProvider $provider): string
    {
        return method_exists($provider, 'version') ? (string)$provider->version() : get_class($provider);
    }
}

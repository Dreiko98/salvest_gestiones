<?php
declare(strict_types=1);

namespace Salvest;

/**
 * Fase 8: shared contract between OpenAIExtractor and ClaudeExtractor so FallbackExtractor can
 * treat either as the primary or the fallback provider interchangeably, and so Worker only ever
 * depends on this contract, never on a concrete provider. Token counters (`inputTokens`/
 * `outputTokens`, public properties accumulated across every call made on that instance) and
 * `version()` (a short string identifying which provider/model actually served the last call)
 * are a structural convention every implementation follows — PHP interfaces can't declare
 * properties, so they aren't part of this interface, but FallbackExtractor reads them directly
 * off whichever concrete instance it just called.
 */
interface ExtractorProvider
{
    /** @return array<string,mixed> */
    public function extract(string $path, string $mimeType, string $context, string $reasoningEffort = 'low'): array;

    /** @param list<array{id:int,official_name:string}> $candidates */
    public function resolveSupplierAmongCandidates(string $path, string $mimeType, string $context, string $communityName, array $candidates): ?int;
}

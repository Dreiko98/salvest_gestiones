<?php
declare(strict_types=1);

namespace Salvest;

/** Una respuesta estructurada de un proveedor de IA, con lo necesario para la traza y el coste. */
final class AiResponse
{
    /** @param array<string,mixed> $data */
    public function __construct(
        public readonly array $data,
        public readonly string $provider,
        public readonly string $model,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $latencyMs,
    ) {}
}

<?php
declare(strict_types=1);

namespace Salvest;

/**
 * Claude como proveedor principal y OpenAI como respaldo, llamada a llamada: si una llamada concreta
 * del principal lanza una excepción, se registra en el log y se repite esa misma llamada con el
 * respaldo. Si el respaldo también falla, su excepción es la que sube. Mismo comportamiento que el
 * antiguo FallbackExtractor, ahora para cualquier tarea estructurada.
 */
final class FallbackAiClient implements AiClient
{
    public function __construct(private AiClient $primary, private AiClient $fallback) {}

    public function structured(string $task, string $instructions, array $schema, ?string $pdfPath, string $userText, array $options = []): AiResponse
    {
        try {
            return $this->primary->structured($task, $instructions, $schema, $pdfPath, $userText, $options);
        } catch (\Throwable $error) {
            error_log('ai_fallback status=primary_failed task='.$task.' error='.$error->getMessage());
            return $this->fallback->structured($task, $instructions, $schema, $pdfPath, $userText, $options);
        }
    }
}

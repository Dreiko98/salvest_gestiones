<?php
declare(strict_types=1);

namespace Salvest;

/**
 * Contrato mínimo con un proveedor de IA: una llamada que recibe instrucciones, un esquema JSON y,
 * opcionalmente, un PDF, y devuelve un objeto que cumple ese esquema. Los esquemas y los textos de
 * las instrucciones viven en AiContracts, nunca en los adaptadores: así Claude y OpenAI no pueden
 * acabar implementando contratos ligeramente distintos.
 */
interface AiClient
{
    /**
     * @param array<string,mixed> $schema JSON Schema de un objeto
     * @param array{max_output_tokens?:int,reasoning_effort?:string} $options
     * @throws \RuntimeException fallo de red, HTTP no 2xx o respuesta sin el objeto estructurado
     */
    public function structured(string $task, string $instructions, array $schema, ?string $pdfPath, string $userText, array $options = []): AiResponse;
}

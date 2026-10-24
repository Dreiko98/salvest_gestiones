<?php
declare(strict_types=1);

namespace Salvest;

/**
 * Adaptador de Anthropic (Messages API). La salida estructurada se obtiene forzando una única
 * herramienta cuyo input_schema es el esquema pedido: la respuesta es siempre el `input` de esa
 * llamada, nunca texto libre que haya que volver a interpretar. El esquema no se impone de forma
 * estricta en el lado de Anthropic, por eso DocumentIntelligence valida todo lo que vuelve.
 */
final class ClaudeClient implements AiClient
{
    /** @param array<string,mixed> $config */
    public function __construct(private array $config) {}

    public function structured(string $task, string $instructions, array $schema, ?string $pdfPath, string $userText, array $options = []): AiResponse
    {
        $content = [];
        if ($pdfPath !== null) $content[] = ['type'=>'document','source'=>['type'=>'base64','media_type'=>'application/pdf','data'=>base64_encode(self::read($pdfPath))]];
        $content[] = ['type'=>'text','text'=>$userText];
        $payload = [
            'model' => (string)$this->config['model'],
            'max_tokens' => (int)($options['max_output_tokens'] ?? 2048),
            'system' => $instructions,
            'tools' => [['name'=>$task,'description'=>'Devuelve el resultado estructurado de la tarea.','input_schema'=>$schema]],
            'tool_choice' => ['type'=>'tool','name'=>$task],
            'messages' => [['role'=>'user','content'=>$content]],
        ];
        $started = microtime(true);
        $decoded = $this->send($payload);
        foreach (($decoded['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === $task && is_array($block['input'] ?? null)) {
                return new AiResponse($block['input'], 'claude', (string)($decoded['model'] ?? $this->config['model']),
                    (int)($decoded['usage']['input_tokens'] ?? 0), (int)($decoded['usage']['output_tokens'] ?? 0), (int)round((microtime(true) - $started) * 1000));
            }
        }
        throw new \RuntimeException('Claude no devolvió el resultado estructurado de '.$task);
    }

    private static function read(string $path): string
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) throw new \RuntimeException('No se pudo leer el documento');
        return $bytes;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function send(array $payload): array
    {
        $curl = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($curl, [
            CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>['x-api-key: '.$this->config['api_key'],'anthropic-version: 2023-06-01','content-type: application/json'],
            CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
            CURLOPT_TIMEOUT=>(int)($this->config['timeout_seconds'] ?? 120),
        ]);
        $response = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($response === false) throw new \RuntimeException('Error de red Claude: '.curl_error($curl));
        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) throw new \RuntimeException('Claude devolvió una respuesta que no es JSON (HTTP '.$status.')');
        if ($status < 200 || $status >= 300) throw new \RuntimeException('Claude respondió HTTP '.$status.': '.($decoded['error']['message'] ?? 'error desconocido'));
        return $decoded;
    }
}

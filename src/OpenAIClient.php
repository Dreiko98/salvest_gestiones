<?php
declare(strict_types=1);

namespace Salvest;

/**
 * Adaptador de OpenAI (Responses API) con `json_schema` estricto. El modo estricto exige que todo
 * objeto declare todas sus propiedades como obligatorias y `additionalProperties: false`;
 * AiContracts construye los esquemas así para que sirvan igual a los dos proveedores.
 */
final class OpenAIClient implements AiClient
{
    /** @param array<string,mixed> $config */
    public function __construct(private array $config) {}

    public function structured(string $task, string $instructions, array $schema, ?string $pdfPath, string $userText, array $options = []): AiResponse
    {
        $content = [];
        if ($pdfPath !== null) {
            $bytes = file_get_contents($pdfPath);
            if ($bytes === false) throw new \RuntimeException('No se pudo leer el documento');
            $content[] = ['type'=>'input_file','filename'=>basename($pdfPath),'file_data'=>'data:application/pdf;base64,'.base64_encode($bytes)];
        }
        $content[] = ['type'=>'input_text','text'=>$userText];
        $payload = [
            'model' => (string)$this->config['model'],
            'instructions' => $instructions,
            'reasoning' => ['effort'=>(string)($options['reasoning_effort'] ?? 'low')],
            'input' => [['role'=>'user','content'=>$content]],
            'text' => ['format'=>['type'=>'json_schema','name'=>$task,'strict'=>true,'schema'=>$schema]],
        ];
        $started = microtime(true);
        $decoded = $this->send($payload);
        $text = null;
        foreach (($decoded['output'] ?? []) as $output) {
            foreach (($output['content'] ?? []) as $part) if (($part['type'] ?? '') === 'output_text') $text = $part['text'] ?? null;
        }
        $data = is_string($text) ? json_decode($text, true) : null;
        if (!is_array($data)) throw new \RuntimeException('OpenAI no devolvió el resultado estructurado de '.$task);
        return new AiResponse($data, 'openai', (string)($decoded['model'] ?? $this->config['model']),
            (int)($decoded['usage']['input_tokens'] ?? 0), (int)($decoded['usage']['output_tokens'] ?? 0), (int)round((microtime(true) - $started) * 1000));
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function send(array $payload): array
    {
        $curl = curl_init('https://api.openai.com/v1/responses');
        curl_setopt_array($curl, [
            CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$this->config['api_key'],'Content-Type: application/json'],
            CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
            CURLOPT_TIMEOUT=>(int)($this->config['timeout_seconds'] ?? 120),
        ]);
        $response = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($response === false) throw new \RuntimeException('Error de red OpenAI: '.curl_error($curl));
        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) throw new \RuntimeException('OpenAI devolvió una respuesta que no es JSON (HTTP '.$status.')');
        if ($status < 200 || $status >= 300) throw new \RuntimeException('OpenAI respondió HTTP '.$status.': '.($decoded['error']['message'] ?? 'error desconocido'));
        return $decoded;
    }
}

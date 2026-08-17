<?php
declare(strict_types=1);

namespace Salvest;

final class OpenAIExtractor
{
    public const VERSION = 'openai-php-v1';
    public int $inputTokens = 0;
    public int $outputTokens = 0;

    /** @param array<string,mixed> $config */
    public function __construct(private array $config) {}

    /** @return array<string,mixed> */
    public function extract(string $path, string $mimeType, string $context): array
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) throw new \RuntimeException('No se pudo leer el documento');
        $encoded = base64_encode($bytes);
        $document = str_starts_with($mimeType, 'image/')
            ? ['type'=>'input_image','image_url'=>"data:$mimeType;base64,$encoded"]
            : ['type'=>'input_file','filename'=>basename($path),'file_data'=>"data:application/pdf;base64,$encoded"];
        $fields = [
            'proveedor','tipo_servicio','direccion','importe','fecha_factura','proveedor_cif',
            'nombre_comunidad','comunidad_cif','codigo_postal','cups','numero_contrato',
            'referencia_cliente','numero_factura','periodo_facturacion','moneda','codigo_comunidad',
        ];
        $properties = [];
        foreach ($fields as $field) {
            $properties[$field] = ['type'=>in_array($field, ['importe'], true) ? ['number','null'] : ['string','null']];
        }
        $payload = [
            'model'=>$this->config['model'],
            'instructions'=>"Extrae los datos de la factura sin inventar. tipo_servicio debe ser una categoría breve en minúsculas. fecha_factura usa AAAA-MM-DD. importe es el total numérico. Usa null cuando no aparezca un valor. No propongas IDs internos.",
            'reasoning'=>['effort'=>'low'],
            'input'=>[['role'=>'user','content'=>[$document,['type'=>'input_text','text'=>'Extrae los campos requeridos. Contexto del correo: '.mb_substr($context,0,12000)]]]],
            'text'=>['format'=>['type'=>'json_schema','name'=>'invoice_extraction','strict'=>true,
                'schema'=>['type'=>'object','properties'=>$properties,'required'=>$fields,'additionalProperties'=>false]]],
        ];
        $curl = curl_init('https://api.openai.com/v1/responses');
        curl_setopt_array($curl, [
            CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$this->config['api_key'],'Content-Type: application/json'],
            CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
            CURLOPT_TIMEOUT=>(int)($this->config['timeout_seconds'] ?? 120),
        ]);
        $response = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($response === false) throw new \RuntimeException('Error de red OpenAI: '.curl_error($curl));
        $decoded = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('OpenAI respondió HTTP '.$status.': '.($decoded['error']['message'] ?? 'error desconocido'));
        }
        $this->inputTokens += (int)($decoded['usage']['input_tokens'] ?? 0);
        $this->outputTokens += (int)($decoded['usage']['output_tokens'] ?? 0);
        $text = null;
        foreach (($decoded['output'] ?? []) as $output) {
            foreach (($output['content'] ?? []) as $content) if (($content['type'] ?? '') === 'output_text') $text = $content['text'] ?? null;
        }
        if (!is_string($text)) throw new \RuntimeException('OpenAI no devolvió una extracción estructurada');
        $invoice = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        $invoice['proveedor'] = $invoice['proveedor'] ?: 'desconocido';
        $invoice['tipo_servicio'] = Text::normalize((string)($invoice['tipo_servicio'] ?: 'desconocido'));
        $invoice['moneda'] = $invoice['moneda'] ?: 'EUR';
        return $invoice;
    }
}

<?php
declare(strict_types=1);

namespace Salvest;

/**
 * Talks to OpenAI. Everything it returns is a candidate value, never a final decision —
 * Classifier/InvoiceRouter/MySQL are the authority on which community/supplier/service a
 * document actually belongs to. This class also supports a second, restricted call used
 * only when the deterministic master-data matching in Classifier could not resolve a
 * supplier on its own: given the same PDF and a closed list of suppliers already linked to
 * the resolved community, the model may pick one of them (or null) — it can never propose a
 * supplier outside that list.
 */
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
        $document = self::documentInput($path, $mimeType);
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
            'instructions'=>"Extrae los datos de la factura sin inventar. El campo proveedor es el nombre tal cual aparece en el documento — no confirmes ni valides contra ningún maestro, solo transcribe lo que ves. tipo_servicio debe ser una categoría breve en minúsculas. fecha_factura usa AAAA-MM-DD. importe es el total numérico. Usa null cuando no aparezca un valor. No propongas IDs internos.",
            'reasoning'=>['effort'=>'low'],
            'input'=>[['role'=>'user','content'=>[$document,['type'=>'input_text','text'=>'Extrae los campos requeridos. Contexto del correo: '.mb_substr($context,0,12000)]]]],
            'text'=>['format'=>['type'=>'json_schema','name'=>'invoice_extraction','strict'=>true,
                'schema'=>['type'=>'object','properties'=>$properties,'required'=>$fields,'additionalProperties'=>false]]],
        ];
        $invoice = json_decode(self::responseText($this->send($payload)), true, flags: JSON_THROW_ON_ERROR);
        $invoice['proveedor'] = $invoice['proveedor'] ?: 'desconocido';
        $invoice['tipo_servicio'] = Text::normalize((string)($invoice['tipo_servicio'] ?: 'desconocido'));
        $invoice['moneda'] = $invoice['moneda'] ?: 'EUR';
        return $invoice;
    }

    /**
     * Second, restricted inspection: only ever called after the deterministic master-data
     * matching (Classifier::resolveSupplierInCommunity) already failed to find a supplier
     * for a community that is otherwise correctly resolved. Re-reads the same PDF — headers,
     * logos, stamps included — with a closed list of the suppliers this community actually
     * has on file, and must return either one of those ids or null. A strict JSON schema
     * enum constrains the model's output to that exact set of ids (plus null); the id is
     * re-validated against the candidate list in code regardless, so a malformed or gamed
     * response can never introduce a supplier the community doesn't have.
     * @param list<array{id:int,official_name:string}> $candidates
     */
    public function resolveSupplierAmongCandidates(string $path, string $mimeType, string $context, string $communityName, array $candidates): ?int
    {
        if (!$candidates) return null;
        $document = self::documentInput($path, $mimeType);
        $ids = array_map(static fn(array $c): int => $c['id'], $candidates);
        $list = implode("\n", array_map(static fn(array $c): string => '- id '.$c['id'].': '.$c['official_name'], $candidates));
        $payload = [
            'model'=>$this->config['model'],
            'instructions'=>"Vuelve a inspeccionar el documento completo — incluye cabeceras, logos, sellos y cualquier tabla — para identificar quién emite o presta el servicio de esta factura. La comunidad ya está confirmada como \"$communityName\". Elige EXCLUSIVAMENTE uno de estos proveedores, si hay evidencia clara en el documento de que es él:\n$list\nDevuelve su id numérico exacto en supplier_id. Si ninguno de la lista coincide con evidencia suficiente, devuelve supplier_id null. No propongas ningún proveedor fuera de esta lista, ni inventes uno nuevo.",
            'reasoning'=>['effort'=>'medium'],
            'input'=>[['role'=>'user','content'=>[$document,['type'=>'input_text','text'=>'Contexto del correo: '.mb_substr($context,0,12000)]]]],
            'text'=>['format'=>['type'=>'json_schema','name'=>'supplier_resolution','strict'=>true,
                'schema'=>['type'=>'object','properties'=>['supplier_id'=>['type'=>['integer','null'],'enum'=>array_merge($ids,[null])]],'required'=>['supplier_id'],'additionalProperties'=>false]]],
        ];
        $result = json_decode(self::responseText($this->send($payload)), true, flags: JSON_THROW_ON_ERROR);
        $chosen = $result['supplier_id'] ?? null;
        return $chosen !== null && in_array((int)$chosen, $ids, true) ? (int)$chosen : null;
    }

    private static function documentInput(string $path, string $mimeType): array
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) throw new \RuntimeException('No se pudo leer el documento');
        $encoded = base64_encode($bytes);
        return str_starts_with($mimeType, 'image/')
            ? ['type'=>'input_image','image_url'=>"data:$mimeType;base64,$encoded"]
            : ['type'=>'input_file','filename'=>basename($path),'file_data'=>"data:application/pdf;base64,$encoded"];
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
        $decoded = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('OpenAI respondió HTTP '.$status.': '.($decoded['error']['message'] ?? 'error desconocido'));
        }
        $this->inputTokens += (int)($decoded['usage']['input_tokens'] ?? 0);
        $this->outputTokens += (int)($decoded['usage']['output_tokens'] ?? 0);
        return $decoded;
    }

    /** @param array<string,mixed> $decoded */
    private static function responseText(array $decoded): string
    {
        $text = null;
        foreach (($decoded['output'] ?? []) as $output) {
            foreach (($output['content'] ?? []) as $content) if (($content['type'] ?? '') === 'output_text') $text = $content['text'] ?? null;
        }
        if (!is_string($text)) throw new \RuntimeException('OpenAI no devolvió una extracción estructurada');
        return $text;
    }
}

<?php
declare(strict_types=1);

namespace Salvest;

/**
 * Fase 8: Claude (Anthropic Messages API) as the primary extractor. Mirrors OpenAIExtractor's
 * public contract exactly (extract()/resolveSupplierAmongCandidates() from ExtractorProvider,
 * plus the same public `inputTokens`/`outputTokens` counters and a `version()` method) so
 * FallbackExtractor — and Worker, through it — can treat both providers interchangeably.
 * Everything this class returns is a candidate value, never a final decision — exactly the same
 * contract OpenAIExtractor already had; Classifier/InvoiceRouter/MySQL remain the only authority.
 *
 * Structured output uses forced tool-use: Claude has no equivalent to OpenAI's strict
 * `json_schema` response format, so a single tool is offered whose `input_schema` matches the
 * same field set OpenAIExtractor::extract() uses, and `tool_choice` forces the model to call it —
 * the response is always that tool call's structured `input`, never freeform text to re-parse.
 * Same normalisation rules as OpenAIExtractor::extract() (proveedor/tipo_servicio/moneda
 * fallbacks) so InvoiceRouter/Classifier see an identical shape regardless of which provider
 * actually served the request.
 */
final class ClaudeExtractor implements ExtractorProvider
{
    public const VERSION = 'claude-sonnet-5-v1';
    public int $inputTokens = 0;
    public int $outputTokens = 0;

    /** @param array<string,mixed> $config */
    public function __construct(private array $config) {}

    public function version(): string { return self::VERSION; }

    /** $reasoningEffort is accepted only for ExtractorProvider/interface parity with
     * OpenAIExtractor — Claude's Messages API tool-use call has no equivalent low/medium tuning
     * knob at this shape, so it is accepted but unused here. */
    public function extract(string $path, string $mimeType, string $context, string $reasoningEffort = 'low'): array
    {
        $fields = [
            'proveedor','tipo_servicio','direccion','importe','fecha_factura','proveedor_cif',
            'nombre_comunidad','comunidad_cif','codigo_postal','cups','numero_contrato',
            'referencia_cliente','numero_factura','periodo_facturacion','moneda','codigo_comunidad',
        ];
        $properties = [];
        foreach ($fields as $field) {
            $properties[$field] = ['type'=>in_array($field, ['importe'], true) ? ['number','null'] : ['string','null']];
        }
        $tool = [
            'name' => 'extraer_factura',
            'description' => 'Extrae los datos de la factura sin inventar.',
            'input_schema' => ['type'=>'object','properties'=>$properties,'required'=>$fields,'additionalProperties'=>false],
        ];
        $payload = [
            'model' => $this->config['model'],
            'max_tokens' => 4096,
            'system' => "Extrae los datos de la factura sin inventar. El campo proveedor es el nombre tal cual aparece en el documento — no confirmes ni valides contra ningún maestro, solo transcribe lo que ves. Los campos de la comunidad (nombre_comunidad, direccion, comunidad_cif, codigo_comunidad, codigo_postal) deben salir ÚNICAMENTE de lo impreso en el propio documento de la factura, nunca del contexto del correo — aunque el asunto o el cuerpo del correo mencionen una comunidad, esa mención nunca sustituye ni completa lo que dice el documento; si el documento no indica esos datos, usa null en vez de tomarlos del correo. comunidad_cif es el CIF/NIF del titular o cliente (la comunidad), nunca el del proveedor — en facturas de suministros (luz, agua, gas) suele aparecer etiquetado como \"CIF titular\" o \"NIF titular\", a menudo en un bloque de información adicional/técnica separado de los datos de contacto del cliente; búscalo también ahí, no solo junto al nombre o la dirección. tipo_servicio debe ser una categoría breve en minúsculas. fecha_factura usa AAAA-MM-DD. importe es el total numérico. Usa null cuando no aparezca un valor. No propongas IDs internos.",
            'tools' => [$tool],
            'tool_choice' => ['type'=>'tool','name'=>'extraer_factura'],
            'messages' => [['role'=>'user','content'=>[self::documentBlock($path,$mimeType),['type'=>'text','text'=>'Extrae los campos requeridos. Contexto del correo: '.mb_substr($context,0,12000)]]]],
        ];
        $invoice = self::toolInput($this->send($payload), 'extraer_factura');
        $invoice['proveedor'] = $invoice['proveedor'] ?: 'desconocido';
        $invoice['tipo_servicio'] = Text::normalize((string)($invoice['tipo_servicio'] ?: 'desconocido'));
        $invoice['moneda'] = $invoice['moneda'] ?: 'EUR';
        return $invoice;
    }

    /**
     * Same restricted-candidate contract as OpenAIExtractor::resolveSupplierAmongCandidates():
     * re-reads the same document with a closed list of ids, forced via `enum` in the tool's
     * input_schema, and the returned id is re-validated against the candidate list in code
     * regardless — a malformed or gamed response can never introduce a supplier outside the list.
     * @param list<array{id:int,official_name:string}> $candidates
     */
    public function resolveSupplierAmongCandidates(string $path, string $mimeType, string $context, string $communityName, array $candidates): ?int
    {
        if (!$candidates) return null;
        $ids = array_map(static fn(array $c): int => $c['id'], $candidates);
        $list = implode("\n", array_map(static fn(array $c): string => '- id '.$c['id'].': '.$c['official_name'], $candidates));
        $tool = [
            'name' => 'elegir_proveedor',
            'description' => 'Elige el proveedor de la lista cerrada, o null si ninguno coincide con evidencia suficiente.',
            'input_schema' => [
                'type'=>'object',
                'properties'=>['supplier_id'=>['anyOf'=>[['type'=>'integer','enum'=>$ids],['type'=>'null']]]],
                'required'=>['supplier_id'],'additionalProperties'=>false,
            ],
        ];
        $payload = [
            'model' => $this->config['model'],
            'max_tokens' => 1024,
            'system' => "Vuelve a inspeccionar el documento completo — incluye cabeceras, logos, sellos y cualquier tabla — para identificar quién emite o presta el servicio de esta factura. La comunidad ya está confirmada como \"$communityName\". Elige EXCLUSIVAMENTE uno de estos proveedores, si hay evidencia clara en el documento de que es él:\n$list\nDevuelve su id numérico exacto en supplier_id. Si ninguno de la lista coincide con evidencia suficiente, devuelve supplier_id null. No propongas ningún proveedor fuera de esta lista, ni inventes uno nuevo.",
            'tools' => [$tool],
            'tool_choice' => ['type'=>'tool','name'=>'elegir_proveedor'],
            'messages' => [['role'=>'user','content'=>[self::documentBlock($path,$mimeType),['type'=>'text','text'=>'Contexto del correo: '.mb_substr($context,0,12000)]]]],
        ];
        $result = self::toolInput($this->send($payload), 'elegir_proveedor');
        $chosen = $result['supplier_id'] ?? null;
        return $chosen !== null && in_array((int)$chosen, $ids, true) ? (int)$chosen : null;
    }

    private static function documentBlock(string $path, string $mimeType): array
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) throw new \RuntimeException('No se pudo leer el documento');
        $encoded = base64_encode($bytes);
        return str_starts_with($mimeType, 'image/')
            ? ['type'=>'image','source'=>['type'=>'base64','media_type'=>$mimeType,'data'=>$encoded]]
            : ['type'=>'document','source'=>['type'=>'base64','media_type'=>'application/pdf','data'=>$encoded]];
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
        $decoded = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Claude respondió HTTP '.$status.': '.($decoded['error']['message'] ?? 'error desconocido'));
        }
        $this->inputTokens += (int)($decoded['usage']['input_tokens'] ?? 0);
        $this->outputTokens += (int)($decoded['usage']['output_tokens'] ?? 0);
        return $decoded;
    }

    /** @param array<string,mixed> $decoded @return array<string,mixed> */
    private static function toolInput(array $decoded, string $toolName): array
    {
        foreach (($decoded['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === $toolName) {
                return is_array($block['input'] ?? null) ? $block['input'] : [];
            }
        }
        throw new \RuntimeException('Claude no devolvió una extracción estructurada');
    }
}

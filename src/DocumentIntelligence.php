<?php
declare(strict_types=1);

namespace Salvest;

/**
 * Las tres operaciones de IA del pipeline. La IA propone; esta clase garantiza que lo que propone
 * cumple el contrato: tipos coherentes, enumerados válidos, confianza entre 0 y 1 y, en las
 * elecciones, un id que pertenece a la lista cerrada que se le envió. Nada de lo que devuelve la
 * IA llega al resto del sistema sin pasar por aquí.
 *
 * Cada llamada queda registrada en $calls (tarea, proveedor, modelo, tokens, latencia y respuesta
 * cruda) para la traza y para medir el coste por documento.
 */
final class DocumentIntelligence
{
    /** @var list<array<string,mixed>> */
    public array $calls = [];

    public function __construct(private AiClient $ai) {}

    public function resetCalls(): void { $this->calls = []; }

    /**
     * @return array<string,mixed> comprensión validada; la respuesta cruda queda en $calls
     * @throws \RuntimeException si la respuesta no permite ni saber qué documento es
     */
    public function understand(string $pdfPath, string $emailContext): array
    {
        $response = $this->call(AiContracts::UNDERSTAND_TASK, AiContracts::understandingInstructions(), AiContracts::understandingSchema(),
            $pdfPath, AiContracts::understandingUserText($emailContext), ['max_output_tokens'=>2048,'reasoning_effort'=>'low']);
        return self::validateUnderstanding($response->data);
    }

    /**
     * @param array<string,mixed> $understanding @param list<array<string,mixed>> $candidates cada uno con 'id'
     * @return array{id:?int,confidence:float,reason:string,valid:bool,rejected_id:?int}
     */
    public function chooseCommunity(string $pdfPath, array $understanding, array $candidates): array
    {
        $ids = array_map(static fn(array $c): int => (int)$c['id'], $candidates);
        $response = $this->call(AiContracts::COMMUNITY_TASK, AiContracts::communityInstructions(), AiContracts::choiceSchema('community_id', $ids),
            $pdfPath, AiContracts::choiceUserText('Comunidades', $understanding, $candidates), ['max_output_tokens'=>1024,'reasoning_effort'=>'medium']);
        return self::validateChoice($response->data, 'community_id', $ids);
    }

    /**
     * @param array<string,mixed> $understanding @param list<array<string,mixed>> $candidates cada uno con 'id'
     * @return array{id:?int,confidence:float,reason:string,valid:bool,rejected_id:?int}
     */
    public function chooseSupplier(string $pdfPath, array $understanding, string $communityName, array $candidates): array
    {
        $ids = array_map(static fn(array $c): int => (int)$c['id'], $candidates);
        $response = $this->call(AiContracts::SUPPLIER_TASK, AiContracts::supplierInstructions(), AiContracts::choiceSchema('supplier_id', $ids),
            $pdfPath, AiContracts::choiceUserText('Proveedores', $understanding, $candidates, $communityName), ['max_output_tokens'=>1024,'reasoning_effort'=>'medium']);
        return self::validateChoice($response->data, 'supplier_id', $ids);
    }

    /** @param array<string,mixed> $schema @param array<string,mixed> $options */
    private function call(string $task, string $instructions, array $schema, ?string $pdfPath, string $userText, array $options): AiResponse
    {
        $response = $this->ai->structured($task, $instructions, $schema, $pdfPath, $userText, $options);
        $this->calls[] = ['task'=>$task,'provider'=>$response->provider,'model'=>$response->model,'input_tokens'=>$response->inputTokens,
            'output_tokens'=>$response->outputTokens,'latency_ms'=>$response->latencyMs,'response'=>$response->data];
        return $response;
    }

    /**
     * Valida y normaliza la comprensión. Lo que no cumple el contrato se convierte en null (un dato
     * que falta) en lugar de propagarse. Solo el tipo de documento es imprescindible: sin él no se
     * puede decidir nada y la respuesta se rechaza como error técnico.
     * @param array<string,mixed> $raw @return array<string,mixed>
     */
    public static function validateUnderstanding(array $raw): array
    {
        $type = $raw['document_type'] ?? null;
        if (!is_string($type) || !in_array($type, AiContracts::DOCUMENT_TYPES, true)) {
            throw new \RuntimeException('La IA no devolvió un tipo de documento válido');
        }
        $string = static function (mixed $value): ?string {
            if (!is_scalar($value)) return null;
            $value = trim((string)$value);
            return $value === '' ? null : mb_substr($value, 0, 500);
        };
        $group = static fn(string $key, array $fields): array => array_combine($fields,
            array_map(static fn(string $field): ?string => $string(is_array($raw[$key] ?? null) ? ($raw[$key][$field] ?? null) : null), $fields));
        $amount = is_array($raw['invoice'] ?? null) ? ($raw['invoice']['total_amount'] ?? null) : null;
        $category = is_array($raw['service'] ?? null) ? ($raw['service']['semantic_category'] ?? null) : null;
        $invoice = $group('invoice', ['number','date','currency','billing_period']);
        $invoice['total_amount'] = is_int($amount) || is_float($amount) ? (float)$amount : null;
        $service = $group('service', ['raw']);
        $service['semantic_category'] = is_string($category) && in_array($category, AiContracts::SERVICE_CATEGORIES, true) ? $category : null;
        return [
            'document_type' => $type,
            'should_process_as_invoice' => ($raw['should_process_as_invoice'] ?? null) === true,
            'document_confidence' => self::confidence($raw['document_confidence'] ?? null),
            'document_reason' => $string($raw['document_reason'] ?? null) ?? '',
            'issuer' => $group('issuer', ['name','tax_id']),
            'customer' => $group('customer', ['name','tax_id','address','postal_code']),
            'invoice' => $invoice,
            'service' => $service,
            'supply_identifiers' => $group('supply_identifiers', ['cups','contract_number','customer_reference']),
        ];
    }

    /**
     * La IA nunca puede devolver un id que no estaba en la lista: si lo hace, la respuesta se
     * rechaza (valid=false) y se guarda el id rechazado para la traza.
     * @param array<string,mixed> $raw @param list<int> $ids
     * @return array{id:?int,confidence:float,reason:string,valid:bool,rejected_id:?int}
     */
    public static function validateChoice(array $raw, string $idField, array $ids): array
    {
        $value = $raw[$idField] ?? null;
        $reason = is_string($raw['reason'] ?? null) ? mb_substr(trim($raw['reason']), 0, 1000) : '';
        $confidence = self::confidence($raw['confidence'] ?? null);
        if ($value === null) return ['id'=>null,'confidence'=>$confidence,'reason'=>$reason,'valid'=>true,'rejected_id'=>null];
        $id = is_int($value) || (is_string($value) && ctype_digit($value)) || (is_float($value) && floor($value) === $value) ? (int)$value : null;
        if ($id === null || !in_array($id, $ids, true)) {
            return ['id'=>null,'confidence'=>$confidence,'reason'=>$reason,'valid'=>false,'rejected_id'=>is_scalar($value) ? (int)$value : null];
        }
        return ['id'=>$id,'confidence'=>$confidence,'reason'=>$reason,'valid'=>true,'rejected_id'=>null];
    }

    private static function confidence(mixed $value): float
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) return 0.0;
        return max(0.0, min(1.0, (float)$value));
    }
}

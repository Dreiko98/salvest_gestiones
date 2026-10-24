<?php
declare(strict_types=1);

namespace Salvest;

/**
 * El único sitio que decide el estado final de un documento. La IA propone; aquí se decide si lo
 * propuesto basta para actuar sin una persona.
 *
 * Estados: classified, needs_review, unclassified (factura sin comunidad: se conserva porque
 * decide la carpeta "Sin clasificar" del correo) e ignored_not_invoice. Los errores técnicos no
 * pasan por aquí: son excepciones y los registra el Worker como `error`.
 *
 * UMBRALES. La IA puntúa su confianza con una rúbrica de tres tramos definida en AiContracts:
 * >= 0.90 "el documento lo dice explícitamente", 0.70–0.89 "evidencia fuerte e indirecta sin
 * alternativa igual de buena", < 0.70 "débil o contradictoria". Los umbrales son los bordes de esos
 * tramos, no números elegidos aparte, y se eligen por el coste de cada error:
 * - Descartar una factura real es un error silencioso (nadie la ve), así que para ignorar un
 *   documento como "no factura" se exige el tramo explícito (0.90). Por debajo, revisión.
 * - Aceptar que algo es una factura, o aceptar la elección de la IA entre una lista cerrada, exige
 *   el tramo fuerte (0.70): por debajo, la propia rúbrica dice que la evidencia no basta.
 */
final class ClassificationPolicy
{
    public const NOT_INVOICE_MIN_CONFIDENCE = 0.90;
    public const INVOICE_MIN_CONFIDENCE = 0.70;
    public const AI_CHOICE_MIN_CONFIDENCE = 0.70;

    private const COMMUNITY_REASONS = [
        'no_candidates' => 'No se ha encontrado ninguna comunidad con el NIF, el nombre o la dirección del cliente.',
        'ai_abstained' => 'Hay varias comunidades posibles y el documento no permite distinguirlas.',
        'ai_low_confidence' => 'La comunidad propuesta no tiene evidencia suficiente en el documento.',
        'ai_invalid_candidate' => 'La IA propuso una comunidad fuera de las candidatas; se ha descartado.',
        'ai_skipped' => 'Comunidad sin decidir.',
    ];
    private const SUPPLIER_REASONS = [
        'no_candidates' => 'El emisor no coincide con ningún proveedor del maestro.',
        'ai_abstained' => 'Hay varios proveedores posibles y el documento no permite distinguirlos.',
        'ai_low_confidence' => 'El proveedor propuesto no tiene evidencia suficiente en el documento.',
        'ai_invalid_candidate' => 'La IA propuso un proveedor fuera de los candidatos; se ha descartado.',
        'tax_id_contradiction' => 'El CIF del emisor no coincide con el del proveedor propuesto.',
        'ai_skipped' => 'Proveedor sin decidir.',
    ];

    /**
     * Primera puerta: ¿se procesa como factura?
     * @param array<string,mixed> $understanding
     * @return array{decision:string,reason_code:?string,reason:?string}
     */
    public function documentGate(array $understanding, string $filename): array
    {
        $type = (string)$understanding['document_type'];
        $isInvoiceType = in_array($type, AiContracts::INVOICE_TYPES, true);
        $process = (bool)$understanding['should_process_as_invoice'];
        $confidence = (float)$understanding['document_confidence'];
        if ($isInvoiceType !== $process) {
            return self::gate('needs_review', 'document_type_inconsistent', 'La IA dio un tipo de documento incoherente con su propia decisión; revisa qué documento es.');
        }
        if (!$isInvoiceType) {
            return $confidence >= self::NOT_INVOICE_MIN_CONFIDENCE
                ? self::gate('ignored_not_invoice', 'not_invoice', 'No es una factura ('.self::typeLabel($type).').')
                : self::gate('needs_review', 'document_type_uncertain', 'Puede que no sea una factura ('.self::typeLabel($type).'), pero no hay certeza suficiente; revisa qué documento es.');
        }
        if ($confidence < self::INVOICE_MIN_CONFIDENCE) {
            return self::gate('needs_review', 'document_type_uncertain', 'No está claro que el documento sea una factura; revisa qué documento es.');
        }
        if (self::filenameSuggestsQuote($filename)) {
            return self::gate('needs_review', 'quote_filename', 'El nombre del archivo indica un presupuesto aunque el documento parece una factura; revisa antes de archivar.');
        }
        return self::gate('continue', null, null);
    }

    /**
     * Decisión final, una vez superada la puerta de documento.
     * @param array<string,mixed> $community resultado de CommunityResolver @param array<string,mixed> $supplier resultado de SupplierResolver
     * @param array<string,mixed> $service resultado de ServiceResolver @param array<string,mixed> $understanding
     * @return array{status:string,reason_code:?string,reason:?string}
     */
    public function decide(array $community, array $supplier, array $service, array $understanding): array
    {
        if ($community['community'] === null) {
            return ['status'=>'unclassified','reason_code'=>'community_'.$community['outcome'],'reason'=>self::COMMUNITY_REASONS[$community['outcome']] ?? 'Comunidad sin decidir.'];
        }
        if ($supplier['supplier'] === null) {
            return ['status'=>'needs_review','reason_code'=>'supplier_'.$supplier['outcome'],'reason'=>self::SUPPLIER_REASONS[$supplier['outcome']] ?? 'Proveedor sin decidir.'];
        }
        if ($service['folder'] === null) {
            return ['status'=>'needs_review','reason_code'=>'service_unknown','reason'=>'No se ha podido determinar a qué servicio corresponde la factura.'];
        }
        if (self::validInvoiceDate($understanding['invoice']['date'] ?? null) === null) {
            return ['status'=>'needs_review','reason_code'=>'invalid_date','reason'=>'Fecha de factura ausente, no reconocible o futura; revisa y confirma manualmente.'];
        }
        return ['status'=>'classified','reason_code'=>null,'reason'=>null];
    }

    /** AAAA-MM-DD real y no posterior a hoy; si no, null. Es lo que exigen la base de datos y Drive. */
    public static function validInvoiceDate(?string $value, ?\DateTimeImmutable $today = null): ?string
    {
        $value = trim((string)$value);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
        $today ??= new \DateTimeImmutable('today');
        return $value <= $today->format('Y-m-d') ? $value : null;
    }

    /** Fase 20: "Presupuesto 1-000035.pdf", "PRE 266.pdf", "Pressupost_12.pdf" sí; "Prestaciones.pdf" no. */
    public static function filenameSuggestsQuote(string $filename): bool
    {
        $words = explode(' ', Text::normalize(pathinfo($filename, PATHINFO_FILENAME)));
        return (bool)array_intersect($words, ['presupuesto','presupuestos','pressupost','pre','proforma']);
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'invoice'=>'factura','credit_note'=>'factura rectificativa','receipt'=>'justificante de pago','budget'=>'presupuesto',
            'technical_report'=>'parte o informe técnico','report'=>'informe o extracto','certificate'=>'certificado','contract'=>'contrato',
            default=>'otro documento',
        };
    }

    /** @return array{decision:string,reason_code:?string,reason:?string} */
    private static function gate(string $decision, ?string $code, ?string $reason): array
    {
        return ['decision'=>$decision,'reason_code'=>$code,'reason'=>$reason];
    }
}

<?php
declare(strict_types=1);

namespace Salvest;

/**
 * Comprensión y clasificación de un PDF, sin efectos externos salvo dos: las llamadas a la IA y,
 * si procede, el autolink comunidad-proveedor. No toca Drive, ni IMAP, ni el disco: el Worker
 * archiva y persiste con lo que esto devuelve.
 *
 *   IA: comprender documento → política: ¿se procesa como factura?
 *     ├─ no → ignored_not_invoice o needs_review (sin más IA)
 *     └─ sí → comunidad (NIF; si no basta, IA entre candidatas)
 *             → proveedor (CIF; identificador exacto; si no basta, IA entre candidatos)
 *             → servicio → política final → autolink si procede
 *
 * Llamadas a la IA por documento: 1 (comprensión) + 0/1 (comunidad) + 0/1 (proveedor). Comunidad
 * y proveedor se desambiguan por separado a propósito: los candidatos de proveedor dependen de la
 * comunidad ya resuelta (qué proveedores trabajan con ella), cada elección se valida contra su
 * propia lista cerrada y la traza dice exactamente cuál de las dos falló. Que las dos hagan falta
 * en el mismo documento es raro: casi todas las facturas traen el NIF de la comunidad y el CIF
 * del emisor.
 */
final class InvoiceProcessor
{
    public function __construct(
        private DocumentIntelligence $ai,
        private CommunityResolver $communities,
        private SupplierResolver $suppliers,
        private ServiceResolver $services,
        private ClassificationPolicy $policy,
        private ?CommunitySupplierAutoLinker $autoLinker = null,
    ) {}

    /** @return array<string,mixed> */
    public function process(string $pdfPath, string $originalFilename, string $emailContext, string $sender): array
    {
        $this->ai->resetCalls();
        $understanding = $this->ai->understand($pdfPath, $emailContext);
        $rawUnderstanding = $this->ai->calls[0]['response'] ?? null;
        $corrections = [];
        $understanding = $this->moveMisplacedCustomerTaxId($understanding, $corrections);
        $gate = $this->policy->documentGate($understanding, $originalFilename);

        $community = ['community'=>null,'source'=>null,'confidence'=>0.0,'outcome'=>'not_attempted','candidates'=>[],'ai'=>null,'tax_id'=>null];
        $supplier = ['supplier'=>null,'source'=>null,'confidence'=>0.0,'outcome'=>'not_attempted','linked'=>false,'relation'=>null,'candidates'=>[],'ai'=>null,'tax_id'=>null];
        $service = ['name'=>null,'folder'=>null,'source'=>null,'tried'=>[]];
        if ($gate['decision'] === 'ignored_not_invoice') {
            $decision = ['status'=>'ignored_not_invoice','reason_code'=>$gate['reason_code'],'reason'=>$gate['reason']];
        } else {
            // Con la puerta en revisión se sigue resolviendo, pero solo con reglas deterministas:
            // sirve para que /Revisar sugiera comunidad y proveedor sin gastar más IA.
            $allowAi = $gate['decision'] === 'continue';
            $community = $this->communities->resolve($understanding, $allowAi
                ? fn(array $candidates): array => $this->ai->chooseCommunity($pdfPath, $understanding, $candidates) : null);
            $supplier = $this->suppliers->resolve($understanding, $community['community'], $sender, $allowAi && $community['community'] !== null
                ? fn(array $candidates): array => $this->ai->chooseSupplier($pdfPath, $understanding, (string)$community['community']['official_name'], $candidates) : null);
            $service = $this->services->resolve($supplier['supplier'], $supplier['relation'], $understanding);
            $decision = $gate['decision'] === 'continue'
                ? $this->policy->decide($community, $supplier, $service, $understanding)
                : ['status'=>'needs_review','reason_code'=>$gate['reason_code'],'reason'=>$gate['reason']];
        }
        $autoLink = $this->autoLink($decision['status'], $community, $supplier, $understanding);

        return [
            'status' => $decision['status'],
            'reason_code' => $decision['reason_code'],
            'reason' => $decision['reason'],
            'understanding' => $understanding,
            'raw_understanding' => $rawUnderstanding,
            'community' => $community['community'],
            'supplier' => $supplier['supplier'],
            'service' => $service,
            'ai_calls' => $this->ai->calls,
            'classification' => $this->classification($understanding, $gate, $community, $supplier, $service, $decision, $autoLink, $corrections),
            'legacy' => self::legacyFields($understanding, $supplier['supplier'], $service),
        ];
    }

    /**
     * Fase 23 sobre la comprensión: si el CIF del "emisor" es el de una comunidad nuestra, la IA lo
     * ha colocado mal (pasa en los recibos de FACSA, donde el único NIF visible es el del titular).
     * Ningún proveedor comparte CIF con una comunidad, así que se mueve al cliente.
     * @param array<string,mixed> $understanding @param list<string> $corrections @return array<string,mixed>
     */
    private function moveMisplacedCustomerTaxId(array $understanding, array &$corrections): array
    {
        $issuerTaxId = Text::normalizeIdentifier($understanding['issuer']['tax_id'] ?? null);
        if ($issuerTaxId === '' || !$this->communities->communitiesWithTaxId($issuerTaxId)) return $understanding;
        if (Text::normalizeIdentifier($understanding['customer']['tax_id'] ?? null) === '') {
            $understanding['customer']['tax_id'] = $understanding['issuer']['tax_id'];
            $corrections[] = 'issuer_tax_id_moved_to_customer';
        } else {
            $corrections[] = 'issuer_tax_id_was_a_community_tax_id';
        }
        $understanding['issuer']['tax_id'] = null;
        return $understanding;
    }

    /**
     * Solo se aprende una relación desde una resolución determinista (CIF, nombre exacto o alias
     * exacto), de un proveedor con servicio principal en el maestro, en una factura clasificada.
     * Nunca desde una elección de la IA: un error de la IA no debe convertirse en una regla.
     * @param array<string,mixed> $community @param array<string,mixed> $supplier @param array<string,mixed> $understanding
     * @return array<string,mixed>
     */
    private function autoLink(string $status, array $community, array $supplier, array $understanding): array
    {
        if ($status !== 'classified' || $supplier['linked']) return ['attempted'=>false,'reason'=>$supplier['linked'] ? 'relation_already_exists' : 'not_classified'];
        if (!in_array($supplier['source'], SupplierResolver::DETERMINISTIC_SOURCES, true)) return ['attempted'=>false,'reason'=>'non_deterministic_source'];
        $folder = ServiceCatalog::folderFor($supplier['supplier']['service_type_name'] ?? null);
        if ($folder === null) return ['attempted'=>false,'reason'=>'no_master_service'];
        if ($this->autoLinker === null) return ['attempted'=>false,'reason'=>'autolink_disabled'];
        $raw = (string)($understanding['issuer']['name'] ?? '') ?: (string)($supplier['supplier']['name'] ?? '') ?: (string)$supplier['supplier']['official_name'];
        $result = $this->autoLinker->linkIfMissing((int)$community['community']['id'], (int)$supplier['supplier']['id'], $folder, $raw);
        return ['attempted'=>true,'inserted'=>$result['inserted'],'reason'=>$result['reason'],'category'=>$folder];
    }

    /**
     * La explicación completa de la decisión: se guarda en processed_attachments.decision_json.
     * @param array<string,mixed> $understanding @param array<string,mixed> $gate @param array<string,mixed> $community
     * @param array<string,mixed> $supplier @param array<string,mixed> $service @param array<string,mixed> $decision
     * @param array<string,mixed> $autoLink @param list<string> $corrections @return array<string,mixed>
     */
    private function classification(array $understanding, array $gate, array $community, array $supplier, array $service, array $decision, array $autoLink, array $corrections): array
    {
        return [
            'version' => 2,
            'document' => ['type'=>$understanding['document_type'],'should_process_as_invoice'=>$understanding['should_process_as_invoice'],
                'confidence'=>$understanding['document_confidence'],'reason'=>$understanding['document_reason'],'gate'=>$gate['decision'],'gate_reason'=>$gate['reason_code']],
            'corrections' => $corrections,
            'community' => ['id'=>$community['community']['id'] ?? null,'name'=>$community['community']['official_name'] ?? null,'source'=>$community['source'],
                'confidence'=>$community['confidence'],'outcome'=>$community['outcome'],'tax_id'=>$community['tax_id'],'candidates'=>$community['candidates'],'ai'=>$community['ai']],
            'supplier' => ['id'=>$supplier['supplier']['id'] ?? null,'name'=>$supplier['supplier']['official_name'] ?? null,'source'=>$supplier['source'],
                'confidence'=>$supplier['confidence'],'outcome'=>$supplier['outcome'],'linked'=>$supplier['linked'],'tax_id'=>$supplier['tax_id'],'candidates'=>$supplier['candidates'],'ai'=>$supplier['ai']],
            'service' => $service,
            'policy' => $decision,
            'autolink' => $autoLink,
            'ai_calls' => array_map(static fn(array $call): array => array_diff_key($call, ['response'=>true]) + ['response'=>$call['task'] === AiContracts::UNDERSTAND_TASK ? '(ver extraction_json)' : $call['response']], $this->ai->calls),
        ];
    }

    /**
     * Los campos planos que siguen usando las columnas de processed_attachments, Archiver y
     * DriveInvoiceArchiver. La fecha solo se pasa si es válida: una fecha mal formada nunca llega
     * a la columna DATE.
     * @param array<string,mixed> $understanding @param array<string,mixed>|null $supplier @param array<string,mixed> $service
     * @return array<string,mixed>
     */
    public static function legacyFields(array $understanding, ?array $supplier, array $service): array
    {
        return [
            'proveedor' => $supplier !== null ? (string)$supplier['official_name'] : null,
            'raw_supplier_name' => $understanding['issuer']['name'] ?? null,
            'proveedor_cif' => $understanding['issuer']['tax_id'] ?? null,
            'tipo_servicio' => $service['name'] !== null ? mb_strtolower((string)$service['name']) : ($understanding['service']['semantic_category'] ?? null),
            'direccion' => $understanding['customer']['address'] ?? null,
            'importe' => $understanding['invoice']['total_amount'] ?? null,
            'moneda' => $understanding['invoice']['currency'] ?? 'EUR',
            'fecha_factura' => ClassificationPolicy::validInvoiceDate($understanding['invoice']['date'] ?? null),
            'numero_factura' => $understanding['invoice']['number'] ?? null,
        ];
    }
}

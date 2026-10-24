<?php
declare(strict_types=1);

namespace Salvest;

/**
 * ¿Qué proveedor del maestro emite la factura?
 *
 * 1. CIF del emisor normalizado → si identifica exactamente un proveedor activo, ese es, con
 *    confianza 1, esté o no vinculado a la comunidad. Si lo comparten varios (caso real INMECAS /
 *    H2O PLUS), decide el nombre o alias exacto si señala a uno solo; si no, la IA elige entre
 *    exactamente esos.
 * 2. Si no hay CIF o no coincide con ninguno: SupplierCandidateRetriever propone candidatos. Una
 *    coincidencia exacta con un identificador del maestro (nombre comercial, razón social o alias)
 *    que apunta a un único proveedor decide sin IA, salvo que el CIF del documento la contradiga.
 * 3. En cualquier otro caso, la IA elige entre los candidatos (vinculados a la comunidad + plausibles
 *    de todo el maestro) o se abstiene.
 *
 * La elección de la IA se descarta si el documento trae un CIF de emisor y el proveedor elegido
 * tiene otro distinto en el maestro (regla de la Fase 20).
 */
final class SupplierResolver
{
    /** Fuentes deterministas: las únicas desde las que se permite aprender una relación nueva (autolink). */
    public const DETERMINISTIC_SOURCES = ['tax_id_exact','name_exact','alias_exact'];

    public function __construct(private Database $db, private SupplierCandidateRetriever $retriever) {}

    /**
     * @param array<string,mixed> $understanding @param array<string,mixed>|null $community
     * @param (callable(list<array<string,mixed>>):array{id:?int,confidence:float,reason:string,valid:bool,rejected_id:?int})|null $choose
     * @return array<string,mixed>
     */
    public function resolve(array $understanding, ?array $community, string $sender, ?callable $choose): array
    {
        $issuer = $understanding['issuer'] ?? [];
        $taxId = Text::normalizeIdentifier($issuer['tax_id'] ?? null);
        $communityId = $community !== null ? (int)$community['id'] : null;
        $result = ['supplier'=>null,'source'=>null,'confidence'=>0.0,'outcome'=>'no_candidates','linked'=>false,'relation'=>null,
            'tax_id'=>['value'=>$issuer['tax_id'] ?? null,'normalized'=>$taxId,'matches'=>0],'candidates'=>[],'ai'=>null];

        $byTaxId = $taxId !== '' ? $this->suppliersWithTaxId($taxId) : [];
        $result['tax_id']['matches'] = count($byTaxId);
        if (count($byTaxId) === 1) {
            return $this->resolved($result, $byTaxId[0], 'tax_id_exact', 1.0, $communityId, [self::summary($byTaxId[0], ['tax_id'], $this->relation($communityId, (int)$byTaxId[0]['id']) !== null)]);
        }
        if (count($byTaxId) > 1) {
            $source = 'ai_after_shared_tax_id';
            // Las señales del recuperador se suman a las del CIF. Quienes comparten CIF son la misma
            // sociedad, así que la razón social no los distingue (en el maestro real INMECAS tiene
            // razón social "H2O PLUS, S.L." y H2O PLUS "H2O PLUS SLU"): solo el nombre comercial o
            // un alias. Si uno de esos coincide exactamente con un solo proveedor, decide sin IA.
            $signals = [];
            foreach ($this->retriever->candidates($issuer, $sender, $communityId) as $c) $signals[(int)$c['supplier']['id']] = $c['signals'];
            $candidates = array_map(fn(array $s): array => ['supplier'=>$s,'signals'=>array_merge(['tax_id'], $signals[(int)$s['id']] ?? []),
                'linked'=>$this->relation($communityId, (int)$s['id']) !== null], $byTaxId);
            $issuerName = Text::normalizeCompanyName($issuer['name'] ?? null);
            $exact = array_values(array_filter($candidates, static fn(array $c): bool => (bool)array_intersect($c['signals'], ['exact_alias','sender_domain_alias'])
                || ($issuerName !== '' && Text::normalizeCompanyName((string)($c['supplier']['name'] ?? '')) === $issuerName)));
            if (count($exact) === 1) {
                $exactSource = in_array('exact_name', $exact[0]['signals'], true) ? 'name_exact' : 'alias_exact';
                return $this->resolved($result, $exact[0]['supplier'], $exactSource, 1.0, $communityId,
                    array_map(static fn(array $c): array => self::summary($c['supplier'], $c['signals'], $c['linked']), $candidates));
            }
        } else {
            $source = 'ai_after_retrieval';
            $candidates = $this->retriever->candidates($issuer, $sender, $communityId);
            $exact = array_values(array_filter($candidates, static fn(array $c): bool => (bool)array_intersect($c['signals'], SupplierCandidateRetriever::EXACT_SIGNALS)));
            // Varios proveedores con el mismo identificador exacto, pero solo uno ya trabaja con esta
            // comunidad: ese vínculo desempata sin IA (los vinculados son los candidatos prioritarios).
            if (count($exact) > 1) {
                $linkedExact = array_values(array_filter($exact, static fn(array $c): bool => $c['linked']));
                if (count($linkedExact) === 1) $exact = $linkedExact;
            }
            if (count($exact) === 1 && !self::contradictsTaxId($taxId, $exact[0]['supplier'])) {
                $exactSource = in_array('exact_name', $exact[0]['signals'], true) ? 'name_exact' : 'alias_exact';
                return $this->resolved($result, $exact[0]['supplier'], $exactSource, 1.0, $communityId,
                    array_map(static fn(array $c): array => self::summary($c['supplier'], $c['signals'], $c['linked']), $candidates));
            }
        }
        $result['candidates'] = array_map(static fn(array $c): array => self::summary($c['supplier'], $c['signals'], $c['linked']), $candidates);
        if (!$candidates) return $result;
        if ($choose === null) return ['outcome'=>'ai_skipped'] + $result;

        $choice = $choose($result['candidates']);
        $result['ai'] = $choice;
        $result['confidence'] = $choice['confidence'];
        if (!$choice['valid']) return ['outcome'=>'ai_invalid_candidate'] + $result;
        if ($choice['id'] === null) return ['outcome'=>'ai_abstained'] + $result;
        if ($choice['confidence'] < ClassificationPolicy::AI_CHOICE_MIN_CONFIDENCE) return ['outcome'=>'ai_low_confidence'] + $result;
        foreach ($candidates as $candidate) {
            if ((int)$candidate['supplier']['id'] !== $choice['id']) continue;
            if (self::contradictsTaxId($taxId, $candidate['supplier'])) return ['outcome'=>'tax_id_contradiction'] + $result;
            return $this->resolved($result, $candidate['supplier'], $source, $choice['confidence'], $communityId, $result['candidates']);
        }
        return ['outcome'=>'ai_invalid_candidate'] + $result;
    }

    /** @param array<string,mixed> $result @param array<string,mixed> $supplier @param list<array<string,mixed>> $candidates @return array<string,mixed> */
    private function resolved(array $result, array $supplier, string $source, float $confidence, ?int $communityId, array $candidates): array
    {
        $relation = $this->relation($communityId, (int)$supplier['id']);
        return ['supplier'=>$supplier,'source'=>$source,'confidence'=>$confidence,'outcome'=>'resolved','linked'=>$relation !== null,'relation'=>$relation,'candidates'=>$candidates] + $result;
    }

    /** @return list<array<string,mixed>> */
    private function suppliersWithTaxId(string $normalizedTaxId): array
    {
        $rows = $this->db->all('SELECT s.*,st.name service_type_name FROM suppliers s LEFT JOIN service_types st ON st.id=s.main_service_type_id WHERE s.active=1 AND s.cif IS NOT NULL ORDER BY s.id');
        return array_values(array_filter($rows, static fn(array $s): bool => Text::normalizeIdentifier((string)$s['cif']) === $normalizedTaxId));
    }

    /** @return array<string,mixed>|null */
    private function relation(?int $communityId, int $supplierId): ?array
    {
        if ($communityId === null) return null;
        return $this->db->one('SELECT category,contract_reference FROM community_suppliers WHERE community_id=? AND supplier_id=?', [$communityId, $supplierId]);
    }

    /** El documento trae CIF del emisor, el proveedor tiene CIF en el maestro y no coinciden. @param array<string,mixed> $supplier */
    public static function contradictsTaxId(string $documentTaxId, array $supplier): bool
    {
        $supplierTaxId = Text::normalizeIdentifier((string)($supplier['cif'] ?? ''));
        return $documentTaxId !== '' && $supplierTaxId !== '' && $supplierTaxId !== $documentTaxId;
    }

    /** Lo que ve la IA (y la traza) de cada candidato. @param array<string,mixed> $supplier @param list<string> $signals @return array<string,mixed> */
    private static function summary(array $supplier, array $signals, bool $linked): array
    {
        return array_filter([
            'id'=>(int)$supplier['id'],
            'nombre_comercial'=>$supplier['name'] ?? null,
            'razon_social'=>(string)$supplier['official_name'],
            'cif'=>$supplier['cif'] ?? null,
            'alias'=>($supplier['aliases'] ?? []) ?: null,
            'servicio_principal'=>$supplier['service_type_name'] ?? null,
            'ya_trabaja_con_la_comunidad'=>$linked,
            'senales'=>$signals,
        ], static fn(mixed $value): bool => $value !== null && $value !== '');
    }
}

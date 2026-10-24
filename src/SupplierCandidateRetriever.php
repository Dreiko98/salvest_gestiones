<?php
declare(strict_types=1);

namespace Salvest;

/**
 * Propone proveedores para un documento cuyo CIF de emisor no identifica a ninguno. Las señales
 * por nombre sirven para recuperar candidatos, no para decidir; la única excepción son las
 * coincidencias exactas con un identificador declarado por una persona (nombre comercial, razón
 * social o alias del maestro), que SupplierResolver acepta sin IA cuando apuntan a un solo
 * proveedor.
 *
 * Los proveedores ya vinculados a la comunidad entran siempre como candidatos (es un indicio
 * fuerte), pero no son una cárcel: también entran los proveedores de todo el maestro con alguna
 * señal, para que el correcto pueda encontrarse aunque todavía no tenga relación con la comunidad.
 */
final class SupplierCandidateRetriever
{
    public const MAX_CANDIDATES = 12;
    public const RETRIEVAL_MIN_SIMILARITY = 70.0;
    /** Señales que equivalen a un identificador declarado en el maestro. */
    public const EXACT_SIGNALS = ['exact_name','exact_alias','sender_domain_alias'];

    public function __construct(private Database $db) {}

    /**
     * @param array<string,mixed> $issuer comprensión: name, tax_id
     * @return list<array{supplier:array<string,mixed>,signals:list<string>,similarity:float,linked:bool,relation:?array<string,mixed>}>
     *   `supplier` es la fila del maestro más `service_type_name` y `aliases` (valores originales)
     */
    public function candidates(array $issuer, string $sender, ?int $communityId): array
    {
        $providerName = Text::normalizeCompanyName($issuer['name'] ?? null);
        $taxAlias = Text::normalize($issuer['tax_id'] ?? null);
        $domain = Text::normalize(self::senderDomain($sender));
        $suppliers = $this->db->all('SELECT s.*,st.name service_type_name FROM suppliers s LEFT JOIN service_types st ON st.id=s.main_service_type_id WHERE s.active=1 ORDER BY s.id');
        $aliases = []; $aliasValues = [];
        foreach ($this->db->all('SELECT supplier_id,value,normalized_value FROM supplier_aliases WHERE active=1 ORDER BY id') as $alias) {
            $aliases[(int)$alias['supplier_id']][] = (string)$alias['normalized_value'];
            $aliasValues[(int)$alias['supplier_id']][] = (string)$alias['value'];
        }
        $relations = [];
        if ($communityId !== null) {
            foreach ($this->db->all('SELECT supplier_id,category,contract_reference FROM community_suppliers WHERE community_id=?', [$communityId]) as $relation) $relations[(int)$relation['supplier_id']] = $relation;
        }

        $found = [];
        foreach ($suppliers as $supplier) {
            $id = (int)$supplier['id'];
            $names = self::candidateNames($supplier);
            $supplierAliases = $aliases[$id] ?? [];
            $signals = [];
            if ($providerName !== '' && in_array($providerName, $names, true)) $signals[] = 'exact_name';
            if ($providerName !== '' && in_array($providerName, $supplierAliases, true)) $signals[] = 'exact_alias';
            elseif ($taxAlias !== '' && in_array($taxAlias, $supplierAliases, true)) $signals[] = 'exact_alias';
            if ($domain !== '' && in_array($domain, $supplierAliases, true)) $signals[] = 'sender_domain_alias';
            $similarity = 0.0;
            if ($providerName !== '') {
                foreach (array_merge($names, $supplierAliases) as $name) {
                    if (Text::containsWholeWords($providerName, $name)) $signals['containment'] = 'containment';
                    elseif (Text::containsAllWords($providerName, $name)) $signals['tokens'] = 'tokens';
                    $similarity = max($similarity, Text::similarity($providerName, $name));
                }
            }
            if ($domain !== '') foreach ($names as $name) $similarity = max($similarity, Text::similarity($domain, $name));
            if ($similarity >= self::RETRIEVAL_MIN_SIMILARITY) $signals['similarity'] = 'similarity';
            $linked = isset($relations[$id]);
            if (!$signals && !$linked) continue;
            $found[] = ['supplier'=>$supplier + ['aliases'=>$aliasValues[$id] ?? []],'signals'=>array_values(array_unique(array_values($signals))),'similarity'=>$similarity,'linked'=>$linked,'relation'=>$relations[$id] ?? null];
        }
        usort($found, static function (array $a, array $b): int {
            $rank = static fn(array $c): array => [
                array_intersect($c['signals'], self::EXACT_SIGNALS) ? 1 : 0,
                in_array('containment', $c['signals'], true) || in_array('tokens', $c['signals'], true) ? 1 : 0,
                $c['signals'] ? 1 : 0,
                $c['linked'] ? 1 : 0,
                $c['similarity'],
            ];
            return $rank($b) <=> $rank($a);
        });
        return array_slice($found, 0, self::MAX_CANDIDATES);
    }

    /** Nombre comercial y razón social normalizados, sin repetidos. @param array<string,mixed> $supplier @return list<string> */
    public static function candidateNames(array $supplier): array
    {
        $names = [];
        foreach ([$supplier['name'] ?? null, $supplier['official_name'] ?? null] as $raw) {
            $normalized = Text::normalizeCompanyName($raw === null ? null : (string)$raw);
            if ($normalized !== '' && !in_array($normalized, $names, true)) $names[] = $normalized;
        }
        return $names;
    }

    /** "Facturas FACSA <facturas@facsa.es>" → "facsa.es". */
    public static function senderDomain(string $sender): string
    {
        if (!preg_match('/@([A-Za-z0-9.-]+)/', $sender, $match)) return '';
        return strtolower(trim($match[1], '.'));
    }
}

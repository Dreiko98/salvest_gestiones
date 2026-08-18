<?php
declare(strict_types=1);

namespace Salvest;

/**
 * OpenAI extracts; this class decides. Every method here treats OpenAI's output as
 * candidate values, never as a final decision — MySQL's exact identifiers (CIF,
 * CUPS/contract/reference, supplier CIF) always outrank fuzzy name/address matching,
 * and fuzzy matching always outranks whatever OpenAI proposed as a plain suggestion.
 */
final class Classifier
{
    public function __construct(private Database $db, private float $threshold = 92.0) {}

    /**
     * Community precedence: internal code exact -> holder CIF exact -> contractual
     * identifier exact (CUPS/contract/customer reference) -> fuzzy name/address
     * (OpenAI's nombre_comunidad/direccion only ever feed this fuzzy step — they are
     * never trusted directly).
     * @param array<string,mixed> $invoice @return array{community:?array,confidence:float,evidence:array}
     */
    public function classify(array $invoice, string $context = ''): array
    {
        $code=CommunityCsvImporter::codeOrEmpty((string)($invoice['codigo_comunidad']??''));
        if($code!==''){
            $row=$this->db->one('SELECT * FROM communities WHERE external_code=? AND active=1',[$code]);
            if($row)return['community'=>$row,'confidence'=>100.0,'evidence'=>['field'=>'codigo_comunidad','type'=>'exact']];
        }
        // Holder/customer CIF — never the supplier's CIF — checked before contractual
        // identifiers because every community row requires a CIF at import time, while
        // CUPS/contract/reference are optional per-community entries and more likely to
        // be missing or stale.
        $holderCif = Text::normalize((string)($invoice['comunidad_cif'] ?? ''));
        if ($holderCif !== '') {
            $row = $this->matchByNormalizedCif($holderCif);
            if ($row) return ['community'=>$row,'confidence'=>100.0,'evidence'=>['field'=>'holder_cif','type'=>'exact']];
        }
        foreach (['cups'=>'cups','numero_contrato'=>'contract','referencia_cliente'=>'customer_reference'] as $field => $type) {
            $value = Text::normalize((string)($invoice[$field] ?? ''));
            if ($value === '') continue;
            $row = $this->db->one("SELECT c.* FROM community_identifiers i JOIN communities c ON c.id=i.community_id
                WHERE i.identifier_type=? AND i.normalized_value=? AND i.active=1 AND c.active=1", [$type, $value]);
            if ($row) return ['community'=>$row,'confidence'=>100.0,'evidence'=>['field'=>$field,'type'=>'exact']];
        }
        $communities = $this->db->all('SELECT * FROM communities WHERE active=1');
        $aliases = $this->db->all('SELECT a.*,c.official_name,c.main_address,c.cif,c.active FROM community_aliases a JOIN communities c ON c.id=a.community_id WHERE a.active=1 AND c.active=1');
        $candidates = [];
        foreach($communities as$community){$candidates[]=['community'=>$community,'value'=>$community['main_address'],'kind'=>'address'];$candidates[]=['community'=>$community,'value'=>$community['official_name'],'kind'=>'name'];}
        foreach ($aliases as $alias) {
            $candidates[] = ['community'=>['id'=>$alias['community_id'],'official_name'=>$alias['official_name'],
                'main_address'=>$alias['main_address'],'cif'=>$alias['cif']],'value'=>$alias['value'],'kind'=>'address'];
        }
        $queries=['address'=>array_filter([(string)($invoice['direccion']??''),$context]),'name'=>array_filter([(string)($invoice['nombre_comunidad']??''),$context])];
        $best = null; $bestScore = 0.0;
        foreach ($candidates as $candidate) {
            foreach ($queries[$candidate['kind']] as $query) {
                $score = Text::similarity($query, (string)$candidate['value']);
                if ($score > $bestScore) { $best = $candidate['community']; $bestScore = $score; }
            }
        }
        return ['community'=>$bestScore >= $this->threshold ? $best : null,'confidence'=>$bestScore,
            'evidence'=>['field'=>'address','type'=>'fuzzy','score'=>$bestScore / 100]];
    }

    /** community.cif is stored as typed (spacing/case may vary), so this compares normalized values in PHP rather than in SQL. */
    private function matchByNormalizedCif(string $holderCif): ?array
    {
        foreach ($this->db->all('SELECT * FROM communities WHERE active=1') as $community) {
            if (Text::normalize((string)$community['cif']) === $holderCif) return $community;
        }
        return null;
    }

    /**
     * Supplier precedence: emitter/supplier CIF exact -> name exact (>=threshold) -> alias
     * (name/CIF/sender domain). This is the global "which supplier is this" lookup, scoped
     * to no particular community — InvoiceRouter checks separately whether the resolved
     * supplier is actually linked to the resolved community.
     * @param array<string,mixed> $invoice @return array{supplier:?array,evidence:?array}
     */
    public function resolveSupplier(array $invoice, string $sender): array
    {
        $name = Text::normalize((string)($invoice['proveedor'] ?? ''));
        $cif = Text::normalize((string)($invoice['proveedor_cif'] ?? ''));
        $domain = Text::normalize(substr(strrchr($sender, '@') ?: '', 1));
        $suppliers = $this->db->all('SELECT s.*,st.name service_type_name FROM suppliers s LEFT JOIN service_types st ON st.id=s.main_service_type_id WHERE s.active=1');
        if ($cif !== '') {
            foreach ($suppliers as $supplier) {
                if (Text::normalize((string)$supplier['cif']) === $cif) {
                    return ['supplier'=>$supplier,'evidence'=>['field'=>'supplier_cif','type'=>'exact']];
                }
            }
        }
        if ($name !== '') {
            foreach ($suppliers as $supplier) {
                if (Text::similarity($name, (string)$supplier['normalized_name']) >= 92) {
                    return ['supplier'=>$supplier,'evidence'=>['field'=>'proveedor','type'=>'fuzzy']];
                }
            }
        }
        foreach ($this->db->all('SELECT * FROM supplier_aliases WHERE active=1') as $alias) {
            if (in_array((string)$alias['normalized_value'], [$name,$cif,$domain], true)) {
                foreach ($suppliers as $supplier) {
                    if ((int)$supplier['id'] === (int)$alias['supplier_id']) {
                        $field = $alias['normalized_value']===$cif&&$cif!=='' ? 'supplier_cif' : ($alias['normalized_value']===$domain&&$domain!=='' ? 'sender_domain' : 'alias');
                        return ['supplier'=>$supplier,'evidence'=>['field'=>$field,'type'=>'alias']];
                    }
                }
            }
        }
        return ['supplier'=>null,'evidence'=>null];
    }

    /**
     * Community-scoped supplier resolution — the primary path whenever a community is
     * already known. Most suppliers have no CIF on file (it is an optional, excellent
     * signal when present, never a requirement), so the chain has to work by name alone:
     * exact normalized name -> exact alias -> unambiguous commercial-name containment
     * ("IBERDROLA" found as a whole word inside "IBERDROLA CLIENTES S.A.U.") -> unambiguous
     * whole-word overlap -> the existing 92% fuzzy match, still restricted to this
     * community's suppliers, never loosened globally. Any tier that matches more than one
     * candidate stops immediately with ambiguous=true instead of guessing.
     * @param array<string,mixed> $invoice @return array{supplier:?array,evidence:?array,ambiguous:bool}
     */
    public function resolveSupplierInCommunity(int $communityId, array $invoice, string $sender): array
    {
        $rows = $this->db->all('SELECT cs.category,cs.contract_reference,s.*,st.name service_type_name FROM community_suppliers cs
            JOIN suppliers s ON s.id=cs.supplier_id LEFT JOIN service_types st ON st.id=s.main_service_type_id WHERE cs.community_id=? AND s.active=1', [$communityId]);
        $none = ['supplier'=>null,'evidence'=>null,'ambiguous'=>false];
        if (!$rows) return $none;

        $cif = Text::normalize((string)($invoice['proveedor_cif'] ?? ''));
        if ($cif !== '') {
            $found = self::uniqueMatch($rows, static fn(array $r): bool => (string)($r['cif'] ?? '') !== '' && Text::normalize((string)$r['cif']) === $cif);
            if ($found !== null) return $found === false ? ['supplier'=>null,'evidence'=>null,'ambiguous'=>true] : ['supplier'=>$found,'evidence'=>['field'=>'supplier_cif','type'=>'exact'],'ambiguous'=>false];
        }

        $providerName = Text::normalizeCompanyName((string)($invoice['proveedor'] ?? ''));
        if ($providerName !== '') {
            $found = self::uniqueMatch($rows, static fn(array $r): bool => Text::normalizeCompanyName((string)$r['official_name']) === $providerName);
            if ($found !== null) return $found === false ? ['supplier'=>null,'evidence'=>null,'ambiguous'=>true] : ['supplier'=>$found,'evidence'=>['field'=>'proveedor','type'=>'exact_name'],'ambiguous'=>false];
        }

        $domain = Text::normalize(substr(strrchr($sender, '@') ?: '', 1));
        $candidateValues = array_filter([$providerName, $cif, $domain], static fn(string $v): bool => $v !== '');
        if ($candidateValues) {
            $supplierIds = array_map(static fn(array $r): int => (int)$r['id'], $rows);
            $placeholders = implode(',', array_fill(0, count($supplierIds), '?'));
            $aliases = $this->db->all("SELECT * FROM supplier_aliases WHERE active=1 AND supplier_id IN ($placeholders)", $supplierIds);
            $matchedIds = [];
            foreach ($aliases as $alias) {
                if (in_array((string)$alias['normalized_value'], $candidateValues, true)) $matchedIds[(int)$alias['supplier_id']] = true;
            }
            if (count($matchedIds) > 1) return ['supplier'=>null,'evidence'=>null,'ambiguous'=>true];
            if (count($matchedIds) === 1) {
                $id = array_key_first($matchedIds);
                foreach ($rows as $row) if ((int)$row['id'] === $id) return ['supplier'=>$row,'evidence'=>['field'=>'alias','type'=>'exact'],'ambiguous'=>false];
            }
        }

        if ($providerName !== '') {
            $found = self::uniqueMatch($rows, static fn(array $r): bool => Text::containsWholeWords($providerName, Text::normalizeCompanyName((string)$r['official_name'])));
            if ($found !== null) return $found === false ? ['supplier'=>null,'evidence'=>null,'ambiguous'=>true] : ['supplier'=>$found,'evidence'=>['field'=>'proveedor','type'=>'name_containment'],'ambiguous'=>false];

            $found = self::uniqueMatch($rows, static fn(array $r): bool => Text::containsAllWords($providerName, Text::normalizeCompanyName((string)$r['official_name'])));
            if ($found !== null) return $found === false ? ['supplier'=>null,'evidence'=>null,'ambiguous'=>true] : ['supplier'=>$found,'evidence'=>['field'=>'proveedor','type'=>'token_match'],'ambiguous'=>false];
        }

        $best = null; $bestScore = 0.0; $tied = 0;
        foreach ($rows as $row) {
            $name = (string)$row['normalized_name'];
            $score = max($providerName !== '' ? Text::similarity($providerName, $name) : 0.0, $domain !== '' ? Text::similarity($domain, $name) : 0.0);
            if ($score > $bestScore) { $bestScore = $score; $best = $row; $tied = 1; }
            elseif ($score === $bestScore && $score > 0.0) { $tied++; }
        }
        if ($bestScore >= $this->threshold) {
            return $tied > 1 ? ['supplier'=>null,'evidence'=>null,'ambiguous'=>true] : ['supplier'=>$best,'evidence'=>['field'=>'proveedor','type'=>'fuzzy'],'ambiguous'=>false];
        }
        return $none;
    }

    /** @param list<array<string,mixed>> $rows @param callable(array<string,mixed>):bool $predicate
     * @return array<string,mixed>|false|null the matching row, false if >1 matched (ambiguous), or null if none matched */
    private static function uniqueMatch(array $rows, callable $predicate): array|false|null
    {
        $matches = array_values(array_filter($rows, $predicate));
        if (count($matches) > 1) return false;
        return $matches[0] ?? null;
    }

    /**
     * Service precedence, once a supplier is confirmed: the supplier's own configured type
     * (suppliers.main_service_type_id) outranks the specific community-supplier relation's
     * category, which outranks OpenAI's tipo_servicio guess. MySQL corrects OpenAI here,
     * not the other way round.
     * @param array<string,mixed> $supplier @param array<string,mixed>|null $relation
     * @return array{service:string,evidence:array}
     */
    public function resolveService(array $supplier, ?array $relation, string $openAiHint): array
    {
        if (!empty($supplier['service_type_name'])) {
            return ['service'=>(string)$supplier['service_type_name'],'evidence'=>['field'=>'supplier_main_service_type','type'=>'configured']];
        }
        if ($relation && !empty($relation['category'])) {
            return ['service'=>(string)$relation['category'],'evidence'=>['field'=>'community_supplier_category','type'=>'configured']];
        }
        return ['service'=>$openAiHint!==''?$openAiHint:'desconocido','evidence'=>['field'=>'tipo_servicio','type'=>'openai_suggestion']];
    }

    public function supplierAcceptsService(array $supplier, string $service): bool
    {
        $normalized = Text::normalize($service);
        return $this->db->one("SELECT 1 ok FROM service_types st LEFT JOIN supplier_service_types ss ON ss.service_type_id=st.id
            WHERE st.active=1 AND st.normalized_name=? AND (st.id=? OR ss.supplier_id=?) LIMIT 1",
            [$normalized, $supplier['main_service_type_id'], $supplier['id']]) !== null;
    }
}

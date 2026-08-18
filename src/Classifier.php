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

    /** Is $supplierId linked to $communityId, and under which category/contract reference? */
    public function communitySupplierRelation(int $communityId, int $supplierId): ?array
    {
        return $this->db->one('SELECT category,contract_reference FROM community_suppliers WHERE community_id=? AND supplier_id=?', [$communityId, $supplierId]);
    }

    /** Fallback used only when resolveSupplier() found nothing globally: fuzzy-match the
     * community's own configured suppliers by name/sender. Kept for the same cases this
     * already covered before CIF-first resolution existed.
     * @param array<string,mixed> $invoice @return array<string,mixed>|null */
    public function resolveCommunitySupplier(int $communityId,array $invoice,string $sender):?array
    {
        $rows=$this->db->all('SELECT cs.category,cs.contract_reference,s.*,st.name service_type_name FROM community_suppliers cs
            JOIN suppliers s ON s.id=cs.supplier_id LEFT JOIN service_types st ON st.id=s.main_service_type_id WHERE cs.community_id=? AND s.active=1',[$communityId]);
        $provider=Text::normalize((string)($invoice['proveedor']??''));$sender=Text::normalize($sender);$best=null;$score=0.0;
        foreach($rows as$row){
            $name=(string)$row['normalized_name'];$candidate=max($provider!==''?Text::similarity($provider,$name):0.0,$sender!==''?Text::similarity($sender,$name):0.0);
            if($candidate>$score){$score=$candidate;$best=$row;}
        }
        return$score>=92.0?$best:null;
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

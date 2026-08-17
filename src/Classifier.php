<?php
declare(strict_types=1);

namespace Salvest;

final class Classifier
{
    public function __construct(private Database $db, private float $threshold = 92.0) {}

    /** @param array<string,mixed> $invoice @return array{community:?array,confidence:float,evidence:array} */
    public function classify(array $invoice, string $context = ''): array
    {
        foreach (['cups'=>'cups','numero_contrato'=>'contract','referencia_cliente'=>'customer_reference'] as $field => $type) {
            $value = Text::normalize((string)($invoice[$field] ?? ''));
            if ($value === '') continue;
            $row = $this->db->one("SELECT c.* FROM community_identifiers i JOIN communities c ON c.id=i.community_id
                WHERE i.identifier_type=? AND i.normalized_value=? AND i.active=1 AND c.active=1", [$type, $value]);
            if ($row) return ['community'=>$row,'confidence'=>100.0,'evidence'=>['field'=>$field,'type'=>'exact']];
        }
        $cif = Text::normalize((string)($invoice['comunidad_cif'] ?? ''));
        $communities = $this->db->all('SELECT * FROM communities WHERE active=1');
        if ($cif !== '') {
            foreach ($communities as $community) {
                if (Text::normalize((string)$community['cif']) === $cif) {
                    return ['community'=>$community,'confidence'=>100.0,'evidence'=>['field'=>'cif','type'=>'exact']];
                }
            }
        }
        $aliases = $this->db->all('SELECT a.*,c.official_name,c.main_address,c.cif,c.active FROM community_aliases a JOIN communities c ON c.id=a.community_id WHERE a.active=1 AND c.active=1');
        $candidates = $communities;
        foreach ($aliases as $alias) {
            $candidates[] = ['id'=>$alias['community_id'],'official_name'=>$alias['official_name'],
                'main_address'=>$alias['value'],'cif'=>$alias['cif']];
        }
        $queries = array_filter([(string)($invoice['direccion'] ?? ''), (string)($invoice['nombre_comunidad'] ?? ''), $context]);
        $best = null; $bestScore = 0.0;
        foreach ($candidates as $candidate) {
            foreach ($queries as $query) {
                $score = Text::similarity($query, (string)$candidate['main_address']);
                if ($score > $bestScore) { $best = $candidate; $bestScore = $score; }
            }
        }
        return ['community'=>$bestScore >= $this->threshold ? $best : null,'confidence'=>$bestScore,
            'evidence'=>['field'=>'address','type'=>'fuzzy','score'=>$bestScore / 100]];
    }

    /** @param array<string,mixed> $invoice */
    public function resolveSupplier(array $invoice, string $sender): ?array
    {
        $name = Text::normalize((string)($invoice['proveedor'] ?? ''));
        $cif = Text::normalize((string)($invoice['proveedor_cif'] ?? ''));
        $domain = Text::normalize(substr(strrchr($sender, '@') ?: '', 1));
        $suppliers = $this->db->all('SELECT * FROM suppliers WHERE active=1');
        foreach ($suppliers as $supplier) {
            if ($cif !== '' && Text::normalize((string)$supplier['cif']) === $cif) return $supplier;
            if ($name !== '' && Text::similarity($name, (string)$supplier['normalized_name']) >= 92) return $supplier;
        }
        foreach ($this->db->all('SELECT * FROM supplier_aliases WHERE active=1') as $alias) {
            if (in_array((string)$alias['normalized_value'], [$name,$cif,$domain], true)) {
                foreach ($suppliers as $supplier) if ((int)$supplier['id'] === (int)$alias['supplier_id']) return $supplier;
            }
        }
        return null;
    }

    public function supplierAcceptsService(array $supplier, string $service): bool
    {
        $normalized = Text::normalize($service);
        return $this->db->one("SELECT 1 ok FROM service_types st LEFT JOIN supplier_service_types ss ON ss.service_type_id=st.id
            WHERE st.active=1 AND st.normalized_name=? AND (st.id=? OR ss.supplier_id=?) LIMIT 1",
            [$normalized, $supplier['main_service_type_id'], $supplier['id']]) !== null;
    }
}

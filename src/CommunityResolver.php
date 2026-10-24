<?php
declare(strict_types=1);

namespace Salvest;

/**
 * ¿A qué comunidad va dirigida la factura?
 *
 * 1. NIF del cliente normalizado → si identifica exactamente una comunidad activa, esa es, con
 *    confianza 1 y sin más comprobaciones: nada posterior puede contradecirlo.
 * 2. NIF compartido por varias comunidades (caso real EDIFICI / PARKING GERMANIES) → la IA elige
 *    entre exactamente esas comunidades.
 * 3. Sin NIF, o NIF que no coincide con ninguna → CommunityCandidateRetriever propone candidatas
 *    y la IA elige entre ellas. Sin candidatas, no hay comunidad.
 *
 * La IA solo puede devolver un id de la lista que se le envía (lo valida DocumentIntelligence) y
 * solo se acepta con confianza suficiente (ClassificationPolicy::AI_CHOICE_MIN_CONFIDENCE).
 * Los identificadores contractuales (CUPS, contrato, referencia de cliente) no intervienen por
 * decisión explícita: se revisarán en una fase posterior.
 */
final class CommunityResolver
{
    /** @param (callable():array<string,string>)|null $folderNames código externo → nombre real de la carpeta de Drive, si se conoce */
    public function __construct(private Database $db, private CommunityCandidateRetriever $retriever, private $folderNames = null) {}

    /**
     * @param array<string,mixed> $understanding comprensión validada
     * @param (callable(list<array<string,mixed>>):array{id:?int,confidence:float,reason:string,valid:bool,rejected_id:?int})|null $choose
     *   la IA de desambiguación; null = no se permite llamar a la IA (se informa de los candidatos)
     * @return array<string,mixed>
     */
    public function resolve(array $understanding, ?callable $choose): array
    {
        $rawTaxId = $understanding['customer']['tax_id'] ?? null;
        $taxId = Text::normalizeIdentifier($rawTaxId);
        $result = ['community'=>null,'source'=>null,'confidence'=>0.0,'outcome'=>'no_candidates',
            'tax_id'=>['value'=>$rawTaxId,'normalized'=>$taxId,'matches'=>0],'candidates'=>[],'ai'=>null];
        $folderNames = $this->folderNames !== null ? ($this->folderNames)() : [];

        $matches = $taxId !== '' ? $this->communitiesWithTaxId($taxId) : [];
        $result['tax_id']['matches'] = count($matches);
        if (count($matches) === 1) {
            return ['community'=>$matches[0],'source'=>'tax_id_exact','confidence'=>1.0,'outcome'=>'resolved','candidates'=>[self::summary($matches[0], $folderNames, [], ['tax_id'])]] + $result;
        }
        $aliases = [];
        foreach ($this->db->all('SELECT community_id,value FROM community_aliases WHERE active=1 ORDER BY id') as $alias) $aliases[(int)$alias['community_id']][] = (string)$alias['value'];
        if (count($matches) > 1) {
            $source = 'ai_after_shared_tax_id';
            $candidates = array_map(static fn(array $row): array => ['community'=>$row,'signals'=>['tax_id']], $matches);
        } else {
            $source = 'ai_after_retrieval';
            $candidates = $this->retriever->candidates($understanding['customer'] ?? [], $folderNames);
        }
        $result['candidates'] = array_map(static fn(array $c): array => self::summary($c['community'], $folderNames, $aliases[(int)$c['community']['id']] ?? [], $c['signals']), $candidates);
        if (!$candidates) return $result;
        if ($choose === null) return ['outcome'=>'ai_skipped'] + $result;

        $choice = $choose($result['candidates']);
        $result['ai'] = $choice;
        $result['confidence'] = $choice['confidence'];
        if (!$choice['valid']) return ['outcome'=>'ai_invalid_candidate'] + $result;
        if ($choice['id'] === null) return ['outcome'=>'ai_abstained'] + $result;
        if ($choice['confidence'] < ClassificationPolicy::AI_CHOICE_MIN_CONFIDENCE) return ['outcome'=>'ai_low_confidence'] + $result;
        foreach ($candidates as $candidate) {
            if ((int)$candidate['community']['id'] === $choice['id']) {
                return ['community'=>$candidate['community'],'source'=>$source,'outcome'=>'resolved'] + $result;
            }
        }
        return ['outcome'=>'ai_invalid_candidate'] + $result;
    }

    /** Comunidades activas cuyo CIF, normalizado, es exactamente este. @return list<array<string,mixed>> */
    public function communitiesWithTaxId(string $normalizedTaxId): array
    {
        if ($normalizedTaxId === '') return [];
        return array_values(array_filter($this->db->all('SELECT * FROM communities WHERE active=1 ORDER BY id'),
            static fn(array $community): bool => Text::normalizeIdentifier((string)$community['cif']) === $normalizedTaxId));
    }

    /**
     * Lo que ve la IA (y la traza) de cada candidata. `nombre` es communities.official_name, que en
     * los datos reales es el nombre corto de gestión (columna "Comunidad" del maestro), no la razón
     * social; `nombre_carpeta_drive` es el nombre con el que la administración archiva la comunidad.
     * @param array<string,mixed> $community @param array<string,string> $folderNames @param list<string> $aliases @param list<string> $signals
     * @return array<string,mixed>
     */
    private static function summary(array $community, array $folderNames, array $aliases, array $signals): array
    {
        $folder = $folderNames[(string)$community['external_code']] ?? null;
        return array_filter([
            'id'=>(int)$community['id'],
            'codigo'=>(string)$community['external_code'],
            'nombre'=>(string)$community['official_name'],
            'nombre_carpeta_drive'=>$folder !== null ? CommunityCandidateRetriever::withoutLeadingCode($folder) : null,
            'alias'=>$aliases ?: null,
            'direccion'=>(string)$community['main_address'],
            'codigo_postal'=>$community['postal_code'] ?? null,
            'ciudad'=>$community['city'] ?? null,
            'cif'=>$community['cif'] ?? null,
            'senales'=>$signals,
        ], static fn(mixed $value): bool => $value !== null && $value !== '');
    }
}

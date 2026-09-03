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
     * @param array<string,mixed> $invoice
     * @param (callable(string,string,array<string,mixed>):void)|null $trace Observer for
     *   /Revisar's "Detalle técnico" trace, invoked as ($tier, outcome, details) at every tier
     *   boundary. Never affects control flow — passing null (every caller except Worker) costs
     *   nothing extra.
     * @return array{community:?array,confidence:float,evidence:array}
     */
    public function classify(array $invoice, string $context = '', ?callable $trace = null): array
    {
        $code=CommunityCsvImporter::codeOrEmpty((string)($invoice['codigo_comunidad']??''));
        if($code!==''){
            $row=$this->db->one('SELECT * FROM communities WHERE external_code=? AND active=1',[$code]);
            if($trace)$trace('codigo_comunidad',$row?'match':'none',['codigo'=>$code]);
            if($row)return['community'=>$row,'confidence'=>100.0,'evidence'=>['field'=>'codigo_comunidad','type'=>'exact']];
        }
        // Holder/customer CIF — never the supplier's CIF — checked before contractual
        // identifiers because every community row requires a CIF at import time, while
        // CUPS/contract/reference are optional per-community entries and more likely to
        // be missing or stale.
        $holderCif = Text::normalizeIdentifier((string)($invoice['comunidad_cif'] ?? ''));
        if ($holderCif !== '') {
            $row = $this->matchByNormalizedCif($holderCif);
            if($trace)$trace('holder_cif',$row?'match':'none',['cif_extraido'=>(string)($invoice['comunidad_cif']??''),'cif_normalizado'=>$holderCif]);
            if ($row) return ['community'=>$row,'confidence'=>100.0,'evidence'=>['field'=>'holder_cif','type'=>'exact']];
        }
        foreach (['cups'=>'cups','numero_contrato'=>'contract','referencia_cliente'=>'customer_reference'] as $field => $type) {
            $value = Text::normalize((string)($invoice[$field] ?? ''));
            if ($value === '') continue;
            $row = $this->db->one("SELECT c.* FROM community_identifiers i JOIN communities c ON c.id=i.community_id
                WHERE i.identifier_type=? AND i.normalized_value=? AND i.active=1 AND c.active=1", [$type, $value]);
            if($trace)$trace('community_identifier',$row?'match':'none',['field'=>$field,'type'=>$type,'value'=>$value]);
            if ($row) return ['community'=>$row,'confidence'=>100.0,'evidence'=>['field'=>$field,'type'=>'exact']];
        }
        $communities = $this->db->all('SELECT * FROM communities WHERE active=1');
        $communitiesById = array_column($communities, null, 'id');
        $aliases = $this->db->all('SELECT a.*,c.official_name,c.main_address,c.cif,c.active FROM community_aliases a JOIN communities c ON c.id=a.community_id WHERE a.active=1 AND c.active=1');
        $candidates = [];
        foreach($communities as$community){$candidates[]=['community'=>$community,'value'=>$community['main_address'],'kind'=>'address'];$candidates[]=['community'=>$community,'value'=>$community['official_name'],'kind'=>'name'];}
        foreach ($aliases as $alias) {
            // Fase 16.2: un candidato vía alias solo necesita el TEXTO del alias para comparar —
            // pero la comunidad que se devuelve si gana debe ser la fila completa de
            // `communities` (external_code, cif, main_address... todo), nunca este objeto a
            // medias construido solo con lo que trae el JOIN del alias. Bug real de producción:
            // un documento resuelto por alias llegaba a "classified" con una comunidad sin
            // external_code, y DriveInvoiceArchiver reventaba con "La comunidad no tiene código
            // externo" al archivar — el documento SÍ se había reconocido bien, solo que el objeto
            // que se pasaba río abajo estaba incompleto.
            $fullCommunity = $communitiesById[$alias['community_id']] ?? ['id'=>$alias['community_id'],'official_name'=>$alias['official_name'],'main_address'=>$alias['main_address'],'cif'=>$alias['cif']];
            $candidates[] = ['community'=>$fullCommunity,'value'=>$alias['value'],'kind'=>'address'];
        }
        // Fase 9: containment safety net — exact whole-word match, no numeric threshold. Same
        // philosophy resolveSupplier() already uses for suppliers (its own supplier_containment
        // tier), which never existed for communities until now. Checked after every exact-
        // identifier tier above and before the fuzzy score below: catches cases like extracted
        // nombre_comunidad "LLOMBAI 11 ESCALERA" containing a community's official_name
        // "LLOMBAI 11" verbatim — real case that only scored 72.83/100 on the fuzzy tier (below
        // the 92 threshold) because the extra word diluted the token-overlap component, even
        // though the master's name is unambiguously present. Deliberately narrower than the
        // fuzzy tier's own query list below: only the extracted nombre_comunidad/direccion
        // fields are checked, never $context (the raw email body/subject) — containment has no
        // numeric margin to absorb an unrelated community's name mentioned in passing there.
        $containmentQueries=['address'=>array_filter([(string)($invoice['direccion']??'')]),'name'=>array_filter([(string)($invoice['nombre_comunidad']??'')])];
        $containmentMatches=[];
        foreach ($candidates as $candidate) {
            // Fase 16: además de la coincidencia exacta, se prueba también quitando como mucho
            // una palabra genérica de vía ("Calle", "Avenida"...) del principio de la dirección
            // del maestro — caso real: comunidad dada de alta como "CALLE ENCARNACION 35",
            // proveedor que en su factura solo escribe "ENCARNACION, 35" (sin "Calle"). Antes no
            // había match porque la comparación exigía la palabra "calle" también en el texto
            // extraído, aunque el resto de la dirección coincidiera exactamente.
            $candidateValue=Text::normalize((string)$candidate['value']);
            if ($candidateValue==='') continue;
            $candidateValueStripped=Text::stripLeadingAddressWord($candidateValue);
            foreach ($containmentQueries[$candidate['kind']] as $query) {
                $queryNormalized=Text::normalize((string)$query);
                if (Text::containsWholeWords($queryNormalized,$candidateValue)
                    || ($candidateValueStripped!==$candidateValue && Text::containsWholeWords($queryNormalized,$candidateValueStripped))) {
                    $containmentMatches[$candidate['community']['id']]=$candidate['community'];
                    break;
                }
            }
        }
        if($trace)$trace('community_containment',count($containmentMatches)>1?'ambiguous':(count($containmentMatches)===1?'match':'none'),['matches'=>count($containmentMatches)]);
        if(count($containmentMatches)===1)return['community'=>reset($containmentMatches),'confidence'=>100.0,'evidence'=>['field'=>'nombre_comunidad','type'=>'name_containment']];
        if(count($containmentMatches)>1)return['community'=>null,'confidence'=>0.0,'evidence'=>['field'=>'nombre_comunidad','type'=>'ambiguous_containment']];

        // Fase 9.1: the invoice's OWN extracted fields (nombre_comunidad/direccion — read off the
        // actual PDF) must always outrank the raw email context (subject/body — someone else's
        // free text, which can legitimately mention a different community in passing, e.g. a
        // forwarded/CC'd thread, or an unrelated line in a long email body). Real case that
        // exposed this: a PDF genuinely for community A, sent in an email whose subject/body
        // named community B — the old single-pass query (invoice fields and $context mixed
        // together, best score wins regardless of source) let B's mention outscore A's own
        // correct match and archived the invoice under the wrong community. Fixed by running the
        // invoice's own fields as their own, higher-priority pass first; $context is only ever
        // consulted in a second pass, and only when the invoice's own fields didn't resolve
        // anything on their own.
        $invoiceQueries=['address'=>array_filter([(string)($invoice['direccion']??'')]),'name'=>array_filter([(string)($invoice['nombre_comunidad']??'')])];
        $invoiceFuzzy=self::bestFuzzyMatch($candidates,$invoiceQueries,$trace);
        if($trace)$trace('community_fuzzy',$invoiceFuzzy['score']>=$this->threshold?'match':'none',
            ['best'=>$invoiceFuzzy['best']['official_name']??null,'score'=>$invoiceFuzzy['score'],'threshold'=>$this->threshold]);
        if($invoiceFuzzy['score']>=$this->threshold){
            return['community'=>$invoiceFuzzy['best'],'confidence'=>$invoiceFuzzy['score'],
                'evidence'=>['field'=>'address','type'=>'fuzzy','score'=>$invoiceFuzzy['score']/100]];
        }

        $contextQueries=['address'=>array_filter([$context]),'name'=>array_filter([$context])];
        $contextFuzzy=self::bestFuzzyMatch($candidates,$contextQueries,$trace,'community_fuzzy_context');
        if($trace)$trace('community_fuzzy_context',$contextFuzzy['score']>=$this->threshold?'match':'none',
            ['best'=>$contextFuzzy['best']['official_name']??null,'score'=>$contextFuzzy['score'],'threshold'=>$this->threshold]);
        return['community'=>$contextFuzzy['score']>=$this->threshold?$contextFuzzy['best']:null,'confidence'=>$contextFuzzy['score'],
            'evidence'=>['field'=>'context','type'=>'fuzzy','score'=>$contextFuzzy['score']/100]];
    }

    /** @param list<array{community:array,value:mixed,kind:string}> $candidates
     * @param array{address:list<string>,name:list<string>} $queries
     * @return array{best:?array,score:float} */
    private static function bestFuzzyMatch(array $candidates,array $queries,?callable $trace,string $traceTier='community_fuzzy'):array
    {
        $best=null;$bestScore=0.0;
        foreach($candidates as $candidate){
            foreach($queries[$candidate['kind']] as $query){
                $score=Text::similarity($query,(string)$candidate['value']);
                if($trace && $score>0.0)$trace($traceTier,'candidate',['comunidad'=>$candidate['community']['official_name']??null,'kind'=>$candidate['kind'],'score'=>$score]);
                if($score>$bestScore){$best=$candidate['community'];$bestScore=$score;}
            }
        }
        return['best'=>$best,'score'=>$bestScore];
    }

    /** community.cif is stored as typed — dashes, spaces, case all vary in practice (real
     * case: PDF "H-12815601" vs master "H12815601") — so this compares identifier-normalized
     * values in PHP rather than in SQL. */
    private function matchByNormalizedCif(string $holderCif): ?array
    {
        foreach ($this->db->all('SELECT * FROM communities WHERE active=1') as $community) {
            if (Text::normalizeIdentifier((string)$community['cif']) === $holderCif) return $community;
        }
        return null;
    }

    /**
     * Fase 5: the global "which supplier is this" lookup — scoped to no particular community,
     * used today only informatively by InvoiceRouter (never as a final decision; that activation
     * is a later phase) — now carries the exact same rigor as resolveSupplierInCommunity(): CIF
     * exact -> name exact -> official_name exact -> alias exact (name/CIF/sender domain) ->
     * whole-word containment -> token overlap -> fuzzy (best score between name/official_name,
     * never penalised for having two very different representations). There is no
     * community+service tier here — there is no community to compare against.
     *
     * Every tier fails safe: more than one active supplier matching the same tier at the same
     * priority stops immediately with ambiguous=true, never falls through to a lower tier to
     * "break the tie" and never returns an arbitrary first match. This holds even for tiers a
     * clean master should never produce duplicates in (CIF, alias) — the master is real data
     * now, not a fixture, so this must be robust to it being wrong, not just to it being right.
     * @param array<string,mixed> $invoice @param (callable(string,string,array<string,mixed>):void)|null $trace
     *   Same observer contract as classify()/resolveSupplierInCommunity() — never affects control flow.
     * @return array{supplier:?array,evidence:?array,ambiguous:bool}
     */
    public function resolveSupplier(array $invoice, string $sender, ?callable $trace = null): array
    {
        $none = ['supplier'=>null,'evidence'=>null,'ambiguous'=>false];
        $suppliers = $this->db->all('SELECT s.*,st.name service_type_name FROM suppliers s LEFT JOIN service_types st ON st.id=s.main_service_type_id WHERE s.active=1');
        if (!$suppliers) return $none;

        $cifIdentifier = Text::normalizeIdentifier((string)($invoice['proveedor_cif'] ?? ''));
        if ($cifIdentifier !== '') {
            $found = self::uniqueMatch($suppliers, static fn(array $r): bool => (string)($r['cif'] ?? '') !== '' && Text::normalizeIdentifier((string)$r['cif']) === $cifIdentifier);
            if($trace)$trace('supplier_cif',$found===false?'ambiguous':($found?'match':'none'),['cif_normalizado'=>$cifIdentifier]);
            if ($found !== null) return $found === false ? ['supplier'=>null,'evidence'=>null,'ambiguous'=>true] : ['supplier'=>$found,'evidence'=>['field'=>'supplier_cif','type'=>'exact'],'ambiguous'=>false];
        }

        $providerName = Text::normalizeCompanyName((string)($invoice['proveedor'] ?? ''));
        if ($providerName !== '') {
            $found = self::uniqueMatch($suppliers, static fn(array $r): bool => in_array($providerName, self::candidateNames($r), true));
            if($trace)$trace('supplier_exact_name',$found===false?'ambiguous':($found?'match':'none'),['proveedor_normalizado'=>$providerName]);
            if ($found !== null) {
                if ($found === false) return ['supplier'=>null,'evidence'=>null,'ambiguous'=>true];
                $matchedShortName = (string)($found['name'] ?? '') !== '' && Text::normalizeCompanyName((string)$found['name']) === $providerName;
                return ['supplier'=>$found,'evidence'=>['field'=>'proveedor','type'=>$matchedShortName ? 'supplier_name_exact' : 'supplier_official_name_exact'],'ambiguous'=>false];
            }
        }

        $domain = Text::normalize(substr(strrchr($sender, '@') ?: '', 1));
        $cif = Text::normalize((string)($invoice['proveedor_cif'] ?? '')); // aliases store normalized_value via normalize(), not normalizeIdentifier()
        $candidateValues = array_filter([$providerName, $cif, $domain], static fn(string $v): bool => $v !== '');
        if ($candidateValues) {
            // Restricted to currently-active suppliers explicitly, not just by relying on the
            // lookup below silently finding nothing for a stale alias on an inactive/merged
            // supplier (e.g. EXTNCAS, id 13, merged into EXTINCAS) — fails safe either way, but
            // this makes the guarantee explicit rather than incidental.
            $activeIds = array_flip(array_map(static fn(array $r): int => (int)$r['id'], $suppliers));
            $matchedIds = [];
            foreach ($this->db->all('SELECT * FROM supplier_aliases WHERE active=1') as $alias) {
                if (!in_array((string)$alias['normalized_value'], $candidateValues, true)) continue;
                $supplierId = (int)$alias['supplier_id'];
                if (isset($activeIds[$supplierId])) $matchedIds[$supplierId] = $alias;
            }
            if($trace)$trace('supplier_alias',count($matchedIds)>1?'ambiguous':(count($matchedIds)===1?'match':'none'),['candidatos'=>array_values($candidateValues)]);
            if (count($matchedIds) > 1) return ['supplier'=>null,'evidence'=>null,'ambiguous'=>true];
            if (count($matchedIds) === 1) {
                $id = array_key_first($matchedIds);
                $alias = $matchedIds[$id];
                foreach ($suppliers as $supplier) {
                    if ((int)$supplier['id'] === $id) {
                        $field = $alias['normalized_value']===$cif&&$cif!=='' ? 'supplier_cif' : ($alias['normalized_value']===$domain&&$domain!=='' ? 'sender_domain' : 'alias');
                        return ['supplier'=>$supplier,'evidence'=>['field'=>$field,'type'=>'alias'],'ambiguous'=>false];
                    }
                }
            }
        } elseif ($trace) { $trace('supplier_alias','skipped',[]); }

        if ($providerName !== '') {
            $found = self::uniqueMatch($suppliers, static fn(array $r): bool => self::anyContainsWholeWords($providerName, $r));
            if($trace)$trace('supplier_containment',$found===false?'ambiguous':($found?'match':'none'),['proveedor_normalizado'=>$providerName]);
            if ($found !== null) return $found === false ? ['supplier'=>null,'evidence'=>null,'ambiguous'=>true] : ['supplier'=>$found,'evidence'=>['field'=>'proveedor','type'=>'name_containment'],'ambiguous'=>false];

            $found = self::uniqueMatch($suppliers, static fn(array $r): bool => self::anyContainsAllWords($providerName, $r));
            if($trace)$trace('supplier_token',$found===false?'ambiguous':($found?'match':'none'),['proveedor_normalizado'=>$providerName]);
            if ($found !== null) return $found === false ? ['supplier'=>null,'evidence'=>null,'ambiguous'=>true] : ['supplier'=>$found,'evidence'=>['field'=>'proveedor','type'=>'token_match'],'ambiguous'=>false];
        }

        $best = null; $bestScore = 0.0; $tied = 0;
        foreach ($suppliers as $supplier) {
            $score = self::bestNameScore($providerName, $domain, $supplier);
            if ($trace && $score > 0.0) $trace('supplier_fuzzy','candidate',['proveedor'=>$supplier['official_name'],'score'=>$score]);
            if ($score > $bestScore) { $bestScore = $score; $best = $supplier; $tied = 1; }
            elseif ($score === $bestScore && $score > 0.0) { $tied++; }
        }
        if ($bestScore >= $this->threshold) {
            if($trace)$trace('supplier_fuzzy',$tied>1?'ambiguous':'match',['best'=>$best['official_name']??null,'score'=>$bestScore]);
            return $tied > 1 ? ['supplier'=>null,'evidence'=>null,'ambiguous'=>true] : ['supplier'=>$best,'evidence'=>['field'=>'proveedor','type'=>'fuzzy'],'ambiguous'=>false];
        }
        if($trace)$trace('supplier_fuzzy','none',['best'=>$best['official_name']??null,'score'=>$bestScore,'threshold'=>$this->threshold]);
        return $none;
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
    /** Candidate list for the restricted second OpenAI call — only id + display name, nothing else. @return list<array{id:int,official_name:string}> */
    public function suppliersForCommunity(int $communityId): array
    {
        $rows = $this->db->all('SELECT s.id,s.official_name FROM community_suppliers cs JOIN suppliers s ON s.id=cs.supplier_id WHERE cs.community_id=? AND s.active=1 ORDER BY s.official_name', [$communityId]);
        return array_map(static fn(array $r): array => ['id'=>(int)$r['id'],'official_name'=>(string)$r['official_name']], $rows);
    }

    /** Full supplier+relation row for an id the restricted call chose — re-validated by community, never trusted blindly. */
    public function supplierInCommunity(int $communityId, int $supplierId): ?array
    {
        return $this->db->one('SELECT cs.category,cs.contract_reference,s.*,st.name service_type_name FROM community_suppliers cs
            JOIN suppliers s ON s.id=cs.supplier_id LEFT JOIN service_types st ON st.id=s.main_service_type_id
            WHERE cs.community_id=? AND cs.supplier_id=? AND s.active=1', [$communityId, $supplierId]);
    }

    /** @param (callable(string,string,array<string,mixed>):void)|null $trace Same contract as
     * classify()'s $trace — a pure observer for /Revisar's technical trace, never influencing
     * which branch is taken. */
    public function resolveSupplierInCommunity(int $communityId, array $invoice, string $sender, ?callable $trace = null): array
    {
        $rows = $this->db->all('SELECT cs.category,cs.contract_reference,s.*,st.name service_type_name FROM community_suppliers cs
            JOIN suppliers s ON s.id=cs.supplier_id LEFT JOIN service_types st ON st.id=s.main_service_type_id WHERE cs.community_id=? AND s.active=1', [$communityId]);
        $none = ['supplier'=>null,'evidence'=>null,'ambiguous'=>false];
        if($trace)$trace('supplier_candidates','listed',['community_id'=>$communityId,'proveedores'=>array_map(
            static fn(array $r):array=>['id'=>(int)$r['id'],'nombre'=>$r['official_name'],'cif'=>$r['cif'],'servicio'=>$r['service_type_name'],'categoria'=>$r['category']], $rows)]);
        if (!$rows) return $none;

        $cifIdentifier = Text::normalizeIdentifier((string)($invoice['proveedor_cif'] ?? ''));
        if ($cifIdentifier !== '') {
            $found = self::uniqueMatch($rows, static fn(array $r): bool => (string)($r['cif'] ?? '') !== '' && Text::normalizeIdentifier((string)$r['cif']) === $cifIdentifier);
            if($trace)$trace('supplier_cif',$found===false?'ambiguous':($found?'match':'none'),['cif_normalizado'=>$cifIdentifier]);
            if ($found !== null) return $found === false ? ['supplier'=>null,'evidence'=>null,'ambiguous'=>true] : ['supplier'=>$found,'evidence'=>['field'=>'supplier_cif','type'=>'exact'],'ambiguous'=>false];
        }

        $providerName = Text::normalizeCompanyName((string)($invoice['proveedor'] ?? ''));
        if ($providerName !== '') {
            $found = self::uniqueMatch($rows, static fn(array $r): bool => in_array($providerName, self::candidateNames($r), true));
            if($trace)$trace('supplier_exact_name',$found===false?'ambiguous':($found?'match':'none'),['proveedor_normalizado'=>$providerName]);
            if ($found !== null) {
                if ($found === false) return ['supplier'=>null,'evidence'=>null,'ambiguous'=>true];
                $matchedShortName = (string)($found['name'] ?? '') !== '' && Text::normalizeCompanyName((string)$found['name']) === $providerName;
                return ['supplier'=>$found,'evidence'=>['field'=>'proveedor','type'=>$matchedShortName ? 'supplier_name_exact' : 'supplier_official_name_exact'],'ambiguous'=>false];
            }
        }

        $domain = Text::normalize(substr(strrchr($sender, '@') ?: '', 1));
        $cif = Text::normalize((string)($invoice['proveedor_cif'] ?? '')); // aliases store normalized_value via normalize(), not normalizeIdentifier()
        $candidateValues = array_filter([$providerName, $cif, $domain], static fn(string $v): bool => $v !== '');
        if ($candidateValues) {
            $supplierIds = array_map(static fn(array $r): int => (int)$r['id'], $rows);
            $placeholders = implode(',', array_fill(0, count($supplierIds), '?'));
            $aliases = $this->db->all("SELECT * FROM supplier_aliases WHERE active=1 AND supplier_id IN ($placeholders)", $supplierIds);
            $matchedIds = [];
            foreach ($aliases as $alias) {
                if (in_array((string)$alias['normalized_value'], $candidateValues, true)) $matchedIds[(int)$alias['supplier_id']] = true;
            }
            if($trace)$trace('supplier_alias',count($matchedIds)>1?'ambiguous':(count($matchedIds)===1?'match':'none'),['candidatos'=>array_values($candidateValues)]);
            if (count($matchedIds) > 1) return ['supplier'=>null,'evidence'=>null,'ambiguous'=>true];
            if (count($matchedIds) === 1) {
                $id = array_key_first($matchedIds);
                foreach ($rows as $row) if ((int)$row['id'] === $id) return ['supplier'=>$row,'evidence'=>['field'=>'alias','type'=>'exact'],'ambiguous'=>false];
            }
        } elseif ($trace) { $trace('supplier_alias','skipped',[]); }

        if ($providerName !== '') {
            $found = self::uniqueMatch($rows, static fn(array $r): bool => self::anyContainsWholeWords($providerName, $r));
            if($trace)$trace('supplier_containment',$found===false?'ambiguous':($found?'match':'none'),['proveedor_normalizado'=>$providerName]);
            if ($found !== null) return $found === false ? ['supplier'=>null,'evidence'=>null,'ambiguous'=>true] : ['supplier'=>$found,'evidence'=>['field'=>'proveedor','type'=>'name_containment'],'ambiguous'=>false];

            $found = self::uniqueMatch($rows, static fn(array $r): bool => self::anyContainsAllWords($providerName, $r));
            if($trace)$trace('supplier_token',$found===false?'ambiguous':($found?'match':'none'),['proveedor_normalizado'=>$providerName]);
            if ($found !== null) return $found === false ? ['supplier'=>null,'evidence'=>null,'ambiguous'=>true] : ['supplier'=>$found,'evidence'=>['field'=>'proveedor','type'=>'token_match'],'ambiguous'=>false];
        }

        $best = null; $bestScore = 0.0; $tied = 0;
        foreach ($rows as $row) {
            $score = self::bestNameScore($providerName, $domain, $row);
            if ($trace && $score > 0.0) $trace('supplier_fuzzy','candidate',['proveedor'=>$row['official_name'],'score'=>$score]);
            if ($score > $bestScore) { $bestScore = $score; $best = $row; $tied = 1; }
            elseif ($score === $bestScore && $score > 0.0) { $tied++; }
        }
        if ($bestScore >= $this->threshold) {
            if($trace)$trace('supplier_fuzzy',$tied>1?'ambiguous':'match',['best'=>$best['official_name']??null,'score'=>$bestScore]);
            return $tied > 1 ? ['supplier'=>null,'evidence'=>null,'ambiguous'=>true] : ['supplier'=>$best,'evidence'=>['field'=>'proveedor','type'=>'fuzzy'],'ambiguous'=>false];
        }
        if($trace)$trace('supplier_fuzzy','none',['best'=>$best['official_name']??null,'score'=>$bestScore,'threshold'=>$this->threshold]);

        // Last resort, only once every name/alias/CIF/domain tier above has failed to find
        // even a single candidate: if community_id + service are both known, the master data
        // itself may already narrow this down. A community rarely has more than one supplier
        // configured for the same service (e.g. exactly one LIMPIEZA supplier), so when that
        // holds this document is classifiable even if OpenAI never resolved a usable supplier
        // name at all. Never applies with zero or several matches — a single tie among several
        // compatible suppliers would be a guess, not a decision, so it falls through unchanged
        // (0 -> needs_review once every other path is also exhausted; >1 -> the restricted
        // OpenAI retry in InvoiceRouter gets a chance next, still never picked arbitrarily here).
        $serviceHint = Text::normalize((string)($invoice['tipo_servicio'] ?? ''));
        if ($serviceHint !== '') {
            $compatible = array_values(array_filter($rows, static fn(array $r): bool =>
                (!empty($r['service_type_name']) && Text::normalize((string)$r['service_type_name']) === $serviceHint)
                || (!empty($r['category']) && Text::normalize((string)$r['category']) === $serviceHint)
            ));
            if($trace)$trace('supplier_community_service',count($compatible)===1?'match':(count($compatible)>1?'ambiguous_skipped':'none'),['service_hint'=>$serviceHint,'compatibles'=>count($compatible)]);
            if (count($compatible) === 1) {
                return ['supplier'=>$compatible[0],'evidence'=>['field'=>'community_service','type'=>'community_service_unique_supplier'],'ambiguous'=>false];
            }
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
     * Fase 3->5 compatibility shim: a supplier's name/exact/containment/token/fuzzy tiers must
     * keep working whether `suppliers.name` is still NULL (pre-Fase-3 data, official_name IS the
     * short commercial name) or already populated (post-Fase-3, official_name is the legal
     * razón social). Every tier below compares against BOTH candidates instead of official_name
     * alone; this is the single source of that candidate list, so all four tiers stay
     * consistent and a value that normalizes identically under both fields (the common
     * pre-Fase-3 case, or any supplier whose short/legal names coincide) is never compared
     * twice.
     * @param array<string,mixed> $r @return list<string> */
    private static function candidateNames(array $r): array
    {
        $candidates = [];
        foreach ([$r['name'] ?? null, $r['official_name'] ?? null] as $raw) {
            if ($raw === null || (string)$raw === '') continue;
            $normalized = Text::normalizeCompanyName((string)$raw);
            if ($normalized !== '' && !in_array($normalized, $candidates, true)) $candidates[] = $normalized;
        }
        return $candidates;
    }

    private static function anyContainsWholeWords(string $providerName, array $r): bool
    {
        foreach (self::candidateNames($r) as $candidate) {
            if (Text::containsWholeWords($providerName, $candidate)) return true;
        }
        return false;
    }

    private static function anyContainsAllWords(string $providerName, array $r): bool
    {
        foreach (self::candidateNames($r) as $candidate) {
            if (Text::containsAllWords($providerName, $candidate)) return true;
        }
        return false;
    }

    /** Best fuzzy score for a supplier row against the extracted provider name and/or sender
     * domain, taken over every valid candidate (short name, legal name) so a supplier is never
     * penalised just because one of its two representations is very different from the other —
     * e.g. providerName="FACSA" must score against name="FACSA" (100), not get dragged down by
     * comparing only against the much longer official_name. */
    private static function bestNameScore(string $providerName, string $domain, array $r): float
    {
        $score = 0.0;
        foreach (self::candidateNames($r) as $candidate) {
            if ($providerName !== '') $score = max($score, Text::similarity($providerName, $candidate));
            if ($domain !== '') $score = max($score, Text::similarity($domain, $candidate));
        }
        return $score;
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

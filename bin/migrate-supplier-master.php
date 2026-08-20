#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Fase 3 del maestro de proveedores: migración de datos controlada, explícita y separada de
 * Schema::migrate() (que solo toca estructura). Rellena name/official_name/normalized_name/
 * normalized_official_name/cif y aliases para los 41 suppliers reales, y fusiona los dos
 * duplicados conocidos (EXTNCAS/EXTINCAS, ENERVIA/ENERVIA SOLUCIONES ENERGETICAS) de forma
 * transaccional, sin DELETE, preservando toda relación existente.
 *
 * --dry-run (por defecto): solo lee, no escribe nada. --apply: ejecuta los cambios.
 * Idempotente: una segunda ejecución sobre datos ya migrados debe tender a KEEP en todo.
 *
 * NO toca Classifier/InvoiceRouter/Worker/OpenAIExtractor — usa Text::normalizeCompanyName()
 * como utilidad de solo lectura, exactamente la misma función que Classifier ya usa hoy para
 * comparar nombres, para que normalized_name/normalized_official_name queden coherentes con lo
 * que el matching futuro (Fase 5) va a leer.
 */

$config = require dirname(__DIR__) . '/bootstrap.php';
$db = new Salvest\Database($config['database']);
$pdo = $db->pdo();

$apply = in_array('--apply', $argv, true);
$dryRun = !$apply;

/** Identificador fiscal canónico de almacenamiento: mayúsculas, sin separadores. Distinto a
 * propósito de Text::normalizeIdentifier() (que devuelve minúsculas y solo sirve para comparar
 * en el momento del matching, nunca para decidir qué se guarda en la columna). "Pendiente",
 * cadena vacía o solo separadores -> NULL, nunca un texto inventado. */
function canonicalCif(?string $raw): ?string
{
    $raw = trim((string)$raw);
    if ($raw === '' || strcasecmp($raw, 'pendiente') === 0) return null;
    $clean = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $raw));
    return $clean === '' ? null : $clean;
}

/** @return list<string> aliases a insertar, ya filtrados: sin vacíos, sin duplicados internos
 * tras normalizar, y sin ninguno cuya forma normalizada coincida con name u official_name
 * (ya cubiertos por esos dos campos, insertarlos como alias sería redundante). */
function filterAliases(array $rawAliases, string $normalizedName, string $normalizedOfficialName): array
{
    $seen = [$normalizedName, $normalizedOfficialName];
    $result = [];
    foreach ($rawAliases as $alias) {
        $alias = trim($alias);
        if ($alias === '') continue;
        $normalized = Salvest\Text::normalizeCompanyName($alias);
        if ($normalized === '' || in_array($normalized, $seen, true)) continue;
        $seen[] = $normalized;
        $result[] = $alias;
    }
    return $result;
}

// ---- 1. Maestro aprobado (Fase 1 + confirmaciones posteriores del usuario) ----
// Cada entrada se empareja contra el supplier real por su official_name ACTUAL en producción
// (confirmado 1:1 en Fase 1 — ninguna de estas 37 filas participa en las dos fusiones).
$masterData = [
    ['match'=>'PERTOR','name'=>'PERTOR','official_name'=>'ASCENSORES PERTOR, S.L.','cif'=>'B46699864','aliases'=>['ASCENSORES PERTOR','PERTOR ASCENSORES']],
    ['match'=>'IBERDROLA','name'=>'IBERDROLA','official_name'=>'IBERDROLA CLIENTES, S.A.U.','cif'=>'A95758389','aliases'=>['IBERDROLA CLIENTES']],
    ['match'=>'FACSA','name'=>'FACSA','official_name'=>'SOCIEDAD DE FOMENTO AGRÍCOLA CASTELLONENSE, S.A.U.','cif'=>'A12000022','aliases'=>['SOCIEDAD DE FOMENTO AGRÍCOLA CASTELLONENSE','SOCIEDAD DE FOMENTO AGRICOLA CASTELLONENSE','FOMENTO AGRÍCOLA CASTELLONENSE']],
    ['match'=>'FAIN','name'=>'FAIN','official_name'=>'FAIN ASCENSORES, S.A.','cif'=>'A28303485','aliases'=>['FAIN ASCENSORES']],
    ['match'=>'RUIZ','name'=>'RUIZ','official_name'=>'TALLERES RUIZ ASCENSORES, S.L.','cif'=>'B46022513','aliases'=>['ASCENSORES RUIZ','TALLERES RUIZ','TALLERES RUIZ ASCENSORES']],
    ['match'=>'CRISMAN','name'=>'CRISMAN','official_name'=>'LIMPIEZAS Y CRISTALIZADOS CRISMAN, S.L.','cif'=>'B42894071','aliases'=>['LIMPIEZAS CRISMAN','LIMPIEZAS Y CRISTALIZADOS CRISMAN']],
    ['match'=>'EXTINPLAN','name'=>'EXTINPLAN','official_name'=>'EXTINTORES LA PLANA, S.L.','cif'=>'B12626966','aliases'=>['EXTINTORES LA PLANA']],
    ['match'=>'OTIS','name'=>'OTIS','official_name'=>'OTIS MOBILITY, S.A.','cif'=>'A28011153','aliases'=>['OTIS MOBILITY','ZARDOYA OTIS','ZARDOYA OTIS, S.A.']],
    ['match'=>'THYSSEN','name'=>'THYSSEN','official_name'=>'TK ELEVADORES ESPAÑA, S.L.U.','cif'=>'B46001897','aliases'=>['THYSSENKRUPP','THYSSENKRUPP ELEVADORES','TK ELEVADORES','TK ELEVATOR']],
    ['match'=>'ORONA','name'=>'ORONA','official_name'=>'ORONA, S. COOP.','cif'=>'F20025318','aliases'=>['ORONA S COOP','ORONA S. COOP.']],
    ['match'=>'CRISLA','name'=>'CRISLA','official_name'=>'CRISLA LIMPIEZAS Y CRISTALIZADOS, S.L.','cif'=>'B12534228','aliases'=>['LIMPIEZAS CRISLA','CRISLA LIMPIEZAS Y CRISTALIZADOS']],
    ['match'=>'MALLASEN','name'=>'MALLASEN','official_name'=>'MALLASEN EXTINTORES, S.L.','cif'=>'B12932356','aliases'=>['MALLASEN EXTINTORES']],
    ['match'=>'EMBARBA','name'=>'EMBARBA','official_name'=>'A. EMBARBA, S.A.','cif'=>'A29018637','aliases'=>['A EMBARBA','EMBARBA ASCENSORES','A EMBARBA ASCENSORES']],
    ['match'=>'LA BRUJA','name'=>'LA BRUJA','official_name'=>'LIMPIEZAS Y CRISTALIZADOS LA BRUJA, S.L.','cif'=>'B44534253','aliases'=>['LIMPIEZAS LA BRUJA','LIMPIEZAS Y CRISTALIZADOS LA BRUJA']],
    ['match'=>'SCHINDLER','name'=>'SCHINDLER','official_name'=>'SCHINDLER, S.A.','cif'=>'A50001726','aliases'=>['ASCENSORES SCHINDLER']],
    ['match'=>'ENINTER','name'=>'ENINTER','official_name'=>'ASCENSORES ENINTER, S.L.','cif'=>'B08875205','aliases'=>['ASCENSORES ENINTER']],
    ['match'=>'SUMINISTROS SANZ','name'=>'SUMINISTROS SANZ','official_name'=>'SUMINISTROS SANZ, E.S.P.J.','cif'=>'E44502805','aliases'=>['SUMINISTROS HOSTELEROS Y EXTINTORES SANZ','EXTINTORES SANZ']],
    ['match'=>'GYFSA','name'=>'GYFSA','official_name'=>'GYFSA DISTRIBUCIONES, S.L.','cif'=>'B12217204','aliases'=>['GYFSA DISTRIBUCIONES']],
    ['match'=>'PROPODA','name'=>'PROPODA','official_name'=>'PROPODA, S.L.','cif'=>'B02995645','aliases'=>['PRO PODA']],
    ['match'=>'POOLTERMIA','name'=>'POOLTERMIA','official_name'=>'POOL TERMIA, S.L.','cif'=>'B12901351','aliases'=>['POOL TERMIA','POOL-TERMIA']],
    ['match'=>'PROFOC','name'=>'PROFOC','official_name'=>'GARCÍA MARÍN CONSULTORES, S.L.','cif'=>'B12802971','aliases'=>['PRO FOC','GARCIA MARIN CONSULTORES']],
    ['match'=>'DOSDA','name'=>'DOSDA','official_name'=>'MAQUINARIA AGRÍCOLA DOSDÁ, S.L.','cif'=>'B12388092','aliases'=>['DOSDÁ','MAQUINARIA AGRÍCOLA DOSDÁ']],
    ['match'=>'INMECAS','name'=>'INMECAS','official_name'=>'H2O PLUS, S.L.','cif'=>'B12891099','aliases'=>['H2O PLUS','H2O PLUS SL']],
    ['match'=>'ENDESA','name'=>'ENDESA','official_name'=>'ENDESA ENERGÍA, S.A.U.','cif'=>'A81948077','aliases'=>['ENDESA ENERGÍA','ENDESA ENERGIA']],
    ['match'=>'JARDIGRUP','name'=>'JARDIGRUP','official_name'=>'JARDIGRUP, C.B.','cif'=>'E12898821','aliases'=>['JARDI GRUP']],
    ['match'=>'JARDITEC','name'=>'JARDITEC','official_name'=>'JARDITEC, S.C.','cif'=>'J12775227','aliases'=>['JARDITEC SC']],
    ['match'=>'MESNET','name'=>'MESNET','official_name'=>'MES NET, S.L.','cif'=>'B58603028','aliases'=>['MES NET','MES NET SL']],
    ['match'=>'LIMBUR','name'=>'LIMBUR','official_name'=>'BURISLIM, S.L.','cif'=>'B12977583','aliases'=>['BURISLIM','BURISLIM SL']],
    ['match'=>'JOMASAN','name'=>'JOMASAN','official_name'=>'EXTINTORES JOMASAN, S.L.','cif'=>'B12457560','aliases'=>['EXTINTORES JOMASAN']],
    // Identificadores confirmados después con facturas reales — sustituyen "Pendiente".
    ['match'=>'ADRIAN TURCU','name'=>'ADRIAN TURCU','official_name'=>'ADRIAN TURCU','cif'=>'X4153497L','aliases'=>['LIMPIEZAS ADRIÁN','LIMPIEZAS ADRIAN']],
    ['match'=>'MB','name'=>'MB','official_name'=>'MANTENIMIENTOS MANUEL BASTIDA S.L.U.','cif'=>'B10623015','aliases'=>['MANTENIMIENTOS MB','MANTENIMIENTOS MANUEL BASTIDA','MANTENIMIENTOS MANUEL BASTIDA SLU']],
    ['match'=>'YOLIMPIO','name'=>'YOLIMPIO','official_name'=>'RAFAEL GUIJARRO PRADES','cif'=>'18965195Q','aliases'=>['YO LIMPIO','RAFAEL GUIJARRO PRADES']],
    ['match'=>'SERGIO RAUL','name'=>'SERGIO RAUL','official_name'=>'SERGIO RAUL MARIN RUIZ','cif'=>'53376935F','aliases'=>['SERGIO RAÚL','SERGIO RAUL MARIN RUIZ']],
    ['match'=>'CALIN IGNAT','name'=>'CALIN IGNAT','official_name'=>'CALIN IGNAT BUDA','cif'=>'55119149V','aliases'=>['CALIN IGNAT BUDA']],
    // Sin identificador fiscal conocido — cif se fuerza a NULL explícitamente, nunca se inventa.
    ['match'=>'CONSTANTIN - PROPIETARIO','name'=>'CONSTANTIN - PROPIETARIO','official_name'=>'CONSTANTIN FRATILA','cif'=>null,'aliases'=>['CONSTANTIN','CONSTANTIN FRATILA','CONSTANTIN PROPIETARIO']],
    ['match'=>'ALINA - PROPIETARIA','name'=>'ALINA - PROPIETARIA','official_name'=>'ALINA - PROPIETARIA','cif'=>null,'aliases'=>['ALINA','ALINA PROPIETARIA']],
    ['match'=>'LAURA - PROPIETARIO','name'=>'LAURA - PROPIETARIO','official_name'=>'LAURA - PROPIETARIO','cif'=>null,'aliases'=>['LAURA','LAURA PROPIETARIO']],
];

// ---- 2. Fusiones ----
// target = supplier canónico que sobrevive activo; source = duplicado que queda active=0.
// Elegido por mayor uso real (más relaciones community_suppliers) y mayor cercanía al estado
// final deseado — ver diagnóstico completo en la respuesta que acompaña este script.
$merges = [
    'extincas' => [
        'target_match' => 'EXTINCAS', 'source_match' => 'EXTNCAS',
        'name' => 'EXTINCAS', 'official_name' => 'EXTINTORES CASTELLÓN, S.L.', 'cif' => 'B12433314',
        'main_service_type_id' => null, // null = conservar el del target, no hay conflicto (ambos Extintores)
        'aliases' => ['EXTNCAS','EXTINTORES CASTELLÓN','EXTINTORES CASTELLON'],
    ],
    'enervia' => [
        'target_match' => 'ENERVIA SOLUCIONES ENERGETICAS', 'source_match' => 'ENERVIA',
        'name' => 'ENERVIA', 'official_name' => 'ENERVIA SOLUCIONES ENERGETICAS S.L.', 'cif' => 'B98172885',
        'main_service_type_id' => null, // null = conservar el del target (Electricidad, id 92) — ver justificación en el informe
        'aliases' => ['ENERVIA SOLUCIONES ENERGETICAS','ENERVIA SOLUCIONES ENERGETICAS S.L.'],
    ],
];

/**
 * Empareja un supplier tanto en su estado ANTES de migrar (official_name todavía es el nombre
 * corto actual de producción, p.ej. "PERTOR") como en su estado YA MIGRADO (official_name ya es
 * la razón social final, p.ej. "ASCENSORES PERTOR, S.L.", pero name+official_name ya coinciden
 * con el par final). Sin este doble camino, una segunda ejecución no encontraría nada que
 * actualizar (official_name ya no vale lo que valía antes) y reportaría "no encontrado" para
 * todo lo ya migrado correctamente — justo lo contrario de la idempotencia que se pide.
 */
function findSupplierRobust(Salvest\Database $db, string $beforeOfficialName, string $afterName, string $afterOfficialName): ?array
{
    return $db->one(
        'SELECT * FROM suppliers WHERE active=1 AND (official_name=? OR (name=? AND official_name=?)) ORDER BY id LIMIT 1',
        [$beforeOfficialName, $afterName, $afterOfficialName]
    );
}

$report = ['updates' => [], 'merges' => [], 'aliases_planned' => 0, 'errors' => []];

// ---- 3. Actualizaciones normales (37 suppliers) ----
foreach ($masterData as $entry) {
    $row = findSupplierRobust($db, $entry['match'], $entry['name'], $entry['official_name']);
    if (!$row) {
        $report['updates'][] = ['match'=>$entry['match'],'action'=>'ERROR','reason'=>'no se encontró ningún supplier activo con ese official_name'];
        $report['errors'][] = "No encontrado: {$entry['match']}";
        continue;
    }
    $newCif = canonicalCif($entry['cif']);
    $newNormalizedName = Salvest\Text::normalizeCompanyName($entry['name']);
    $newNormalizedOfficial = Salvest\Text::normalizeCompanyName($entry['official_name']);
    $currentCif = canonicalCif($row['cif']);
    $fieldsChanged = $row['name'] !== $entry['name']
        || $row['official_name'] !== $entry['official_name']
        || $row['normalized_name'] !== $newNormalizedName
        || $row['normalized_official_name'] !== $newNormalizedOfficial
        || $currentCif !== $newCif;
    // CIF_NULL cuando el destino final es (y sigue siendo) sin identificador fiscal — distingue
    // "no tenemos CIF y lo confirmamos explícitamente" de un UPDATE normal con CIF real.
    $action = !$fieldsChanged ? 'KEEP' : ($newCif === null ? 'CIF_NULL' : 'UPDATE');

    $aliasesToInsert = filterAliases($entry['aliases'], $newNormalizedName, $newNormalizedOfficial);
    $existingAliases = array_column($db->all('SELECT normalized_value FROM supplier_aliases WHERE supplier_id=?', [(int)$row['id']]), 'normalized_value');
    $aliasesReallyNew = array_values(array_filter($aliasesToInsert, static fn(string $a): bool => !in_array(Salvest\Text::normalizeCompanyName($a), $existingAliases, true)));

    $report['updates'][] = [
        'id'=>(int)$row['id'],'match'=>$entry['match'],
        'name_before'=>$row['name'],'name_after'=>$entry['name'],
        'official_name_before'=>$row['official_name'],'official_name_after'=>$entry['official_name'],
        'normalized_name_before'=>$row['normalized_name'],'normalized_name_after'=>$newNormalizedName,
        'normalized_official_name_after'=>$newNormalizedOfficial,
        'cif_before'=>$row['cif'],'cif_after'=>$newCif,
        'service'=>$row['main_service_type_id'],
        'aliases_to_insert'=>$aliasesReallyNew,
        'action'=>$action,
    ];
    $report['aliases_planned'] += count($aliasesReallyNew);

    if ($apply) {
        $pdo->beginTransaction();
        try {
            $db->execute('UPDATE suppliers SET name=?,official_name=?,normalized_name=?,normalized_official_name=?,cif=? WHERE id=?',
                [$entry['name'], $entry['official_name'], $newNormalizedName, $newNormalizedOfficial, $newCif, (int)$row['id']]);
            foreach ($aliasesReallyNew as $alias) {
                $db->execute('INSERT IGNORE INTO supplier_aliases(supplier_id,alias_type,value,normalized_value,active) VALUES (?,?,?,?,1)',
                    [(int)$row['id'], 'name', $alias, Salvest\Text::normalizeCompanyName($alias)]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $report['errors'][] = "Fallo actualizando {$entry['match']}: {$error->getMessage()}";
        }
    }
}

// ---- 4. Fusiones ----
foreach ($merges as $key => $merge) {
    $target = findSupplierRobust($db, $merge['target_match'], $merge['name'], $merge['official_name']);
    $source = $db->one('SELECT * FROM suppliers WHERE official_name=?', [$merge['source_match']]); // puede estar ya active=0 en una segunda ejecución
    $mergeReport = ['key'=>$key,'target_match'=>$merge['target_match'],'source_match'=>$merge['source_match']];

    if (!$target) { $mergeReport['action']='ERROR'; $mergeReport['reason']='target no encontrado (o ya no está active=1)'; $report['merges'][]=$mergeReport; $report['errors'][]="Fusión $key: target no encontrado"; continue; }
    if (!$source) { $mergeReport['action']='ERROR'; $mergeReport['reason']='source no encontrado en absoluto'; $report['merges'][]=$mergeReport; $report['errors'][]="Fusión $key: source no encontrado"; continue; }

    $alreadyMerged = (int)$source['active'] === 0;
    $newCif = canonicalCif($merge['cif']);
    $newNormalizedName = Salvest\Text::normalizeCompanyName($merge['name']);
    $newNormalizedOfficial = Salvest\Text::normalizeCompanyName($merge['official_name']);
    $finalServiceId = $merge['main_service_type_id'] ?? (int)$target['main_service_type_id'];

    $sourceRelations = $db->all('SELECT * FROM community_suppliers WHERE supplier_id=?', [(int)$source['id']]);
    $transferPlan = [];
    foreach ($sourceRelations as $rel) {
        $collision = $db->one('SELECT id FROM community_suppliers WHERE community_id=? AND supplier_id=? AND category=?', [$rel['community_id'], (int)$target['id'], $rel['category']]);
        $transferPlan[] = ['relation_id'=>(int)$rel['id'],'community_id'=>(int)$rel['community_id'],'category'=>$rel['category'],
            'plan'=>$collision ? 'DEDUP_DELETE (ya existe en target)' : 'TRANSFER_TO_TARGET'];
    }

    // Mismo filtro de redundancia que las actualizaciones normales, para que el dry-run muestre
    // EXACTAMENTE lo que --apply va a insertar de verdad (algunos de los "aliases mínimos"
    // configurados pueden normalizar igual que name/official_name y quedar filtrados — ver
    // ENERVIA en el informe).
    $mergeAliasesFiltered = filterAliases($merge['aliases'], $newNormalizedName, $newNormalizedOfficial);
    $existingTargetAliases = array_column($db->all('SELECT normalized_value FROM supplier_aliases WHERE supplier_id=?', [(int)$target['id']]), 'normalized_value');
    $mergeAliasesReallyNew = array_values(array_filter($mergeAliasesFiltered, static fn(string $a): bool => !in_array(Salvest\Text::normalizeCompanyName($a), $existingTargetAliases, true)));

    $mergeReport += [
        'target_id'=>(int)$target['id'],'source_id'=>(int)$source['id'],
        'already_merged'=>$alreadyMerged,
        'target_before'=>['name'=>$target['name'],'official_name'=>$target['official_name'],'cif'=>$target['cif'],'main_service_type_id'=>$target['main_service_type_id']],
        'target_after'=>['name'=>$merge['name'],'official_name'=>$merge['official_name'],'cif'=>$newCif,'main_service_type_id'=>$finalServiceId],
        'source_relations_count'=>count($sourceRelations),
        'relation_transfer_plan'=>$transferPlan,
        'aliases_to_insert'=>$mergeAliasesReallyNew,
        'action'=>$alreadyMerged ? 'KEEP (ya fusionado)' : 'MERGE_TARGET / MERGE_SOURCE',
    ];
    $report['merges'][] = $mergeReport;
    $report['aliases_planned'] += count($mergeAliasesReallyNew);

    if ($apply) {
        $pdo->beginTransaction();
        try {
            // Precondiciones re-verificadas DENTRO de la transacción, con FOR UPDATE, para no
            // depender ciegamente del estado leído más arriba (otro proceso pudo cambiarlo).
            $targetLocked = $db->one('SELECT * FROM suppliers WHERE id=? FOR UPDATE', [(int)$target['id']]);
            $sourceLocked = $db->one('SELECT * FROM suppliers WHERE id=? FOR UPDATE', [(int)$source['id']]);
            if (!$targetLocked || (int)$targetLocked['active'] !== 1) throw new RuntimeException('target ya no es válido (active=1) en el momento de aplicar');
            if (!$sourceLocked) throw new RuntimeException('source desapareció en el momento de aplicar');

            foreach ($sourceRelations as $rel) {
                $collision = $db->one('SELECT id FROM community_suppliers WHERE community_id=? AND supplier_id=? AND category=?', [$rel['community_id'], (int)$target['id'], $rel['category']]);
                if ($collision) {
                    $db->execute('DELETE FROM community_suppliers WHERE id=?', [(int)$rel['id']]);
                } else {
                    $db->execute('UPDATE community_suppliers SET supplier_id=? WHERE id=?', [(int)$target['id'], (int)$rel['id']]);
                }
            }
            $db->execute('UPDATE suppliers SET name=?,official_name=?,normalized_name=?,normalized_official_name=?,cif=?,main_service_type_id=? WHERE id=?',
                [$merge['name'], $merge['official_name'], $newNormalizedName, $newNormalizedOfficial, $newCif, $finalServiceId, (int)$target['id']]);
            foreach ($mergeAliasesFiltered as $alias) {
                $db->execute('INSERT IGNORE INTO supplier_aliases(supplier_id,alias_type,value,normalized_value,active) VALUES (?,?,?,?,1)',
                    [(int)$target['id'], 'name', $alias, Salvest\Text::normalizeCompanyName($alias)]);
            }
            if (!$alreadyMerged) $db->execute('UPDATE suppliers SET active=0 WHERE id=?', [(int)$source['id']]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $report['errors'][] = "Fusión $key falló, ROLLBACK completo: {$error->getMessage()}";
        }
    }
}

// ---- 5. Salida ----
fwrite(STDOUT, $apply ? "=== APPLY (escribiendo en BD) ===\n" : "=== DRY-RUN (sin escribir nada) ===\n");
foreach ($report['updates'] as $u) {
    fwrite(STDOUT, sprintf("[%s] id=%s %-28s aliases_nuevos=%d\n", $u['action'], $u['id'] ?? '?', $u['match'], count($u['aliases_to_insert'] ?? [])));
}
foreach ($report['merges'] as $m) {
    fwrite(STDOUT, sprintf("[MERGE %s] target=%d source=%d relations=%d action=%s\n", $m['key'], $m['target_id'] ?? 0, $m['source_id'] ?? 0, $m['source_relations_count'] ?? 0, $m['action']));
}
fwrite(STDOUT, sprintf("\nTotal aliases planificados: %d\n", $report['aliases_planned']));
if ($report['errors']) {
    fwrite(STDOUT, "\nERRORES:\n");
    foreach ($report['errors'] as $e) fwrite(STDOUT, "  - $e\n");
}
fwrite(STDOUT, $dryRun ? "\n(dry-run: nada se ha escrito. Ejecuta con --apply para aplicar.)\n" : "\n(apply: cambios aplicados y confirmados.)\n");

// Salida machine-readable para quien quiera inspeccionar el detalle completo.
file_put_contents('php://stderr', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

exit($report['errors'] ? 1 : 0);

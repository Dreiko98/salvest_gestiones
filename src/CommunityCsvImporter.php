<?php
declare(strict_types=1);

namespace Salvest;

final class CommunityCsvImporter
{
    private const COLUMNS=[
        'ASCENSOR'=>['ASCENSOR','Nº cliente/contrato (ascensor)'],
        'ELECTRICIDAD'=>['ELECTRICIDAD','Nº cliente/contrato (electricidad)'],
        'AGUA'=>['AGUA (FACSA)','Nº cliente/contrato (agua)'],
        'LIMPIEZA'=>['LIMPIEZA','Nº cliente/contrato (limpieza)'],
        'EXTINTORES'=>['EXTINTORES','Nº cliente/contrato (extintores)'],
        'JARDINERIA'=>['JARDINERÍA','Nº cliente/contrato (jardinería)'],
        'PISCINA'=>['PISCINA','Nº cliente/contrato (Piscina)'],
        'DESCALCIFICADOR'=>['DESCALCIFICADOR','Nº cliente/contrato (Descalcificador)'],
        'MANTENIMIENTO'=>['otro proveedor 1','Nº cliente/contrato (otro proveedor 1)'],
    ];

    public function __construct(private Database $db){}

    /**
     * FROZEN — DO NOT RUN AFTER SUPPLIER MASTER MIGRATION.
     *
     * replaceFrom() below deletes and rebuilds `suppliers`/`supplier_aliases`/
     * `supplier_service_types` from the CSV, writing them with the pre-Fase-3 model
     * (official_name = short commercial name, `name` never touched). Once the Fase 3 data
     * migration has run, that would silently destroy every CIF, razón social, alias and the two
     * supplier merges it wrote — with no way back from inside the database itself.
     *
     * This is a deliberate fail-fast, not a full adaptation of the importer (that's a later,
     * separate task): if any active supplier already has a non-empty `name`, the master is
     * considered migrated and this throws before the transaction opens — before the very first
     * DELETE. No bypass flag exists on purpose; the real fix is making the importer merge into
     * the new model instead of replacing it, not making this check easier to skip.
     *
     * A pre-Fase-2 database (the `name` column doesn't exist yet) or a post-Fase-2-but-
     * pre-Fase-3 one (the column exists but is NULL for every row) both pass through unchanged —
     * this only blocks the state that would actually be destroyed.
     */
    private function guardAgainstMigratedSupplierMaster(): void
    {
        $columnExists=$this->db->one("SELECT 1 ok FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='suppliers' AND column_name='name'");
        if(!$columnExists)return;
        $migrated=$this->db->one("SELECT COUNT(*) n FROM suppliers WHERE active=1 AND name IS NOT NULL AND name<>''");
        if($migrated&&(int)$migrated['n']>0){
            throw new \RuntimeException(
                'ABORTADO: el maestro de proveedores ya está migrado ('.$migrated['n'].' supplier(s) activo(s) con suppliers.name relleno). '.
                'CommunityCsvImporter::replaceFrom() borraría y reconstruiría suppliers/supplier_aliases con el modelo antiguo, destruyendo CIF, '.
                'razón social, aliases y fusiones ya migrados. No hay ningún flag para saltarse esta protección — la adaptación completa del '.
                'importador al nuevo maestro de proveedores queda pendiente para una fase posterior. Ninguna fila se ha modificado.'
            );
        }
    }

    /** @return array{communities:int,suppliers:int,relations:int} */
    public function replaceFrom(string $path):array
    {
        $this->guardAgainstMigratedSupplierMaster();
        $rows=$this->read($path);
        if(count($rows)!==65)throw new \RuntimeException('El CSV definitivo debe contener exactamente 65 comunidades; contiene '.count($rows));
        $codes=[];
        foreach($rows as$row){
            $code=self::code((string)$row['Código']);
            if($code===''||isset($codes[$code]))throw new \RuntimeException("Código de comunidad vacío o duplicado: $code");
            if(in_array($code,['100','200'],true))throw new \RuntimeException("El CSV no debe incluir la comunidad excluida $code");
            $codes[$code]=true;
        }
        foreach(['75','115','118']as$required)if(!isset($codes[$required]))throw new \RuntimeException("Falta la comunidad requerida $required");

        $pdo=$this->db->pdo();$pdo->beginTransaction();
        try{
            $this->db->execute('UPDATE processed_attachments SET community_id=NULL');
            $this->db->execute('DELETE FROM community_suppliers');
            $this->db->execute('DELETE FROM community_aliases');
            $this->db->execute('DELETE FROM community_identifiers');
            $this->db->execute('DELETE FROM communities');
            $this->db->execute('DELETE FROM supplier_aliases');
            $this->db->execute('DELETE FROM supplier_service_types');
            $this->db->execute('DELETE FROM suppliers');
            $this->db->execute('DELETE FROM drive_folders');
            $supplierIds=[];$relations=0;
            foreach($rows as$row){
                $code=self::code((string)$row['Código']);
                $name=trim((string)$row['Comunidad']);
                if($name===''||trim((string)$row['CIF'])===''||trim((string)$row['Dirección'])==='')throw new \RuntimeException("Datos básicos incompletos en comunidad $code");
                $this->db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,postal_code,city,province,notes,imap_folder_name,active) VALUES (?,?,?,?,?,?,?,?,?,?,1)',[
                    $code,$name,Text::normalize($name),trim((string)$row['CIF']),trim((string)$row['Dirección']),trim((string)$row['Código Postal']),trim((string)$row['Ciudad']),trim((string)$row['Provincia']),trim((string)$row['Notas']),$code.' - '.$name,
                ]);
                $communityId=(int)$pdo->lastInsertId();
                foreach(self::COLUMNS as$category=>[$providerColumn,$contractColumn]){
                    $raw=trim((string)$row[$providerColumn]);
                    if($raw===''||Text::normalize($raw)==='no')continue;
                    $provider=self::providerName($raw);$normalized=Text::normalize($provider);
                    if(!isset($supplierIds[$normalized])){
                        $service=$this->db->one('SELECT id FROM service_types WHERE normalized_name=?', [Text::normalize($category)]);
                        if(!$service)throw new \RuntimeException("No existe la categoría $category");
                        $this->db->execute('INSERT INTO suppliers(official_name,normalized_name,main_service_type_id,active) VALUES (?,?,?,1)',[$provider,$normalized,$service['id']]);
                        $supplierIds[$normalized]=(int)$pdo->lastInsertId();
                    }
                    $supplierId=$supplierIds[$normalized];
                    $service=$this->db->one('SELECT id FROM service_types WHERE normalized_name=?',[Text::normalize($category)]);
                    $this->db->execute('INSERT IGNORE INTO supplier_service_types(supplier_id,service_type_id) VALUES (?,?)',[$supplierId,$service['id']]);
                    $this->db->execute('INSERT INTO community_suppliers(community_id,supplier_id,category,contract_reference,source_column,raw_provider_name) VALUES (?,?,?,?,?,?)',[
                        $communityId,$supplierId,$category,trim((string)$row[$contractColumn])?:null,$providerColumn,$raw,
                    ]);$relations++;
                }
            }
            $pdo->commit();
            return ['communities'=>count($rows),'suppliers'=>count($supplierIds),'relations'=>$relations];
        }catch(\Throwable$error){if($pdo->inTransaction())$pdo->rollBack();throw$error;}
    }

    public static function code(string $value):string
    {
        $value=trim($value);
        if(!preg_match('/^\d+$/',$value))throw new \InvalidArgumentException("Código no numérico: $value");
        $number=(int)$value;return $number<10?str_pad((string)$number,2,'0',STR_PAD_LEFT):(string)$number;
    }

    public static function codeOrEmpty(string $value):string
    {
        $value=trim($value);return preg_match('/^\d+$/',$value)?self::code($value):'';
    }

    public static function providerName(string $value):string
    {
        return trim((string)preg_replace('/\s*\(\d+\)\s*$/u','',trim($value)));
    }

    /** @return list<array<string,string>> */
    private function read(string $path):array
    {
        $handle=fopen($path,'rb');if(!$handle)throw new \RuntimeException("No se pudo abrir $path");
        try{
            $header=fgetcsv($handle,0,';');if(!$header)throw new \RuntimeException('CSV sin cabecera');
            $header=array_map(static fn(string$value):string=>trim($value,"\xEF\xBB\xBF \t\r\n"),$header);
            $required=array_merge(['Código','Comunidad','CIF','Dirección','Código Postal','Ciudad','Provincia','Notas'],array_merge(...array_values(self::COLUMNS)));
            foreach($required as$name)if(!in_array($name,$header,true))throw new \RuntimeException("Falta la columna $name");
            $rows=[];
            while(($values=fgetcsv($handle,0,';'))!==false){
                if(count($values)===1&&trim((string)$values[0])==='')continue;
                if(count($values)!==count($header))throw new \RuntimeException('Fila CSV con número de columnas incorrecto');
                /** @var array<string,string> $row */$row=array_combine($header,$values);$rows[]=$row;
            }
            return $rows;
        }finally{fclose($handle);}
    }
}

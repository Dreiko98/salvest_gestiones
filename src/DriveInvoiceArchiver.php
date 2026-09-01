<?php
declare(strict_types=1);

namespace Salvest;

final class DriveInvoiceArchiver
{
    public function __construct(private GoogleDriveClient $drive,private string $rootFolderId){}

    /** @param array<string,mixed> $community @param array<string,mixed> $supplier @param array<string,mixed> $invoice
     * @return array{id:string,name:string,path:string,webViewLink:?string} */
    public function archive(string $localPath,array $community,array $supplier,string $category,array $invoice):array
    {
        $date=(string)($invoice['fecha_factura']??'');if(!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$date,$match))throw new \RuntimeException('Fecha de factura no válida para Drive');
        $communityFolder=self::communityFolderName($community);$category=self::category($category);
        // Fase 14: la carpeta de comunidad de nivel superior no la creó esta app — ya existía,
        // organizada a mano por el cliente, con nombres que no siempre coinciden al carácter con
        // lo que generaríamos nosotros (espacios/guiones distintos, alguna palabra pegada). Buscar
        // solo por nombre exacto producía carpetas duplicadas. Se busca primero por el código
        // numérico de la comunidad (fiable e inequívoco); solo si no existe ninguna con ese código
        // se cae al criterio antiguo (nombre exacto o crear nueva). Las carpetas ANIDADAS (año,
        // categoría) sí las crea siempre esta misma app, así que ahí el nombre exacto ya es fiable
        // y no hace falta este mismo tratamiento.
        $code=(string)($community['external_code']??'');
        $parent=$this->findCommunityFolderByCode($this->rootFolderId,$code)??$this->drive->ensureFolder($this->rootFolderId,$communityFolder);
        $parent=$this->drive->ensureFolder($parent,'Doc año en Vigor');
        $invoiceYear=(int)$match[1];$currentYear=(int)date('Y');
        if($invoiceYear>$currentYear)throw new \RuntimeException('La fecha de factura pertenece a un año futuro');
        $parts=self::storageParts($invoiceYear,$currentYear,$category);
        foreach($parts as$part)$parent=$this->drive->ensureFolder($parent,$part);
        // Fase 14: nomenclatura pedida explícitamente — número de factura, nombre CORTO del
        // proveedor (suppliers.name, no la razón social larga), e importe con el símbolo €. Sin
        // fecha (ya la dice la carpeta que lo contiene). Si por lo que sea no hay número de
        // factura, se usa la fecha como respaldo para no perder unicidad descriptiva.
        $numberToken=self::token((string)($invoice['numero_factura']??''));
        $supplierToken=self::token((string)($supplier['name']?:$supplier['official_name']));
        $base=implode('-',array_filter([$numberToken!==''?$numberToken:$date,$supplierToken]));
        $amountToken=self::amountToken($invoice['importe']??null);
        if($amountToken!=='')$base.='-'.$amountToken;
        $names=array_map(static fn(array$file):string=>(string)$file['name'],$this->drive->children($parent));
        $filename=self::availableFilename($names,$base.'.pdf');$uploaded=$this->drive->upload($parent,$filename,$localPath);
        return['id'=>(string)$uploaded['id'],'name'=>$filename,'path'=>$communityFolder.'/Doc año en Vigor/'.implode('/',$parts).'/'.$filename,'webViewLink'=>$uploaded['webViewLink']??null];
    }

    /** @param array<string,mixed> $community */
    public static function communityFolderName(array $community):string
    {
        $code=(string)($community['external_code']??'');if($code==='')throw new \RuntimeException('La comunidad no tiene código externo');
        return$code.' - '.trim((string)$community['official_name']);
    }

    public static function category(string $value):string
    {
        $normalized=Text::normalize($value);return match($normalized){
            'electricidad','luz'=>'ELECTRICIDAD','facsa','agua'=>'AGUA','extintores','extincas','extncas'=>'EXTINTORES',
            'ascensor'=>'ASCENSOR','limpieza'=>'LIMPIEZA','jardineria'=>'JARDINERIA','piscina'=>'PISCINA','descalcificador'=>'DESCALCIFICADOR',default=>'MANTENIMIENTO'};
    }

    public static function token(string $value):string
    {
        $value=Text::normalize($value);$value=strtoupper((string)preg_replace('/[^a-z0-9]+/','-',$value));return trim($value,'-');
    }

    /** Mismo criterio que Archiver::archive(): solo aparece cuando la extracción trajo un
     * importe real — nunca se inventa un "0.00" para una factura sin importe reconocido, se
     * omite el trozo entero. Fase 14: ahora con el símbolo € y sin separador propio — el llamante
     * decide cómo unirlo al resto del nombre. */
    public static function amountToken(mixed $amount):string
    {
        return is_numeric($amount) ? number_format((float)$amount,2,'.','').'€' : '';
    }

    /** Fase 14: ¿el nombre de esta carpeta empieza por el código numérico de la comunidad, sea
     * cual sea el separador que venga después ("82 - X", "82-X", "082 X"...)? Se extrae la
     * primera racha de dígitos al principio del nombre (avanzando hasta el primer carácter que
     * no sea dígito) y se compara como número — así "82" y "082" son la misma comunidad, pero
     * "820 X" nunca cuenta como un falso positivo de la comunidad "82" (la racha completa es
     * "820", no "82"). Sin ningún dígito inicial, no hay coincidencia posible. */
    public static function folderMatchesCode(string $folderName,string $code):bool
    {
        if(!preg_match('/^0*(\d+)\D/',trim($folderName),$match))return false;
        return(int)$match[1]===(int)$code;
    }

    private function findCommunityFolderByCode(string $parentId,string $code):?string
    {
        if($code==='')return null;
        foreach($this->drive->children($parentId) as $file){
            if(($file['mimeType']??null)!=='application/vnd.google-apps.folder')continue;
            if(self::folderMatchesCode((string)($file['name']??''),$code))return(string)$file['id'];
        }
        return null;
    }

    /** @return list<string> */
    public static function storageParts(int $invoiceYear,int $currentYear,string $category):array
    {
        if($invoiceYear>$currentYear)throw new \RuntimeException('No se archivan facturas con fecha futura');
        $category=self::category($category);
        return$invoiceYear===$currentYear?[$category]:[(string)$invoiceYear,$category];
    }

    /** @param list<string> $names */
    public static function availableFilename(array $names,string $desired):string
    {
        $names=array_flip($names);
        if(!isset($names[$desired]))return$desired;$stem=pathinfo($desired,PATHINFO_FILENAME);$extension=pathinfo($desired,PATHINFO_EXTENSION);
        for($index=2;;$index++){$candidate=$stem.' ('.$index.').'.$extension;if(!isset($names[$candidate]))return$candidate;}
    }
}

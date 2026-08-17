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
        $parent=$this->drive->ensureFolder($this->rootFolderId,$communityFolder);
        $parent=$this->drive->ensureFolder($parent,'Doc año en Vigor');
        $parent=$this->drive->ensureFolder($parent,$match[1]);
        $parent=$this->drive->ensureFolder($parent,$category);
        $base=$date.'_'.self::token((string)$supplier['official_name']);
        $number=self::token((string)($invoice['numero_factura']??''));if($number!=='')$base.='_'.$number;
        $names=array_map(static fn(array$file):string=>(string)$file['name'],$this->drive->children($parent));
        $filename=self::availableFilename($names,$base.'.pdf');$uploaded=$this->drive->upload($parent,$filename,$localPath);
        return['id'=>(string)$uploaded['id'],'name'=>$filename,'path'=>$communityFolder.'/Doc año en Vigor/'.$match[1].'/'.$category.'/'.$filename,'webViewLink'=>$uploaded['webViewLink']??null];
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

    /** @param list<string> $names */
    public static function availableFilename(array $names,string $desired):string
    {
        $names=array_flip($names);
        if(!isset($names[$desired]))return$desired;$stem=pathinfo($desired,PATHINFO_FILENAME);$extension=pathinfo($desired,PATHINFO_EXTENSION);
        for($index=2;;$index++){$candidate=$stem.' ('.$index.').'.$extension;if(!isset($names[$candidate]))return$candidate;}
    }
}

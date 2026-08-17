#!/usr/bin/env php
<?php
declare(strict_types=1);

if(count($argv)!==9){fwrite(STDERR,"Uso: php bin/test-drive-upload.php CLIENT_JSON TOKEN_JSON ROOT_FOLDER PDF CODIGO PROVEEDOR FECHA NUMERO\n");exit(2);}
[, $clientFile,$tokenFile,$rootFolder,$pdf,$code,$providerName,$date,$invoiceNumber]=$argv;
if(!is_file($clientFile)||!is_file($tokenFile)||!is_file($pdf))throw new RuntimeException('Falta un archivo requerido');
$config=require dirname(__DIR__).'/bootstrap.php';$db=new Salvest\Database($config['database']);
$community=$db->one('SELECT * FROM communities WHERE external_code=? AND active=1',[Salvest\CommunityCsvImporter::code($code)]);
if(!$community)throw new RuntimeException('Comunidad no encontrada');
$supplier=$db->one('SELECT s.*,cs.category FROM community_suppliers cs JOIN suppliers s ON s.id=cs.supplier_id WHERE cs.community_id=? AND s.normalized_name=?',[$community['id'],Salvest\Text::normalize($providerName)]);
if(!$supplier)throw new RuntimeException('El proveedor no pertenece a la comunidad');
$tokens=new Salvest\GoogleUserOAuthProvider($clientFile,$tokenFile);
$archiver=new Salvest\DriveInvoiceArchiver(new Salvest\GoogleDriveClient($tokens),$rootFolder);
$result=$archiver->archive($pdf,$community,$supplier,(string)$supplier['category'],[
    'fecha_factura'=>$date,'proveedor'=>$supplier['official_name'],'numero_factura'=>$invoiceNumber,
]);
fwrite(STDOUT,json_encode(['community_code'=>$community['external_code'],'community'=>$community['official_name'],'provider'=>$supplier['official_name'],'category'=>$supplier['category'],'drive'=>$result],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL);

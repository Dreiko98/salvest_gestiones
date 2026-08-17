<?php
declare(strict_types=1);

spl_autoload_register(static function(string $class): void {
    if (str_starts_with($class,'Salvest\\')) require dirname(__DIR__).'/src/'.substr($class,8).'.php';
});

$tests=[];
$test=static function(string $name,callable $callback)use(&$tests):void{$tests[$name]=$callback;};
$assert=static function(bool $condition,string $message='assertion failed'):void{if(!$condition)throw new RuntimeException($message);};

$test('normalización y slug',static function()use($assert):void{
    $assert(Salvest\Text::normalize("Carrer del Jardí, 42") === 'calle del jardi 42');
    $assert(Salvest\Text::slug("CP Mirador de l'Horta") === 'cp-mirador-de-l-horta');
});
$test('cifrado autenticado',static function()use($assert):void{
    $crypto=new Salvest\Crypto(Salvest\Crypto::generateKey()); $cipher=$crypto->encrypt('secreto');
    $assert($cipher!=='secreto'); $assert($crypto->decrypt($cipher)==='secreto');
});
$test('imap modified utf7',static function()use($assert):void{
    $assert(Salvest\ImapClient::modifiedUtf7('Pendientes de revisión')==='Pendientes de revisi&APM-n');
});
$test('mime con un pdf',static function()use($assert):void{
    $raw="From: demo@example.com\r\nSubject: Factura\r\nContent-Type: application/pdf; name=invoice.pdf\r\nContent-Disposition: attachment; filename=invoice.pdf\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode('%PDF-demo');
    $message=(new Salvest\MimeParser())->parse($raw);
    $assert(count($message['attachments'])===1);
    $assert($message['attachments'][0]['original_filename']==='invoice.pdf');
    $assert($message['attachments'][0]['sha256']===hash('sha256','%PDF-demo'));
});
$test('mime sin documentos',static function()use($assert):void{
    $raw="From: demo@example.com\r\nSubject: Consulta\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nNo contiene facturas.";
    $message=(new Salvest\MimeParser())->parse($raw);
    $assert(count($message['attachments'])===0);
    $assert($message['body']==='No contiene facturas.');
});
$test('validación rechaza un falso pdf',static function()use($assert):void{
    try{
        Salvest\DocumentValidator::validate(['payload'=>'esto no es un PDF','mime_type'=>'application/pdf','original_filename'=>'factura.pdf'],1024);
        $assert(false,'debería rechazar el documento');
    }catch(RuntimeException $error){$assert(str_contains($error->getMessage(),'firma PDF'));}
});
$test('validación acepta un pdf',static function()use($assert):void{
    Salvest\DocumentValidator::validate(['payload'=>'%PDF-1.4 demo','mime_type'=>'application/pdf','original_filename'=>'factura.pdf'],1024);
    $assert(true);
});
$test('mime con varios pdf y nombre especial',static function()use($assert):void{
    $boundary='demo-boundary';
    $raw="From: Demo <demo@example.com>\r\nSubject: Facturas\r\nContent-Type: multipart/mixed; boundary=\"$boundary\"\r\n\r\n".
        "--$boundary\r\nContent-Type: text/plain\r\n\r\nAdjunto facturas\r\n".
        "--$boundary\r\nContent-Type: application/pdf; name=\"factura á.pdf\"\r\nContent-Disposition: attachment; filename=\"factura á.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode('%PDF-one')."\r\n".
        "--$boundary\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode('%PDF-two')."\r\n--$boundary--\r\n";
    $message=(new Salvest\MimeParser())->parse($raw);
    $assert(count($message['attachments'])===2); $assert($message['attachments'][0]['payload']==='%PDF-one');
    $assert(str_ends_with($message['attachments'][1]['original_filename'],'.pdf'));
});
$test('archivado y colisión determinista',static function()use($assert):void{
    $root=sys_get_temp_dir().'/salvest-test-'.bin2hex(random_bytes(4)); mkdir($root,0770,true);
    $invoice=['fecha_factura'=>'2026-07-01','tipo_servicio'=>'agua','proveedor'=>'Hidralux Servicios'];
    $community=['official_name'=>'CP Uno']; $archiver=new Salvest\Archiver($root);
    $one=$root.'/one.pdf';$two=$root.'/two.pdf';file_put_contents($one,'%PDF');file_put_contents($two,'%PDF');
    $a=$archiver->archive($one,'factura.pdf',$invoice,$community,'classified');
    $b=$archiver->archive($two,'factura.pdf',$invoice,$community,'classified');
    $assert(basename($a)==='2026-07_agua_hidralux-servicios.pdf');
    $assert(basename($b)==='2026-07_agua_hidralux-servicios_02.pdf');
});
$test('códigos y proveedores del CSV real',static function()use($assert):void{
    $assert(Salvest\CommunityCsvImporter::code('1')==='01');
    $assert(Salvest\CommunityCsvImporter::code('109')==='109');
    $assert(Salvest\CommunityCsvImporter::providerName('IBERDROLA (4)')==='IBERDROLA');
    $assert(Salvest\CommunityCsvImporter::providerName('EXTINCAS')==='EXTINCAS');
});
$test('ruta Drive y categorías canónicas',static function()use($assert):void{
    $assert(Salvest\DriveInvoiceArchiver::communityFolderName(['external_code'=>'01','official_name'=>'LES ERES 3'])==='01 - LES ERES 3');
    $assert(Salvest\DriveInvoiceArchiver::category('LUZ')==='ELECTRICIDAD');
    $assert(Salvest\DriveInvoiceArchiver::category('FACSA')==='AGUA');
    $assert(Salvest\DriveInvoiceArchiver::category('EXTINCAS')==='EXTINTORES');
    $assert(Salvest\DriveInvoiceArchiver::category('otro proveedor 1')==='MANTENIMIENTO');
    $assert(Salvest\DriveInvoiceArchiver::token('Adrián Turcu S.L.')==='ADRIAN-TURCU-S-L');
});
$test('colisión Drive nunca sobrescribe',static function()use($assert):void{
    $base='2026-08-17_IBERDROLA_F-001.pdf';
    $assert(Salvest\DriveInvoiceArchiver::availableFilename([],$base)===$base);
    $assert(Salvest\DriveInvoiceArchiver::availableFilename([$base],$base)==='2026-08-17_IBERDROLA_F-001 (2).pdf');
    $assert(Salvest\DriveInvoiceArchiver::availableFilename([$base,'2026-08-17_IBERDROLA_F-001 (2).pdf'],$base)==='2026-08-17_IBERDROLA_F-001 (3).pdf');
});

$failed=0;
foreach($tests as $name=>$callback){try{$callback();echo "PASS $name\n";}catch(Throwable $error){$failed++;echo "FAIL $name: {$error->getMessage()}\n";}}
echo sprintf("%d tests, %d failed\n",count($tests),$failed);
exit($failed?1:0);

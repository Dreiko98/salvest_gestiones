#!/usr/bin/env php
<?php
declare(strict_types=1);

$config=require dirname(__DIR__).'/bootstrap.php';$db=new Salvest\Database($config['database']);
$sender='Proveedor Fantasma <facturas@proveedor-fantasma.example>';$subject='Factura Club de Campo Las Palmeras código 100';
$invoice=['codigo_comunidad'=>'100','nombre_comunidad'=>'Club de Campo Las Palmeras','comunidad_cif'=>null,'direccion'=>'Ubicación no incluida en el maestro','proveedor'=>'PROVEEDOR FANTASMA SL','proveedor_cif'=>'B00000000','fecha_factura'=>'2026-08-17','numero_factura'=>'NOCLAS-100','importe'=>123.45,'tipo_servicio'=>'mantenimiento'];
$route=(new Salvest\InvoiceRouter(new Salvest\Classifier($db,(float)$config['processing']['classification_threshold'])))->route($invoice,$sender,"Remitente: $sender\nAsunto: $subject");
$result=['simulated_email'=>['sender'=>$sender,'subject'=>$subject,'attachment'=>'2026-08-17_PROVEEDOR-FANTASMA_NOCLAS-100.pdf'],'extracted'=>$invoice,
    'community'=>$route['decision']['community']['official_name']??null,'confidence'=>$route['decision']['confidence'],'supplier'=>$route['supplier']['official_name']??null,
    'attachment_status'=>$route['status'],'message_status'=>$route['message_status'],'imap_destination'=>$route['imap_destination'],'drive_upload'=>$route['drive_upload']];
fwrite(STDOUT,json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL);

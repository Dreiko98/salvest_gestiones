<?php
declare(strict_types=1);

spl_autoload_register(static function(string $class): void {
    if (str_starts_with($class,'Salvest\\')) require dirname(__DIR__).'/src/'.substr($class,8).'.php';
});

$tests=[];
$test=static function(string $name,callable $callback)use(&$tests):void{$tests[$name]=$callback;};
$assert=static function(bool $condition,string $message='assertion failed'):void{if(!$condition)throw new RuntimeException($message);};

// In-memory SQLite Database for tests that exercise Classifier/WebApp logic
// without a real MySQL server. Database only ever calls plain PDO methods,
// so swapping the driver behind the same class is safe for testing.
$sqliteDb=static function(string $schema):Salvest\Database{
    $pdo=new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    $pdo->exec($schema);
    $reflection=new ReflectionClass(Salvest\Database::class);
    $db=$reflection->newInstanceWithoutConstructor();
    $property=$reflection->getProperty('pdo');$property->setAccessible(true);$property->setValue($db,$pdo);
    return$db;
};
$classifierSchema=<<<SQL
CREATE TABLE communities(id INTEGER PRIMARY KEY AUTOINCREMENT,external_code TEXT,official_name TEXT,normalized_name TEXT,cif TEXT,main_address TEXT,postal_code TEXT,city TEXT,imap_folder_name TEXT,active INTEGER DEFAULT 1);
CREATE TABLE community_identifiers(id INTEGER PRIMARY KEY AUTOINCREMENT,community_id INTEGER,identifier_type TEXT,value TEXT,normalized_value TEXT,active INTEGER DEFAULT 1);
CREATE TABLE community_aliases(id INTEGER PRIMARY KEY AUTOINCREMENT,community_id INTEGER,alias_type TEXT,value TEXT,normalized_value TEXT,active INTEGER DEFAULT 1);
CREATE TABLE suppliers(id INTEGER PRIMARY KEY AUTOINCREMENT,official_name TEXT,normalized_name TEXT,cif TEXT,main_service_type_id INTEGER,active INTEGER DEFAULT 1);
CREATE TABLE supplier_aliases(id INTEGER PRIMARY KEY AUTOINCREMENT,supplier_id INTEGER,alias_type TEXT,value TEXT,normalized_value TEXT,active INTEGER DEFAULT 1);
CREATE TABLE service_types(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,normalized_name TEXT,active INTEGER DEFAULT 1);
CREATE TABLE supplier_service_types(supplier_id INTEGER,service_type_id INTEGER);
SQL;

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
$test('proveedores IMAP seguros',static function()use($assert):void{
    $assert(Salvest\MailboxProvider::connection('gmail')===['host'=>'imap.gmail.com','port'=>993,'use_ssl'=>1]);
    $assert(Salvest\MailboxProvider::connection('ionos')===['host'=>'imap.ionos.es','port'=>993,'use_ssl'=>1]);
    $assert(Salvest\MailboxProvider::fromHost('imap.gmail.com')==='gmail');
    try{Salvest\MailboxProvider::connection('personalizado');$assert(false,'debería rechazar servidores arbitrarios');}catch(InvalidArgumentException){}
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
    $assert(Salvest\DriveInvoiceArchiver::storageParts(2026,2026,'LUZ')===['ELECTRICIDAD']);
    $assert(Salvest\DriveInvoiceArchiver::storageParts(2025,2026,'AGUA')===['2025','AGUA']);
    try{Salvest\DriveInvoiceArchiver::storageParts(2027,2026,'AGUA');$assert(false,'debería rechazar años futuros');}catch(RuntimeException){}
});
$test('colisión Drive nunca sobrescribe',static function()use($assert):void{
    $base='2026-08-17_IBERDROLA_F-001.pdf';
    $assert(Salvest\DriveInvoiceArchiver::availableFilename([],$base)===$base);
    $assert(Salvest\DriveInvoiceArchiver::availableFilename([$base],$base)==='2026-08-17_IBERDROLA_F-001 (2).pdf');
    $assert(Salvest\DriveInvoiceArchiver::availableFilename([$base,'2026-08-17_IBERDROLA_F-001 (2).pdf'],$base)==='2026-08-17_IBERDROLA_F-001 (3).pdf');
});

$test('explorador Drive: carpetas y archivos en el mismo nivel',static function()use($assert):void{
    $items=[
        ['id'=>'f1','name'=>'ELECTRICIDAD','mimeType'=>'application/vnd.google-apps.folder'],
        ['id'=>'file1','name'=>'2026-08-17_IBERDROLA.pdf','mimeType'=>'application/pdf','webViewLink'=>'https://drive.google.com/file/d/file1/view'],
        ['id'=>'file2','name'=>'sin-enlace.pdf','mimeType'=>'application/pdf'],
    ];
    $html=Salvest\DriveTree::renderNodes($items,2);
    $assert(str_contains($html,'class="folder-node level-2"'),'la carpeta debe seguir siendo expandible');
    $assert(str_contains($html,'data-folder-id="f1"'),'la carpeta debe conservar su id para la carga perezosa');
    $assert(str_contains($html,'class="folder-leaf"'),'el PDF debe aparecer como hoja');
    $assert(str_contains($html,'class="file-icon"'),'el archivo debe tener un icono distinto al de carpeta');
    $leafSection=substr($html,(int)strpos($html,'class="folder-leaf"'));
    $assert(!str_contains(substr($leafSection,0,200),'class="folder-icon"'),'la hoja de archivo no debe reutilizar el icono de carpeta');
    $assert(str_contains($html,'<a href="https://drive.google.com/file/d/file1/view" target="_blank" rel="noopener noreferrer">'),'el pdf con webViewLink debe abrir en una pestaña nueva');
    $assert(str_contains($html,'sin-enlace.pdf') && !preg_match('/<a[^>]*>[^<]*sin-enlace\.pdf/',$html),'sin webViewLink no debe generar enlace');
});
$test('explorador Drive: carpeta que solo contiene PDFs no dice "no hay subcarpetas"',static function()use($assert):void{
    $items=[['id'=>'file1','name'=>'factura.pdf','mimeType'=>'application/pdf']];
    $html=Salvest\DriveTree::renderNodes($items,3);
    $assert(str_contains($html,'factura.pdf'),'el pdf debe listarse');
    $assert(!str_contains($html,'No hay subcarpetas'),'no debe mostrar el mensaje engañoso de "sin subcarpetas" cuando hay archivos');
});
$test('explorador Drive: carpeta realmente vacía',static function()use($assert):void{
    $assert(Salvest\DriveTree::renderNodes([],3)==='<div class="folder-empty">Carpeta vacía.</div>');
});
$test('explorador Drive: raíz sin comunidades',static function()use($assert):void{
    $assert(str_contains(Salvest\DriveTree::renderRoot([]),'No hay carpetas de comunidad'));
});
$test('explorador Drive: isFolder distingue carpetas de archivos',static function()use($assert):void{
    $assert(Salvest\DriveTree::isFolder(['mimeType'=>'application/vnd.google-apps.folder'])===true);
    $assert(Salvest\DriveTree::isFolder(['mimeType'=>'application/pdf'])===false);
    $assert(Salvest\DriveTree::isFolder([])===false);
});
$test('filas expandibles: toggle y detalle accesibles',static function()use($assert):void{
    $toggle=Salvest\RowDetail::toggle('community-detail-9','01');
    $assert(str_contains($toggle,'aria-expanded="false"'));
    $assert(str_contains($toggle,'aria-controls="community-detail-9"'));
    $assert(str_contains($toggle,'>01<') || str_contains($toggle,'01<span'));
    $row=Salvest\RowDetail::row('community-detail-9',[['Código','01'],['CIF','']],5);
    $assert(str_contains($row,'<tr id="community-detail-9" class="row-detail" hidden>'),'la fila de detalle debe empezar oculta');
    $assert(str_contains($row,'colspan="5"'));
    $assert(str_contains($row,'<span>Código</span><strong>01</strong>'));
    $assert(str_contains($row,'<span>CIF</span><strong>—</strong>'),'un valor vacío debe mostrarse como guion largo, no en blanco');
});
$test('ocultar campos: los valores existentes de comunidad viajan ocultos y no se borran',static function()use($assert,$sqliteDb,$classifierSchema):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,postal_code,city,imap_folder_name,active) VALUES (?,?,?,?,?,?,?,?,1)',
        ['01','LES ERES 3',Salvest\Text::normalize('LES ERES 3'),'B12345678','Avenida Real 45','46001','Valencia','01 - LES ERES 3']);
    $id=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO community_aliases(community_id,alias_type,value,normalized_value,active) VALUES (?,?,?,?,1)',[$id,'address','Calle Vieja 1',Salvest\Text::normalize('Calle Vieja 1')]);
    $db->execute('INSERT INTO community_identifiers(community_id,identifier_type,value,normalized_value,active) VALUES (?,?,?,?,1)',[$id,'cups','ES0021000000000001JN',Salvest\Text::normalize('ES0021000000000001JN')]);
    $config=['app'=>['base_url'=>'http://127.0.0.1','timezone'=>'Europe/Madrid','session_name'=>'salvest_test_'.bin2hex(random_bytes(4)),
        'secret_key'=>'test-secret','encryption_key'=>Salvest\Crypto::generateKey(),'cron_token'=>'test','cookie_secure'=>false]];
    // Auth touches session_start()/header() on construction; under the CLI test
    // runner, prior PASS/FAIL lines already "sent output", so PHP warns about
    // session/header changes that are harmless here (no real HTTP response).
    set_error_handler(static fn(int$errno,string$message):bool=>str_contains($message,'session')||str_contains($message,'headers already'));
    $webApp=new Salvest\WebApp($db,$config);
    $_SERVER['REQUEST_METHOD']='GET';$_GET=['edit'=>(string)$id];
    $method=new ReflectionMethod(Salvest\WebApp::class,'communities');$method->setAccessible(true);
    ob_start();$method->invoke($webApp);$html=ob_get_clean();restore_error_handler();
    $assert(str_contains($html,'<input type="hidden" name="address_aliases" value="Calle Vieja 1">'),'el alias existente debe seguir viajando en el formulario, oculto');
    $assert(str_contains($html,'<input type="hidden" name="identifiers" value="cups: ES0021000000000001JN">'),'el identificador existente debe seguir viajando en el formulario, oculto');
    $assert(!str_contains($html,'<textarea name="address_aliases"'),'el campo ya no debe ser editable en el alta/edición cotidiana');
    $assert(!str_contains($html,'<textarea class="mono" name="identifiers"'),'el campo ya no debe ser editable en el alta/edición cotidiana');
    $assert(!str_contains($html,'Otras direcciones conocidas'),'la etiqueta visible debe haber desaparecido');
    $assert(!str_contains($html,'Identificadores de contrato'),'la etiqueta visible debe haber desaparecido');
    $assert(str_contains($html,'name="cif" value="B12345678"') && str_contains($html,'<label class="mono"') === false && str_contains($html,'CIF<input class="mono" name="cif" value="B12345678"'),'el CIF de comunidad sigue siendo editable, no se pidió ocultarlo');
    $still=(new Salvest\Classifier($db))->classify(['cups'=>'ES0021000000000001JN']);
    $assert($still['community']!==null && (int)$still['community']['id']===$id,'el identificador ocultado en la UI debe seguir siendo utilizable por el Classifier');
});
$test('ocultar campos: los valores existentes de proveedor viajan ocultos y no se borran',static function()use($assert,$sqliteDb,$classifierSchema):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO suppliers(official_name,normalized_name,cif,main_service_type_id,active) VALUES (?,?,?,?,1)',
        ['Iberdrola Comercializacion',Salvest\Text::normalize('Iberdrola Comercializacion'),'A12345678',null]);
    $id=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO supplier_aliases(supplier_id,alias_type,value,normalized_value,active) VALUES (?,?,?,?,1)',[$id,'name','iberdrola.es',Salvest\Text::normalize('iberdrola.es')]);
    $config=['app'=>['base_url'=>'http://127.0.0.1','timezone'=>'Europe/Madrid','session_name'=>'salvest_test_'.bin2hex(random_bytes(4)),
        'secret_key'=>'test-secret','encryption_key'=>Salvest\Crypto::generateKey(),'cron_token'=>'test','cookie_secure'=>false]];
    // Auth touches session_start()/header() on construction; under the CLI test
    // runner, prior PASS/FAIL lines already "sent output", so PHP warns about
    // session/header changes that are harmless here (no real HTTP response).
    set_error_handler(static fn(int$errno,string$message):bool=>str_contains($message,'session')||str_contains($message,'headers already'));
    $webApp=new Salvest\WebApp($db,$config);
    $_SERVER['REQUEST_METHOD']='GET';$_GET=['edit'=>(string)$id];
    $method=new ReflectionMethod(Salvest\WebApp::class,'suppliers');$method->setAccessible(true);
    ob_start();$method->invoke($webApp);$html=ob_get_clean();restore_error_handler();
    $assert(str_contains($html,'<input type="hidden" name="cif" value="A12345678">'),'el CIF existente debe seguir viajando en el formulario, oculto');
    $assert(str_contains($html,'<input type="hidden" name="aliases" value="iberdrola.es">'),'el alias existente debe seguir viajando en el formulario, oculto');
    $assert(!str_contains($html,'name="cif" required') && !preg_match('/<label>CIF<input/',$html),'el CIF ya no debe ser editable en el alta/edición cotidiana');
    $assert(!str_contains($html,'<textarea name="aliases"'),'el campo ya no debe ser editable en el alta/edición cotidiana');
    $assert(!str_contains($html,'Otros nombres o dominios conocidos'),'la etiqueta visible debe haber desaparecido');
    $resolved=(new Salvest\Classifier($db))->resolveSupplier(['proveedor'=>'','proveedor_cif'=>'A12345678'],'facturas@otra-cosa.example');
    $assert($resolved!==null && (int)$resolved['id']===$id,'el CIF ocultado en la UI debe seguir siendo utilizable por el Classifier');
    $resolvedByAlias=(new Salvest\Classifier($db))->resolveSupplier(['proveedor'=>'Nombre distinto','proveedor_cif'=>''],'facturas@iberdrola.es');
    $assert($resolvedByAlias!==null && (int)$resolvedByAlias['id']===$id,'el alias/dominio ocultado en la UI debe seguir siendo utilizable por el Classifier');
});
$test('Classifier sigue clasificando por identificador de contrato (CUPS)',static function()use($assert,$sqliteDb,$classifierSchema):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,postal_code,city,imap_folder_name,active) VALUES (?,?,?,?,?,?,?,?,1)',
        ['02','CP DOS',Salvest\Text::normalize('CP DOS'),'','Calle Dos 2','46002','Valencia','02 - CP DOS']);
    $id=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO community_identifiers(community_id,identifier_type,value,normalized_value,active) VALUES (?,?,?,?,1)',[$id,'contract','C-9988',Salvest\Text::normalize('C-9988')]);
    $result=(new Salvest\Classifier($db))->classify(['numero_contrato'=>'C-9988']);
    $assert($result['community']!==null && (int)$result['community']['id']===$id && $result['confidence']===100.0);
});
$test('Classifier sigue clasificando por CIF de comunidad',static function()use($assert,$sqliteDb,$classifierSchema):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,postal_code,city,imap_folder_name,active) VALUES (?,?,?,?,?,?,?,?,1)',
        ['03','CP TRES',Salvest\Text::normalize('CP TRES'),'B99999999','Calle Tres 3','46003','Valencia','03 - CP TRES']);
    $id=(int)$db->pdo()->lastInsertId();
    $result=(new Salvest\Classifier($db))->classify(['comunidad_cif'=>'B99999999']);
    $assert($result['community']!==null && (int)$result['community']['id']===$id && $result['evidence']['field']==='cif');
});
$test('Classifier sigue clasificando comunidades por dirección alias (fuzzy)',static function()use($assert,$sqliteDb,$classifierSchema):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,postal_code,city,imap_folder_name,active) VALUES (?,?,?,?,?,?,?,?,1)',
        ['04','CP CUATRO',Salvest\Text::normalize('CP CUATRO'),'','Calle Falsa 123','46004','Valencia','04 - CP CUATRO']);
    $id=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO community_aliases(community_id,alias_type,value,normalized_value,active) VALUES (?,?,?,?,1)',[$id,'address','Avenida Real 45',Salvest\Text::normalize('Avenida Real 45')]);
    $result=(new Salvest\Classifier($db))->classify(['direccion'=>'Avenida Real 45']);
    $assert($result['community']!==null && (int)$result['community']['id']===$id,'debe encontrar la comunidad a través del alias de dirección, no de la dirección principal');
});

$failed=0;
foreach($tests as $name=>$callback){try{$callback();echo "PASS $name\n";}catch(Throwable $error){$failed++;echo "FAIL $name: {$error->getMessage()}\n";}}
echo sprintf("%d tests, %d failed\n",count($tests),$failed);
exit($failed?1:0);

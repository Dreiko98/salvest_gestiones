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

// Same in-memory Database helper, but for the manual "run-worker" route: it also
// registers fake GET_LOCK/RELEASE_LOCK SQL functions (SQLite has no such thing)
// so Worker's real MySQL locking code can be exercised deterministically.
$workerSchema=<<<SQL
CREATE TABLE mailboxes(id INTEGER PRIMARY KEY AUTOINCREMENT,descriptive_name TEXT,email TEXT,imap_host TEXT,imap_port INTEGER,use_ssl INTEGER,username TEXT,encrypted_password TEXT,input_folder TEXT,active INTEGER DEFAULT 1,process_existing_on_activate INTEGER DEFAULT 0,baseline_uidvalidity TEXT,baseline_uid INTEGER,baseline_captured_at TEXT,last_connection_at TEXT,last_connection_ok INTEGER,last_error TEXT);
CREATE TABLE processing_runs(id INTEGER PRIMARY KEY AUTOINCREMENT,run_uuid TEXT,trigger_type TEXT,triggered_by_user_id INTEGER,started_at TEXT,finished_at TEXT,status TEXT,mailboxes_count INTEGER DEFAULT 0,messages_reviewed INTEGER DEFAULT 0,documents_detected INTEGER DEFAULT 0,classified_count INTEGER DEFAULT 0,unclassified_count INTEGER DEFAULT 0,needs_review_count INTEGER DEFAULT 0,duplicate_count INTEGER DEFAULT 0,error_count INTEGER DEFAULT 0,openai_input_tokens INTEGER DEFAULT 0,openai_output_tokens INTEGER DEFAULT 0,error_message TEXT);
CREATE TABLE audit_log(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,action TEXT,entity_type TEXT,entity_id TEXT,old_values_json TEXT,new_values_json TEXT,ip_address TEXT,created_at TEXT);
CREATE TABLE processed_attachments(id INTEGER PRIMARY KEY AUTOINCREMENT,status TEXT,processed_at TEXT);
CREATE TABLE communities(id INTEGER PRIMARY KEY AUTOINCREMENT,active INTEGER DEFAULT 1);
CREATE TABLE suppliers(id INTEGER PRIMARY KEY AUTOINCREMENT,active INTEGER DEFAULT 1);
SQL;
/** @param 'always-free'|'always-busy'|'free-then-busy' $lockBehavior */
$sqliteDbWithLock=static function(string $lockBehavior='always-free')use($workerSchema):Salvest\Database{
    $pdo=new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    $pdo->exec($workerSchema);
    $calls=0;
    $pdo->sqliteCreateFunction('GET_LOCK',static function()use(&$calls,$lockBehavior):int{
        $calls++;
        return match($lockBehavior){'always-busy'=>0,'free-then-busy'=>$calls===1?1:0,default=>1};
    },2);
    $pdo->sqliteCreateFunction('RELEASE_LOCK',static fn():int=>1,1);
    $pdo->sqliteCreateFunction('NOW',static fn():string=>date('Y-m-d H:i:s'),0);
    $reflection=new ReflectionClass(Salvest\Database::class);
    $db=$reflection->newInstanceWithoutConstructor();
    $property=$reflection->getProperty('pdo');$property->setAccessible(true);$property->setValue($db,$pdo);
    return$db;
};
$workerConfig=static fn():array=>['app'=>['base_url'=>'http://127.0.0.1','timezone'=>'Europe/Madrid','session_name'=>'salvest_test_'.bin2hex(random_bytes(4)),
    'secret_key'=>'test-secret','encryption_key'=>Salvest\Crypto::generateKey(),'cron_token'=>'test','cookie_secure'=>false],
    'openai'=>['api_key'=>'test-key','model'=>'gpt-test','timeout_seconds'=>5],
    'imap'=>['default_host'=>'imap.ionos.es','default_port'=>993,'timeout_seconds'=>5,'max_messages_per_mailbox'=>5],
    'processing'=>['classification_threshold'=>92.0,'max_attachment_bytes'=>1000000,'storage_root'=>sys_get_temp_dir(),'incoming_root'=>sys_get_temp_dir()],
    'google_drive'=>['enabled'=>false]];
/** A real ImapClient with only uidValidity() usable — no socket, never connects. Worker::applyBaseline()
 * only calls that one method, so this is enough to exercise it without a live IMAP server. */
$fakeImapClient=static function(string $uidValidity):Salvest\ImapClient{
    $reflection=new ReflectionClass(Salvest\ImapClient::class);
    $client=$reflection->newInstanceWithoutConstructor();
    $property=$reflection->getProperty('uidValidity');$property->setAccessible(true);$property->setValue($client,$uidValidity);
    return$client;
};
/** @param list<string> $uids @return list<string> */
$applyBaseline=static function(Salvest\Database $db,array $config,array $mailbox,Salvest\ImapClient $client,array $uids):array{
    $worker=Salvest\Worker::create($db,$config);
    $method=new ReflectionMethod(Salvest\Worker::class,'applyBaseline');$method->setAccessible(true);
    return$method->invoke($worker,$mailbox,$client,$uids);
};
/** Calls the private, redirect-free WebApp::saveMailboxFromPost() directly — mailboxes() itself
 * calls exit() on a successful save, which would kill the whole test runner process if we went
 * through the real POST/run() round trip instead. */
$saveMailbox=static function(Salvest\WebApp $webApp,array $post):array{
    set_error_handler(static fn(int$errno,string$message):bool=>str_contains($message,'session')||str_contains($message,'headers already'));
    $_POST=$post;$_SERVER['REQUEST_METHOD']='POST';
    $method=new ReflectionMethod(Salvest\WebApp::class,'saveMailboxFromPost');$method->setAccessible(true);
    $result=$method->invoke($webApp);
    restore_error_handler();
    return$result;
};
/** Auth's constructor touches session_start()/header(); see the note on the disclosure tests above. */
$makeWebApp=static function(Salvest\Database $db,array $config):Salvest\WebApp{
    set_error_handler(static fn(int$errno,string$message):bool=>str_contains($message,'session')||str_contains($message,'headers already'));
    $webApp=new Salvest\WebApp($db,$config);
    restore_error_handler();
    return$webApp;
};
/** Calls WebApp::run() through a fake request exactly like a browser would, capturing the output. */
$requestWebApp=static function(Salvest\WebApp $webApp,string $method,string $route,array $post=[])use($assert):string{
    set_error_handler(static fn(int$errno,string$message):bool=>str_contains($message,'session')||str_contains($message,'headers already'));
    $_SERVER['REQUEST_METHOD']=$method;$_GET=['route'=>$route];$_POST=$post;
    ob_start();$webApp->run();$html=ob_get_clean();
    restore_error_handler();
    return$html;
};

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
    $assert(str_contains($html,'<a class="node-link" href="https://drive.google.com/file/d/file1/view" target="_blank" rel="noopener noreferrer">'),'el pdf con webViewLink debe abrir en una pestaña nueva');
    $assert(str_contains($html,'<span class="node-link">'),'sin webViewLink el envoltorio debe ser un span, no un enlace');
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
$test('explorador Drive: los nombres largos llevan una envoltura truncable, no texto suelto',static function()use($assert):void{
    $longName='2026-08-17_IBERDROLA-COMERCIALIZACION-DE-ULTIMO-RECURSO_FRA-2026-00123456.pdf';
    $html=Salvest\DriveTree::fileNode($longName,null);
    $assert(str_contains($html,'<span class="node-name" title="'.$longName.'">'.$longName.'</span>'),'el nombre debe ir en un span truncable con el nombre completo en title');
    $folderHtml=Salvest\DriveTree::folderNode('f1','Comunidad con un nombre muy muy muy largo',2);
    $assert(str_contains($folderHtml,'<span class="node-name" title="Comunidad con un nombre muy muy muy largo">'),'las carpetas también deben usar la envoltura truncable');
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

$test('ejecutar bot ahora: happy path, usa el mismo Worker y registra trigger_type=manual y el usuario',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp,$requestWebApp):void{
    $db=$sqliteDbWithLock('always-free');
    $webApp=$makeWebApp($db,$workerConfig());
    $_SESSION['user_id']=77;
    $html=$requestWebApp($webApp,'POST','run-worker',['csrf'=>$_SESSION['csrf']]);
    $payload=json_decode($html,true);
    $assert($payload!==null,'la respuesta debe ser JSON válido: '.$html);
    $assert(($payload['status']??null)==='ok','debe reportar éxito sin buzones activos: '.$html);
    $assert(isset($payload['summary']['classified'],$payload['summary']['needs_review'],$payload['summary']['errors']),'debe devolver un resumen de la ejecución');
    $run=$db->one('SELECT * FROM processing_runs ORDER BY id DESC LIMIT 1');
    $assert($run!==null,'debe quedar registrada una ejecución en processing_runs');
    $assert($run['trigger_type']==='manual','el disparo manual debe distinguirse de "cron" en la traza');
    $assert((int)$run['triggered_by_user_id']===77,'debe guardar qué usuario de la sesión lanzó la ejecución');
    $assert($run['status']==='completed','sin buzones activos el ciclo debe completarse sin errores');
});
$test('ejecutar bot ahora: CSRF inválido no debe lanzar el worker',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp,$requestWebApp):void{
    $db=$sqliteDbWithLock('always-free');
    $webApp=$makeWebApp($db,$workerConfig());
    $_SESSION['user_id']=5;
    $html=$requestWebApp($webApp,'POST','run-worker',['csrf'=>'token-invalido']);
    $assert(!str_contains($html,'"status":"ok"'),'un CSRF inválido nunca debe llegar a ejecutar el worker: '.$html);
    $assert(str_contains($html,'La sesión ha caducado'),'debe rechazarse con el mismo mensaje de CSRF que el resto de formularios');
    $assert((int)$db->one('SELECT COUNT(*) n FROM processing_runs')['n']===0,'no debe quedar ninguna ejecución registrada');
});
$test('ejecutar bot ahora: sin sesión no ejecuta nada',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp,$requestWebApp):void{
    $db=$sqliteDbWithLock('always-free');
    $webApp=$makeWebApp($db,$workerConfig());
    unset($_SESSION['user_id']);
    $html=$requestWebApp($webApp,'POST','run-worker',['csrf'=>$_SESSION['csrf']??'']);
    $assert(str_contains($html,'Acceso'),'sin sesión debe devolver la pantalla de acceso, no ejecutar el worker: '.$html);
    $assert(!str_contains($html,'"status":"ok"'));
    $assert((int)$db->one('SELECT COUNT(*) n FROM processing_runs')['n']===0,'no debe quedar ninguna ejecución registrada');
});
$test('ejecutar bot ahora: si el worker ya está bloqueado, responde amigable y no lanza otro',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp,$requestWebApp):void{
    $db=$sqliteDbWithLock('always-busy');
    $webApp=$makeWebApp($db,$workerConfig());
    $_SESSION['user_id']=9;
    $html=$requestWebApp($webApp,'POST','run-worker',['csrf'=>$_SESSION['csrf']]);
    $payload=json_decode($html,true);
    $assert($payload!==null && ($payload['status']??null)==='busy','debe responder con estado "busy" cuando el lock ya está tomado: '.$html);
    $assert(!preg_match('/GET_LOCK|RuntimeException|Salvest\\\\\\\\Worker/',$payload['message']??''),'el mensaje debe ser amigable, no un detalle técnico');
    $assert((int)$db->one('SELECT COUNT(*) n FROM processing_runs')['n']===0,'no debe crear una segunda ejecución mientras la otra está en marcha');
});
$test('ejecutar bot ahora: doble clic / doble petición solo lanza una ejecución',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp,$requestWebApp):void{
    $db=$sqliteDbWithLock('free-then-busy');
    $webApp=$makeWebApp($db,$workerConfig());
    $_SESSION['user_id']=3;
    $csrf=$_SESSION['csrf'];
    $first=json_decode($requestWebApp($webApp,'POST','run-worker',['csrf'=>$csrf]),true);
    $second=json_decode($requestWebApp($webApp,'POST','run-worker',['csrf'=>$csrf]),true);
    $assert(($first['status']??null)==='ok','la primera petición debe completarse con éxito');
    $assert(($second['status']??null)==='busy','la segunda petición casi simultánea debe rechazarse, no relanzar el worker');
    $assert((int)$db->one('SELECT COUNT(*) n FROM processing_runs')['n']===1,'pese a las dos peticiones solo debe quedar registrada una ejecución');
});
$test('estado del bot: el dashboard muestra la última ejecución sin detalles técnicos',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp):void{
    $db=$sqliteDbWithLock('always-free');
    $webApp=$makeWebApp($db,$workerConfig());
    $db->execute('INSERT INTO processing_runs(run_uuid,trigger_type,started_at,finished_at,status,classified_count,needs_review_count,error_count) VALUES (?,?,?,?,?,?,?,?)',
        ['uuid-1','manual',date('Y-m-d H:i:s'),date('Y-m-d H:i:s'),'completed',2,1,0]);
    $method=new ReflectionMethod(Salvest\WebApp::class,'botStatusCard');$method->setAccessible(true);
    $html=$method->invoke($webApp);
    $assert(str_contains($html,'2 archivadas'),'debe mostrar cuántas facturas se archivaron: '.$html);
    $assert(str_contains($html,'1 pendiente'),'debe mostrar cuántas quedaron pendientes, en singular cuando es 1: '.$html);
    $assert(str_contains($html,'0 errores'),'debe mostrar cuántas fallaron: '.$html);
    $assert(str_contains($html,'Última ejecución: <strong>hoy '),'debe mostrar cuándo fue la última ejecución');
    $assert(str_contains($html,'Ejecutar bot ahora'),'el botón debe estar disponible cuando no hay nada en marcha');
    $assert(!preg_match('/\bdisabled\b/',$html),'el botón no debe estar deshabilitado si no hay ninguna ejecución en curso');
    $assert(!preg_match('/api[_-]?key|stack trace|Fatal error|uidvalidity/i',$html),'no debe filtrar detalles técnicos a la interfaz normal');
});
$test('estado del bot: mientras se ejecuta se deshabilita el botón y se avisa',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp):void{
    $db=$sqliteDbWithLock('always-free');
    $webApp=$makeWebApp($db,$workerConfig());
    $db->execute('INSERT INTO processing_runs(run_uuid,trigger_type,started_at,status) VALUES (?,?,?,?)',['uuid-running','cron',date('Y-m-d H:i:s'),'running']);
    $method=new ReflectionMethod(Salvest\WebApp::class,'botStatusCard');$method->setAccessible(true);
    $html=$method->invoke($webApp);
    $assert(str_contains($html,'En ejecución'),'debe indicar que ya hay un ciclo en marcha');
    $assert(str_contains($html,'Bot ejecutándose'),'el botón debe reflejar el estado de ejecución en curso');
    $assert((bool)preg_match('/\bdisabled\b/',$html),'el botón debe estar deshabilitado mientras hay una ejecución en curso');
});

$test('baseline: buzón con 1.000 correos existentes + uno nuevo, solo procesa el nuevo',static function()use($assert,$sqliteDbWithLock,$workerConfig,$fakeImapClient,$applyBaseline):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();
    $mailbox=['id'=>1,'process_existing_on_activate'=>0,'baseline_uidvalidity'=>'1001','baseline_uid'=>1000,'baseline_captured_at'=>date('Y-m-d H:i:s')];
    $uids=array_map('strval',range(1,1000));$uids[]='1001';
    $result=$applyBaseline($db,$config,$mailbox,$fakeImapClient('1001'),$uids);
    $assert($result===['1001'],'solo el UID posterior al baseline debe sobrevivir al filtro: '.json_encode($result));
});
$test('baseline: no depende de UNSEEN, un correo ya leído se procesa igual (guarda de regresión del comando IMAP)',static function()use($assert):void{
    $source=file_get_contents(__DIR__.'/../src/ImapClient.php');
    $assert(str_contains($source,"UID SEARCH ALL"),'listUids() debe usar SEARCH ALL para no depender de si el correo ya se marcó como leído');
    $assert(!str_contains($source,'UNSEEN'),'no debe aparecer UNSEEN en ninguna parte del cliente IMAP');
});
$test('baseline: buzón vacío al crear, el primer correo que llega después se procesa',static function()use($assert,$sqliteDbWithLock,$workerConfig,$fakeImapClient,$applyBaseline):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();
    $db->execute('INSERT INTO mailboxes(descriptive_name,email,active,process_existing_on_activate) VALUES (?,?,1,0)',['Prueba','prueba@example.com']);
    $id=(int)$db->pdo()->lastInsertId();
    $mailboxRow=$db->one('SELECT * FROM mailboxes WHERE id=?',[$id]);
    $firstCycle=$applyBaseline($db,$config,$mailboxRow,$fakeImapClient('555'),[]);
    $assert($firstCycle===[],'una bandeja vacía al dar de alta no debe procesar nada en el ciclo de captura del baseline');
    $stored=$db->one('SELECT baseline_uid,baseline_uidvalidity,baseline_captured_at FROM mailboxes WHERE id=?',[$id]);
    $assert((int)$stored['baseline_uid']===0 && $stored['baseline_uidvalidity']==='555' && $stored['baseline_captured_at']!==null,'debe quedar grabado un baseline en 0 aunque la bandeja estuviera vacía');
    $mailboxRow2=$db->one('SELECT * FROM mailboxes WHERE id=?',[$id]);
    $secondCycle=$applyBaseline($db,$config,$mailboxRow2,$fakeImapClient('555'),['1']);
    $assert($secondCycle===['1'],'el primer correo que llega tras el alta sí debe procesarse: '.json_encode($secondCycle));
});
$test('baseline: "procesar correos existentes al activar" activado procesa el histórico completo',static function()use($assert,$sqliteDbWithLock,$workerConfig,$fakeImapClient,$applyBaseline):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();
    $mailbox=['id'=>1,'process_existing_on_activate'=>1,'baseline_uidvalidity'=>null,'baseline_uid'=>null,'baseline_captured_at'=>null];
    $uids=['1','2','3'];
    $result=$applyBaseline($db,$config,$mailbox,$fakeImapClient('9'),$uids);
    $assert($result===$uids,'con la opción activada debe devolver todos los UID sin filtrar, igual que hoy');
    $assert($db->one('SELECT COUNT(*) n FROM mailboxes')['n']===0,'no debe escribir ningún baseline mientras esta opción esté activada');
});
$test('baseline: un cambio de UIDVALIDITY invalida el baseline anterior y no reprocesa histórico a ciegas',static function()use($assert,$sqliteDbWithLock,$workerConfig,$fakeImapClient,$applyBaseline):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();
    $db->execute('INSERT INTO mailboxes(descriptive_name,email,active,process_existing_on_activate,baseline_uidvalidity,baseline_uid,baseline_captured_at) VALUES (?,?,1,0,?,?,?)',
        ['Prueba','prueba2@example.com','1001',500,date('Y-m-d H:i:s')]);
    $id=(int)$db->pdo()->lastInsertId();
    $mailboxRow=$db->one('SELECT * FROM mailboxes WHERE id=?',[$id]);
    // El servidor renumeró la carpeta: UIDVALIDITY nueva, y llegan UIDs que bajo la validity vieja
    // parecerían "antiguos" (1, 2) mezclados con lo que sea que el servidor tenga ahora.
    $result=$applyBaseline($db,$config,$mailboxRow,$fakeImapClient('2002'),['1','2','999']);
    $assert($result===[],'ante un cambio de UIDVALIDITY no debe reprocesar el histórico existente a ciegas');
    $stored=$db->one('SELECT baseline_uidvalidity,baseline_uid FROM mailboxes WHERE id=?',[$id]);
    $assert($stored['baseline_uidvalidity']==='2002' && (int)$stored['baseline_uid']===999,'debe re-capturar el baseline bajo la nueva UIDVALIDITY, no conservar la antigua');
    $mailboxRow2=$db->one('SELECT * FROM mailboxes WHERE id=?',[$id]);
    $nextCycle=$applyBaseline($db,$config,$mailboxRow2,$fakeImapClient('2002'),['1','2','999','1000']);
    $assert($nextCycle===['1000'],'tras la re-captura, solo lo posterior al nuevo baseline debe procesarse: '.json_encode($nextCycle));
});
$test('baseline: convive con la deduplicación existente por mailbox_id+UIDVALIDITY+UID (guarda de regresión de código)',static function()use($assert):void{
    $source=file_get_contents(__DIR__.'/../src/Worker.php');
    $assert(str_contains($source,'applyBaseline($mailbox, $client, $client->listUids())'),'el filtro de baseline debe aplicarse sobre el listado de UIDs antes del bucle de mensajes');
    $assert(str_contains($source,"SELECT status FROM processed_messages WHERE mailbox_id=? AND uidvalidity=? AND message_uid=?"),'la deduplicación por mailbox_id+UIDVALIDITY+UID debe seguir intacta después del filtro de baseline');
    $assert(str_contains($source,"in_array(\$existing['status'],['completed','ignored','needs_review','error'],true)) continue;"),'solo los estados terminales deben saltarse; el baseline no sustituye a esta comprobación');
});
$test('baseline: el límite max_messages_per_mailbox se sigue aplicando tras el filtro de baseline (guarda de regresión de código)',static function()use($assert):void{
    $source=file_get_contents(__DIR__.'/../src/Worker.php');
    $assert((bool)preg_match('/applyBaseline\(\$mailbox, \$client, \$client->listUids\(\)\); \$examined = 0;/',$source),'el contador de mensajes examinados debe arrancar en 0 después de aplicar el baseline, no antes');
    $assert(str_contains($source,'if ($limit !== null && $examined >= $limit) break;'),'el corte por límite debe seguir activo tras el filtro de baseline');
});

// Helper to call the private, pure WebApp::mailboxCaptureDecision() without needing an instance.
$captureDecision=static function(?int $priorProcessExisting,bool $hadBaseline,int $processExisting,int $active):array{
    $method=new ReflectionMethod(Salvest\WebApp::class,'mailboxCaptureDecision');$method->setAccessible(true);
    return$method->invoke(null,$priorProcessExisting,$hadBaseline,$processExisting,$active);
};

$test('activación: crear un buzón protegido y activo debe capturar baseline al guardar (sin esperar al Worker)',static function()use($assert,$captureDecision):void{
    $decision=$captureDecision(null,false,0,1);
    $assert($decision['mustCapture']===true,'un buzón nuevo, activo y protegido debe capturar baseline en el propio guardado');
    $assert($decision['transitioned1to0']===false,'no es una transición 1→0, es alta nueva');
});
$test('activación: un buzón protegido que se guarda desactivado no captura todavía',static function()use($assert,$captureDecision):void{
    $decision=$captureDecision(null,false,0,0);
    $assert($decision['mustCapture']===false,'si no se activa, la captura se difiere hasta que de verdad pase a estar activo');
});
$test('activación: con 1.000 correos ya existentes, el baseline capturado es exactamente 1.000',static function()use($assert):void{
    $uids=array_map('strval',range(1,1000));
    $baseline=Salvest\MailboxBaseline::fromUids('9001',$uids);
    $assert($baseline===['uidvalidity'=>'9001','uid'=>1000],'el punto de corte debe ser el UID más alto existente en el momento de activar: '.json_encode($baseline));
});
$test('activación: buzón vacío al crear, el baseline capturado es 0',static function()use($assert):void{
    $assert(Salvest\MailboxBaseline::fromUids('9001',[])===['uidvalidity'=>'9001','uid'=>0]);
});
$test('activación: el UID 1001 que llega antes del primer Worker sí se procesa (baseline capturado al activar + primer ciclo)',static function()use($assert,$sqliteDbWithLock,$workerConfig,$fakeImapClient,$applyBaseline):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();
    // Lo que WebApp::mailboxes() ya habría hecho al activar el buzón, antes de que corriera ningún Worker:
    $baselineAtActivation=Salvest\MailboxBaseline::fromUids('9001',array_map('strval',range(1,1000)));
    $db->execute('INSERT INTO mailboxes(descriptive_name,email,active,process_existing_on_activate,baseline_uidvalidity,baseline_uid,baseline_captured_at) VALUES (?,?,1,0,?,?,?)',
        ['Prueba','activado@example.com',$baselineAtActivation['uidvalidity'],$baselineAtActivation['uid'],date('Y-m-d H:i:s')]);
    $id=(int)$db->pdo()->lastInsertId();
    $mailboxRow=$db->one('SELECT * FROM mailboxes WHERE id=?',[$id]);
    // Antes de que corra el primer Worker ya llegó el correo 1001 real (no histórico).
    $uidsEnElPrimerCiclo=array_map('strval',range(1,1000));$uidsEnElPrimerCiclo[]='1001';
    $result=$applyBaseline($db,$config,$mailboxRow,$fakeImapClient('9001'),$uidsEnElPrimerCiclo);
    $assert($result===['1001'],'el correo llegado tras la activación debe procesarse aunque el Worker todavía no hubiera corrido nunca: '.json_encode($result));
});
$test('activación: si la captura de baseline falla, el código nunca deja el buzón activo (guarda de regresión de código)',static function()use($assert):void{
    $source=file_get_contents(__DIR__.'/../src/WebApp.php');
    $catchStart=strpos($source,'error_log(\'mailbox_baseline_capture');
    $assert($catchStart!==false,'debe existir un catch específico alrededor de la captura de baseline');
    $catchBlock=substr($source,$catchStart,400);
    $assert(str_contains($catchBlock,'$active=0;'),'si falla la captura, active debe forzarse a 0 antes de guardar, nunca dejar un buzón protegido activo sin baseline válido');
    $assert((bool)preg_match('/\$formError=.[^;]*;/',$catchBlock),'debe quedar un mensaje amigable para mostrar en el formulario, sin detalles técnicos del error IMAP');
    $insertPos=strpos($source,'INSERT INTO mailboxes($columns)');
    $assert($insertPos!==false && $insertPos>$catchStart,'la captura (y el posible active=0 si falla) debe decidirse antes de escribir la fila, no después');
});
$test('edición: la casilla refleja el valor persistido y modificar otro campo no la cambia',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp,$saveMailbox):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    $db->execute("INSERT INTO mailboxes(descriptive_name,email,imap_host,imap_port,use_ssl,username,encrypted_password,active,process_existing_on_activate) VALUES (?,?,?,?,1,?,?,1,1)",
        ['Nombre antiguo','buzon@example.com','imap.gmail.com',993,'buzon@example.com','cifrado-falso']);
    $id=(int)$db->pdo()->lastInsertId();
    $result=$saveMailbox($webApp,['id'=>(string)$id,'provider'=>'gmail','name'=>'Nombre nuevo','email'=>'buzon@example.com','password'=>'','active'=>'1','process_existing'=>'1']);
    $assert($result['formError']==='','no debería haber fallado nada: '.$result['formError']);
    $row=$db->one('SELECT * FROM mailboxes WHERE id=?',[$id]);
    $assert($row['descriptive_name']==='Nombre nuevo','el nombre sí debe actualizarse');
    $assert((int)$row['process_existing_on_activate']===1,'la opción avanzada no debe cambiar solo por editar otro campo, y seguía marcada');
    $assert($row['baseline_captured_at']===null,'un buzón con la opción activada nunca debe llevar baseline');
});
$test('edición: transición 0→0 no recaptura innecesariamente en cada edición',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp,$saveMailbox):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    $capturedAt='2026-08-10 09:00:00';
    $db->execute("INSERT INTO mailboxes(descriptive_name,email,imap_host,imap_port,use_ssl,username,encrypted_password,active,process_existing_on_activate,baseline_uidvalidity,baseline_uid,baseline_captured_at) VALUES (?,?,?,?,1,?,?,1,0,?,?,?)",
        ['Nombre antiguo','protegido@example.com','imap.gmail.com',993,'protegido@example.com','cifrado-falso','500',250,$capturedAt]);
    $id=(int)$db->pdo()->lastInsertId();
    $result=$saveMailbox($webApp,['id'=>(string)$id,'provider'=>'gmail','name'=>'Nombre nuevo','email'=>'protegido@example.com','password'=>'','active'=>'1']);
    $assert($result['formError']==='','no debería intentar reconectar ni fallar: '.$result['formError']);
    $row=$db->one('SELECT * FROM mailboxes WHERE id=?',[$id]);
    $assert((int)$row['process_existing_on_activate']===0,'sigue protegido');
    $assert($row['baseline_uidvalidity']==='500' && (int)$row['baseline_uid']===250 && $row['baseline_captured_at']===$capturedAt,'el baseline existente no debe tocarse en una edición que no cambia la protección: '.json_encode($row));
});
$test('edición: transición 1→0 recaptura el UID máximo actual, no reutiliza un baseline histórico',static function()use($assert,$captureDecision):void{
    $decision=$captureDecision(1,false,0,1);
    $assert($decision['transitioned1to0']===true && $decision['mustCapture']===true,'pasar de "procesar histórico" a protegido debe forzar una captura nueva en ese mismo instante');
    $decisionIncludingHadBaseline=$captureDecision(1,true,0,0);
    $assert($decisionIncludingHadBaseline['mustCapture']===true,'debe recapturar aunque hubiera quedado algún baseline antiguo de una protección previa, y aunque el buzón se guarde inactivo');
});
$test('edición: transición 0→1 permite procesar el histórico sin necesitar captura',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp,$saveMailbox,$fakeImapClient,$applyBaseline,$captureDecision):void{
    $decision=$captureDecision(0,true,1,1);
    $assert($decision['mustCapture']===false,'activar "procesar histórico" no necesita capturar nada, el Worker simplemente dejará de filtrar');
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    $db->execute("INSERT INTO mailboxes(descriptive_name,email,imap_host,imap_port,use_ssl,username,encrypted_password,active,process_existing_on_activate,baseline_uidvalidity,baseline_uid,baseline_captured_at) VALUES (?,?,?,?,1,?,?,1,0,?,?,?)",
        ['Nombre','historico@example.com','imap.gmail.com',993,'historico@example.com','cifrado-falso','500',250,'2026-08-10 09:00:00']);
    $id=(int)$db->pdo()->lastInsertId();
    $result=$saveMailbox($webApp,['id'=>(string)$id,'provider'=>'gmail','name'=>'Nombre','email'=>'historico@example.com','password'=>'','active'=>'1','process_existing'=>'1']);
    $assert($result['formError']==='','no debería fallar: '.$result['formError']);
    $row=$db->one('SELECT * FROM mailboxes WHERE id=?',[$id]);
    $assert((int)$row['process_existing_on_activate']===1,'la opción debe quedar activada');
    $result=$applyBaseline($db,$config,$row,$fakeImapClient('500'),['1','2','999']);
    $assert($result===['1','2','999'],'con la opción activada el Worker ya no debe filtrar nada, ni siquiera lo anterior al viejo baseline: '.json_encode($result));
});

$failed=0;
foreach($tests as $name=>$callback){try{$callback();echo "PASS $name\n";}catch(Throwable $error){$failed++;echo "FAIL $name: {$error->getMessage()}\n";}}
echo sprintf("%d tests, %d failed\n",count($tests),$failed);
exit($failed?1:0);

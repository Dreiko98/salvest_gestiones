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
CREATE TABLE community_suppliers(id INTEGER PRIMARY KEY AUTOINCREMENT,community_id INTEGER,supplier_id INTEGER,category TEXT,contract_reference TEXT);
SQL;

// Same in-memory Database helper, but for the manual "run-worker" route: it also
// registers fake GET_LOCK/RELEASE_LOCK SQL functions (SQLite has no such thing)
// so Worker's real MySQL locking code can be exercised deterministically.
$workerSchema=<<<SQL
CREATE TABLE mailboxes(id INTEGER PRIMARY KEY AUTOINCREMENT,descriptive_name TEXT,email TEXT,imap_host TEXT,imap_port INTEGER,use_ssl INTEGER,username TEXT,encrypted_password TEXT,input_folder TEXT,active INTEGER DEFAULT 1,process_existing_on_activate INTEGER DEFAULT 0,baseline_uidvalidity TEXT,baseline_uid INTEGER,baseline_captured_at TEXT,last_connection_at TEXT,last_connection_ok INTEGER,last_error TEXT);
CREATE TABLE processing_runs(id INTEGER PRIMARY KEY AUTOINCREMENT,run_uuid TEXT,trigger_type TEXT,triggered_by_user_id INTEGER,started_at TEXT,finished_at TEXT,status TEXT,mailboxes_count INTEGER DEFAULT 0,messages_reviewed INTEGER DEFAULT 0,documents_detected INTEGER DEFAULT 0,classified_count INTEGER DEFAULT 0,unclassified_count INTEGER DEFAULT 0,needs_review_count INTEGER DEFAULT 0,duplicate_count INTEGER DEFAULT 0,error_count INTEGER DEFAULT 0,openai_input_tokens INTEGER DEFAULT 0,openai_output_tokens INTEGER DEFAULT 0,error_message TEXT);
CREATE TABLE audit_log(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,action TEXT,entity_type TEXT,entity_id TEXT,old_values_json TEXT,new_values_json TEXT,ip_address TEXT,created_at TEXT);
CREATE TABLE processed_attachments(id INTEGER PRIMARY KEY AUTOINCREMENT,mailbox_id INTEGER,uidvalidity TEXT,message_uid TEXT,original_filename TEXT,attachment_sha256 TEXT,mime_type TEXT,size_bytes INTEGER,status TEXT,processed_at TEXT,community_id INTEGER,provider TEXT,raw_supplier_name TEXT,provider_cif TEXT,service_type TEXT,supply_address TEXT,amount TEXT,currency TEXT,invoice_number TEXT,invoice_date TEXT,confidence TEXT,final_filename TEXT,output_path TEXT,extraction_json TEXT,decision_json TEXT,debug_trace_json TEXT,requeued_at TEXT,error_message TEXT,extractor_version TEXT,drive_file_id TEXT,drive_path TEXT,drive_status TEXT,UNIQUE(mailbox_id,uidvalidity,message_uid,attachment_sha256));
CREATE TABLE processed_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,mailbox_id INTEGER,uidvalidity TEXT,message_uid TEXT,message_id_header TEXT,sender TEXT,subject TEXT,received_at TEXT,status TEXT,document_count INTEGER DEFAULT 0,imap_destination TEXT,imap_move_status TEXT,error_message TEXT,processed_at TEXT);
CREATE TABLE communities(id INTEGER PRIMARY KEY AUTOINCREMENT,official_name TEXT,active INTEGER DEFAULT 1);
CREATE TABLE suppliers(id INTEGER PRIMARY KEY AUTOINCREMENT,official_name TEXT,active INTEGER DEFAULT 1);
CREATE TABLE service_types(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,normalized_name TEXT,active INTEGER DEFAULT 1);
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
/** Seeds one mailbox + one processed_messages row + N processed_attachments rows sharing the
 * same (mailbox_id,uidvalidity='1001',message_uid='500') for InboxRequeue tests.
 * @param list<string> $attachmentStatuses @return array{mailboxId:int,messageId:int,attachmentIds:list<int>} */
$seedRequeueFixture=static function(Salvest\Database $db,array $attachmentStatuses,string $imapMoveStatus='moved',?string $messageIdHeader='<msg-1@example.com>'):array{
    $db->execute('INSERT INTO mailboxes(descriptive_name,email,imap_host,imap_port,use_ssl,username,encrypted_password,input_folder,active) VALUES (?,?,?,?,?,?,?,?,1)',
        ['Test','buzon@example.com','imap.example.com',993,1,'buzon@example.com','ignored-in-these-tests','INBOX']);
    $mailboxId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO processed_messages(mailbox_id,uidvalidity,message_uid,message_id_header,sender,subject,received_at,status,document_count,imap_destination,imap_move_status,processed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        [$mailboxId,'1001','500',$messageIdHeader,'facturas@proveedor.example','Factura',date('Y-m-d H:i:s'),'needs_review',count($attachmentStatuses),'Facturas/Pendientes de revisión',$imapMoveStatus,date('Y-m-d H:i:s')]);
    $messageId=(int)$db->pdo()->lastInsertId();
    $attachmentIds=[];
    foreach($attachmentStatuses as $index=>$status){
        $db->execute('INSERT INTO processed_attachments(mailbox_id,uidvalidity,message_uid,original_filename,attachment_sha256,mime_type,size_bytes,status,processed_at,debug_trace_json) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$mailboxId,'1001','500','adjunto-'.$index.'.pdf','sha-'.$index,'application/pdf',1000,$status,date('Y-m-d H:i:s'),$status==='needs_review'?'[{"step":"document","data":{"filename":"adjunto-'.$index.'.pdf"}}]':null]);
        $attachmentIds[]=(int)$db->pdo()->lastInsertId();
    }
    return ['mailboxId'=>$mailboxId,'messageId'=>$messageId,'attachmentIds'=>$attachmentIds];
};
/** A stub satisfying the four methods InboxRequeue actually calls on whatever its IMAP client
 * factory returns — no real socket, no real ImapClient (final, can't be subclassed to fake). */
$fakeRequeueImapClient=static function(array $findResult,?\Throwable $throwOnConnect=null):object{
    return new class($findResult,$throwOnConnect){
        public array $moved=[];
        public function __construct(private array $findResult,private ?\Throwable $throwOnConnect){}
        public function connect():void{if($this->throwOnConnect)throw $this->throwOnConnect;}
        public function findUidsByMessageId(string $messageId):array{return $this->findResult;}
        public function move(string $uid,string $destination):void{$this->moved=['uid'=>$uid,'destination'=>$destination];}
        public function close():void{}
    };
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
    $assert($resolved['supplier']!==null && (int)$resolved['supplier']['id']===$id,'el CIF ocultado en la UI debe seguir siendo utilizable por el Classifier');
    $resolvedByAlias=(new Salvest\Classifier($db))->resolveSupplier(['proveedor'=>'Nombre distinto','proveedor_cif'=>''],'facturas@iberdrola.es');
    $assert($resolvedByAlias['supplier']!==null && (int)$resolvedByAlias['supplier']['id']===$id,'el alias/dominio ocultado en la UI debe seguir siendo utilizable por el Classifier');
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
    $assert($result['community']!==null && (int)$result['community']['id']===$id && $result['evidence']['field']==='holder_cif');
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

$test('caso Iberdrola: MySQL corrige el "Agua" incorrecto de OpenAI usando CIF titular + CIF proveedor + tipo configurado',static function()use($assert,$sqliteDb,$classifierSchema):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,postal_code,city,imap_folder_name,active) VALUES (?,?,?,?,?,?,?,?,1)',
        ['23','PK ILLES BALEARS 23',Salvest\Text::normalize('PK ILLES BALEARS 23'),'H12645537','Illes Balears 23','07001','Palma','23 - PK ILLES BALEARS 23']);
    $communityId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO service_types(name,normalized_name,active) VALUES (?,?,1)',['ELECTRICIDAD',Salvest\Text::normalize('ELECTRICIDAD')]);
    $serviceTypeId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO service_types(name,normalized_name,active) VALUES (?,?,1)',['AGUA',Salvest\Text::normalize('AGUA')]);
    $db->execute('INSERT INTO suppliers(official_name,normalized_name,cif,main_service_type_id,active) VALUES (?,?,?,?,1)',
        ['IBERDROLA CLIENTES, S.A.U.',Salvest\Text::normalize('IBERDROLA CLIENTES, S.A.U.'),'A95758389',$serviceTypeId]);
    $supplierId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO community_suppliers(community_id,supplier_id,category,contract_reference) VALUES (?,?,?,?)',[$communityId,$supplierId,'ELECTRICIDAD','763322520']);
    // Trampa: un identificador de CUPS mal cargado que apuntaría a otra comunidad si el
    // CIF titular no tuviera prioridad sobre los identificadores contractuales.
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,postal_code,city,imap_folder_name,active) VALUES (?,?,?,?,?,?,?,?,1)',
        ['99','COMUNIDAD SEÑUELO',Salvest\Text::normalize('COMUNIDAD SEÑUELO'),'X00000000','Otra calle','28001','Madrid','99 - SEÑUELO']);
    $decoyId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO community_identifiers(community_id,identifier_type,value,normalized_value,active) VALUES (?,?,?,?,1)',
        [$decoyId,'cups','ES0021000011499086BA',Salvest\Text::normalize('ES0021000011499086BA')]);

    $invoice=['proveedor'=>'IBERDROLA CLIENTES, S.A.U.','proveedor_cif'=>'A95758389','nombre_comunidad'=>'PK ILLES BALEARS 23',
        'comunidad_cif'=>'H12645537','direccion'=>'','cups'=>'ES0021000011499086BA','numero_contrato'=>'763322520',
        'tipo_servicio'=>'agua','fecha_factura'=>'2026-08-01','importe'=>123.45,'numero_factura'=>'F-2026-001'];
    $route=(new Salvest\InvoiceRouter(new Salvest\Classifier($db)))->route($invoice,'facturas@iberdrola.es');

    $assert($route['status']==='classified','el caso real debe quedar clasificado automáticamente: '.json_encode($route));
    $assert((int)$route['decision']['community']['id']===$communityId,'la comunidad debe ser PK ILLES BALEARS 23, no la del CUPS señuelo');
    $assert($route['decision']['evidence']['field']==='holder_cif','la comunidad debe resolverse por CIF titular, con prioridad sobre CUPS/contrato');
    $assert((int)$route['supplier']['id']===$supplierId && $route['supplier']['official_name']==='IBERDROLA CLIENTES, S.A.U.','el proveedor debe ser Iberdrola');
    $assert($route['evidence']['supplier']['field']==='supplier_cif','el proveedor debe resolverse por su propio CIF, nunca el de la comunidad');
    $assert($route['service']==='ELECTRICIDAD','MySQL debe corregir el "agua" erróneo de OpenAI: '.$route['service']);
    $assert($route['evidence']['service']['field']==='supplier_main_service_type','el servicio debe venir del tipo configurado del proveedor, no de la sugerencia de OpenAI');
    $assert($route['reason']===null,'un caso correctamente asociado no debe llevar motivo de revisión');
});
$test('proveedor reconocido pero no asociado a la comunidad: va a revisión, no se fuerza el archivado',static function()use($assert,$sqliteDb,$classifierSchema):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,postal_code,city,imap_folder_name,active) VALUES (?,?,?,?,?,?,?,?,1)',
        ['30','CP TREINTA',Salvest\Text::normalize('CP TREINTA'),'H99999999','Calle Treinta','46030','Valencia','30 - CP TREINTA']);
    $communityId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO service_types(name,normalized_name,active) VALUES (?,?,1)',['ELECTRICIDAD',Salvest\Text::normalize('ELECTRICIDAD')]);
    $serviceTypeId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO suppliers(official_name,normalized_name,cif,main_service_type_id,active) VALUES (?,?,?,?,1)',
        ['IBERDROLA CLIENTES, S.A.U.',Salvest\Text::normalize('IBERDROLA CLIENTES, S.A.U.'),'A95758389',$serviceTypeId]);
    // Nótese: no hay fila en community_suppliers vinculando a este proveedor con esta comunidad.
    $invoice=['proveedor'=>'IBERDROLA CLIENTES, S.A.U.','proveedor_cif'=>'A95758389','comunidad_cif'=>'H99999999','tipo_servicio'=>'agua'];
    $route=(new Salvest\InvoiceRouter(new Salvest\Classifier($db)))->route($invoice,'facturas@iberdrola.es');
    $assert($route['status']==='needs_review','un proveedor real pero no asociado nunca debe clasificarse automáticamente: '.json_encode($route));
    $assert($route['supplier']===null,'no debe forzarse un proveedor no asociado a la comunidad resuelta');
    $assert($route['reason']==='Proveedor reconocido pero no asociado a esta comunidad.','debe quedar el motivo exacto para el panel de revisión');
});

/** Shared fixture: a community plus one supplier linked to it, no CIF anywhere unless given. */
$makeCommunityWithSupplier=static function(Salvest\Database $db,string $communityCode,string $communityName,string $supplierOfficialName,string $serviceTypeName,?string $supplierCif=null,?string $communityCif=null):array{
    $communityCif??=$communityCode.'-CIF';
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,postal_code,city,imap_folder_name,active) VALUES (?,?,?,?,?,?,?,?,1)',
        [$communityCode,$communityName,Salvest\Text::normalize($communityName),$communityCif,'Calle '.$communityName,'46000','Valencia',$communityCode.' - '.$communityName]);
    $communityId=(int)$db->pdo()->lastInsertId();
    $service=$db->one('SELECT id FROM service_types WHERE name=?',[$serviceTypeName]);
    if(!$service){$db->execute('INSERT INTO service_types(name,normalized_name,active) VALUES (?,?,1)',[$serviceTypeName,Salvest\Text::normalize($serviceTypeName)]);$serviceTypeId=(int)$db->pdo()->lastInsertId();}
    else $serviceTypeId=(int)$service['id'];
    $db->execute('INSERT INTO suppliers(official_name,normalized_name,cif,main_service_type_id,active) VALUES (?,?,?,?,1)',
        [$supplierOfficialName,Salvest\Text::normalize($supplierOfficialName),$supplierCif,$serviceTypeId]);
    $supplierId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO community_suppliers(community_id,supplier_id,category,contract_reference) VALUES (?,?,?,?)',[$communityId,$supplierId,$serviceTypeName,null]);
    return['communityId'=>$communityId,'supplierId'=>$supplierId,'communityCif'=>$communityCif];
};

$test('resolución contextual: IBERDROLA (sin CIF) reconoce "IBERDROLA CLIENTES, S.A.U." por contención de nombre',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'23','PK ILLES BALEARS 23','IBERDROLA','ELECTRICIDAD');
    $invoice=['proveedor'=>'IBERDROLA CLIENTES, S.A.U.','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'agua'];
    $route=(new Salvest\InvoiceRouter(new Salvest\Classifier($db)))->route($invoice,'facturas@iberdrola.es');
    $assert($route['status']==='classified','el caso real (sin CIF de proveedor en el maestro) debe clasificar solo: '.json_encode($route));
    $assert((int)$route['supplier']['id']===$fixture['supplierId'],'debe resolver al proveedor IBERDROLA');
    $assert($route['evidence']['supplier']['field']==='proveedor'&&$route['evidence']['supplier']['type']==='name_containment','debe quedar constancia de que fue por contención de nombre, no CIF');
    $assert($route['service']==='ELECTRICIDAD','debe corregir el "agua" de OpenAI usando el tipo configurado del proveedor: '.$route['service']);
    $assert($route['evidence']['service']['field']==='supplier_main_service_type');
});
$test('resolución contextual: IBERDROLA vs "IBERDROLA CLIENTES" sin forma societaria también contiene el nombre',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'24','CP VEINTICUATRO','IBERDROLA','ELECTRICIDAD');
    $invoice=['proveedor'=>'IBERDROLA CLIENTES','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'desconocido'];
    $route=(new Salvest\InvoiceRouter(new Salvest\Classifier($db)))->route($invoice,'facturas@iberdrola.es');
    $assert($route['status']==='classified' && (int)$route['supplier']['id']===$fixture['supplierId'],json_encode($route));
});
$test('resolución contextual: formas societarias distintas (maestro S.L., factura S.A.) igualan por nombre comercial',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'25','CP VEINTICINCO','AGUAS DEL LEVANTE, S.L.','AGUA');
    $invoice=['proveedor'=>'AGUAS DEL LEVANTE, S.A.','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'desconocido'];
    $route=(new Salvest\InvoiceRouter(new Salvest\Classifier($db)))->route($invoice,'facturas@aguaslevante.es');
    $assert($route['status']==='classified' && (int)$route['supplier']['id']===$fixture['supplierId'],'S.L. en el maestro y S.A. en la factura deben considerarse el mismo nombre comercial: '.json_encode($route));
    $assert($route['evidence']['supplier']['type']==='exact_name','tras quitar la forma societaria ambos nombres deben quedar idénticos, no solo contenidos');
});
$test('resolución contextual: mayúsculas, tildes y puntuación no impiden la coincidencia exacta',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'26','CP VEINTISEIS','Jardinería Compañía, S.L.','JARDINERIA');
    $invoice=['proveedor'=>'JARDINERIA COMPAÑIA SL','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'desconocido'];
    $route=(new Salvest\InvoiceRouter(new Salvest\Classifier($db)))->route($invoice,'facturas@jardineria.es');
    $assert($route['status']==='classified' && (int)$route['supplier']['id']===$fixture['supplierId'],json_encode($route));
});
$test('resolución contextual: alias de proveedor dentro de la comunidad',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'27','CP VEINTISIETE','Descalcificadores Este S.L.','DESCALCIFICADOR');
    $db->execute('INSERT INTO supplier_aliases(supplier_id,alias_type,value,normalized_value,active) VALUES (?,?,?,?,1)',
        [$fixture['supplierId'],'name','AQUATREAT',Salvest\Text::normalize('AQUATREAT')]);
    $invoice=['proveedor'=>'AQUATREAT','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'desconocido'];
    $route=(new Salvest\InvoiceRouter(new Salvest\Classifier($db)))->route($invoice,'facturas@aquatreat.example');
    $assert($route['status']==='classified' && (int)$route['supplier']['id']===$fixture['supplierId'],json_encode($route));
    $assert($route['evidence']['supplier']['field']==='alias','debe quedar constancia de que fue por alias: '.json_encode($route['evidence']['supplier']));
});
$test('resolución contextual: dos proveedores igualmente plausibles van a revisión, no se adivina',static function()use($assert,$sqliteDb,$classifierSchema):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,postal_code,city,imap_folder_name,active) VALUES (?,?,?,?,?,?,?,?,1)',
        ['28','CP VEINTIOCHO',Salvest\Text::normalize('CP VEINTIOCHO'),'Z28000000','Calle 28','46028','Valencia','28 - CP VEINTIOCHO']);
    $communityId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO service_types(name,normalized_name,active) VALUES (?,?,1)',['MANTENIMIENTO',Salvest\Text::normalize('MANTENIMIENTO')]);
    $serviceTypeId=(int)$db->pdo()->lastInsertId();
    foreach(['PROVEEDOR ALFA','PROVEEDOR BETA'] as $name){
        $db->execute('INSERT INTO suppliers(official_name,normalized_name,cif,main_service_type_id,active) VALUES (?,?,NULL,?,1)',[$name,Salvest\Text::normalize($name),$serviceTypeId]);
        $db->execute('INSERT INTO community_suppliers(community_id,supplier_id,category,contract_reference) VALUES (?,?,?,NULL)',[$communityId,(int)$db->pdo()->lastInsertId(),'MANTENIMIENTO']);
    }
    $invoice=['proveedor'=>'PROVEEDOR ALFA PROVEEDOR BETA','proveedor_cif'=>null,'comunidad_cif'=>'Z28000000','tipo_servicio'=>'desconocido'];
    $route=(new Salvest\InvoiceRouter(new Salvest\Classifier($db)))->route($invoice,'facturas@ambiguo.example');
    $assert($route['status']==='needs_review','ante dos candidatos igual de plausibles no debe adivinar: '.json_encode($route));
    $assert($route['supplier']===null);
    $assert(str_contains((string)$route['reason'],'Varios proveedores'),'el motivo debe explicar que hay varios candidatos: '.$route['reason']);
});
$test('resolución contextual: si hay CIF de proveedor disponible, sigue ganando a un nombre que no coincide',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'29','CP VEINTINUEVE','IBERDROLA','ELECTRICIDAD','A95758389');
    // El nombre extraído no se parece en nada al maestro, pero el CIF sí coincide exactamente.
    $invoice=['proveedor'=>'Suministradora Eléctrica Desconocida SL','proveedor_cif'=>'A95758389','comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'agua'];
    $route=(new Salvest\InvoiceRouter(new Salvest\Classifier($db)))->route($invoice,'facturas@otra.example');
    $assert($route['status']==='classified' && (int)$route['supplier']['id']===$fixture['supplierId'],'el CIF debe ganar aunque el nombre extraído no se parezca al maestro: '.json_encode($route));
    $assert($route['evidence']['supplier']['field']==='supplier_cif' && $route['evidence']['supplier']['type']==='exact');
    $assert($route['service']==='ELECTRICIDAD');
});
$test('inferencia comunidad+servicio: caso real MENENDEZ YPELAYO 10 / CRISLA — único proveedor de LIMPIEZA se infiere sin coincidencia de nombre',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'82','MENENDEZ YPELAYO 10','CRISLA','LIMPIEZA',null,'H12557229');
    // OpenAI no acertó el nombre del proveedor (extrajo un texto que no contiene "CRISLA" en
    // absoluto ni comparte palabras con él — p.ej. de una cabecera o sello confusos), pero sí el servicio.
    $invoice=['proveedor'=>'Servicios Generales de Mantenimiento Ibérica','proveedor_cif'=>'B12534228','comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'limpieza'];
    // Nótese: aunque el nombre y el CIF del proveedor no están en el maestro (como en la
    // realidad: suppliers.cif es NULL), la inferencia comunidad+servicio debe bastar igualmente.
    $route=(new Salvest\InvoiceRouter(new Salvest\Classifier($db)))->route($invoice,'facturas@crisla.example');
    $assert($route['status']==='classified','comunidad+servicio con un único proveedor compatible debe clasificar: '.json_encode($route));
    $assert((int)$route['supplier']['id']===$fixture['supplierId'],'debe resolver a CRISLA');
    $assert($route['evidence']['supplier']['field']==='community_service' && $route['evidence']['supplier']['type']==='community_service_unique_supplier','debe quedar constancia de la señal usada: '.json_encode($route['evidence']['supplier']));
    $assert($route['service']==='LIMPIEZA');
});
$test('inferencia comunidad+servicio: dos proveedores compatibles con el mismo servicio nunca se eligen automáticamente',static function()use($assert,$sqliteDb,$classifierSchema):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,postal_code,city,imap_folder_name,active) VALUES (?,?,?,?,?,?,?,?,1)',
        ['31','CP TREINTAYUNO',Salvest\Text::normalize('CP TREINTAYUNO'),'H31000000','Calle 31','46031','Valencia','31 - CP TREINTAYUNO']);
    $communityId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO service_types(name,normalized_name,active) VALUES (?,?,1)',['LIMPIEZA',Salvest\Text::normalize('LIMPIEZA')]);
    $serviceTypeId=(int)$db->pdo()->lastInsertId();
    foreach(['LIMPIEZAS NORTE','LIMPIEZAS SUR'] as $name){
        $db->execute('INSERT INTO suppliers(official_name,normalized_name,cif,main_service_type_id,active) VALUES (?,?,NULL,?,1)',[$name,Salvest\Text::normalize($name),$serviceTypeId]);
        $db->execute('INSERT INTO community_suppliers(community_id,supplier_id,category,contract_reference) VALUES (?,?,?,NULL)',[$communityId,(int)$db->pdo()->lastInsertId(),'LIMPIEZA']);
    }
    // El nombre extraído no coincide con ninguno de los dos, así que solo queda la señal comunidad+servicio — y hay dos.
    $invoice=['proveedor'=>'Proveedor de Limpieza No Identificado','proveedor_cif'=>null,'comunidad_cif'=>'H31000000','tipo_servicio'=>'limpieza'];
    $route=(new Salvest\InvoiceRouter(new Salvest\Classifier($db)))->route($invoice,'facturas@limpieza.example');
    $assert($route['status']==='needs_review','con dos proveedores compatibles no debe elegirse ninguno automáticamente: '.json_encode($route));
    $assert($route['supplier']===null);
});
$test('inferencia comunidad+servicio: un proveedor identificado explícitamente por nombre tiene prioridad sobre esta señal',static function()use($assert,$sqliteDb,$classifierSchema):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,postal_code,city,imap_folder_name,active) VALUES (?,?,?,?,?,?,?,?,1)',
        ['32','CP TREINTAYDOS',Salvest\Text::normalize('CP TREINTAYDOS'),'H32000000','Calle 32','46032','Valencia','32 - CP TREINTAYDOS']);
    $communityId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO service_types(name,normalized_name,active) VALUES (?,?,1)',['LIMPIEZA',Salvest\Text::normalize('LIMPIEZA')]);
    $serviceTypeId=(int)$db->pdo()->lastInsertId();
    foreach(['LIMPIEZAS NORTE','LIMPIEZAS SUR'] as $name){
        $db->execute('INSERT INTO suppliers(official_name,normalized_name,cif,main_service_type_id,active) VALUES (?,?,NULL,?,1)',[$name,Salvest\Text::normalize($name),$serviceTypeId]);
        $db->execute('INSERT INTO community_suppliers(community_id,supplier_id,category,contract_reference) VALUES (?,?,?,NULL)',[$communityId,(int)$db->pdo()->lastInsertId(),'LIMPIEZA']);
    }
    // Ambos proveedores comparten servicio (la nueva señal por sí sola sería ambigua), pero
    // el nombre extraído coincide exactamente con uno de ellos: la coincidencia de nombre debe ganar.
    $invoice=['proveedor'=>'LIMPIEZAS NORTE','proveedor_cif'=>null,'comunidad_cif'=>'H32000000','tipo_servicio'=>'limpieza'];
    $route=(new Salvest\InvoiceRouter(new Salvest\Classifier($db)))->route($invoice,'facturas@limpiezasnorte.example');
    $assert($route['status']==='classified','la coincidencia de nombre explícita debe primar sobre la inferencia comunidad+servicio: '.json_encode($route));
    $assert($route['evidence']['supplier']['type']==='exact_name','no debe usarse la señal comunidad+servicio cuando el nombre ya resolvió el proveedor: '.json_encode($route['evidence']['supplier']));
});
$test('inferencia comunidad+servicio: proveedor confirmado por nombre pero con servicio erróneo de OpenAI — gana el servicio configurado en BD',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'33','CP TREINTAYTRES','CRISLA','LIMPIEZA');
    // OpenAI acertó el nombre del proveedor pero se equivocó de servicio ("agua" en vez de limpieza).
    $invoice=['proveedor'=>'CRISLA','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'agua'];
    $route=(new Salvest\InvoiceRouter(new Salvest\Classifier($db)))->route($invoice,'facturas@crisla.example');
    $assert($route['status']==='classified' && (int)$route['supplier']['id']===$fixture['supplierId'],json_encode($route));
    $assert($route['evidence']['supplier']['type']==='exact_name','el proveedor se resolvió por nombre, no por la señal comunidad+servicio');
    $assert($route['service']==='LIMPIEZA','el servicio configurado del proveedor en BD debe corregir el "agua" erróneo de OpenAI: '.$route['service']);
    $assert($route['evidence']['service']['field']==='supplier_main_service_type');
});
$test('inferencia comunidad+servicio: ningún proveedor compatible con el servicio va a revisión',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier):void{
    $db=$sqliteDb($classifierSchema);
    // Esta comunidad solo tiene un proveedor de ELECTRICIDAD; la factura dice ser de LIMPIEZA
    // y el nombre extraído no coincide con nada, así que ningún proveedor es compatible.
    $fixture=$makeCommunityWithSupplier($db,'34','CP TREINTAYCUATRO','IBERDROLA','ELECTRICIDAD');
    $invoice=['proveedor'=>'Proveedor de Limpieza Desconocido','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'limpieza'];
    $route=(new Salvest\InvoiceRouter(new Salvest\Classifier($db)))->route($invoice,'facturas@desconocido.example');
    $assert($route['status']==='needs_review','sin ningún proveedor compatible con el servicio no debe clasificarse: '.json_encode($route));
    $assert($route['supplier']===null);
});
$test('panel Revisar: el <select> de comunidad refleja el community_id ya resuelto, no solo el texto sugerido',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    $db->execute('INSERT INTO communities(official_name,active) VALUES (?,1)',['PK ILLES BALEARS 23']);
    $communityId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO service_types(name,normalized_name,active) VALUES (?,?,1)',['ELECTRICIDAD',Salvest\Text::normalize('ELECTRICIDAD')]);
    // provider queda vacío a propósito: un adjunto en needs_review nunca tiene un proveedor
    // confirmado — solo el texto bruto que devolvió OpenAI, en raw_supplier_name.
    $db->execute('INSERT INTO processed_attachments(status,processed_at,community_id,provider,raw_supplier_name,service_type,original_filename) VALUES (?,?,?,NULL,?,?,?)',
        ['needs_review',date('Y-m-d H:i:s'),$communityId,'GARCIA-MARIN CONSULTORES, S.L.U.','electricidad','factura.pdf']);
    $_SERVER['REQUEST_METHOD']='GET';
    set_error_handler(static fn(int$errno,string$message):bool=>str_contains($message,'session')||str_contains($message,'headers already'));
    $method=new ReflectionMethod(Salvest\WebApp::class,'reviews');$method->setAccessible(true);
    ob_start();$method->invoke($webApp);$html=ob_get_clean();restore_error_handler();
    $assert(str_contains($html,'Comunidad sugerida<strong>PK ILLES BALEARS 23</strong>'),'el texto de sugerencia debe seguir mostrándose');
    $assert((bool)preg_match('/<option value="'.$communityId.'" selected>PK ILLES BALEARS 23<\/option>/',$html),
        'el <select> de comunidad debe venir preseleccionado con el community_id ya resuelto, no quedarse en "Seleccionar": '.substr($html,(int)strpos($html,'name="community_id"'),300));
    $assert((bool)preg_match('/<option value="electricidad" selected>ELECTRICIDAD<\/option>/',$html),'el <select> de servicio también debe venir preseleccionado');
    $assert(str_contains($html,'Proveedor resuelto<strong>Pendiente</strong>'),'sin proveedor confirmado la UI no debe fingir que sí lo hay: '.substr($html,(int)strpos($html,'Proveedor resuelto'),80));
    $assert(str_contains($html,'Texto detectado<strong>GARCIA-MARIN CONSULTORES, S.L.U.</strong>'),'el texto bruto de OpenAI debe verse por separado, nunca como "el proveedor"');
});

$test('segunda llamada restringida: si el primer intento ya resuelve, la segunda llamada nunca se invoca',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'40','CP CUARENTA','IBERDROLA','ELECTRICIDAD');
    $invoice=['proveedor'=>'IBERDROLA','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'desconocido'];
    $resolver=function()use($assert):?int{$assert(false,'no debe llamarse a la segunda inspección si el primer intento ya resolvió');return null;};
    $route=(new Salvest\InvoiceRouter(new Salvest\Classifier($db)))->route($invoice,'facturas@iberdrola.es','',$resolver);
    $assert($route['status']==='classified' && (int)$route['supplier']['id']===$fixture['supplierId'],json_encode($route));
});
$test('segunda llamada restringida: recibe exactamente los proveedores de esa comunidad, y resuelve si acierta',static function()use($assert,$sqliteDb,$classifierSchema):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,postal_code,city,imap_folder_name,active) VALUES (?,?,?,?,?,?,?,?,1)',
        ['41','CP BENICARLO 8',Salvest\Text::normalize('CP BENICARLO 8'),'B41000000','Calle Benicarló','12580','Benicarló','41 - CP BENICARLO 8']);
    $communityId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO service_types(name,normalized_name,active) VALUES (?,?,1)',['EXTINTORES',Salvest\Text::normalize('EXTINTORES')]);
    $serviceTypeId=(int)$db->pdo()->lastInsertId();
    $ids=[];
    foreach(['Protección Contra Incendios Este SL','Mantenimientos García SL','Extintores Levante SA'] as $name){
        $db->execute('INSERT INTO suppliers(official_name,normalized_name,cif,main_service_type_id,active) VALUES (?,?,NULL,?,1)',[$name,Salvest\Text::normalize($name),$serviceTypeId]);
        $id=(int)$db->pdo()->lastInsertId();$ids[]=$id;
        $db->execute('INSERT INTO community_suppliers(community_id,supplier_id,category,contract_reference) VALUES (?,?,?,NULL)',[$communityId,$id,'EXTINTORES']);
    }
    // El nombre bruto ("GARCIA-MARIN CONSULTORES, S.L.U.") no coincide con ninguno de los tres
    // maestros por ningún nivel determinista: solo la segunda inspección (con el PDF completo)
    // puede reconocer que en realidad es "Mantenimientos García SL".
    $invoice=['proveedor'=>'GARCIA-MARIN CONSULTORES, S.L.U.','proveedor_cif'=>null,'comunidad_cif'=>'B41000000','tipo_servicio'=>'desconocido'];
    $seenCandidates=null;$seenCommunity=null;
    $resolver=function(array $candidates,array $community)use(&$seenCandidates,&$seenCommunity,$ids):?int{
        $seenCandidates=$candidates;$seenCommunity=$community;
        return $ids[1]; // "Mantenimientos García SL"
    };
    $route=(new Salvest\InvoiceRouter(new Salvest\Classifier($db)))->route($invoice,'facturas@garciamarin.example','',$resolver);
    $assert($seenCandidates!==null,'la segunda inspección sí debe invocarse cuando el primer intento no resuelve nada');
    $seenIds=array_column($seenCandidates,'id');sort($seenIds);$expectedIds=$ids;sort($expectedIds);
    $assert(count($seenCandidates)===3 && $seenIds===$expectedIds,'debe recibir exactamente los proveedores de esa comunidad, en la lista cerrada: '.json_encode($seenCandidates));
    $assert($seenCommunity['official_name']==='CP BENICARLO 8','debe recibir la comunidad ya resuelta como contexto');
    $assert($route['status']==='classified' && (int)$route['supplier']['id']===$ids[1],'debe clasificar con lo que devolvió la segunda inspección: '.json_encode($route));
    $assert($route['evidence']['supplier']['type']==='restricted_openai_retry','debe quedar constancia de que fue por la segunda llamada restringida');
    $assert($route['service']==='EXTINTORES');
});
$test('segunda llamada restringida: si devuelve un id fuera de la lista, se rechaza y no se fuerza nada',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'42','CP CUARENTA Y DOS','PROVEEDOR REAL','MANTENIMIENTO');
    $invoice=['proveedor'=>'Nombre irreconocible SL','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'desconocido'];
    $resolver=static fn():?int=>999999; // id que no pertenece a los proveedores de esta comunidad
    $route=(new Salvest\InvoiceRouter(new Salvest\Classifier($db)))->route($invoice,'facturas@desconocido.example','',$resolver);
    $assert($route['status']==='needs_review','un id fuera de la lista cerrada nunca debe forzar una clasificación: '.json_encode($route));
    $assert($route['supplier']===null);
});
$test('segunda llamada restringida: si tampoco resuelve (null), va a revisión sin inventar proveedor',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'43','CP CUARENTA Y TRES','PROVEEDOR REAL','MANTENIMIENTO');
    $invoice=['proveedor'=>'Nombre irreconocible SL','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'desconocido'];
    $resolver=static fn():?int=>null;
    $route=(new Salvest\InvoiceRouter(new Salvest\Classifier($db)))->route($invoice,'facturas@desconocido.example','',$resolver);
    $assert($route['status']==='needs_review' && $route['supplier']===null,json_encode($route));
    $assert($route['raw_supplier_name']==='Nombre irreconocible SL','el texto bruto debe seguir disponible para el panel de revisión, nunca convertido en proveedor final');
});
$test('segunda llamada restringida: con dos candidatos ambiguos en el primer intento, no se llega a invocar la segunda',static function()use($assert,$sqliteDb,$classifierSchema):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,postal_code,city,imap_folder_name,active) VALUES (?,?,?,?,?,?,?,?,1)',
        ['44','CP CUARENTA Y CUATRO',Salvest\Text::normalize('CP CUARENTA Y CUATRO'),'Z44000000','Calle 44','46044','Valencia','44 - CP CUARENTA Y CUATRO']);
    $communityId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO service_types(name,normalized_name,active) VALUES (?,?,1)',['MANTENIMIENTO',Salvest\Text::normalize('MANTENIMIENTO')]);
    $serviceTypeId=(int)$db->pdo()->lastInsertId();
    foreach(['PROVEEDOR ALFA','PROVEEDOR BETA'] as $name){
        $db->execute('INSERT INTO suppliers(official_name,normalized_name,cif,main_service_type_id,active) VALUES (?,?,NULL,?,1)',[$name,Salvest\Text::normalize($name),$serviceTypeId]);
        $db->execute('INSERT INTO community_suppliers(community_id,supplier_id,category,contract_reference) VALUES (?,?,?,NULL)',[$communityId,(int)$db->pdo()->lastInsertId(),'MANTENIMIENTO']);
    }
    $invoice=['proveedor'=>'PROVEEDOR ALFA PROVEEDOR BETA','proveedor_cif'=>null,'comunidad_cif'=>'Z44000000','tipo_servicio'=>'desconocido'];
    $resolver=function()use($assert):?int{$assert(false,'un caso ambiguo debe ir a revisión directamente, sin gastar una segunda llamada a OpenAI');return null;};
    $route=(new Salvest\InvoiceRouter(new Salvest\Classifier($db)))->route($invoice,'facturas@ambiguo.example','',$resolver);
    $assert($route['status']==='needs_review');
});

$test('Text::normalizeIdentifier(): H-12815601, H12815601, H 12815601 y h12815601 son el mismo CIF',static function()use($assert):void{
    $canonical='h12815601';
    foreach(['H-12815601','H12815601','H 12815601','h12815601','H.12815601','h-12.815601'] as $value){
        $assert(Salvest\Text::normalizeIdentifier($value)===$canonical,"\"$value\" debería normalizar a \"$canonical\", dio \"".Salvest\Text::normalizeIdentifier($value).'"');
    }
});
$test('caso real BERNAT GUILLEM DETENÇA 39: el guion del CIF del PDF no debe impedir la coincidencia con el maestro',static function()use($assert,$sqliteDb,$classifierSchema):void{
    $db=$sqliteDb($classifierSchema);
    // El maestro guarda el CIF tal cual lo dieron de alta, sin guion.
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,postal_code,city,imap_folder_name,active) VALUES (?,?,?,?,?,?,?,?,1)',
        ['39','BERNAT GUILLEM DETENÇA 39',Salvest\Text::normalize('BERNAT GUILLEM DETENÇA 39'),'H12815601','Carrer Bernat Guillem Detença 39','07000','Palma','39 - BERNAT GUILLEM DETENCA 39']);
    $communityId=(int)$db->pdo()->lastInsertId();
    // El PDF real lo trae con guion.
    $result=(new Salvest\Classifier($db))->classify(['comunidad_cif'=>'H-12815601']);
    $assert($result['community']!==null && (int)$result['community']['id']===$communityId,
        'un CIF con guion en el PDF debe reconocer al mismo titular que el maestro sin guion: '.json_encode($result));
    $assert($result['evidence']['field']==='holder_cif' && $result['evidence']['type']==='exact','debe resolverse como coincidencia exacta de CIF titular, no por fuzzy');
});
$test('CIF de proveedor con guion también debe resolver contra el maestro sin guion (global y dentro de comunidad)',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'50','CP CINCUENTA','PROTECCION INCENDIOS SL','EXTINTORES','A12815601');
    $global=(new Salvest\Classifier($db))->resolveSupplier(['proveedor'=>'','proveedor_cif'=>'A-12.815601'],'facturas@otra.example');
    $assert($global['supplier']!==null && (int)$global['supplier']['id']===$fixture['supplierId'],'resolveSupplier() global también debe ignorar el guion/puntos del CIF: '.json_encode($global));
    $inCommunity=(new Salvest\Classifier($db))->resolveSupplierInCommunity($fixture['communityId'],['proveedor'=>'','proveedor_cif'=>'A-12.815601'],'facturas@otra.example');
    $assert($inCommunity['supplier']!==null && (int)$inCommunity['supplier']['id']===$fixture['supplierId'],'resolveSupplierInCommunity() también debe ignorar el guion/puntos del CIF: '.json_encode($inCommunity));
});

// ---- ReviewTrace: the in-memory, per-attachment technical trace behind /Revisar's "Detalle técnico" ----
$test('ReviewTrace: los pasos quedan en orden cronológico real, con timestamp ISO-8601 y milisegundos',static function()use($assert):void{
    $trace=new Salvest\ReviewTrace();
    $trace->add('document',['filename'=>'factura.pdf']);
    usleep(2000);
    $trace->add('openai_request',['model'=>'gpt-5.6-luna']);
    $steps=$trace->toArray();
    $assert(count($steps)===2);
    $assert((bool)preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}[+-]\d{2}:\d{2}$/',$steps[0]['timestamp']),'formato esperado 2026-08-18T16:12:03.418+02:00, dio: '.$steps[0]['timestamp']);
    $assert($steps[0]['timestamp']<=$steps[1]['timestamp'],'los pasos deben quedar en el mismo orden cronológico en que se registraron');
    $assert($steps[0]['step']==='document' && $steps[1]['step']==='openai_request');
});
$test('ReviewTrace: la respuesta completa de OpenAI se conserva íntegra, incluidos campos no usados después',static function()use($assert):void{
    $trace=new Salvest\ReviewTrace();
    $response=['proveedor'=>'CRISLA','tipo_servicio'=>'limpieza','direccion'=>'Calle X','importe'=>120.62,'fecha_factura'=>'2026-02-28',
        'proveedor_cif'=>'B12534228','nombre_comunidad'=>'MENENDEZ PELAYO 10','comunidad_cif'=>'H12557229','codigo_postal'=>'46000',
        'cups'=>null,'numero_contrato'=>null,'referencia_cliente'=>null,'numero_factura'=>'F-0486','periodo_facturacion'=>'Febrero 2026',
        'moneda'=>'EUR','codigo_comunidad'=>null];
    $trace->add('openai_response',['latency_ms'=>2143,'input_tokens'=>18342,'output_tokens'=>212,'response'=>$response]);
    $stored=$trace->toArray()[0]['data']['response'];
    foreach($response as $field=>$value)$assert(array_key_exists($field,$stored)&&$stored[$field]===$value,"el campo $field debe conservarse tal cual, aunque no se use después");
});
$test('ReviewTrace: candidatos y scores del nivel fuzzy se conservan, no solo el ganador',static function()use($assert):void{
    $trace=new Salvest\ReviewTrace();
    $trace->add('supplier_resolution',['tiers'=>[
        ['method'=>'supplier_cif','result'=>'none'],['method'=>'supplier_exact_name','result'=>'none'],
        ['method'=>'supplier_fuzzy','result'=>'candidate','proveedor'=>'ADRIAN TURCU','score'=>63.2],
        ['method'=>'supplier_fuzzy','result'=>'candidate','proveedor'=>'IBERDROLA','score'=>12.1],
        ['method'=>'supplier_fuzzy','result'=>'none','best'=>'ADRIAN TURCU','score'=>63.2,'threshold'=>92.0],
    ],'supplier_id'=>null]);
    $tiers=$trace->toArray()[0]['data']['tiers'];
    $fuzzyCandidates=array_values(array_filter($tiers,static fn(array$t):bool=>$t['method']==='supplier_fuzzy'&&$t['result']==='candidate'));
    $assert(count($fuzzyCandidates)===2,'deben verse ambos candidatos fuzzy descartados, no solo el mejor');
    $assert($fuzzyCandidates[0]['score']===63.2 && $fuzzyCandidates[1]['score']===12.1);
});
$test('ReviewTrace: redacta claves sensibles pero conserva input_tokens/output_tokens',static function()use($assert):void{
    $trace=new Salvest\ReviewTrace();
    $trace->add('openai_request',['model'=>'gpt-5.6-luna','api_key'=>'sk-esto-no-debe-guardarse','password'=>'x','oauth_token'=>'y','session_id'=>'z']);
    $trace->add('openai_response',['input_tokens'=>18342,'output_tokens'=>212]);
    $data0=$trace->toArray()[0]['data'];$data1=$trace->toArray()[1]['data'];
    $assert($data0['api_key']==='[redacted]' && $data0['password']==='[redacted]' && $data0['oauth_token']==='[redacted]' && $data0['session_id']==='[redacted]','password/api_key/oauth_token/session_id deben quedar redactados');
    $assert($data0['model']==='gpt-5.6-luna','un campo funcional normal no debe verse afectado');
    $assert($data1['input_tokens']===18342 && $data1['output_tokens']===212,'input_tokens/output_tokens son datos funcionales, no un secreto, pese a contener "token"');
});
$test('ReviewTrace: se persiste para cualquier estado que /Revisar muestre (unclassified, needs_review, error), nunca para classified/duplicate',static function()use($assert):void{
    $trace=new Salvest\ReviewTrace();
    $trace->add('final_decision',['status'=>'classified']);
    $assert($trace->persistForReview('classified')===null,'classified nunca necesita depuración manual');
    $assert($trace->persistForReview('duplicate')===null,'un duplicado nunca guarda su propia traza (ver también el caso de Worker que fuerza NULL aunque el original la tuviera)');
    foreach(['unclassified','needs_review','error'] as $reviewable){
        $json=$trace->persistForReview($reviewable);
        $assert(is_string($json) && json_decode($json,true)!==null,"$reviewable debe producir el JSON del timeline, ya que también aparece en /Revisar");
    }
});
$test('ReviewTrace: un dato interno problemático (p.ej. UTF-8 inválido) nunca lanza una excepción que interrumpa el procesamiento',static function()use($assert):void{
    $trace=new Salvest\ReviewTrace();
    $trace->add('document',['filename'=>"factura-\xB1\x31-invalida.pdf"]); // bytes UTF-8 inválidos a propósito
    try{
        $result=$trace->persistForReview('needs_review');
        $assert($result===null||is_string($result),'debe degradar con seguridad (null, o JSON parcial) en vez de lanzar');
    }catch(\Throwable $error){
        $assert(false,'persistForReview() nunca debe dejar escapar una excepción — rompería el procesamiento real de la factura: '.$error->getMessage());
    }
});

// ---- InvoiceRouter/Classifier: el observador $trace no cambia ninguna decisión ----
$test('InvoiceRouter::route($trace): la señal community_service_unique_supplier se observa en el trace sin cambiar la decisión',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'82','MENENDEZ YPELAYO 10','CRISLA','LIMPIEZA',null,'H12557229');
    $invoice=['proveedor'=>'Servicios Generales de Mantenimiento Ibérica','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'limpieza'];
    $signals=[];
    $trace=function(string $tier,string $outcome,array $details)use(&$signals):void{$signals[]=[$tier,$outcome];};
    $withTrace=(new Salvest\InvoiceRouter(new Salvest\Classifier($db)))->route($invoice,'facturas@crisla.example','',null,$trace);
    $withoutTrace=(new Salvest\InvoiceRouter(new Salvest\Classifier($db)))->route($invoice,'facturas@crisla.example');
    $assert($withTrace['status']===$withoutTrace['status'] && $withTrace['status']==='classified','pasar un $trace no debe cambiar la decisión: '.json_encode($withTrace));
    $assert((int)$withTrace['supplier']['id']===(int)$withoutTrace['supplier']['id']);
    $assert(in_array(['supplier_community_service','match'],$signals,true),'debe observarse la señal comunidad+servicio actuando: '.json_encode($signals));
});
$test('InvoiceRouter::route(): supplier_ambiguous=true cuando hay varios proveedores igualmente plausibles',static function()use($assert,$sqliteDb,$classifierSchema):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,postal_code,city,imap_folder_name,active) VALUES (?,?,?,?,?,?,?,?,1)',
        ['28','CP VEINTIOCHO',Salvest\Text::normalize('CP VEINTIOCHO'),'Z28000000','Calle 28','46028','Valencia','28 - CP VEINTIOCHO']);
    $communityId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO service_types(name,normalized_name,active) VALUES (?,?,1)',['MANTENIMIENTO',Salvest\Text::normalize('MANTENIMIENTO')]);
    $serviceTypeId=(int)$db->pdo()->lastInsertId();
    foreach(['PROVEEDOR ALFA','PROVEEDOR BETA'] as $name){
        $db->execute('INSERT INTO suppliers(official_name,normalized_name,cif,main_service_type_id,active) VALUES (?,?,NULL,?,1)',[$name,Salvest\Text::normalize($name),$serviceTypeId]);
        $db->execute('INSERT INTO community_suppliers(community_id,supplier_id,category,contract_reference) VALUES (?,?,?,NULL)',[$communityId,(int)$db->pdo()->lastInsertId(),'MANTENIMIENTO']);
    }
    $invoice=['proveedor'=>'PROVEEDOR ALFA PROVEEDOR BETA','proveedor_cif'=>null,'comunidad_cif'=>'Z28000000','tipo_servicio'=>'desconocido'];
    $route=(new Salvest\InvoiceRouter(new Salvest\Classifier($db)))->route($invoice,'facturas@ambiguo.example');
    $assert($route['supplier_ambiguous']===true,'el nuevo campo debe reflejar la ambigüedad real: '.json_encode($route));
});
$test('InvoiceRouter::route(): supplier_ambiguous=false en un caso normal correctamente clasificado',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'23','PK ILLES BALEARS 23','IBERDROLA','ELECTRICIDAD');
    $invoice=['proveedor'=>'IBERDROLA CLIENTES, S.A.U.','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'agua'];
    $route=(new Salvest\InvoiceRouter(new Salvest\Classifier($db)))->route($invoice,'facturas@iberdrola.es');
    $assert($route['status']==='classified' && $route['supplier_ambiguous']===false);
});

// ---- WebApp /Revisar: renderizado del detalle técnico ----
$test('/Revisar: una factura needs_review con debug_trace_json muestra el detalle técnico, cerrado por defecto',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp,$requestWebApp):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    $db->execute('INSERT INTO communities(official_name,active) VALUES (?,1)',['MENENDEZ YPELAYO 10']);
    $communityId=(int)$db->pdo()->lastInsertId();
    $trace=new Salvest\ReviewTrace();
    $trace->add('document',['filename'=>'F-0486_CRISLA.pdf','mime'=>'application/pdf','size_bytes'=>184320,'sha256'=>str_repeat('a',64)]);
    $trace->add('final_decision',['status'=>'needs_review','reason'=>'Proveedor no resuelto']);
    $json=$trace->persistForReview('needs_review');
    $db->execute('INSERT INTO processed_attachments(status,processed_at,community_id,provider,raw_supplier_name,service_type,original_filename,debug_trace_json) VALUES (?,?,?,NULL,?,?,?,?)',
        ['needs_review',date('Y-m-d H:i:s'),$communityId,'LIMPIEZAS ADRIAN','limpieza','F-0486_CRISLA.pdf',$json]);
    $html=$requestWebApp($webApp,'GET','reviews');
    $assert(str_contains($html,'Detalle técnico'),'debe aparecer la sección de detalle técnico');
    $assert(str_contains($html,'F-0486_CRISLA.pdf'),'el contenido del paso "document" debe verse en el resumen o el JSON');
    $assert(str_contains($html,'<details class="tech-trace">'),'el detalle técnico debe estar cerrado por defecto (sin atributo open)');
    $assert(!str_contains($html,'<details class="tech-trace" open'),'nunca debe abrirse automáticamente');
});
$test('/Revisar: una factura needs_review antigua sin debug_trace_json (NULL) sigue renderizando con normalidad',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp,$requestWebApp):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    $db->execute('INSERT INTO communities(official_name,active) VALUES (?,1)',['CP HISTORICA']);
    $communityId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO processed_attachments(status,processed_at,community_id,provider,raw_supplier_name,service_type,original_filename,debug_trace_json) VALUES (?,?,?,NULL,?,?,?,NULL)',
        ['needs_review',date('Y-m-d H:i:s'),$communityId,'PROVEEDOR ANTIGUO','desconocido','factura-antigua.pdf']);
    $html=$requestWebApp($webApp,'GET','reviews');
    $assert(str_contains($html,'factura-antigua.pdf'),'la tarjeta debe seguir renderizándose con normalidad');
    $assert(str_contains($html,'No hay detalle técnico disponible para esta factura.'),'una factura antigua sin traza debe mostrar el mensaje explicativo, no un error');
});
$test('/Revisar: una factura unclassified (comunidad no resuelta) también muestra el detalle técnico — caso real de producción',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp,$requestWebApp):void{
    // Regresión del caso real: "FACTURA Nº FA260071 INFOR TORRENT SLU.pdf" terminó en
    // unclassified (el Classifier nunca llegó a resolver comunidad) y, antes de este ajuste,
    // no se guardaba ninguna traza porque persistForReview() solo cubría needs_review.
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    $trace=new Salvest\ReviewTrace();
    $trace->add('document',['filename'=>'FACTURA Nº FA260071 INFOR TORRENT SLU.pdf','mime'=>'application/pdf','size_bytes'=>90210,'sha256'=>str_repeat('b',64)]);
    $trace->add('community_resolution',['signals'=>[],'community_id'=>null,'official_name'=>null,'evidence'=>['field'=>'address','type'=>'fuzzy','score'=>0.0]]);
    $trace->add('final_decision',['status'=>'unclassified','reason'=>null,'blocking_factor'=>'community_unresolved']);
    $json=$trace->persistForReview('unclassified');
    $assert($json!==null,'unclassified debe producir traza ahora');
    $db->execute('INSERT INTO processed_attachments(status,processed_at,community_id,provider,raw_supplier_name,service_type,original_filename,debug_trace_json) VALUES (?,?,NULL,NULL,?,?,?,?)',
        ['unclassified',date('Y-m-d H:i:s'),'INFOR TORRENT SLU','desconocido','FACTURA Nº FA260071 INFOR TORRENT SLU.pdf',$json]);
    $html=$requestWebApp($webApp,'GET','reviews');
    $assert(str_contains($html,'Detalle técnico'),'una factura unclassified también debe mostrar el detalle técnico');
    $assert(str_contains($html,'community_unresolved'),'debe verse el motivo que impidió clasificar en el JSON completo');
    $assert(!str_contains($html,'No hay detalle técnico disponible'),'no debe caer en el mensaje de "sin traza" cuando sí la hay');
});
$test('/Revisar: el botón "Volver a procesar" solo aparece en facturas needs_review, con el texto de confirmación de un único adjunto',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp,$requestWebApp):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    $db->execute('INSERT INTO mailboxes(descriptive_name,email,imap_host,imap_port,use_ssl,username,encrypted_password,input_folder,active) VALUES (?,?,?,?,?,?,?,?,1)',
        ['Test','buzon@example.com','imap.example.com',993,1,'buzon@example.com','x','INBOX']);
    $mailboxId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO processed_attachments(mailbox_id,uidvalidity,message_uid,status,processed_at,original_filename) VALUES (?,?,?,?,?,?)',
        [$mailboxId,'1001','500','needs_review',date('Y-m-d H:i:s'),'sola.pdf']);
    $db->execute('INSERT INTO processed_attachments(mailbox_id,uidvalidity,message_uid,status,processed_at,original_filename) VALUES (?,?,?,?,?,?)',
        [$mailboxId,'2002','900','unclassified',date('Y-m-d H:i:s'),'otra-sin-clasificar.pdf']);
    $html=$requestWebApp($webApp,'GET','reviews');
    $assert(substr_count($html,'Volver a procesar')===1,'el botón debe aparecer una sola vez, solo en la tarjeta needs_review: '.substr_count($html,'Volver a procesar'));
    $assert(str_contains($html,'Esta factura volverá a la bandeja de entrada y Salvest intentará procesarla de nuevo en la próxima ejecución. Se conservará el historial técnico del intento anterior. ¿Continuar?'),'con un único adjunto pendiente debe usarse el texto de confirmación singular');
});
$test('/Revisar: con varios adjuntos pendientes en el mismo correo, la confirmación usa el texto plural',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp,$requestWebApp):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    $db->execute('INSERT INTO mailboxes(descriptive_name,email,imap_host,imap_port,use_ssl,username,encrypted_password,input_folder,active) VALUES (?,?,?,?,?,?,?,?,1)',
        ['Test','buzon@example.com','imap.example.com',993,1,'buzon@example.com','x','INBOX']);
    $mailboxId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO processed_attachments(mailbox_id,uidvalidity,message_uid,status,processed_at,original_filename) VALUES (?,?,?,?,?,?)',
        [$mailboxId,'1001','500','needs_review',date('Y-m-d H:i:s'),'adjunto-a.pdf']);
    $db->execute('INSERT INTO processed_attachments(mailbox_id,uidvalidity,message_uid,status,processed_at,original_filename) VALUES (?,?,?,?,?,?)',
        [$mailboxId,'1001','500','error',date('Y-m-d H:i:s'),'adjunto-b.pdf']);
    $html=$requestWebApp($webApp,'GET','reviews');
    $assert(str_contains($html,'Este correo contiene varios adjuntos. Los pendientes se volverán a procesar; los ya clasificados se conservarán y no volverán a clasificarse. Se conservará el historial técnico de los intentos anteriores. ¿Continuar?'),'con varios adjuntos pendientes debe usarse el texto de confirmación plural');
});
$test('/Revisar: reencolar sin confirmar (confirm_requeue vacío) se rechaza server-side, pese al CSRF válido',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp,$requestWebApp):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    $db->execute('INSERT INTO processed_attachments(status,processed_at,original_filename) VALUES (?,?,?)',['needs_review',date('Y-m-d H:i:s'),'x.pdf']);
    $id=(int)$db->pdo()->lastInsertId();
    $html=$requestWebApp($webApp,'POST','reviews',['action'=>'requeue','id'=>(string)$id,'csrf'=>$_SESSION['csrf']??'','confirm_requeue'=>'']);
    $assert(str_contains($html,'no fue confirmada'),'sin confirm_requeue=REQUEUE debe rechazarse aunque el CSRF sea correcto: '.$html);
    $row=$db->one('SELECT status FROM processed_attachments WHERE id=?',[$id]);
    $assert($row['status']==='needs_review','no debe cambiar nada si la confirmación no llegó');
});

// ---- InboxRequeue: "Volver a procesar" ----
$test('InboxRequeue: needs_review pasa a requeued, con requeued_at y conservando el historial técnico',static function()use($assert,$sqliteDbWithLock,$workerConfig,$seedRequeueFixture,$fakeRequeueImapClient):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();
    $fixture=$seedRequeueFixture($db,['needs_review']);
    $stub=$fakeRequeueImapClient(['777']);
    $requeue=new Salvest\InboxRequeue($db,new Salvest\Crypto(Salvest\Crypto::generateKey()),$config,static fn(array $mailbox,string $folder)=>$stub);
    $result=$requeue->requeue($fixture['attachmentIds'][0]);
    $assert($result['ok']===true,'debe completarse con éxito: '.$result['message']);
    $row=$db->one('SELECT * FROM processed_attachments WHERE id=?',[$fixture['attachmentIds'][0]]);
    $assert($row['status']==='requeued','el estado debe pasar a requeued: '.$row['status']);
    $assert($row['requeued_at']!==null,'requeued_at debe quedar registrado');
    $assert($row['debug_trace_json']!==null && str_contains((string)$row['debug_trace_json'],'adjunto-0.pdf'),'el historial técnico del intento anterior debe conservarse intacto');
    $assert($stub->moved===['uid'=>'777','destination'=>'INBOX'],'debe moverse al input_folder del buzón: '.json_encode($stub->moved));
    $message=$db->one('SELECT status FROM processed_messages WHERE id=?',[$fixture['messageId']]);
    $assert($message['status']==='requeued','processed_messages también debe quedar marcado requeued, por coherencia histórica');
});
$test('InboxRequeue: un hermano classified permanece completamente intacto',static function()use($assert,$sqliteDbWithLock,$workerConfig,$seedRequeueFixture,$fakeRequeueImapClient):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();
    $fixture=$seedRequeueFixture($db,['needs_review','classified']);
    $stub=$fakeRequeueImapClient(['777']);
    $requeue=new Salvest\InboxRequeue($db,new Salvest\Crypto(Salvest\Crypto::generateKey()),$config,static fn(array $mailbox,string $folder)=>$stub);
    $requeue->requeue($fixture['attachmentIds'][0]);
    $sibling=$db->one('SELECT * FROM processed_attachments WHERE id=?',[$fixture['attachmentIds'][1]]);
    $assert($sibling['status']==='classified','un hermano ya clasificado nunca debe tocarse: '.$sibling['status']);
    $assert($sibling['requeued_at']===null,'un hermano classified no debe llevar requeued_at');
});
$test('InboxRequeue: un hermano duplicate permanece completamente intacto',static function()use($assert,$sqliteDbWithLock,$workerConfig,$seedRequeueFixture,$fakeRequeueImapClient):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();
    $fixture=$seedRequeueFixture($db,['needs_review','duplicate']);
    $stub=$fakeRequeueImapClient(['777']);
    $requeue=new Salvest\InboxRequeue($db,new Salvest\Crypto(Salvest\Crypto::generateKey()),$config,static fn(array $mailbox,string $folder)=>$stub);
    $requeue->requeue($fixture['attachmentIds'][0]);
    $sibling=$db->one('SELECT * FROM processed_attachments WHERE id=?',[$fixture['attachmentIds'][1]]);
    $assert($sibling['status']==='duplicate','un hermano duplicate nunca debe tocarse: '.$sibling['status']);
    $assert($sibling['requeued_at']===null);
});
$test('InboxRequeue: una fila requeued deja de participar en el dedupe global por SHA-256',static function()use($assert,$sqliteDbWithLock,$workerConfig,$seedRequeueFixture,$fakeRequeueImapClient):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();
    $fixture=$seedRequeueFixture($db,['needs_review']);
    $stub=$fakeRequeueImapClient(['777']);
    (new Salvest\InboxRequeue($db,new Salvest\Crypto(Salvest\Crypto::generateKey()),$config,static fn(array $mailbox,string $folder)=>$stub))->requeue($fixture['attachmentIds'][0]);
    // Exactamente la misma consulta que Worker::processAttachment() usa para el dedupe global.
    $prior=$db->one("SELECT * FROM processed_attachments WHERE attachment_sha256=? AND status IN ('classified','unclassified','needs_review','duplicate') ORDER BY id LIMIT 1",['sha-0']);
    $assert($prior===null,'una fila requeued no debe encontrarse nunca por el dedupe global: '.json_encode($prior));
});
$test('InboxRequeue: un fallo IMAP provoca rollback completo (nada cambia)',static function()use($assert,$sqliteDbWithLock,$workerConfig,$seedRequeueFixture,$fakeRequeueImapClient):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();
    $fixture=$seedRequeueFixture($db,['needs_review']);
    $stub=$fakeRequeueImapClient([],new RuntimeException('conexión IMAP caída'));
    $result=(new Salvest\InboxRequeue($db,new Salvest\Crypto(Salvest\Crypto::generateKey()),$config,static fn(array $mailbox,string $folder)=>$stub))->requeue($fixture['attachmentIds'][0]);
    $assert($result['ok']===false,'debe fallar de forma controlada');
    $row=$db->one('SELECT * FROM processed_attachments WHERE id=?',[$fixture['attachmentIds'][0]]);
    $assert($row['status']==='needs_review','tras el rollback el estado debe seguir siendo el original: '.$row['status']);
    $assert($row['requeued_at']===null,'tras el rollback no debe quedar requeued_at');
    $message=$db->one('SELECT status FROM processed_messages WHERE id=?',[$fixture['messageId']]);
    $assert($message['status']==='needs_review','processed_messages también debe quedar exactamente como estaba');
});
$test('InboxRequeue: 0 resultados por Message-ID aborta sin cambios',static function()use($assert,$sqliteDbWithLock,$workerConfig,$seedRequeueFixture,$fakeRequeueImapClient):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();
    $fixture=$seedRequeueFixture($db,['needs_review']);
    $stub=$fakeRequeueImapClient([]); // ninguna coincidencia
    $result=(new Salvest\InboxRequeue($db,new Salvest\Crypto(Salvest\Crypto::generateKey()),$config,static fn(array $mailbox,string $folder)=>$stub))->requeue($fixture['attachmentIds'][0]);
    $assert($result['ok']===false,'0 coincidencias debe abortar: '.$result['message']);
    $row=$db->one('SELECT status FROM processed_attachments WHERE id=?',[$fixture['attachmentIds'][0]]);
    $assert($row['status']==='needs_review','no debe cambiar nada si no se pudo localizar el correo');
});
$test('InboxRequeue: más de 1 resultado por Message-ID aborta sin cambios (nunca adivinar)',static function()use($assert,$sqliteDbWithLock,$workerConfig,$seedRequeueFixture,$fakeRequeueImapClient):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();
    $fixture=$seedRequeueFixture($db,['needs_review']);
    $stub=$fakeRequeueImapClient(['777','778']); // ambiguo
    $result=(new Salvest\InboxRequeue($db,new Salvest\Crypto(Salvest\Crypto::generateKey()),$config,static fn(array $mailbox,string $folder)=>$stub))->requeue($fixture['attachmentIds'][0]);
    $assert($result['ok']===false,'varias coincidencias debe abortar en vez de adivinar: '.$result['message']);
    $row=$db->one('SELECT status FROM processed_attachments WHERE id=?',[$fixture['attachmentIds'][0]]);
    $assert($row['status']==='needs_review');
});
$test('InboxRequeue: si el movimiento IMAP original falló, no intenta mover nada (ya está en INBOX)',static function()use($assert,$sqliteDbWithLock,$workerConfig,$seedRequeueFixture):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();
    $fixture=$seedRequeueFixture($db,['needs_review'],'failed');
    $neverCalled=static function()use($assert){$assert(false,'no debe construirse ningún ImapClient cuando el movimiento original falló');};
    $result=(new Salvest\InboxRequeue($db,new Salvest\Crypto(Salvest\Crypto::generateKey()),$config,$neverCalled))->requeue($fixture['attachmentIds'][0]);
    $assert($result['ok']===true,'debe completarse igualmente, solo con el cambio de estado: '.$result['message']);
    $row=$db->one('SELECT status FROM processed_attachments WHERE id=?',[$fixture['attachmentIds'][0]]);
    $assert($row['status']==='requeued');
});
$test('InboxRequeue: un correo con varios adjuntos pendientes reencola todos los no exitosos, ninguno de más',static function()use($assert,$sqliteDbWithLock,$workerConfig,$seedRequeueFixture,$fakeRequeueImapClient):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();
    $fixture=$seedRequeueFixture($db,['needs_review','error','classified']);
    $stub=$fakeRequeueImapClient(['777']);
    $result=(new Salvest\InboxRequeue($db,new Salvest\Crypto(Salvest\Crypto::generateKey()),$config,static fn(array $mailbox,string $folder)=>$stub))->requeue($fixture['attachmentIds'][0]);
    $assert($result['ok']===true);
    $needsReview=$db->one('SELECT status FROM processed_attachments WHERE id=?',[$fixture['attachmentIds'][0]]);
    $error=$db->one('SELECT status FROM processed_attachments WHERE id=?',[$fixture['attachmentIds'][1]]);
    $classified=$db->one('SELECT status FROM processed_attachments WHERE id=?',[$fixture['attachmentIds'][2]]);
    $assert($needsReview['status']==='requeued' && $error['status']==='requeued','needs_review y error deben reencolarse juntos, por ser el mismo correo');
    $assert($classified['status']==='classified','el hermano classified nunca se toca, aunque haya otros pendientes en el mismo correo');
});
$test('InboxRequeue: un intento posterior con un UID nuevo crea una fila nueva, sin chocar con la histórica',static function()use($assert,$sqliteDbWithLock,$workerConfig,$seedRequeueFixture,$fakeRequeueImapClient):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();
    $fixture=$seedRequeueFixture($db,['needs_review']);
    $stub=$fakeRequeueImapClient(['777']);
    (new Salvest\InboxRequeue($db,new Salvest\Crypto(Salvest\Crypto::generateKey()),$config,static fn(array $mailbox,string $folder)=>$stub))->requeue($fixture['attachmentIds'][0]);
    // Simula lo que hace Worker::processAttachment() en la siguiente ejecución: el correo
    // reencolado ha vuelto a INBOX con un UID de IMAP nuevo (otro message_uid), y esta vez
    // se clasifica bien. No debe chocar con la fila histórica (mismo sha256, distinto uid).
    $db->execute('INSERT INTO processed_attachments(mailbox_id,uidvalidity,message_uid,original_filename,attachment_sha256,mime_type,size_bytes,status,processed_at) VALUES (?,?,?,?,?,?,?,?,?)',
        [$fixture['mailboxId'],'1001','600','adjunto-0.pdf','sha-0','application/pdf',1000,'classified',date('Y-m-d H:i:s')]);
    $newRow=$db->one("SELECT * FROM processed_attachments WHERE mailbox_id=? AND message_uid='600'",[$fixture['mailboxId']]);
    $assert($newRow!==null && $newRow['status']==='classified','el segundo intento debe insertarse como fila nueva sin chocar con la clave única');
    $oldRow=$db->one('SELECT status,debug_trace_json FROM processed_attachments WHERE id=?',[$fixture['attachmentIds'][0]]);
    $assert($oldRow['status']==='requeued' && $oldRow['debug_trace_json']!==null,'la fila histórica del primer intento sigue existiendo, requeued, con su traza técnica intacta');
});

$failed=0;
foreach($tests as $name=>$callback){try{$callback();echo "PASS $name\n";}catch(Throwable $error){$failed++;echo "FAIL $name: {$error->getMessage()}\n";}}
echo sprintf("%d tests, %d failed\n",count($tests),$failed);
exit($failed?1:0);

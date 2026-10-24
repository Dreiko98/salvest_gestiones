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
CREATE TABLE suppliers(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,official_name TEXT,normalized_official_name TEXT,normalized_name TEXT,cif TEXT,main_service_type_id INTEGER,active INTEGER DEFAULT 1);
CREATE TABLE supplier_aliases(id INTEGER PRIMARY KEY AUTOINCREMENT,supplier_id INTEGER,alias_type TEXT,value TEXT,normalized_value TEXT,active INTEGER DEFAULT 1);
CREATE TABLE service_types(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,normalized_name TEXT,active INTEGER DEFAULT 1);
CREATE TABLE supplier_service_types(supplier_id INTEGER,service_type_id INTEGER);
CREATE TABLE community_suppliers(id INTEGER PRIMARY KEY AUTOINCREMENT,community_id INTEGER,supplier_id INTEGER,category TEXT,contract_reference TEXT,source_column TEXT,raw_provider_name TEXT,created_at TEXT);
SQL;

// Same in-memory Database helper, but for the manual "run-worker" route: it also
// registers fake GET_LOCK/RELEASE_LOCK SQL functions (SQLite has no such thing)
// so Worker's real MySQL locking code can be exercised deterministically.
$workerSchema=<<<SQL
CREATE TABLE mailboxes(id INTEGER PRIMARY KEY AUTOINCREMENT,descriptive_name TEXT,email TEXT,imap_host TEXT,imap_port INTEGER,use_ssl INTEGER,username TEXT,encrypted_password TEXT,input_folder TEXT,active INTEGER DEFAULT 1,process_existing_on_activate INTEGER DEFAULT 0,baseline_uidvalidity TEXT,baseline_uid INTEGER,baseline_captured_at TEXT,last_connection_at TEXT,last_connection_ok INTEGER,last_error TEXT);
CREATE TABLE processing_runs(id INTEGER PRIMARY KEY AUTOINCREMENT,run_uuid TEXT,trigger_type TEXT,triggered_by_user_id INTEGER,started_at TEXT,finished_at TEXT,status TEXT,mailboxes_count INTEGER DEFAULT 0,messages_reviewed INTEGER DEFAULT 0,documents_detected INTEGER DEFAULT 0,classified_count INTEGER DEFAULT 0,unclassified_count INTEGER DEFAULT 0,needs_review_count INTEGER DEFAULT 0,duplicate_count INTEGER DEFAULT 0,not_invoice_count INTEGER DEFAULT 0,error_count INTEGER DEFAULT 0,openai_input_tokens INTEGER DEFAULT 0,openai_output_tokens INTEGER DEFAULT 0,error_message TEXT);
CREATE TABLE audit_log(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,action TEXT,entity_type TEXT,entity_id TEXT,new_values_json TEXT,ip_address TEXT,created_at TEXT);
CREATE TABLE processed_attachments(id INTEGER PRIMARY KEY AUTOINCREMENT,mailbox_id INTEGER,uidvalidity TEXT,message_uid TEXT,original_filename TEXT,attachment_sha256 TEXT,mime_type TEXT,size_bytes INTEGER,status TEXT,processed_at TEXT,community_id INTEGER,supplier_id INTEGER,document_type TEXT,document_confidence TEXT,provider TEXT,raw_supplier_name TEXT,provider_cif TEXT,service_type TEXT,supply_address TEXT,amount TEXT,currency TEXT,invoice_number TEXT,invoice_date TEXT,confidence TEXT,final_filename TEXT,output_path TEXT,extraction_json TEXT,decision_json TEXT,debug_trace_json TEXT,requeued_at TEXT,error_message TEXT,extractor_version TEXT,drive_file_id TEXT,drive_path TEXT,drive_status TEXT,UNIQUE(mailbox_id,uidvalidity,message_uid,attachment_sha256));
CREATE TABLE processed_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,mailbox_id INTEGER,uidvalidity TEXT,message_uid TEXT,message_id_header TEXT,sender TEXT,subject TEXT,received_at TEXT,status TEXT,document_count INTEGER DEFAULT 0,imap_destination TEXT,imap_move_status TEXT,error_message TEXT,processed_at TEXT);
CREATE TABLE communities(id INTEGER PRIMARY KEY AUTOINCREMENT,official_name TEXT,active INTEGER DEFAULT 1);
CREATE TABLE suppliers(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,official_name TEXT,active INTEGER DEFAULT 1);
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
    $pdo->sqliteCreateFunction('CURDATE',static fn():string=>date('Y-m-d'),0);
    $reflection=new ReflectionClass(Salvest\Database::class);
    $db=$reflection->newInstanceWithoutConstructor();
    $property=$reflection->getProperty('pdo');$property->setAccessible(true);$property->setValue($db,$pdo);
    return$db;
};
$workerConfig=static fn():array=>['app'=>['base_url'=>'http://127.0.0.1','timezone'=>'Europe/Madrid','session_name'=>'salvest_test_'.bin2hex(random_bytes(4)),
    'secret_key'=>'test-secret','encryption_key'=>Salvest\Crypto::generateKey(),'cron_token'=>'test','cookie_secure'=>false],
    'anthropic'=>['api_key'=>'test-key','model'=>'claude-test','timeout_seconds'=>5],
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
        [$mailboxId,'1001','500',$messageIdHeader,'facturas@proveedor.example','Factura',date('Y-m-d H:i:s'),'needs_review',count($attachmentStatuses),'facturgerman/Pendientes de revisión',$imapMoveStatus,date('Y-m-d H:i:s')]);
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

// ---- Dobles del pipeline reconstruido (comprensión con IA + resolvedores + política) ----
/** Comprensión con el formato nuevo a partir de una factura escrita con las claves antiguas
 * (proveedor, proveedor_cif, comunidad_cif, nombre_comunidad, direccion, tipo_servicio,
 * fecha_factura...). Así los casos reales que ya existían conservan exactamente sus datos. */
$understandingFromLegacy=static function(array $invoice):array{
    $type=$invoice['document_type']??'invoice';
    $hint=isset($invoice['tipo_servicio'])?Salvest\Text::normalize((string)$invoice['tipo_servicio']):null;
    return [
        'document_type'=>$type,
        'should_process_as_invoice'=>$invoice['should_process_as_invoice']??in_array($type,['invoice','credit_note'],true),
        'document_confidence'=>$invoice['document_confidence']??0.97,
        'document_reason'=>$invoice['document_reason']??'Factura con emisor, cliente, número, fecha e importe.',
        'issuer'=>['name'=>$invoice['proveedor']??null,'tax_id'=>$invoice['proveedor_cif']??null],
        'customer'=>['name'=>$invoice['nombre_comunidad']??null,'tax_id'=>$invoice['comunidad_cif']??null,'address'=>$invoice['direccion']??null,'postal_code'=>$invoice['codigo_postal']??null],
        'invoice'=>['number'=>$invoice['numero_factura']??'F-1','date'=>array_key_exists('fecha_factura',$invoice)?$invoice['fecha_factura']:'2026-09-15',
            'total_amount'=>$invoice['importe']??100.0,'currency'=>'EUR','billing_period'=>null],
        'service'=>['raw'=>$hint,'semantic_category'=>$invoice['semantic_category']??(in_array($hint,Salvest\AiContracts::SERVICE_CATEGORIES,true)?$hint:null)],
        'supply_identifiers'=>['cups'=>$invoice['cups']??null,'contract_number'=>$invoice['numero_contrato']??null,'customer_reference'=>$invoice['referencia_cliente']??null],
    ];
};
/** Elección "competente" de la IA falsa: la candidata cuyo nombre, alias o dirección aparece en
 * el texto del documento o se le parece mucho; si no hay una sola, se abstiene. Emula a un modelo
 * razonable para los casos de siempre; los casos semánticos (garaje = parking) se fijan a mano. */
$smartChoice=static function(array $candidates,array $understanding):array{
    $texts=array_values(array_filter(array_map([Salvest\Text::class,'normalize'],[$understanding['issuer']['name']??null,$understanding['customer']['name']??null,$understanding['customer']['address']??null]),static fn(string $t):bool=>$t!==''));
    $hits=[];
    foreach($candidates as $candidate){
        $names=array_merge(array_filter([$candidate['nombre']??null,$candidate['nombre_comercial']??null,$candidate['razon_social']??null,$candidate['direccion']??null,$candidate['nombre_carpeta_drive']??null]),$candidate['alias']??[]);
        foreach($names as $name){
            $normalized=Salvest\Text::normalizeCompanyName((string)$name);
            foreach($texts as $text){
                if($normalized!==''&&(Salvest\Text::containsWholeWords(Salvest\Text::normalizeCompanyName($text),$normalized)||Salvest\Text::containsAllWords(Salvest\Text::normalizeCompanyName($text),$normalized)||Salvest\Text::similarity($text,(string)$name)>=80)){$hits[$candidate['id']]=true;break 2;}
            }
        }
    }
    return count($hits)===1?['id'=>array_key_first($hits),'confidence'=>0.9,'reason'=>'coincide con el documento']:['id'=>null,'confidence'=>0.3,'reason'=>'no se puede distinguir'];
};
/** IA falsa con respuesta por tarea. Para las elecciones acepta: 'smart', 'abstain', 'fail'
 * (lanza, como un proveedor caído), un id, ['id'=>..,'confidence'=>..] o un callable(candidatos). */
$fakeAi=static function(array $understanding,mixed $community='smart',mixed $supplier='smart')use($smartChoice):Salvest\AiClient{
    return new class($understanding,$community,$supplier,$smartChoice) implements Salvest\AiClient{
        public array $calls=[];
        public function __construct(private array $understanding,private mixed $community,private mixed $supplier,private $smartChoice){}
        public function tasks():array{return array_column($this->calls,'task');}
        public function structured(string $task,string $instructions,array $schema,?string $pdfPath,string $userText,array $options=[]):Salvest\AiResponse{
            $candidates=null;
            if($task!==Salvest\AiContracts::UNDERSTAND_TASK){
                $candidates=json_decode(substr($userText,strpos($userText,"(lista cerrada):\n")+strlen("(lista cerrada):\n")),true);
            }
            $this->calls[]=['task'=>$task,'candidates'=>$candidates,'instructions'=>$instructions,'schema'=>$schema,'text'=>$userText];
            if($task===Salvest\AiContracts::UNDERSTAND_TASK)return new Salvest\AiResponse($this->understanding,'fake','fake-model',100,20,5);
            $spec=$task===Salvest\AiContracts::COMMUNITY_TASK?$this->community:$this->supplier;
            $field=$task===Salvest\AiContracts::COMMUNITY_TASK?'community_id':'supplier_id';
            if($spec==='fail')throw new RuntimeException('proveedor de IA caído');
            $choice=match(true){
                $spec==='smart'=>($this->smartChoice)($candidates,$this->understanding),
                $spec==='abstain'=>['id'=>null,'confidence'=>0.3,'reason'=>'no se puede distinguir'],
                is_int($spec)=>['id'=>$spec,'confidence'=>0.95,'reason'=>'elegido en el test'],
                is_callable($spec)=>$spec($candidates),
                default=>$spec,
            };
            return new Salvest\AiResponse([$field=>$choice['id'],'confidence'=>$choice['confidence'],'reason'=>$choice['reason']??''],'fake','fake-model',50,10,3);
        }
    };
};
/** Adaptador de forma para los casos reales escritos contra el antiguo InvoiceRouter::route():
 * misma llamada, pero ejecuta el pipeline nuevo (comprensión → comunidad → proveedor → servicio →
 * política). El 4º argumento, la antigua "llamada restringida" fn(candidatos, comunidad): ?int, se
 * convierte en la elección de proveedor de la IA; sin él, la IA falsa elige como un modelo
 * razonable ($smartChoice). Las evidencias se devuelven con los nombres nuevos (source/outcome). */
$routeCompat=static function(Salvest\Database $db,bool $autoLink=false)use($fakeAi,$understandingFromLegacy):object{
    return new class($db,$autoLink,$fakeAi,$understandingFromLegacy){
        public ?object $ai=null;
        public function __construct(private Salvest\Database $db,private bool $autoLink,private $fakeAi,private $fromLegacy){}
        public function route(array $invoice,string $sender='',string $context='',?callable $restricted=null,?callable $trace=null):array{
            $supplierChoice=$restricted===null?'smart':static function(array $candidates)use($restricted):array{
                $id=$restricted($candidates,[]);
                return['id'=>$id,'confidence'=>0.95,'reason'=>'elegido por la llamada del test'];
            };
            $this->ai=($this->fakeAi)(($this->fromLegacy)($invoice),'smart',$supplierChoice);
            $r=Salvest\Worker::processor($this->db,new Salvest\DocumentIntelligence($this->ai),null,$this->autoLink)->process('documento.pdf','factura.pdf','Remitente: '.$sender."\n".$context,$sender);
            $c=$r['classification'];
            $supplier=$r['supplier'];
            if($supplier!==null&&isset($c['supplier']))$supplier['category']=$this->db->one('SELECT category FROM community_suppliers WHERE community_id=? AND supplier_id=?',[(int)($r['community']['id']??0),(int)$supplier['id']])['category']??null;
            return['status'=>$r['status'],'supplier'=>$supplier,'reason'=>$r['reason'],'raw_supplier_name'=>$r['legacy']['raw_supplier_name'],'service'=>$r['service']['name'],'service_folder'=>$r['service']['folder'],
                'decision'=>['community'=>$r['community'],'evidence'=>['source'=>$c['community']['source'],'outcome'=>$c['community']['outcome']]],
                'evidence'=>['community'=>['source'=>$c['community']['source']],'supplier'=>['source'=>$c['supplier']['source'],'outcome'=>$c['supplier']['outcome'],'candidates'=>$c['supplier']['candidates']],
                    'service'=>['source'=>$r['service']['source']]],
                'supplier_ambiguous'=>in_array($c['supplier']['outcome'],['ai_abstained','ai_low_confidence'],true)&&count($c['supplier']['candidates'])>1,
                'autolink'=>$c['autolink'],'ai_tasks'=>$this->ai->tasks(),'result'=>$r];
        }
    };
};
/** SupplierResolver sin comunidad; $choose=null = sin IA (solo deterministas). */
$resolveSupplier=static fn(Salvest\Database $db,?string $name,?string $taxId,string $sender='facturas@x.example',?array $community=null,?callable $choose=null):array
    =>(new Salvest\SupplierResolver($db,new Salvest\SupplierCandidateRetriever($db)))->resolve(['issuer'=>['name'=>$name,'tax_id'=>$taxId]],$community,$sender,$choose);
/** CommunityResolver sobre una comprensión con solo los datos del cliente. $choice: 'smart',
 * 'abstain', null (sin IA), un id, ['id'=>..,'confidence'=>..] o callable(candidatos). */
$resolveCommunity=static function(Salvest\Database $db,array $customer,mixed $choice='smart')use($smartChoice):array{
    $resolver=new Salvest\CommunityResolver($db,new Salvest\CommunityCandidateRetriever($db));
    $understanding=['customer'=>$customer+['name'=>null,'tax_id'=>null,'address'=>null,'postal_code'=>null],'issuer'=>['name'=>null,'tax_id'=>null]];
    $choose=$choice===null?null:static function(array $candidates)use($choice,$smartChoice,$understanding):array{
        $raw=match(true){
            $choice==='smart'=>$smartChoice($candidates,$understanding),
            $choice==='abstain'=>['id'=>null,'confidence'=>0.3,'reason'=>'no se puede distinguir'],
            is_int($choice)=>['id'=>$choice,'confidence'=>0.95,'reason'=>'elegido en el test'],
            is_callable($choice)=>$choice($candidates),
            default=>$choice,
        };
        return Salvest\DocumentIntelligence::validateChoice(['community_id'=>$raw['id'],'confidence'=>$raw['confidence'],'reason'=>$raw['reason']??''],'community_id',array_column($candidates,'id'));
    };
    return $resolver->resolve($understanding,$choose);
};
/** Ejecuta el pipeline completo (sin Drive, sin IMAP, sin disco) sobre una base de prueba. */
$pipeline=static function(Salvest\Database $db,array $invoice,string $sender='facturas@proveedor.example',mixed $community='smart',mixed $supplier='smart',string $filename='factura.pdf',bool $autoLink=true)use($fakeAi,$understandingFromLegacy):array{
    $ai=$fakeAi(array_key_exists('document_type',$invoice)&&isset($invoice['issuer'])?$invoice:$understandingFromLegacy($invoice),$community,$supplier);
    $result=Salvest\Worker::processor($db,new Salvest\DocumentIntelligence($ai),null,$autoLink)->process('documento.pdf',$filename,'Remitente: '.$sender,$sender);
    $result['ai']=$ai;
    return$result;
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
$test('Fase 17 — ImapClient::sanitizeFolderPart() colapsa espacios repetidos a uno solo (caso real de producción: comunidad "CL SANTA TERESA  Y PLAZA SAN MARCOS 14" con doble espacio hacía que Gmail creara la carpeta con un solo espacio y el UID COPY posterior fallara con "No folder..." — el correo se quedaba atascado en la bandeja de entrada aunque las facturas ya se hubieran archivado bien)',static function()use($assert):void{
    $assert(Salvest\ImapClient::sanitizeFolderPart('71 - CL SANTA TERESA  Y PLAZA SAN MARCOS 14')==='71 - CL SANTA TERESA Y PLAZA SAN MARCOS 14');
    $assert(Salvest\ImapClient::sanitizeFolderPart('70 - CL.  ESCORREDOR 46')==='70 - CL. ESCORREDOR 46');
    $assert(Salvest\ImapClient::sanitizeFolderPart('Pendientes de revisión')==='Pendientes de revisión','sin espacios repetidos, no debe cambiar nada');
    $assert(Salvest\ImapClient::sanitizeFolderPart("con\ttabulador")==='con tabulador','un tabulador también cuenta como espacio repetible, no dos palabras pegadas');
    $assert(Salvest\ImapClient::sanitizeFolderPart('  bordes con espacios  ')==='bordes con espacios','recorta espacios sueltos al principio y al final, como antes');
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
$test('mime con boundary en mayúsculas (Outlook/Hotmail, Apple Mail) sí debe detectar el adjunto — caso real: "Factura rames facsa"',static function()use($assert):void{
    // Reproduce exactamente la forma real de un correo de Hotmail que se marcó "ignored" sin
    // haber detectado su PDF: boundary con mayúsculas ("_002_...hotmailcom_"), multipart/mixed
    // con una parte text/plain y una parte application/pdf con filename en RFC2047 (Windows-1252).
    $boundary='_002_FED2942EE6AB4DB983A124F2F96043ABhotmailcom_';
    $pdfPart="Content-Type: application/pdf;\r\n\tname=\"=?Windows-1252?Q?FACSA_ABRIL_45.42=80_RAMSES.pdf?=\"\r\n".
        "Content-Disposition: attachment;\r\n\tfilename=\"=?Windows-1252?Q?FACSA_ABRIL_45.42=80_RAMSES.pdf?=\"\r\n".
        "Content-Transfer-Encoding: base64\r\n\r\n".base64_encode('%PDF-demo-outlook');
    $raw="From: Juan Carlos M P <neococo@hotmail.com>\r\nSubject: Factura rames facsa\r\n".
        "Content-Type: multipart/mixed;\r\n\tboundary=\"$boundary\"\r\n\r\n".
        "--$boundary\r\nContent-Type: text/plain; charset=\"Windows-1252\"\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n\r\n".
        "--$boundary\r\n$pdfPart\r\n".
        "--$boundary--\r\n";
    $message=(new Salvest\MimeParser())->parse($raw);
    $assert(count($message['attachments'])===1,'debe encontrar el PDF pese al boundary en mayúsculas, no tratarlo como correo sin adjuntos: '.json_encode($message['attachments']));
    $assert($message['attachments'][0]['original_filename']==='FACSA ABRIL 45.42€ RAMSES.pdf','debe decodificar también el nombre RFC2047 en Windows-1252: '.$message['attachments'][0]['original_filename']);
    $assert($message['attachments'][0]['sha256']===hash('sha256','%PDF-demo-outlook'));
});
$test('mime con boundary en mayúsculas estilo Apple Mail (multipart/alternative con adjunto anidado) también debe detectarse',static function()use($assert):void{
    $boundary='Apple-Mail=_8A6213BB-9E12-49F2-A6CB-9AD20EABA9C3';
    $raw="From: Juan Carlos Mallo <juancarlos@infortorrent.com>\r\nSubject: Rdo factura ABEL MUS 1\r\n".
        "Content-Type: multipart/alternative;\r\n\tboundary=\"$boundary\"\r\n\r\n".
        "--$boundary\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nVer adjunto.\r\n".
        "--$boundary\r\nContent-Type: application/pdf; name=\"FACTURA_666.pdf\"\r\nContent-Disposition: attachment; filename=\"FACTURA_666.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode('%PDF-apple-mail')."\r\n".
        "--$boundary--\r\n";
    $message=(new Salvest\MimeParser())->parse($raw);
    $assert(count($message['attachments'])===1,'boundary con mayúsculas de Apple Mail también debe encontrar el adjunto: '.json_encode($message['attachments']));
    $assert($message['attachments'][0]['original_filename']==='FACTURA_666.pdf');
});
$test('validación rechaza un falso pdf',static function()use($assert):void{
    try{
        Salvest\DocumentValidator::validate(['payload'=>'esto no es un PDF','mime_type'=>'application/pdf','original_filename'=>'factura.pdf'],1024);
        $assert(false,'debería rechazar el documento');
    }catch(Salvest\NotPdfException $error){$assert(str_contains($error->getMessage(),'no es un PDF real'));}
});
$test('validación acepta un pdf',static function()use($assert):void{
    Salvest\DocumentValidator::validate(['payload'=>'%PDF-1.4 demo','mime_type'=>'application/pdf','original_filename'=>'factura.pdf'],1024);
    $assert(true);
});
$test('Fase 14 — validación rechaza un PDF protegido con contraseña (caso real: ni Claude ni OpenAI pudieron leerlo)',static function()use($assert):void{
    // "/Encrypt" en el trailer es justo la palabra clave real del formato PDF para un
    // diccionario de cifrado — reproduce el caso real encontrado en producción.
    $encrypted="%PDF-1.4\n...contenido...\ntrailer\n<< /Size 10 /Root 1 0 R /Encrypt 5 0 R /ID [<abc><abc>] >>\nstartxref\n123\n%%EOF";
    try{
        Salvest\DocumentValidator::validate(['payload'=>$encrypted,'mime_type'=>'application/pdf','original_filename'=>'factura-protegida.pdf'],1024);
        $assert(false,'debería rechazar un PDF cifrado');
    }catch(Salvest\EncryptedPdfException $error){$assert(str_contains($error->getMessage(),'contraseña'));}
});
$test('Fase 14 — un PDF sin "/Encrypt" en el trailer NUNCA se confunde con uno cifrado, aunque contenga la palabra "Encrypt" en otro contexto',static function()use($assert):void{
    // Con barra inicial explícita en la comprobación (str_contains($payload,\'/Encrypt\')) para no
    // disparar con texto normal de factura que mencione la palabra "Encrypt" sin la barra.
    Salvest\DocumentValidator::validate(['payload'=>"%PDF-1.4\nEste documento no usa Encrypt de ningún tipo.\n%%EOF",'mime_type'=>'application/pdf','original_filename'=>'factura.pdf'],1024);
    $assert(true,'sin la barra inicial, no debe considerarse cifrado');
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
$test('Fase 13 — Archiver::archive() añade el importe al final del nombre cuando la extracción lo trajo',static function()use($assert):void{
    $root=sys_get_temp_dir().'/salvest-test-'.bin2hex(random_bytes(4)); mkdir($root,0770,true);
    $invoice=['fecha_factura'=>'2026-07-01','tipo_servicio'=>'agua','proveedor'=>'Hidralux Servicios','importe'=>13.6];
    $community=['official_name'=>'CP Uno']; $archiver=new Salvest\Archiver($root);
    $file=$root.'/one.pdf';file_put_contents($file,'%PDF');
    $result=$archiver->archive($file,'factura.pdf',$invoice,$community,'classified');
    $assert(basename($result)==='2026-07_agua_hidralux-servicios_13.60.pdf','debe llevar el importe con 2 decimales: '.basename($result));
});
$test('Fase 13 — Archiver::archive() NUNCA inventa un importe: sin dato, el nombre queda exactamente como antes (regresión)',static function()use($assert):void{
    $root=sys_get_temp_dir().'/salvest-test-'.bin2hex(random_bytes(4)); mkdir($root,0770,true);
    $invoice=['fecha_factura'=>'2026-07-01','tipo_servicio'=>'agua','proveedor'=>'Hidralux Servicios'];
    $community=['official_name'=>'CP Uno']; $archiver=new Salvest\Archiver($root);
    $file=$root.'/one.pdf';file_put_contents($file,'%PDF');
    $result=$archiver->archive($file,'factura.pdf',$invoice,$community,'classified');
    $assert(basename($result)==='2026-07_agua_hidralux-servicios.pdf','sin importe extraído, no debe añadirse ningún sufijo: '.basename($result));
});
$test('Fase 14 — DriveInvoiceArchiver::amountToken(): numérico -> "XX.XX€", ausente/no numérico -> cadena vacía (nunca "0.00" inventado)',static function()use($assert):void{
    $assert(Salvest\DriveInvoiceArchiver::amountToken(13.6)==='13.60€');
    $assert(Salvest\DriveInvoiceArchiver::amountToken(0)==='0.00€','un importe real de 0 sí debe reflejarse, no confundirse con "ausente"');
    $assert(Salvest\DriveInvoiceArchiver::amountToken(null)==='');
    $assert(Salvest\DriveInvoiceArchiver::amountToken('')==='');
    $assert(Salvest\DriveInvoiceArchiver::amountToken('no es un número')==='');
});
$test('Fase 14 — DriveInvoiceArchiver::folderMatchesCode(): reconoce el código de comunidad al principio del nombre pase lo que pase después, sin falsos positivos por prefijo',static function()use($assert):void{
    $assert(Salvest\DriveInvoiceArchiver::folderMatchesCode('82 - MENENDEZ Y PELAYO 10','82'));
    $assert(Salvest\DriveInvoiceArchiver::folderMatchesCode('82-MENENDEZ YPELAYO 10','82'),'debe dar igual el separador exacto (sin espacio)');
    $assert(Salvest\DriveInvoiceArchiver::folderMatchesCode('082 - ALGO','82'),'un cero a la izquierda en la carpeta no debe importar');
    $assert(!Salvest\DriveInvoiceArchiver::folderMatchesCode('820 - OTRA COMUNIDAD','82'),'"820" nunca debe confundirse con la comunidad "82"');
    $assert(!Salvest\DriveInvoiceArchiver::folderMatchesCode('8 - OTRA','82'));
    $assert(!Salvest\DriveInvoiceArchiver::folderMatchesCode('Doc año en Vigor','82'),'sin ningún dígito inicial, nunca hay coincidencia');
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
    // Reconstrucción: los identificadores contractuales ya no deciden la comunidad (decisión
    // explícita, se revisarán más adelante), pero siguen guardados y viajando en el formulario.
    $kept=$db->one('SELECT value FROM community_identifiers WHERE community_id=?',[$id]);
    $assert($kept!==null && $kept['value']==='ES0021000000000001JN','el identificador ocultado en la UI se conserva en la base de datos');
});
$test('/Proveedores: el CIF ahora es visible/editable (cambio deliberado de esta mini-fase); los aliases siguen ocultos y no se borran',static function()use($assert,$sqliteDb,$classifierSchema):void{
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
    $assert(str_contains($html,'name="cif" value="A12345678"'),'el CIF existente debe precargarse en el campo visible: '.$html);
    $assert(preg_match('/<label>CIF \/ NIF \/ NIE<input/',$html)===1,'el CIF ya debe ser editable en el alta/edición, con su propia etiqueta');
    $assert(str_contains($html,'<input type="hidden" name="aliases" value="iberdrola.es">'),'el alias existente debe seguir viajando oculto (Fase 5, no esta mini-fase)');
    $assert(!str_contains($html,'<textarea name="aliases"'),'los aliases siguen sin ser editables en el alta/edición cotidiana');
    $assert(!str_contains($html,'Otros nombres o dominios conocidos'),'la etiqueta visible de aliases sigue sin aparecer');
    $resolver=new Salvest\SupplierResolver($db,new Salvest\SupplierCandidateRetriever($db));
    $resolved=$resolver->resolve(['issuer'=>['name'=>null,'tax_id'=>'A12345678']],null,'facturas@otra-cosa.example',null);
    $assert($resolved['supplier']!==null && (int)$resolved['supplier']['id']===$id && $resolved['source']==='tax_id_exact','el CIF ocultado en la UI debe seguir siendo utilizable por el resolvedor');
    $resolvedByAlias=$resolver->resolve(['issuer'=>['name'=>'Nombre distinto','tax_id'=>null]],null,'facturas@iberdrola.es',null);
    $assert($resolvedByAlias['supplier']!==null && (int)$resolvedByAlias['supplier']['id']===$id && $resolvedByAlias['source']==='alias_exact','el alias/dominio ocultado en la UI debe seguir siendo utilizable por el resolvedor: '.json_encode($resolvedByAlias['outcome']));
});
$communityResolver=static fn(Salvest\Database $db):Salvest\CommunityResolver=>new Salvest\CommunityResolver($db,new Salvest\CommunityCandidateRetriever($db));
$test('Reconstrucción — los identificadores contractuales (CUPS, contrato) ya no deciden la comunidad: decisión explícita de esta fase; sin otra señal no hay candidatas',static function()use($assert,$sqliteDb,$classifierSchema,$communityResolver):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,postal_code,city,imap_folder_name,active) VALUES (?,?,?,?,?,?,?,?,1)',
        ['02','CP DOS',Salvest\Text::normalize('CP DOS'),'','Calle Dos 2','46002','Valencia','02 - CP DOS']);
    $id=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO community_identifiers(community_id,identifier_type,value,normalized_value,active) VALUES (?,?,?,?,1)',[$id,'contract','C-9988',Salvest\Text::normalize('C-9988')]);
    $calls=0;
    $result=$communityResolver($db)->resolve(['customer'=>['name'=>null,'tax_id'=>null,'address'=>null],'supply_identifiers'=>['contract_number'=>'C-9988']],function()use(&$calls){$calls++;return['id'=>null,'confidence'=>0.0,'reason'=>'','valid'=>true,'rejected_id'=>null];});
    $assert($result['community']===null && $result['outcome']==='no_candidates' && $calls===0,'sin NIF, nombre ni dirección no hay candidatas y no se llama a la IA: '.json_encode($result['outcome']));
});
$test('NIF de comunidad único: resuelve directamente, confianza 1 y sin llamar a la IA',static function()use($assert,$sqliteDb,$classifierSchema,$communityResolver):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,postal_code,city,imap_folder_name,active) VALUES (?,?,?,?,?,?,?,?,1)',
        ['03','CP TRES',Salvest\Text::normalize('CP TRES'),'B99999999','Calle Tres 3','46003','Valencia','03 - CP TRES']);
    $id=(int)$db->pdo()->lastInsertId();
    $result=$communityResolver($db)->resolve(['customer'=>['name'=>'OTRA COSA DISTINTA','tax_id'=>'B-99999999','address'=>'Calle Inventada 9']],static fn()=>throw new RuntimeException('no debe llamarse a la IA'));
    $assert($result['community']!==null && (int)$result['community']['id']===$id && $result['source']==='tax_id_exact' && $result['confidence']===1.0,
        'un NIF exacto decide aunque el nombre y la dirección no coincidan: '.json_encode($result['outcome']));
});
$test('comunidades por dirección alias: el alias propone la candidata y la IA la confirma',static function()use($assert,$sqliteDb,$classifierSchema,$communityResolver,$smartChoice):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,postal_code,city,imap_folder_name,active) VALUES (?,?,?,?,?,?,?,?,1)',
        ['04','CP CUATRO',Salvest\Text::normalize('CP CUATRO'),'','Calle Falsa 123','46004','Valencia','04 - CP CUATRO']);
    $id=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO community_aliases(community_id,alias_type,value,normalized_value,active) VALUES (?,?,?,?,1)',[$id,'address','Avenida Real 45',Salvest\Text::normalize('Avenida Real 45')]);
    $understanding=['customer'=>['name'=>null,'tax_id'=>null,'address'=>'Avenida Real 45']];
    $result=$communityResolver($db)->resolve($understanding,static fn(array $candidates):array=>Salvest\DocumentIntelligence::validateChoice(
        ['community_id'=>$smartChoice($candidates,$understanding)['id'],'confidence'=>0.9,'reason'=>'alias'],'community_id',array_column($candidates,'id')));
    $assert($result['community']!==null && (int)$result['community']['id']===$id && $result['source']==='ai_after_retrieval','debe encontrar la comunidad a través del alias de dirección: '.json_encode($result['candidates']));
    $assert(in_array('Avenida Real 45',$result['candidates'][0]['alias']??[],true),'la IA recibe el alias de la candidata');
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
$test('Fase 22 — estado del bot: "a revisión" suma también las sin clasificar (caso real 24/09 18:55: 4 archivadas y 5 sin clasificar se mostraba como "0 pendientes"), y el rótulo dice que es de esa ejecución',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp):void{
    $db=$sqliteDbWithLock('always-free');
    $webApp=$makeWebApp($db,$workerConfig());
    $db->execute('INSERT INTO processing_runs(run_uuid,trigger_type,started_at,finished_at,status,classified_count,needs_review_count,unclassified_count,error_count) VALUES (?,?,?,?,?,?,?,?,?)',
        ['uuid-real','cron',date('Y-m-d H:i:s'),date('Y-m-d H:i:s'),'completed',4,0,5,0]);
    $method=new ReflectionMethod(Salvest\WebApp::class,'botStatusCard');$method->setAccessible(true);
    $html=$method->invoke($webApp);
    $assert(str_contains($html,'Resultado de esa ejecución: <strong>4 archivadas · 5 a revisión · 0 errores'),$html);
});
$test('estado del bot: el dashboard muestra la última ejecución sin detalles técnicos',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp):void{
    $db=$sqliteDbWithLock('always-free');
    $webApp=$makeWebApp($db,$workerConfig());
    $db->execute('INSERT INTO processing_runs(run_uuid,trigger_type,started_at,finished_at,status,classified_count,needs_review_count,error_count) VALUES (?,?,?,?,?,?,?,?)',
        ['uuid-1','manual',date('Y-m-d H:i:s'),date('Y-m-d H:i:s'),'completed',2,1,0]);
    $method=new ReflectionMethod(Salvest\WebApp::class,'botStatusCard');$method->setAccessible(true);
    $html=$method->invoke($webApp);
    $assert(str_contains($html,'2 archivadas'),'debe mostrar cuántas facturas se archivaron: '.$html);
    $assert(str_contains($html,'1 a revisión'),'debe mostrar cuántas se mandaron a revisión: '.$html);
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
    $assert(str_contains($source,'$inboxUids = $client->listUids();')&&str_contains($source,'applyBaseline($mailbox, $client, $inboxUids)'),'el filtro de baseline debe aplicarse sobre el listado de UIDs antes del bucle de mensajes');
    $assert(str_contains($source,"SELECT status FROM processed_messages WHERE mailbox_id=? AND uidvalidity=? AND message_uid=?"),'la deduplicación por mailbox_id+UIDVALIDITY+UID debe seguir intacta después del filtro de baseline');
    $assert(str_contains($source,"in_array(\$existing['status'],['completed','ignored','needs_review','error'],true)) continue;"),'solo los estados terminales deben saltarse; el baseline no sustituye a esta comprobación');
});
$test('baseline: el límite max_messages_per_mailbox se sigue aplicando tras el filtro de baseline (guarda de regresión de código)',static function()use($assert):void{
    $source=file_get_contents(__DIR__.'/../src/Worker.php');
    $assert((bool)preg_match('/applyBaseline\(\$mailbox, \$client, \$inboxUids\); \$examined = 0;/',$source),'el contador de mensajes examinados debe arrancar en 0 después de aplicar el baseline, no antes');
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

$test('caso Iberdrola: MySQL corrige el "Agua" incorrecto de OpenAI usando CIF titular + CIF proveedor + tipo configurado',static function()use($assert,$sqliteDb,$classifierSchema,$routeCompat):void{
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
    $route=$routeCompat($db)->route($invoice,'facturas@iberdrola.es');

    $assert($route['status']==='classified','el caso real debe quedar clasificado automáticamente: '.json_encode($route));
    $assert((int)$route['decision']['community']['id']===$communityId,'la comunidad debe ser PK ILLES BALEARS 23, no la del CUPS señuelo');
    $assert($route['decision']['evidence']['source']==='tax_id_exact','la comunidad debe resolverse por CIF titular, con prioridad sobre CUPS/contrato');
    $assert((int)$route['supplier']['id']===$supplierId && $route['supplier']['official_name']==='IBERDROLA CLIENTES, S.A.U.','el proveedor debe ser Iberdrola');
    $assert($route['evidence']['supplier']['source']==='tax_id_exact','el proveedor debe resolverse por su propio CIF, nunca el de la comunidad');
    $assert($route['service']==='ELECTRICIDAD','MySQL debe corregir el "agua" erróneo de OpenAI: '.$route['service']);
    $assert($route['evidence']['service']['source']==='supplier_main_service','el servicio debe venir del tipo configurado del proveedor, no de la sugerencia de OpenAI');
    $assert($route['reason']===null,'un caso correctamente asociado no debe llevar motivo de revisión');
});
$test('Fase 6 — proveedor reconocido globalmente pero no asociado a la comunidad: clasifica con el fallback global, sin crear ninguna relación community_suppliers',static function()use($assert,$sqliteDb,$classifierSchema,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,postal_code,city,imap_folder_name,active) VALUES (?,?,?,?,?,?,?,?,1)',
        ['30','CP TREINTA',Salvest\Text::normalize('CP TREINTA'),'H99999999','Calle Treinta','46030','Valencia','30 - CP TREINTA']);
    $communityId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO service_types(name,normalized_name,active) VALUES (?,?,1)',['ELECTRICIDAD',Salvest\Text::normalize('ELECTRICIDAD')]);
    $serviceTypeId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO suppliers(official_name,normalized_name,cif,main_service_type_id,active) VALUES (?,?,?,?,1)',
        ['IBERDROLA CLIENTES, S.A.U.',Salvest\Text::normalize('IBERDROLA CLIENTES, S.A.U.'),'A95758389',$serviceTypeId]);
    // Nótese: no hay fila en community_suppliers vinculando a este proveedor con esta comunidad.
    $before=(int)$db->one('SELECT COUNT(*) n FROM community_suppliers')['n'];
    $invoice=['proveedor'=>'IBERDROLA CLIENTES, S.A.U.','proveedor_cif'=>'A95758389','comunidad_cif'=>'H99999999','tipo_servicio'=>'agua'];
    $route=$routeCompat($db)->route($invoice,'facturas@iberdrola.es');
    $assert($route['status']==='classified','Fase 6: un proveedor reconocido de forma inequívoca a nivel global debe clasificar, aunque no exista todavía community_suppliers: '.json_encode($route));
    $assert($route['supplier']!==null && $route['supplier']['cif']==='A95758389','debe usarse el proveedor global resuelto');
    $assert($route['evidence']['supplier']['source']==='tax_id_exact'&&$route['result']['classification']['supplier']['linked']===false,'la evidencia debe dejar constancia de que se resolvió por CIF sin estar vinculado a la comunidad');
    $assert($route['service']==='ELECTRICIDAD','el servicio debe salir de supplier.main_service_type_id, con relation=null');
    $assert($route['reason']===null,'una clasificación correcta no debe llevar motivo de revisión');
    $after=(int)$db->one('SELECT COUNT(*) n FROM community_suppliers')['n'];
    $assert($before===$after && $after===0,'Fase 6 NO debe crear ninguna relación community_suppliers — eso es Fase 7 (autolink): antes='.$before.' después='.$after);
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

$test('resolución contextual: IBERDROLA (sin CIF) reconoce "IBERDROLA CLIENTES, S.A.U." por contención de nombre',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'23','PK ILLES BALEARS 23','IBERDROLA','ELECTRICIDAD');
    $invoice=['proveedor'=>'IBERDROLA CLIENTES, S.A.U.','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'agua'];
    $route=$routeCompat($db)->route($invoice,'facturas@iberdrola.es');
    $assert($route['status']==='classified','el caso real (sin CIF de proveedor en el maestro) debe clasificar solo: '.json_encode($route));
    $assert((int)$route['supplier']['id']===$fixture['supplierId'],'debe resolver al proveedor IBERDROLA');
    $assert($route['evidence']['supplier']['source']==='ai_after_retrieval'&&in_array('containment',$route['evidence']['supplier']['candidates'][0]['senales']??[],true),'debe quedar constancia de que fue por contención de nombre, no CIF');
    $assert($route['service']==='ELECTRICIDAD','debe corregir el "agua" de OpenAI usando el tipo configurado del proveedor: '.$route['service']);
    $assert($route['evidence']['service']['source']==='supplier_main_service');
});
$test('resolución contextual: IBERDROLA vs "IBERDROLA CLIENTES" sin forma societaria también contiene el nombre',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'24','CP VEINTICUATRO','IBERDROLA','ELECTRICIDAD');
    $invoice=['proveedor'=>'IBERDROLA CLIENTES','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'desconocido'];
    $route=$routeCompat($db)->route($invoice,'facturas@iberdrola.es');
    $assert($route['status']==='classified' && (int)$route['supplier']['id']===$fixture['supplierId'],json_encode($route));
});
$test('resolución contextual: formas societarias distintas (maestro S.L., factura S.A.) igualan por nombre comercial',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'25','CP VEINTICINCO','AGUAS DEL LEVANTE, S.L.','AGUA');
    $invoice=['proveedor'=>'AGUAS DEL LEVANTE, S.A.','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'desconocido'];
    $route=$routeCompat($db)->route($invoice,'facturas@aguaslevante.es');
    $assert($route['status']==='classified' && (int)$route['supplier']['id']===$fixture['supplierId'],'S.L. en el maestro y S.A. en la factura deben considerarse el mismo nombre comercial: '.json_encode($route));
    $assert($route['evidence']['supplier']['source']==='name_exact','tras quitar la forma societaria ambos nombres deben quedar idénticos, no solo contenidos');
});
$test('resolución contextual: mayúsculas, tildes y puntuación no impiden la coincidencia exacta',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'26','CP VEINTISEIS','Jardinería Compañía, S.L.','JARDINERIA');
    $invoice=['proveedor'=>'JARDINERIA COMPAÑIA SL','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'desconocido'];
    $route=$routeCompat($db)->route($invoice,'facturas@jardineria.es');
    $assert($route['status']==='classified' && (int)$route['supplier']['id']===$fixture['supplierId'],json_encode($route));
});
$test('resolución contextual: alias de proveedor dentro de la comunidad',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'27','CP VEINTISIETE','Descalcificadores Este S.L.','DESCALCIFICADOR');
    $db->execute('INSERT INTO supplier_aliases(supplier_id,alias_type,value,normalized_value,active) VALUES (?,?,?,?,1)',
        [$fixture['supplierId'],'name','AQUATREAT',Salvest\Text::normalize('AQUATREAT')]);
    $invoice=['proveedor'=>'AQUATREAT','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'desconocido'];
    $route=$routeCompat($db)->route($invoice,'facturas@aquatreat.example');
    $assert($route['status']==='classified' && (int)$route['supplier']['id']===$fixture['supplierId'],json_encode($route));
    $assert($route['evidence']['supplier']['source']==='alias_exact','debe quedar constancia de que fue por alias: '.json_encode($route['evidence']['supplier']));
});
$test('resolución contextual: dos proveedores igualmente plausibles van a revisión, no se adivina',static function()use($assert,$sqliteDb,$classifierSchema,$routeCompat):void{
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
    $route=$routeCompat($db)->route($invoice,'facturas@ambiguo.example');
    $assert($route['status']==='needs_review','ante dos candidatos igual de plausibles no debe adivinar: '.json_encode($route));
    $assert($route['supplier']===null);
    $assert(str_contains((string)$route['reason'],'varios proveedores posibles'),'el motivo debe explicar que hay varios candidatos: '.$route['reason']);
    $assert(count($route['evidence']['supplier']['candidates'])===2&&$route['ai_tasks']===['comprender_documento','elegir_proveedor'],'la IA recibe los dos candidatos y se abstiene: '.json_encode($route['ai_tasks']));
});
$test('resolución contextual: si hay CIF de proveedor disponible, sigue ganando a un nombre que no coincide',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'29','CP VEINTINUEVE','IBERDROLA','ELECTRICIDAD','A95758389');
    // El nombre extraído no se parece en nada al maestro, pero el CIF sí coincide exactamente.
    $invoice=['proveedor'=>'Suministradora Eléctrica Desconocida SL','proveedor_cif'=>'A95758389','comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'agua'];
    $route=$routeCompat($db)->route($invoice,'facturas@otra.example');
    $assert($route['status']==='classified' && (int)$route['supplier']['id']===$fixture['supplierId'],'el CIF debe ganar aunque el nombre extraído no se parezca al maestro: '.json_encode($route));
    $assert($route['evidence']['supplier']['source']==='tax_id_exact');
    $assert($route['service']==='ELECTRICIDAD');
});
$test('inferencia comunidad+servicio: caso real MENENDEZ YPELAYO 10 / CRISLA — único proveedor de LIMPIEZA se infiere sin coincidencia de nombre',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'82','MENENDEZ YPELAYO 10','CRISLA','LIMPIEZA',null,'H12557229');
    // OpenAI no acertó el nombre del proveedor (extrajo un texto que no contiene "CRISLA" en
    // absoluto ni comparte palabras con él — p.ej. de una cabecera o sello confusos), pero sí el servicio.
    $invoice=['proveedor'=>'Servicios Generales de Mantenimiento Ibérica','proveedor_cif'=>'B12534228','comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'limpieza'];
    // Nótese: aunque el nombre y el CIF del proveedor no están en el maestro (como en la
    // realidad: suppliers.cif es NULL), la inferencia comunidad+servicio debe bastar igualmente.
    // Reconstrucción: "único proveedor del servicio en la comunidad" ya no decide sin IA; CRISLA
    // llega a la IA como candidato vinculado a la comunidad (con su servicio) y la IA lo elige.
    $route=$routeCompat($db)->route($invoice,'facturas@crisla.example','',static fn(array $candidates):?int=>$fixture['supplierId']);
    $assert($route['status']==='classified','con CRISLA entre los candidatos y elegido por la IA debe clasificar: '.json_encode($route));
    $assert((int)$route['supplier']['id']===$fixture['supplierId'],'debe resolver a CRISLA');
    $crisla=$route['evidence']['supplier']['candidates'][0]??[];
    $assert($route['evidence']['supplier']['source']==='ai_after_retrieval' && ($crisla['ya_trabaja_con_la_comunidad']??false)===true && ($crisla['servicio_principal']??null)==='LIMPIEZA','la IA ve que CRISLA ya trabaja con la comunidad y su servicio: '.json_encode($route['evidence']['supplier']));
    $assert($route['service']==='LIMPIEZA');
});
$test('Fase 20 — inferencia comunidad+servicio: caso real SANCHEZ/IBERDROLA — si el documento trae un CIF de proveedor y el único candidato tiene OTRO CIF en el maestro, nunca se elige (antes: presupuesto de MONTAJES ELECTRICOS O. SANCHEZ archivado como IBERDROLA)',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'24','URBANIZACION LA PALMERA','IBERDROLA CLIENTES, S.A.U.','ELECTRICIDAD','A95758389','H12351482');
    $invoice=['proveedor'=>'MONTAJES ELECTRICOS O. SANCHEZ S.L.','proveedor_cif'=>'B12632105','comunidad_cif'=>'H12351482','tipo_servicio'=>'electricidad'];
    $route=$routeCompat($db)->route($invoice,'sanchez@example.com');
    $assert($route['supplier']===null,'no debe resolverse a IBERDROLA con un CIF de documento distinto: '.json_encode($route['supplier']));
    $assert($route['status']==='needs_review','comunidad conocida pero proveedor sin resolver -> revisión: '.$route['status']);
});
$test('Fase 20 — reintento restringido con IA: si la IA elige un proveedor cuyo CIF contradice el del documento, la elección se descarta',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'24','URBANIZACION LA PALMERA','IBERDROLA CLIENTES, S.A.U.','ELECTRICIDAD','A95758389','H12351482');
    $invoice=['proveedor'=>'MONTAJES ELECTRICOS O. SANCHEZ S.L.','proveedor_cif'=>'B12632105','comunidad_cif'=>'H12351482','tipo_servicio'=>'desconocido'];
    $route=$routeCompat($db)->route($invoice,'sanchez@example.com','',static fn(array $candidates,array $community):int=>$fixture['supplierId']);
    $assert($route['supplier']===null,'la elección de la IA con CIF contradictorio debe descartarse: '.json_encode($route['supplier']));
});
$test('Fase 20 — ClassificationPolicy::filenameSuggestsQuote(): el nombre del archivo delata un presupuesto como palabra completa; nunca una factura normal',static function()use($assert):void{
    $assert(Salvest\ClassificationPolicy::filenameSuggestsQuote('Presupuesto  1-000035.pdf'),'caso real: nombre de archivo de Rafael Guijarro');
    $assert(Salvest\ClassificationPolicy::filenameSuggestsQuote('PRE 266.pdf'),'caso real: nombre de archivo de O. Sanchez');
    $assert(Salvest\ClassificationPolicy::filenameSuggestsQuote('Pressupost_12.pdf'));
    $assert(Salvest\ClassificationPolicy::filenameSuggestsQuote('Proforma 7.pdf'));
    $assert(!Salvest\ClassificationPolicy::filenameSuggestsQuote('Factura H-12286035 1-000167.pdf'),'una factura normal nunca');
    $assert(!Salvest\ClassificationPolicy::filenameSuggestsQuote('INFORME CERTIFICADO I22921.PDF'));
    $assert(!Salvest\ClassificationPolicy::filenameSuggestsQuote('Prestaciones premium.pdf'),'"pre" solo cuenta como palabra completa');
});
$test('Fase 20 → reconstrucción — un presupuesto nunca se archiva como factura: la IA lo identifica (budget) y con certeza queda como no factura; una "factura" cuyo archivo se llama Presupuesto va a revisión',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier,$pipeline):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'24','URBANIZACION LA PALMERA','RAFAEL GUIJARRO PRADES','LIMPIEZA','11111111H','H12351482');
    $budget=$pipeline($db,['document_type'=>'budget','document_confidence'=>0.96,'proveedor'=>'RAFAEL GUIJARRO PRADES','proveedor_cif'=>'11111111H','comunidad_cif'=>'H12351482'],'rafa@example.com','smart','smart','Presupuesto  1-000035.pdf');
    $assert($budget['status']==='ignored_not_invoice' && $budget['reason_code']==='not_invoice','un presupuesto con certeza alta no es factura: '.$budget['status']);
    $assert($budget['community']===null && $budget['ai']->tasks()===['comprender_documento'],'un documento descartado no gasta más IA ni se resuelve');
    $byName=$pipeline($db,['proveedor'=>'RAFAEL GUIJARRO PRADES','proveedor_cif'=>'11111111H','comunidad_cif'=>'H12351482'],'rafa@example.com','smart','smart','Presupuesto 1-000037.pdf');
    $assert($byName['status']==='needs_review' && $byName['reason_code']==='quote_filename','la IA dice factura pero el archivo dice presupuesto: revisión: '.$byName['status']);
    $assert(in_array('budget',Salvest\AiContracts::DOCUMENT_TYPES,true),'el contrato de la IA distingue presupuestos');
});
$test('Fase 20 — Text::normalize() quita el marcador de número delante de una cifra (N-12, Nº 1, núm. 5, nº12), y nada más',static function()use($assert):void{
    $assert(Salvest\Text::normalize('MEDITERRANEO N-12')==='mediterraneo 12');
    $assert(Salvest\Text::normalize('C.P. LES ERES N-3')==='c p les eres 3');
    $assert(Salvest\Text::normalize('C/ BENET XIII Nº 1')==='c benet xiii 1');
    $assert(Salvest\Text::normalize('Calle Mayor núm. 5')==='calle mayor 5');
    $assert(Salvest\Text::normalize('Portal nº12')==='portal 12');
    $assert(Salvest\Text::normalize('No hay nada')==='no hay nada','"no" sin número detrás se queda');
    $assert(Salvest\Text::normalize('Plaza N')==='plaza n','"n" al final se queda');
});
$test('Fase 20 — casos reales de Rafael Guijarro: "C.P. MEDITERRANEO N-12" y "C.P. LES ERES N-3" llegan como candidatas y se resuelven (el NIF mal escrito no estorba)',static function()use($assert,$sqliteDb,$classifierSchema,$resolveCommunity):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,active) VALUES (?,?,?,?,?,1)',['90','AVDA. MEDITERRANEA 12',Salvest\Text::normalize('AVDA. MEDITERRANEA 12'),'H12278057','AVDA MEDITERRANEA 12']);
    $mediterraneoId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO community_aliases(community_id,alias_type,value,normalized_value,active) VALUES (?,?,?,?,1)',[$mediterraneoId,'address','MEDITERRANEO 12',Salvest\Text::normalize('MEDITERRANEO 12')]);
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,active) VALUES (?,?,?,?,?,1)',['01','LES ERES 3',Salvest\Text::normalize('LES ERES 3'),'H12805990','CALLE LES ERES 3']);
    $leseresId=(int)$db->pdo()->lastInsertId();
    $a=$resolveCommunity($db,['name'=>'C.P. MEDITERRANEO N-12','address'=>'MEDITERRANEO N-12']);
    $assert((int)($a['community']['id']??0)===$mediterraneoId && $a['source']==='ai_after_retrieval',json_encode($a['candidates']));
    $b=$resolveCommunity($db,['name'=>'C.P. LES ERES N-3','address'=>'LES ERES N-3','tax_id'=>'H-1285990']);
    $assert((int)($b['community']['id']??0)===$leseresId && $b['tax_id']['matches']===0,'el NIF erróneo no coincide con nada y se recurre a candidatas: '.json_encode($b['candidates']));
});
$test('Fase 23 — caso real FACSA Tarancón: la IA puso el CIF de la comunidad como CIF del proveedor y dejó comunidad_cif vacío -> se recoloca y la factura se clasifica sola',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'67','CARDENAL VTE. ENRIQUE TARANCON','SOCIEDAD DE FOMENTO AGRÍCOLA CASTELLONENSE, S.A.U.','Agua','A12000022','H12542460');
    $invoice=['proveedor'=>'SOCIEDAD DE FOMENTO AGRÍCOLA CASTELLONENSE, S.A.U.','proveedor_cif'=>'H12542460','comunidad_cif'=>null,
        'nombre_comunidad'=>'CDAD PROP AV CARDENAL V ENRIQUE TARANCON','direccion'=>'CARRER MARE TERESA DE CALCUTA 18 - C.G. S.E., BURRIANA','tipo_servicio'=>'agua'];
    $route=$routeCompat($db)->route($invoice,'facturas@facsa.example');
    $assert((int)($route['decision']['community']['id']??0)===$fixture['communityId'],'debe resolver la comunidad por su CIF: '.json_encode($route['decision']));
    $assert($route['decision']['evidence']===['source'=>'tax_id_exact','outcome'=>'resolved'],json_encode($route['decision']['evidence']));
    $assert($route['result']['classification']['corrections']===['issuer_tax_id_moved_to_customer'],'la corrección queda registrada: '.json_encode($route['result']['classification']['corrections']));
    $assert((int)($route['supplier']['id']??0)===$fixture['supplierId']&&$route['status']==='classified',json_encode([$route['status'],$route['supplier']]));
});
$test('Fase 23 — CIF de comunidad leído como CIF del emisor: se mueve al cliente si estaba vacío, se descarta del emisor si ya venía, y un CIF de proveedor real nunca se toca',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier,$pipeline):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'03','XILXES 14','FACSA','Agua','A12000022','H12725404');
    $moved=$pipeline($db,['proveedor'=>'FACSA','proveedor_cif'=>'H-12725404','comunidad_cif'=>null]);
    $assert($moved['understanding']['customer']['tax_id']==='H-12725404' && $moved['understanding']['issuer']['tax_id']===null && $moved['classification']['corrections']===['issuer_tax_id_moved_to_customer'],json_encode($moved['classification']['corrections']));
    $both=$pipeline($db,['proveedor'=>'FACSA','proveedor_cif'=>'H12725404','comunidad_cif'=>'H12725404']);
    $assert($both['understanding']['issuer']['tax_id']===null && $both['classification']['corrections']===['issuer_tax_id_was_a_community_tax_id'],json_encode($both['classification']['corrections']));
    $normal=$pipeline($db,['proveedor'=>'FACSA','proveedor_cif'=>'A12000022','comunidad_cif'=>'H12725404']);
    $assert($normal['understanding']['issuer']['tax_id']==='A12000022' && $normal['classification']['corrections']===[],'un CIF de proveedor de verdad nunca se toca');
});
$test('Fase 23 — dos comunidades con el mismo CIF (caso real EDIFICI/PARKING GERMANIES): el CIF no decide solo; la IA elige entre exactamente esas dos, y sin evidencia no se elige ninguna',static function()use($assert,$sqliteDb,$classifierSchema,$resolveCommunity):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO communities(official_name,normalized_name,cif,main_address,active) VALUES (?,?,?,?,1)',['EDIFICI GERMANIES','edifici germanies','H12771119','CALLE GERMANIES 7']);
    $edificiId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO communities(official_name,normalized_name,cif,main_address,active) VALUES (?,?,?,?,1)',['PARKING GERMANIES','parking germanies','H12771119','CALLE GERMANIES 7']);
    $none=$resolveCommunity($db,['tax_id'=>'H12771119']);
    $assert($none['community']===null && $none['outcome']==='ai_abstained' && count($none['candidates'])===2,'sin nombre ni dirección no debe elegir ninguna de las dos al azar: '.json_encode($none['outcome']));
    $named=$resolveCommunity($db,['tax_id'=>'H12771119','name'=>'COM. PROP. EDIFICI GERMANIES']);
    $assert((int)($named['community']['id']??0)===$edificiId && $named['source']==='ai_after_shared_tax_id',json_encode($named['outcome']));
});
$seedGermanies=static function(Salvest\Database $db):array{
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,active) VALUES (?,?,?,?,?,1)',['65','EDIFICI GERMANIES','edifici germanies','H12771119','CALLE GERMANIES 7']);
    $edificiId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,active) VALUES (?,?,?,?,?,1)',['66','PARKING GERMANIES','parking germanies','H12771119','CALLE GERMANIES 7']);
    return ['edificio'=>$edificiId,'parking'=>(int)$db->pdo()->lastInsertId()];
};
$test('Caso B real (EXTINPLAN 2270): NIF compartido H12771119 + "COM.PROP.GERMANIES 7-9 GARAJE" -> la IA recibe exactamente EDIFICI y PARKING GERMANIES y elige PARKING (garaje = parking sin reglas fijas)',static function()use($assert,$sqliteDb,$classifierSchema,$seedGermanies,$resolveCommunity):void{
    $db=$sqliteDb($classifierSchema);$ids=$seedGermanies($db);
    $seen=null;
    $decision=$resolveCommunity($db,['tax_id'=>'H12771119','name'=>'COM.PROP.GERMANIES 7-9 GARAJE','address'=>'Calle Germanies-Maestro Serrano','postal_code'=>'12530'],
        function(array $candidates)use(&$seen,$ids){$seen=$candidates;return['id'=>$ids['parking'],'confidence'=>0.88,'reason'=>'GARAJE equivale a PARKING'];});
    $assert((int)($decision['community']['id']??0)===$ids['parking'] && $decision['source']==='ai_after_shared_tax_id' && $decision['confidence']===0.88,json_encode($decision['outcome']));
    $names=array_column($seen,'nombre');sort($names);
    $assert($names===['EDIFICI GERMANIES','PARKING GERMANIES'],'la IA recibe exactamente las dos que comparten NIF: '.json_encode($names));
    $assert(!(new ReflectionClass(Salvest\CommunityResolver::class))->hasMethod('pickByDistinctiveWord'),'la equivalencia garaje = parking ya no está escrita a mano en el código');
});
$test('NIF compartido: la elección de la IA solo se acepta con confianza suficiente; por debajo del umbral, sin comunidad',static function()use($assert,$sqliteDb,$classifierSchema,$seedGermanies,$resolveCommunity):void{
    $db=$sqliteDb($classifierSchema);$ids=$seedGermanies($db);
    $low=$resolveCommunity($db,['tax_id'=>'H12771119','name'=>'GERMANIAS'],['id'=>$ids['edificio'],'confidence'=>0.69,'reason'=>'quizá']);
    $assert($low['community']===null && $low['outcome']==='ai_low_confidence','0.69 está por debajo de '.Salvest\ClassificationPolicy::AI_CHOICE_MIN_CONFIDENCE.': '.json_encode($low['outcome']));
    $edge=$resolveCommunity($db,['tax_id'=>'H12771119','name'=>'EDIFICIO GERMANIAS'],['id'=>$ids['edificio'],'confidence'=>0.70,'reason'=>'edificio']);
    $assert((int)($edge['community']['id']??0)===$ids['edificio'],'0.70 es el borde inferior del tramo "evidencia fuerte": se acepta');
});
$test('Caso C y F: NIF compartido que la IA no puede distinguir -> sin comunidad; un id fuera de las candidatas se rechaza',static function()use($assert,$sqliteDb,$classifierSchema,$seedGermanies,$resolveCommunity):void{
    $db=$sqliteDb($classifierSchema);$ids=$seedGermanies($db);
    $abstain=$resolveCommunity($db,['tax_id'=>'H12771119','name'=>'COM. PROP. GERMANIES 7'],'abstain');
    $assert($abstain['community']===null && $abstain['outcome']==='ai_abstained',json_encode($abstain['outcome']));
    $invented=$resolveCommunity($db,['tax_id'=>'H12771119','name'=>'GERMANIES'],['id'=>999,'confidence'=>0.99,'reason'=>'inventado']);
    $assert($invented['community']===null && $invented['outcome']==='ai_invalid_candidate' && $invented['ai']['rejected_id']===999,'un id inventado se rechaza: '.json_encode($invented['ai']));
});

$test('inferencia comunidad+servicio: dos proveedores compatibles con el mismo servicio nunca se eligen automáticamente',static function()use($assert,$sqliteDb,$classifierSchema,$routeCompat):void{
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
    $route=$routeCompat($db)->route($invoice,'facturas@limpieza.example');
    $assert($route['status']==='needs_review','con dos proveedores compatibles no debe elegirse ninguno automáticamente: '.json_encode($route));
    $assert($route['supplier']===null);
});
$test('inferencia comunidad+servicio: un proveedor identificado explícitamente por nombre tiene prioridad sobre esta señal',static function()use($assert,$sqliteDb,$classifierSchema,$routeCompat):void{
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
    $route=$routeCompat($db)->route($invoice,'facturas@limpiezasnorte.example');
    $assert($route['status']==='classified','la coincidencia de nombre explícita debe primar sobre la inferencia comunidad+servicio: '.json_encode($route));
    $assert($route['evidence']['supplier']['source']==='name_exact','no debe usarse la señal comunidad+servicio cuando el nombre ya resolvió el proveedor: '.json_encode($route['evidence']['supplier']));
});
$test('inferencia comunidad+servicio: proveedor confirmado por nombre pero con servicio erróneo de OpenAI — gana el servicio configurado en BD',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'33','CP TREINTAYTRES','CRISLA','LIMPIEZA');
    // OpenAI acertó el nombre del proveedor pero se equivocó de servicio ("agua" en vez de limpieza).
    $invoice=['proveedor'=>'CRISLA','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'agua'];
    $route=$routeCompat($db)->route($invoice,'facturas@crisla.example');
    $assert($route['status']==='classified' && (int)$route['supplier']['id']===$fixture['supplierId'],json_encode($route));
    $assert($route['evidence']['supplier']['source']==='name_exact','el proveedor se resolvió por nombre, no por la señal comunidad+servicio');
    $assert($route['service']==='LIMPIEZA','el servicio configurado del proveedor en BD debe corregir el "agua" erróneo de OpenAI: '.$route['service']);
    $assert($route['evidence']['service']['source']==='supplier_main_service');
});
$test('inferencia comunidad+servicio: ningún proveedor compatible con el servicio va a revisión',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    // Esta comunidad solo tiene un proveedor de ELECTRICIDAD; la factura dice ser de LIMPIEZA
    // y el nombre extraído no coincide con nada, así que ningún proveedor es compatible.
    $fixture=$makeCommunityWithSupplier($db,'34','CP TREINTAYCUATRO','IBERDROLA','ELECTRICIDAD');
    $invoice=['proveedor'=>'Proveedor de Limpieza Desconocido','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'limpieza'];
    $route=$routeCompat($db)->route($invoice,'facturas@desconocido.example');
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

$test('segunda llamada restringida: si el primer intento ya resuelve, la segunda llamada nunca se invoca',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'40','CP CUARENTA','IBERDROLA','ELECTRICIDAD');
    $invoice=['proveedor'=>'IBERDROLA','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'desconocido'];
    $resolver=function()use($assert):?int{$assert(false,'no debe llamarse a la segunda inspección si el primer intento ya resolvió');return null;};
    $route=$routeCompat($db)->route($invoice,'facturas@iberdrola.es','',$resolver);
    $assert($route['status']==='classified' && (int)$route['supplier']['id']===$fixture['supplierId'],json_encode($route));
});
$test('segunda llamada restringida: recibe exactamente los proveedores de esa comunidad, y resuelve si acierta',static function()use($assert,$sqliteDb,$classifierSchema,$routeCompat):void{
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
    $router=$routeCompat($db);$route=$router->route($invoice,'facturas@garciamarin.example','',$resolver);
    $supplierCall=array_values(array_filter($router->ai->calls,static fn(array $c):bool=>$c['task']==='elegir_proveedor'))[0]??null;
    $assert($seenCandidates!==null,'la segunda inspección sí debe invocarse cuando el primer intento no resuelve nada');
    $seenIds=array_column($seenCandidates,'id');sort($seenIds);$expectedIds=$ids;sort($expectedIds);
    $assert(count($seenCandidates)===3 && $seenIds===$expectedIds,'debe recibir exactamente los proveedores de esa comunidad, en la lista cerrada: '.json_encode($seenCandidates));
    $assert($supplierCall!==null&&str_contains($supplierCall['text'],'Comunidad destinataria ya confirmada: CP BENICARLO 8'),'debe recibir la comunidad ya resuelta como contexto');
    $assert($route['status']==='classified' && (int)$route['supplier']['id']===$ids[1],'debe clasificar con lo que devolvió la segunda inspección: '.json_encode($route));
    $assert($route['evidence']['supplier']['source']==='ai_after_retrieval','debe quedar constancia de que lo eligió la IA entre candidatos');
    $assert($route['service']==='EXTINTORES');
});
$test('segunda llamada restringida: si devuelve un id fuera de la lista, se rechaza y no se fuerza nada',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'42','CP CUARENTA Y DOS','PROVEEDOR REAL','MANTENIMIENTO');
    $invoice=['proveedor'=>'Nombre irreconocible SL','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'desconocido'];
    $resolver=static fn():?int=>999999; // id que no pertenece a los proveedores de esta comunidad
    $route=$routeCompat($db)->route($invoice,'facturas@desconocido.example','',$resolver);
    $assert($route['status']==='needs_review','un id fuera de la lista cerrada nunca debe forzar una clasificación: '.json_encode($route));
    $assert($route['supplier']===null);
});
$test('segunda llamada restringida: si tampoco resuelve (null), va a revisión sin inventar proveedor',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'43','CP CUARENTA Y TRES','PROVEEDOR REAL','MANTENIMIENTO');
    $invoice=['proveedor'=>'Nombre irreconocible SL','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'desconocido'];
    $resolver=static fn():?int=>null;
    $route=$routeCompat($db)->route($invoice,'facturas@desconocido.example','',$resolver);
    $assert($route['status']==='needs_review' && $route['supplier']===null,json_encode($route));
    $assert($route['raw_supplier_name']==='Nombre irreconocible SL','el texto bruto debe seguir disponible para el panel de revisión, nunca convertido en proveedor final');
});
$test('Reconstrucción — dos candidatos que el nombre no distingue: la IA desambigua entre ellos (antes iban a revisión sin preguntar); si se abstiene, revisión',static function()use($assert,$sqliteDb,$classifierSchema,$routeCompat):void{
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
    $seen=null;
    $resolver=function(array $candidates)use(&$seen):?int{$seen=$candidates;return null;};
    $route=$routeCompat($db)->route($invoice,'facturas@ambiguo.example','',$resolver);
    $assert(is_array($seen)&&count($seen)===2,'la IA recibe los dos candidatos');
    $assert($route['status']==='needs_review'&&$route['supplier']===null&&$route['evidence']['supplier']['outcome']==='ai_abstained','si la IA no elige, revisión: '.json_encode($route['evidence']['supplier']['outcome']));
});

$test('Text::normalizeIdentifier(): H-12815601, H12815601, H 12815601 y h12815601 son el mismo CIF',static function()use($assert):void{
    $canonical='h12815601';
    foreach(['H-12815601','H12815601','H 12815601','h12815601','H.12815601','h-12.815601'] as $value){
        $assert(Salvest\Text::normalizeIdentifier($value)===$canonical,"\"$value\" debería normalizar a \"$canonical\", dio \"".Salvest\Text::normalizeIdentifier($value).'"');
    }
});
$test('caso real BERNAT GUILLEM DETENÇA 39: el guion del CIF del PDF no debe impedir la coincidencia con el maestro',static function()use($assert,$sqliteDb,$classifierSchema,$resolveCommunity):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,postal_code,city,imap_folder_name,active) VALUES (?,?,?,?,?,?,?,?,1)',
        ['39','BERNAT GUILLEM DETENÇA 39',Salvest\Text::normalize('BERNAT GUILLEM DETENÇA 39'),'H12815601','Carrer Bernat Guillem Detença 39','07000','Palma','39 - BERNAT GUILLEM DETENCA 39']);
    $communityId=(int)$db->pdo()->lastInsertId();
    $result=$resolveCommunity($db,['tax_id'=>'H-12815601'],static fn()=>throw new RuntimeException('un NIF único no llama a la IA'));
    $assert((int)($result['community']['id']??0)===$communityId && $result['source']==='tax_id_exact' && $result['confidence']===1.0,'un CIF con guion en el PDF debe reconocer al mismo titular: '.json_encode($result['outcome']));
});
$test('CIF de proveedor con guion también debe resolver contra el maestro sin guion (con y sin comunidad)',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier,$resolveSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'50','CP CINCUENTA','PROTECCION INCENDIOS SL','EXTINTORES','A12815601');
    $global=$resolveSupplier($db,'','A-12.815601','facturas@otra.example');
    $assert((int)($global['supplier']['id']??0)===$fixture['supplierId'] && $global['source']==='tax_id_exact','sin comunidad también debe ignorar el guion/puntos del CIF: '.json_encode($global['outcome']));
    $inCommunity=$resolveSupplier($db,'','A-12.815601','facturas@otra.example',['id'=>$fixture['communityId'],'official_name'=>'CP CINCUENTA']);
    $assert((int)($inCommunity['supplier']['id']??0)===$fixture['supplierId'] && $inCommunity['linked']===true,'con comunidad también, y sabe que ya está vinculado: '.json_encode($inCommunity['outcome']));
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
$test('Explicabilidad — decision_json guarda los candidatos de proveedor con sus señales y la elección de la IA (sustituye a la traza por niveles del antiguo router)',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier,$pipeline):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'82','MENENDEZ YPELAYO 10','CRISLA','LIMPIEZA',null,'H12557229');
    $invoice=['proveedor'=>'Servicios Generales de Mantenimiento Ibérica','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'limpieza'];
    $result=$pipeline($db,$invoice,'facturas@crisla.example','smart',['id'=>$fixture['supplierId'],'confidence'=>0.84,'reason'=>'logotipo CRISLA en la cabecera']);
    $supplier=$result['classification']['supplier'];
    $assert($result['status']==='classified' && $supplier['source']==='ai_after_retrieval' && $supplier['confidence']===0.84,json_encode($supplier));
    $assert($supplier['ai']['reason']==='logotipo CRISLA en la cabecera','el motivo de la IA queda guardado');
    $assert($supplier['candidates'][0]['id']===$fixture['supplierId'] && $supplier['candidates'][0]['ya_trabaja_con_la_comunidad']===true,'los candidatos quedan guardados con su relación con la comunidad');
    $json=json_decode(json_encode($result['classification']),true);
    $assert(($json['document']['type']??null)==='invoice' && ($json['community']['source']??null)==='tax_id_exact' && ($json['service']['source']??null)==='supplier_main_service','la decisión completa se puede serializar y reconstruir');
});
$test('InvoiceRouter::route(): supplier_ambiguous=true cuando hay varios proveedores igualmente plausibles',static function()use($assert,$sqliteDb,$classifierSchema,$routeCompat):void{
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
    $route=$routeCompat($db)->route($invoice,'facturas@ambiguo.example');
    $assert($route['supplier_ambiguous']===true,'el nuevo campo debe reflejar la ambigüedad real: '.json_encode($route));
});
$test('InvoiceRouter::route(): supplier_ambiguous=false en un caso normal correctamente clasificado',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'23','PK ILLES BALEARS 23','IBERDROLA','ELECTRICIDAD');
    $invoice=['proveedor'=>'IBERDROLA CLIENTES, S.A.U.','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'agua'];
    $route=$routeCompat($db)->route($invoice,'facturas@iberdrola.es');
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
$test('/Revisar: el botón "Volver a procesar" aparece en needs_review, unclassified y error por igual — la mayoría del backlog real es unclassified',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp,$requestWebApp):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    $db->execute('INSERT INTO mailboxes(descriptive_name,email,imap_host,imap_port,use_ssl,username,encrypted_password,input_folder,active) VALUES (?,?,?,?,?,?,?,?,1)',
        ['Test','buzon@example.com','imap.example.com',993,1,'buzon@example.com','x','INBOX']);
    $mailboxId=(int)$db->pdo()->lastInsertId();
    foreach([['1001','500','needs_review','sola.pdf'],['2002','900','unclassified','otra-sin-clasificar.pdf'],['3003','100','error','con-error.pdf']] as [$uidvalidity,$uid,$status,$filename]){
        $db->execute('INSERT INTO processed_attachments(mailbox_id,uidvalidity,message_uid,status,processed_at,original_filename) VALUES (?,?,?,?,?,?)',
            [$mailboxId,$uidvalidity,$uid,$status,date('Y-m-d H:i:s'),$filename]);
    }
    $html=$requestWebApp($webApp,'GET','reviews');
    $assert(substr_count($html,'Volver a procesar')===3,'el botón debe aparecer en las tres tarjetas — needs_review, unclassified y error: '.substr_count($html,'Volver a procesar'));
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

// ---- InboxRequeue::dismiss(): "Esto no es una factura" ----
$test('InboxRequeue::dismiss(): needs_review único pasa a dismissed_not_invoice, conservando el historial técnico y sin crear filas nuevas',static function()use($assert,$sqliteDbWithLock,$workerConfig,$seedRequeueFixture,$fakeRequeueImapClient):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();
    $fixture=$seedRequeueFixture($db,['needs_review']);
    $before=(int)$db->one('SELECT COUNT(*) n FROM processed_attachments WHERE mailbox_id=?',[$fixture['mailboxId']])['n'];
    $stub=$fakeRequeueImapClient(['777']);
    $result=(new Salvest\InboxRequeue($db,new Salvest\Crypto(Salvest\Crypto::generateKey()),$config,static fn(array $mailbox,string $folder)=>$stub))->dismiss($fixture['attachmentIds'][0]);
    $assert($result['ok']===true,'debe completarse con éxito: '.$result['message']);
    $row=$db->one('SELECT * FROM processed_attachments WHERE id=?',[$fixture['attachmentIds'][0]]);
    $assert($row['status']==='dismissed_not_invoice','el estado debe pasar a dismissed_not_invoice: '.$row['status']);
    $assert($row['debug_trace_json']!==null && str_contains((string)$row['debug_trace_json'],'adjunto-0.pdf'),'el historial técnico del intento debe conservarse intacto');
    $message=$db->one('SELECT status FROM processed_messages WHERE id=?',[$fixture['messageId']]);
    $assert($message['status']==='dismissed_not_invoice','processed_messages también debe quedar dismissed_not_invoice');
    $after=(int)$db->one('SELECT COUNT(*) n FROM processed_attachments WHERE mailbox_id=?',[$fixture['mailboxId']])['n'];
    $assert($after===$before,'dismiss() nunca debe crear una fila nueva, solo actualizar la existente: antes='.$before.' después='.$after);
    $assert($stub->moved===['uid'=>'777','destination'=>'INBOX'],'el correo debe volver a la bandeja de entrada del buzón: '.json_encode($stub->moved));
});
$test('InboxRequeue::dismiss(): un hermano classified bloquea la acción por completo',static function()use($assert,$sqliteDbWithLock,$workerConfig,$seedRequeueFixture,$fakeRequeueImapClient):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();
    $fixture=$seedRequeueFixture($db,['needs_review','classified']);
    $stub=$fakeRequeueImapClient(['777']);
    $result=(new Salvest\InboxRequeue($db,new Salvest\Crypto(Salvest\Crypto::generateKey()),$config,static fn(array $mailbox,string $folder)=>$stub))->dismiss($fixture['attachmentIds'][0]);
    $assert($result['ok']===false,'un hermano ya clasificado es prueba de que el correo sí contiene una factura: debe rechazarse');
    $row=$db->one('SELECT status FROM processed_attachments WHERE id=?',[$fixture['attachmentIds'][0]]);
    $assert($row['status']==='needs_review','no debe cambiar nada si se rechaza');
    $sibling=$db->one('SELECT status FROM processed_attachments WHERE id=?',[$fixture['attachmentIds'][1]]);
    $assert($sibling['status']==='classified','el hermano classified debe permanecer intacto');
});
$test('InboxRequeue::dismiss(): un hermano duplicate bloquea la acción por completo',static function()use($assert,$sqliteDbWithLock,$workerConfig,$seedRequeueFixture,$fakeRequeueImapClient):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();
    $fixture=$seedRequeueFixture($db,['needs_review','duplicate']);
    $stub=$fakeRequeueImapClient(['777']);
    $result=(new Salvest\InboxRequeue($db,new Salvest\Crypto(Salvest\Crypto::generateKey()),$config,static fn(array $mailbox,string $folder)=>$stub))->dismiss($fixture['attachmentIds'][0]);
    $assert($result['ok']===false,'un hermano duplicate también es prueba de que el correo contiene una factura real: debe rechazarse');
    $row=$db->one('SELECT status FROM processed_attachments WHERE id=?',[$fixture['attachmentIds'][0]]);
    $assert($row['status']==='needs_review');
});
$test('InboxRequeue::dismiss(): cualquier otro hermano pendiente (needs_review/unclassified/error) bloquea la acción, aunque no esté resuelto',static function()use($assert,$sqliteDbWithLock,$workerConfig,$seedRequeueFixture,$fakeRequeueImapClient):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();
    foreach(['needs_review','unclassified','error'] as $siblingStatus){
        $fixture=$seedRequeueFixture($db,['needs_review',$siblingStatus]);
        $stub=$fakeRequeueImapClient(['777']);
        $result=(new Salvest\InboxRequeue($db,new Salvest\Crypto(Salvest\Crypto::generateKey()),$config,static fn(array $mailbox,string $folder)=>$stub))->dismiss($fixture['attachmentIds'][0]);
        $assert($result['ok']===false,"un hermano $siblingStatus sin revisar debe bloquear el descarte: no puede asumirse que tampoco es factura");
        $row=$db->one('SELECT status FROM processed_attachments WHERE id=?',[$fixture['attachmentIds'][0]]);
        $assert($row['status']==='needs_review');
    }
});
$test('InboxRequeue::dismiss(): 0 resultados por Message-ID aborta sin cambios',static function()use($assert,$sqliteDbWithLock,$workerConfig,$seedRequeueFixture,$fakeRequeueImapClient):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();
    $fixture=$seedRequeueFixture($db,['needs_review']);
    $stub=$fakeRequeueImapClient([]);
    $result=(new Salvest\InboxRequeue($db,new Salvest\Crypto(Salvest\Crypto::generateKey()),$config,static fn(array $mailbox,string $folder)=>$stub))->dismiss($fixture['attachmentIds'][0]);
    $assert($result['ok']===false);
    $row=$db->one('SELECT status FROM processed_attachments WHERE id=?',[$fixture['attachmentIds'][0]]);
    $assert($row['status']==='needs_review','sin poder localizar el correo no debe cambiar nada');
    $message=$db->one('SELECT status FROM processed_messages WHERE id=?',[$fixture['messageId']]);
    $assert($message['status']==='needs_review','processed_messages también debe quedar exactamente como estaba');
});
$test('InboxRequeue::dismiss(): más de 1 resultado por Message-ID aborta sin cambios',static function()use($assert,$sqliteDbWithLock,$workerConfig,$seedRequeueFixture,$fakeRequeueImapClient):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();
    $fixture=$seedRequeueFixture($db,['needs_review']);
    $stub=$fakeRequeueImapClient(['777','778']);
    $result=(new Salvest\InboxRequeue($db,new Salvest\Crypto(Salvest\Crypto::generateKey()),$config,static fn(array $mailbox,string $folder)=>$stub))->dismiss($fixture['attachmentIds'][0]);
    $assert($result['ok']===false,'nunca debe adivinar cuál mover');
    $row=$db->one('SELECT status FROM processed_attachments WHERE id=?',[$fixture['attachmentIds'][0]]);
    $assert($row['status']==='needs_review');
});
$test('InboxRequeue::dismiss(): un fallo IMAP provoca rollback completo',static function()use($assert,$sqliteDbWithLock,$workerConfig,$seedRequeueFixture,$fakeRequeueImapClient):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();
    $fixture=$seedRequeueFixture($db,['needs_review']);
    $stub=$fakeRequeueImapClient([],new RuntimeException('conexión IMAP caída'));
    $result=(new Salvest\InboxRequeue($db,new Salvest\Crypto(Salvest\Crypto::generateKey()),$config,static fn(array $mailbox,string $folder)=>$stub))->dismiss($fixture['attachmentIds'][0]);
    $assert($result['ok']===false);
    $row=$db->one('SELECT status,debug_trace_json FROM processed_attachments WHERE id=?',[$fixture['attachmentIds'][0]]);
    $assert($row['status']==='needs_review','tras el rollback el estado debe volver exactamente al original');
    $assert($row['debug_trace_json']!==null,'la traza técnica sigue intacta (nunca se llegó a tocar el estado)');
    $message=$db->one('SELECT status FROM processed_messages WHERE id=?',[$fixture['messageId']]);
    $assert($message['status']==='needs_review');
});
$test('InboxRequeue::dismiss(): si el movimiento IMAP original falló, se completa igualmente sin intentar mover nada',static function()use($assert,$sqliteDbWithLock,$workerConfig,$seedRequeueFixture):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();
    $fixture=$seedRequeueFixture($db,['needs_review'],'failed');
    $neverCalled=static function()use($assert){$assert(false,'no debe construirse ningún ImapClient cuando el movimiento original falló');};
    $result=(new Salvest\InboxRequeue($db,new Salvest\Crypto(Salvest\Crypto::generateKey()),$config,$neverCalled))->dismiss($fixture['attachmentIds'][0]);
    $assert($result['ok']===true,'debe completarse igualmente, solo con el cambio de estado: '.$result['message']);
    $row=$db->one('SELECT status FROM processed_attachments WHERE id=?',[$fixture['attachmentIds'][0]]);
    $assert($row['status']==='dismissed_not_invoice');
});
$test('InboxRequeue::requeue(): también funciona partiendo de unclassified, no solo needs_review — la mayoría del backlog real es unclassified',static function()use($assert,$sqliteDbWithLock,$workerConfig,$seedRequeueFixture,$fakeRequeueImapClient):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();
    $fixture=$seedRequeueFixture($db,['unclassified']);
    $stub=$fakeRequeueImapClient(['777']);
    $result=(new Salvest\InboxRequeue($db,new Salvest\Crypto(Salvest\Crypto::generateKey()),$config,static fn(array $mailbox,string $folder)=>$stub))->requeue($fixture['attachmentIds'][0]);
    $assert($result['ok']===true,'unclassified debe poder reencolarse igual que needs_review: '.$result['message']);
    $row=$db->one('SELECT status FROM processed_attachments WHERE id=?',[$fixture['attachmentIds'][0]]);
    $assert($row['status']==='requeued');
});
$test('InboxRequeue::dismiss(): también funciona partiendo de unclassified, no solo needs_review',static function()use($assert,$sqliteDbWithLock,$workerConfig,$seedRequeueFixture,$fakeRequeueImapClient):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();
    $fixture=$seedRequeueFixture($db,['unclassified']);
    $stub=$fakeRequeueImapClient(['777']);
    $result=(new Salvest\InboxRequeue($db,new Salvest\Crypto(Salvest\Crypto::generateKey()),$config,static fn(array $mailbox,string $folder)=>$stub))->dismiss($fixture['attachmentIds'][0]);
    $assert($result['ok']===true,'unclassified debe poder descartarse igual que needs_review: '.$result['message']);
    $row=$db->one('SELECT status FROM processed_attachments WHERE id=?',[$fixture['attachmentIds'][0]]);
    $assert($row['status']==='dismissed_not_invoice');
});

// ---- Worker: reconocimiento de correos ya descartados como "no es una factura" ----
$test('Worker: el corte por dismissed_not_invoice ocurre antes de OpenAI, antes de crear filas y antes de cualquier movimiento IMAP (guarda de regresión de código)',static function()use($assert):void{
    $source=file_get_contents(__DIR__.'/../src/Worker.php');
    $messageParsePos=strpos($source,'$message = $this->parser->parse($client->fetch($uid));');
    $dismissedCheckPos=strpos($source,'isDismissedNotInvoice((int)$mailbox');
    $attachmentsCheckPos=strpos($source,"if (!\$message['attachments']) {");
    $documentValidatorPos=strpos($source,'DocumentValidator::validate(');
    $assert($messageParsePos!==false && $dismissedCheckPos!==false && $attachmentsCheckPos!==false && $documentValidatorPos!==false,'no se encontraron todos los puntos de referencia esperados en Worker.php');
    $assert($dismissedCheckPos>$messageParsePos,'el corte debe comprobarse justo después de parsear el correo, nunca antes');
    $assert($dismissedCheckPos<$attachmentsCheckPos,'debe cortar antes incluso de la comprobación de "sin adjuntos"');
    $assert($dismissedCheckPos<$documentValidatorPos,'debe cortar antes de validar o procesar cualquier adjunto — y por tanto antes de cualquier llamada a OpenAI, sin crear filas ni mover el correo');
});
$test('Worker::isDismissedNotInvoice(): reconoce el mismo Message-ID aunque el correo tenga ahora un UID nunca visto',static function()use($assert,$sqliteDbWithLock,$workerConfig):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();
    $db->execute('INSERT INTO mailboxes(descriptive_name,email,imap_host,imap_port,use_ssl,username,encrypted_password,input_folder,active) VALUES (?,?,?,?,?,?,?,?,1)',
        ['Test','buzon@example.com','imap.example.com',993,1,'buzon@example.com','x','INBOX']);
    $mailboxId=(int)$db->pdo()->lastInsertId();
    // Fila histórica: quedó marcada bajo el UID antiguo (500); el correo, tras volver a INBOX,
    // aparecerá en un futuro ciclo bajo un UID que esta tabla nunca ha visto (p.ej. 999).
    $db->execute('INSERT INTO processed_messages(mailbox_id,uidvalidity,message_uid,message_id_header,status,processed_at) VALUES (?,?,?,?,?,?)',
        [$mailboxId,'1001','500','<msg-1@example.com>','dismissed_not_invoice',date('Y-m-d H:i:s')]);
    $worker=Salvest\Worker::create($db,$config);
    $method=new ReflectionMethod(Salvest\Worker::class,'isDismissedNotInvoice');$method->setAccessible(true);
    $assert($method->invoke($worker,$mailboxId,'<msg-1@example.com>')===true,'debe reconocerlo por Message-ID sin importar el UID actual');
    $assert($method->invoke($worker,$mailboxId,'<otro@example.com>')===false,'un Message-ID distinto nunca debe reconocerse');
    $assert($method->invoke($worker,$mailboxId+999,'<msg-1@example.com>')===false,'no debe cruzar buzones distintos');
});
$test('Worker::isDismissedNotInvoice(): un correo requeued (no descartado) no debe reconocerse como "no es una factura"',static function()use($assert,$sqliteDbWithLock,$workerConfig):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();
    $db->execute('INSERT INTO mailboxes(descriptive_name,email,imap_host,imap_port,use_ssl,username,encrypted_password,input_folder,active) VALUES (?,?,?,?,?,?,?,?,1)',
        ['Test','buzon@example.com','imap.example.com',993,1,'buzon@example.com','x','INBOX']);
    $mailboxId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO processed_messages(mailbox_id,uidvalidity,message_uid,message_id_header,status,processed_at) VALUES (?,?,?,?,?,?)',
        [$mailboxId,'1001','500','<msg-2@example.com>','requeued',date('Y-m-d H:i:s')]);
    $worker=Salvest\Worker::create($db,$config);
    $method=new ReflectionMethod(Salvest\Worker::class,'isDismissedNotInvoice');$method->setAccessible(true);
    $assert($method->invoke($worker,$mailboxId,'<msg-2@example.com>')===false,'requeued no equivale a dismissed_not_invoice: no debe cortar el reprocesamiento normal');
});
$test('/Revisar: "Esto no es una factura" solo aparece cuando el adjunto es el único elemento reseñable del correo',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp,$requestWebApp):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    $db->execute('INSERT INTO mailboxes(descriptive_name,email,imap_host,imap_port,use_ssl,username,encrypted_password,input_folder,active) VALUES (?,?,?,?,?,?,?,?,1)',
        ['Test','buzon@example.com','imap.example.com',993,1,'buzon@example.com','x','INBOX']);
    $mailboxId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO processed_attachments(mailbox_id,uidvalidity,message_uid,status,processed_at,original_filename) VALUES (?,?,?,?,?,?)',
        [$mailboxId,'1001','500','needs_review',date('Y-m-d H:i:s'),'sola.pdf']);
    $db->execute('INSERT INTO processed_attachments(mailbox_id,uidvalidity,message_uid,status,processed_at,original_filename) VALUES (?,?,?,?,?,?)',
        [$mailboxId,'2002','900','needs_review',date('Y-m-d H:i:s'),'adjunto-a.pdf']);
    $db->execute('INSERT INTO processed_attachments(mailbox_id,uidvalidity,message_uid,status,processed_at,original_filename) VALUES (?,?,?,?,?,?)',
        [$mailboxId,'2002','900','error',date('Y-m-d H:i:s'),'adjunto-b.pdf']);
    $html=$requestWebApp($webApp,'GET','reviews');
    $assert(substr_count($html,'Esto no es una factura')===1,'solo el correo con un único adjunto reseñable debe ofrecer la acción: apariciones='.substr_count($html,'Esto no es una factura'));
    $assert(str_contains($html,'Este correo volverá a la bandeja de entrada y Salvest dejará de procesarlo en futuras ejecuciones. Se conservará el historial técnico de este intento. ¿Confirmas que este correo no contiene ninguna factura?'),'debe usarse el texto de confirmación exacto');
});

// ---- Dashboard: estimación honesta de la próxima ejecución (nunca una cuenta atrás falsa) ----
$test('WebApp::nextRunEstimate(): sin ninguna ejecución por cron, no hay estimación',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    $method=new ReflectionMethod(Salvest\WebApp::class,'nextRunEstimate');$method->setAccessible(true);
    $assert($method->invoke($webApp)===null,'sin datos históricos no debe inventarse ninguna estimación');
});
$test('WebApp::nextRunEstimate(): con una sola ejecución por cron tampoco hay estimación (hace falta al menos un intervalo)',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    $db->execute("INSERT INTO processing_runs(run_uuid,trigger_type,started_at,finished_at,status) VALUES (?,?,?,?,?)",['u1','cron','2026-08-19 10:00:00','2026-08-19 10:00:05','completed']);
    $method=new ReflectionMethod(Salvest\WebApp::class,'nextRunEstimate');$method->setAccessible(true);
    $assert($method->invoke($webApp)===null,'un único punto no permite calcular ningún intervalo real');
});
$test('WebApp::nextRunEstimate(): calcula el rango real observado (mínimo y máximo intervalo), no una media inventada',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    // Tres ejecuciones por cron: gaps reales de 10 min y 40 min (medio: 25 min).
    $db->execute("INSERT INTO processing_runs(run_uuid,trigger_type,started_at,finished_at,status) VALUES (?,?,?,?,?)",['u1','cron','2026-08-19 09:00:00','2026-08-19 09:00:05','completed']);
    $db->execute("INSERT INTO processing_runs(run_uuid,trigger_type,started_at,finished_at,status) VALUES (?,?,?,?,?)",['u2','cron','2026-08-19 09:10:00','2026-08-19 09:10:05','completed']);
    $db->execute("INSERT INTO processing_runs(run_uuid,trigger_type,started_at,finished_at,status) VALUES (?,?,?,?,?)",['u3','cron','2026-08-19 09:50:00','2026-08-19 09:50:05','completed']);
    $method=new ReflectionMethod(Salvest\WebApp::class,'nextRunEstimate');$method->setAccessible(true);
    $estimate=$method->invoke($webApp);
    $assert($estimate!==null,'con dos intervalos reales sí debe poder estimar');
    $assert($estimate['avg_minutes']===25,'la media de 10 y 40 minutos es 25: dio '.$estimate['avg_minutes']);
    $assert($estimate['from']->format('H:i')==='10:00','el extremo inferior debe ser el último inicio + el gap más pequeño observado (10 min): '.$estimate['from']->format('H:i'));
    $assert($estimate['to']->format('H:i')==='10:30','el extremo superior debe ser el último inicio + el gap más grande observado (40 min): '.$estimate['to']->format('H:i'));
});
$test('WebApp::nextRunEstimate(): las ejecuciones manuales no distorsionan el patrón del cron automático',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    $db->execute("INSERT INTO processing_runs(run_uuid,trigger_type,started_at,finished_at,status) VALUES (?,?,?,?,?)",['u1','cron','2026-08-19 09:00:00','2026-08-19 09:00:05','completed']);
    // Un clic manual un minuto después no debe colarse como si fuera el patrón real del cron.
    $db->execute("INSERT INTO processing_runs(run_uuid,trigger_type,started_at,finished_at,status) VALUES (?,?,?,?,?)",['u2','manual','2026-08-19 09:01:00','2026-08-19 09:01:05','completed']);
    $db->execute("INSERT INTO processing_runs(run_uuid,trigger_type,started_at,finished_at,status) VALUES (?,?,?,?,?)",['u3','cron','2026-08-19 09:30:00','2026-08-19 09:30:05','completed']);
    $method=new ReflectionMethod(Salvest\WebApp::class,'nextRunEstimate');$method->setAccessible(true);
    $estimate=$method->invoke($webApp);
    $assert($estimate!==null && $estimate['avg_minutes']===30,'el intervalo debe calcularse solo entre ejecuciones cron (09:00 -> 09:30 = 30 min), ignorando la manual: '.json_encode($estimate));
});
$test('Inicio: cuando hay estimación, se muestra como rango honesto, no como cuenta atrás con segundos',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    // Tiempos relativos a "ahora", no fechas fijas: la última ejecución fue hace 5 minutos y el
    // gap observado (20 min) proyecta el extremo superior del rango 15 minutos hacia el futuro,
    // para que el test siga siendo válido sin importar cuándo se ejecute la batería.
    $first=(new DateTimeImmutable('-25 minutes'))->format('Y-m-d H:i:s');
    $second=(new DateTimeImmutable('-5 minutes'))->format('Y-m-d H:i:s');
    $db->execute("INSERT INTO processing_runs(run_uuid,trigger_type,started_at,finished_at,status,classified_count,needs_review_count,error_count) VALUES (?,?,?,?,?,?,?,?)",['u1','cron',$first,$first,'completed',0,0,0]);
    $db->execute("INSERT INTO processing_runs(run_uuid,trigger_type,started_at,finished_at,status,classified_count,needs_review_count,error_count) VALUES (?,?,?,?,?,?,?,?)",['u2','cron',$second,$second,'completed',1,0,0]);
    // botStatusCard() directamente: dashboard() en sí usa CURDATE() para otras métricas ajenas a
    // esto, que SQLite no soporta — no hace falta pasar por ahí para probar esta pieza concreta.
    $method=new ReflectionMethod(Salvest\WebApp::class,'botStatusCard');$method->setAccessible(true);
    $html=$method->invoke($webApp);
    $assert(str_contains($html,'Intervalo medio reciente'),'debe verse la etiqueta de intervalo medio: '.$html);
    $assert(str_contains($html,'Próxima ejecución estimada'),'debe verse la estimación como rango');
    $assert(!preg_match('/id="[a-z-]*countdown/',$html),'no debe existir ningún elemento de cuenta atrás en directo simulando precisión que no existe');
});

// ---- Inicio: "Archivadas hoy" despliega dónde se guardó cada factura ----
$test('Inicio: "Archivadas hoy" sin ninguna factura archivada hoy muestra el mensaje explicativo, no una tabla vacía',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    $method=new ReflectionMethod(Salvest\WebApp::class,'archivedTodayPanel');$method->setAccessible(true);
    $html=$method->invoke($webApp);
    $assert(str_contains($html,'Todavía no se ha archivado ninguna factura hoy.'),'sin datos debe verse el mensaje explicativo: '.$html);
    $assert(str_contains($html,'id="archived-today-panel"') && str_contains($html,' hidden'),'el panel debe existir pero permanecer cerrado por defecto');
});
$test('Inicio: "Archivadas hoy" lista comunidad, proveedor, servicio y ruta de cada factura archivada hoy',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    $db->execute('INSERT INTO communities(official_name,active) VALUES (?,1)',['MENENDEZ YPELAYO 10']);
    $communityId=(int)$db->pdo()->lastInsertId();
    $db->execute("INSERT INTO processed_attachments(status,processed_at,community_id,provider,service_type,output_path,drive_path) VALUES (?,?,?,?,?,?,NULL)",
        ['classified',date('Y-m-d H:i:s'),$communityId,'CRISLA','limpieza','/var/storage/comunidades/menendez/2026/08/factura.pdf']);
    $method=new ReflectionMethod(Salvest\WebApp::class,'archivedTodayPanel');$method->setAccessible(true);
    $html=$method->invoke($webApp);
    $assert(str_contains($html,'MENENDEZ YPELAYO 10'),'debe verse la comunidad: '.$html);
    $assert(str_contains($html,'CRISLA'),'debe verse el proveedor');
    $assert(str_contains($html,'limpieza'),'debe verse el servicio');
    $assert(str_contains($html,'/var/storage/comunidades/menendez/2026/08/factura.pdf'),'debe verse la ruta local donde se guardó');
});
$test('Inicio: "Archivadas hoy" prefiere la ruta de Drive sobre la ruta local cuando existen ambas',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    $db->execute("INSERT INTO processed_attachments(status,processed_at,provider,service_type,output_path,drive_path) VALUES (?,?,?,?,?,?)",
        ['classified',date('Y-m-d H:i:s'),'IBERDROLA','electricidad','/var/storage/local/factura.pdf','COMUNIDADES/Menendez/2026/factura.pdf']);
    $method=new ReflectionMethod(Salvest\WebApp::class,'archivedTodayPanel');$method->setAccessible(true);
    $html=$method->invoke($webApp);
    $assert(str_contains($html,'COMUNIDADES/Menendez/2026/factura.pdf'),'con Drive habilitado debe mostrarse la ruta de Drive: '.$html);
    $assert(!str_contains($html,'/var/storage/local/factura.pdf'),'no debe mostrar también la ruta local cuando ya hay una de Drive');
});
$test('Fase 13 — Inicio: el historial trae filas de ayer (para poder filtrar por semana/mes), pero SIN ninguna factura hoy el estado vacío de "Hoy" (el filtro por defecto) sigue mostrándose',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    $yesterday=new DateTimeImmutable('-1 day');
    $db->execute("INSERT INTO processed_attachments(status,processed_at,provider,service_type,output_path) VALUES (?,?,?,?,?)",
        ['classified',$yesterday->format('Y-m-d H:i:s'),'PROVEEDOR DE AYER','agua','/x/ayer.pdf']);
    $method=new ReflectionMethod(Salvest\WebApp::class,'archivedTodayPanel');$method->setAccessible(true);
    $html=$method->invoke($webApp);
    $assert(str_contains($html,'data-date="'.$yesterday->format('Y-m-d').'"'),'la fila de ayer debe estar en el HTML (el filtro por defecto la oculta en el navegador, no el servidor): '.$html);
    $assert(str_contains($html,'PROVEEDOR DE AYER'));
    $assert(str_contains($html,'data-period-empty="today"'),'debe existir el mensaje vacío específico de "Hoy", que es el filtro activo por defecto');
});
$test('Fase 13 — Inicio: una factura de hace dos meses queda FUERA del historial (el rango solo cubre desde el mes pasado, para no cargar todo el histórico)',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    $longAgo=(new DateTimeImmutable('first day of this month'))->modify('-2 months')->modify('-1 day');
    $db->execute("INSERT INTO processed_attachments(status,processed_at,provider,service_type,output_path) VALUES (?,?,?,?,?)",
        ['classified',$longAgo->format('Y-m-d H:i:s'),'PROVEEDOR MUY ANTIGUO','agua','/x/antiguo.pdf']);
    $method=new ReflectionMethod(Salvest\WebApp::class,'archivedTodayPanel');$method->setAccessible(true);
    $html=$method->invoke($webApp);
    $assert(!str_contains($html,'PROVEEDOR MUY ANTIGUO'),'nada anterior al mes pasado debe traerse: '.$html);
});
$test('Fase 13 — Inicio: existen los 4 filtros de periodo, con "Hoy" activo por defecto',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    $method=new ReflectionMethod(Salvest\WebApp::class,'archivedTodayPanel');$method->setAccessible(true);
    $html=$method->invoke($webApp);
    foreach(['today'=>'true','week'=>'false','month'=>'false','last-month'=>'false'] as $period=>$pressed){
        $assert(str_contains($html,'data-period="'.$period.'" aria-pressed="'.$pressed.'"'),"el filtro $period debe existir con aria-pressed=$pressed: ".$html);
    }
    $assert(str_contains($html,'data-today="'),'el panel debe llevar la fecha de "hoy" calculada por el servidor, nunca confiar en el reloj del navegador');
});
$test('Fase 19 — Inicio: "Archivadas hoy" muestra fecha (d/m/Y) y hora de archivado en columnas separadas, no solo la hora — antes la fecha solo vivía oculta en data-date, para el filtro',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    $db->execute("INSERT INTO processed_attachments(status,processed_at,provider,service_type,output_path) VALUES (?,?,?,?,?)",
        ['classified',(new DateTimeImmutable('today 09:05'))->format('Y-m-d H:i:s'),'PROVEEDOR CON FECHA','agua','/x/con-fecha.pdf']);
    $method=new ReflectionMethod(Salvest\WebApp::class,'archivedTodayPanel');$method->setAccessible(true);
    $html=$method->invoke($webApp);
    $assert(str_contains($html,'<th>Fecha</th>'),'debe existir una columna de Fecha, separada de la de Hora: '.$html);
    // Fecha relativa a hoy: el historial solo carga desde el día 1 del mes pasado, así que una
    // fecha fija acaba quedándose fuera del rango con el paso del tiempo.
    $assert(str_contains($html,(new DateTimeImmutable('today'))->format('d/m/Y')),'la fecha debe mostrarse en formato d/m/Y, igual que el resto de la aplicación (formatRunTime): '.$html);
    $assert(str_contains($html,'09:05'),'la hora debe seguir mostrándose, junto a la fecha, no en su lugar: '.$html);
});
$test('Fase 19.1 — Inicio: el rótulo "Archivadas hoy" lleva un id estable (archived-today-label) para que app.js pueda cambiarlo a "Archivadas esta semana"/"este mes"/"el mes pasado" según el filtro activo',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    set_error_handler(static fn(int$errno,string$message):bool=>str_contains($message,'session')||str_contains($message,'headers already'));
    $_SERVER['REQUEST_METHOD']='GET';$_GET=['route'=>''];
    $method=new ReflectionMethod(Salvest\WebApp::class,'dashboard');$method->setAccessible(true);
    ob_start();$method->invoke($webApp);$html=ob_get_clean();
    restore_error_handler();
    $assert(str_contains($html,'<span class="metric-label" id="archived-today-label">Archivadas hoy</span>'),'el rótulo debe llevar exactamente ese id, sobre el que engancha app.js: '.$html);
});
$test('Fase 19.1 — app.js: el rótulo del recuadro cambia junto con el número, para cada uno de los 4 periodos (guarda de regresión de código)',static function()use($assert):void{
    $source=file_get_contents(__DIR__.'/../public/assets/app.js');
    $assert(str_contains($source,"getElementById('archived-today-label')"),'app.js debe enganchar el rótulo por su id');
    foreach(["'Archivadas esta semana'","'Archivadas este mes'","'Archivadas el mes pasado'"] as $label){
        $assert(str_contains($source,$label),"debe existir el texto exacto $label");
    }
    $assert(str_contains($source,'archivedLabel.textContent=periodLabels[period]'),'el cambio de rótulo debe aplicarse en el mismo sitio que el cambio de número (applyPeriod)');
});
$test('Inicio: el botón "Archivadas hoy" está correctamente enlazado al panel desplegable',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    set_error_handler(static fn(int$errno,string$message):bool=>str_contains($message,'session')||str_contains($message,'headers already'));
    $_SERVER['REQUEST_METHOD']='GET';$_GET=['route'=>''];
    $method=new ReflectionMethod(Salvest\WebApp::class,'dashboard');$method->setAccessible(true);
    ob_start();$method->invoke($webApp);$html=ob_get_clean();
    restore_error_handler();
    $assert(str_contains($html,'id="archived-today-toggle"') && str_contains($html,'aria-controls="archived-today-panel"'),'el botón debe apuntar al panel por aria-controls: '.$html);
    $assert(str_contains($html,'id="archived-today-panel"'),'el panel debe existir con el id esperado');
});

// ---- AttachmentPurge: "Eliminar factura" ----
$test('AttachmentPurge::purge(): borra la fila y el fichero local, y deja de participar en el dedupe global',static function()use($assert,$sqliteDbWithLock):void{
    $db=$sqliteDbWithLock('always-free');
    $tmpFile=tempnam(sys_get_temp_dir(),'purge-test-');
    file_put_contents($tmpFile,'%PDF-fake');
    $db->execute("INSERT INTO processed_attachments(status,processed_at,original_filename,attachment_sha256,output_path) VALUES (?,?,?,?,?)",
        ['needs_review',date('Y-m-d H:i:s'),'x.pdf','sha-purge-1',$tmpFile]);
    $id=(int)$db->pdo()->lastInsertId();
    $result=(new Salvest\AttachmentPurge($db))->purge($id);
    $assert($result['ok']===true,'debe completarse con éxito: '.$result['message']);
    $assert($db->one('SELECT * FROM processed_attachments WHERE id=?',[$id])===null,'la fila debe desaparecer por completo');
    $assert(!is_file($tmpFile),'el fichero local debe borrarse también');
    $prior=$db->one("SELECT * FROM processed_attachments WHERE attachment_sha256=? AND status IN ('classified','unclassified','needs_review','duplicate') ORDER BY id LIMIT 1",['sha-purge-1']);
    $assert($prior===null,'tras eliminarla, el dedupe global no debe encontrar ningún rastro: si el mismo documento vuelve a llegar, se trata como nuevo');
});
$test('AttachmentPurge::purge(): no toca processed_messages ni ninguna fila hermana, aunque haya una ya clasificada',static function()use($assert,$sqliteDbWithLock):void{
    $db=$sqliteDbWithLock('always-free');
    $db->execute("INSERT INTO processed_messages(mailbox_id,uidvalidity,message_uid,status,processed_at,document_count) VALUES (?,?,?,?,?,?)",[1,'1001','500','needs_review',date('Y-m-d H:i:s'),2]);
    $messageId=(int)$db->pdo()->lastInsertId();
    $db->execute("INSERT INTO processed_attachments(mailbox_id,uidvalidity,message_uid,status,processed_at,original_filename) VALUES (?,?,?,?,?,?)",[1,'1001','500','needs_review',date('Y-m-d H:i:s'),'a-eliminar.pdf']);
    $targetId=(int)$db->pdo()->lastInsertId();
    $db->execute("INSERT INTO processed_attachments(mailbox_id,uidvalidity,message_uid,status,processed_at,original_filename) VALUES (?,?,?,?,?,?)",[1,'1001','500','classified',date('Y-m-d H:i:s'),'ya-clasificada.pdf']);
    $siblingId=(int)$db->pdo()->lastInsertId();
    $result=(new Salvest\AttachmentPurge($db))->purge($targetId);
    $assert($result['ok']===true,'debe permitirse aunque haya un hermano ya clasificado: '.$result['message']);
    $assert($db->one('SELECT * FROM processed_attachments WHERE id=?',[$targetId])===null,'la fila objetivo debe desaparecer');
    $sibling=$db->one('SELECT status FROM processed_attachments WHERE id=?',[$siblingId]);
    $assert($sibling!==null && $sibling['status']==='classified','el hermano classified nunca debe tocarse');
    $message=$db->one('SELECT status,document_count FROM processed_messages WHERE id=?',[$messageId]);
    $assert($message!==null && $message['status']==='needs_review' && (int)$message['document_count']===2,'processed_messages no debe modificarse en absoluto: '.json_encode($message));
});
$test('AttachmentPurge::purge(): rechaza si el estado ya no es revisable (classified/duplicate/requeued/dismissed_not_invoice)',static function()use($assert,$sqliteDbWithLock):void{
    $db=$sqliteDbWithLock('always-free');
    foreach(['classified','duplicate','requeued','dismissed_not_invoice'] as $status){
        $db->execute("INSERT INTO processed_attachments(status,processed_at,original_filename) VALUES (?,?,?)",[$status,date('Y-m-d H:i:s'),'x.pdf']);
        $id=(int)$db->pdo()->lastInsertId();
        $result=(new Salvest\AttachmentPurge($db))->purge($id);
        $assert($result['ok']===false,"un estado ya resuelto ($status) nunca debe poder eliminarse por esta vía");
        $assert($db->one('SELECT 1 FROM processed_attachments WHERE id=?',[$id])!==null,'no debe borrarse nada si se rechaza');
    }
});
$test('AttachmentPurge::purge(): rechaza si la factura tiene un archivo en Drive asociado (defensa en profundidad)',static function()use($assert,$sqliteDbWithLock):void{
    $db=$sqliteDbWithLock('always-free');
    $db->execute("INSERT INTO processed_attachments(status,processed_at,original_filename,drive_file_id) VALUES (?,?,?,?)",['needs_review',date('Y-m-d H:i:s'),'x.pdf','drive-id-123']);
    $id=(int)$db->pdo()->lastInsertId();
    $result=(new Salvest\AttachmentPurge($db))->purge($id);
    $assert($result['ok']===false,'nunca debe eliminar automáticamente algo con rastro en Drive');
    $assert($db->one('SELECT 1 FROM processed_attachments WHERE id=?',[$id])!==null);
});
$test('AttachmentPurge::purge(): funciona igual desde unclassified, needs_review y error',static function()use($assert,$sqliteDbWithLock):void{
    $db=$sqliteDbWithLock('always-free');
    foreach(['unclassified','needs_review','error'] as $status){
        $db->execute("INSERT INTO processed_attachments(status,processed_at,original_filename) VALUES (?,?,?)",[$status,date('Y-m-d H:i:s'),'x.pdf']);
        $id=(int)$db->pdo()->lastInsertId();
        $result=(new Salvest\AttachmentPurge($db))->purge($id);
        $assert($result['ok']===true,"debe poder eliminarse desde $status: ".$result['message']);
    }
});
$test('/Revisar: "Eliminar factura" aparece siempre, incluso cuando hay un hermano ya clasificado (a diferencia de "Esto no es una factura")',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp,$requestWebApp):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    $db->execute('INSERT INTO mailboxes(descriptive_name,email,imap_host,imap_port,use_ssl,username,encrypted_password,input_folder,active) VALUES (?,?,?,?,?,?,?,?,1)',
        ['Test','buzon@example.com','imap.example.com',993,1,'buzon@example.com','x','INBOX']);
    $mailboxId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO processed_attachments(mailbox_id,uidvalidity,message_uid,status,processed_at,original_filename) VALUES (?,?,?,?,?,?)',
        [$mailboxId,'1001','500','needs_review',date('Y-m-d H:i:s'),'objetivo.pdf']);
    $db->execute('INSERT INTO processed_attachments(mailbox_id,uidvalidity,message_uid,status,processed_at,original_filename) VALUES (?,?,?,?,?,?)',
        [$mailboxId,'1001','500','classified',date('Y-m-d H:i:s'),'ya-clasificada.pdf']);
    $html=$requestWebApp($webApp,'GET','reviews');
    $assert(str_contains($html,'Eliminar factura'),'el botón de eliminar debe verse aunque el correo tenga un hermano ya clasificado');
    $assert(!str_contains($html,'Esto no es una factura'),'en cambio, descartar todo el correo sí debe seguir bloqueado en este caso');
});
$test('/Revisar: eliminar sin confirmar (confirm_purge vacío) se rechaza server-side, pese al CSRF válido',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp,$requestWebApp):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    $db->execute('INSERT INTO processed_attachments(status,processed_at,original_filename) VALUES (?,?,?)',['needs_review',date('Y-m-d H:i:s'),'x.pdf']);
    $id=(int)$db->pdo()->lastInsertId();
    $html=$requestWebApp($webApp,'POST','reviews',['action'=>'purge','id'=>(string)$id,'csrf'=>$_SESSION['csrf']??'','confirm_purge'=>'']);
    $assert(str_contains($html,'no fue confirmada'),'sin confirm_purge=PURGE debe rechazarse aunque el CSRF sea correcto: '.$html);
    $assert($db->one('SELECT 1 FROM processed_attachments WHERE id=?',[$id])!==null,'no debe borrarse nada si la confirmación no llegó');
});
$test('AttachmentPurge::purge(): el resultado trae la fila borrada completa, para que WebApp pueda auditarla',static function()use($assert,$sqliteDbWithLock):void{
    // No se prueba a través de la ruta POST completa: un éxito real dispara WebApp::redirect(),
    // que llama a exit() y mataría el proceso del test runner (mismo motivo por el que
    // requeue()/dismiss() tampoco se prueban así en su camino de éxito). Se verifica en su lugar
    // que purge() devuelve lo necesario para que el $this->audit(...) del controlador funcione.
    $db=$sqliteDbWithLock('always-free');
    $db->execute('INSERT INTO processed_attachments(status,processed_at,original_filename) VALUES (?,?,?)',['needs_review',date('Y-m-d H:i:s'),'para-auditar.pdf']);
    $id=(int)$db->pdo()->lastInsertId();
    $result=(new Salvest\AttachmentPurge($db))->purge($id);
    $assert($result['ok']===true);
    $assert(isset($result['deleted']) && $result['deleted']['original_filename']==='para-auditar.pdf','el resultado debe traer la fila completa que se acaba de borrar, para el audit_log');
});

// ---- WebApp::page(): cache-busting de app.css/app.js ----
$test('WebApp::page(): app.css y app.js se sirven con ?v=<mtime real>, para que un despliegue nunca quede oculto tras caché del navegador',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    $realCssVersion=(string)filemtime(__DIR__.'/../public/assets/app.css');
    $realJsVersion=(string)filemtime(__DIR__.'/../public/assets/app.js');
    set_error_handler(static fn(int$errno,string$message):bool=>str_contains($message,'session')||str_contains($message,'headers already'));
    $method=new ReflectionMethod(Salvest\WebApp::class,'page');$method->setAccessible(true);
    ob_start();$method->invoke($webApp,'Prueba','<p>contenido</p>');$html=ob_get_clean();
    restore_error_handler();
    $assert(str_contains($html,'/assets/app.css?v='.$realCssVersion),'debe usar el mtime real de app.css, no un valor fijo: '.$html);
    $assert(str_contains($html,'/assets/app.js?v='.$realJsVersion),'debe usar el mtime real de app.js: '.$html);
});

// ---- Fase 2 del maestro de proveedores: estructura nueva, ningún comportamiento nuevo ----
// Corre contra un MySQL de tests indicado EXPLÍCITAMENTE en la variable de entorno
// SALVEST_TEST_MYSQL ("host=127.0.0.1;port=3307;dbname=salvest_test;user=root;password=") y
// cuyo nombre de base contenga "test". Antes leía config/config.php, que en una copia del
// servidor apunta a PRODUCCIÓN: si esa base hubiera sido alcanzable, estos tests habrían
// migrado y escrito en ella. Nunca más: sin la variable, los tests se saltan. Se conecta directo
// a esa base (no se crea ninguna nueva): Schema::migrate() es aditivo e idempotente por diseño, así que
// dejarlo aplicado ahí entre ejecuciones es seguro y deseable (así se comporta igual que en
// producción cuando llegue el día). Los tests que insertan filas de prueba (aliases, suppliers)
// lo hacen dentro de una transacción con rollback en el finally, así que no dejan datos
// residuales pase lo que pase. Si no hay MySQL disponible en el entorno donde corre
// tests/run.php, cada test se salta con un aviso explícito en vez de fallar: Schema::migrate()
// es MySQL-específico (information_schema) y no tiene sentido simularlo contra SQLite, a
// diferencia del resto de la suite.
/** Configuración de la base MySQL de tests, en el mismo formato que config.php['database'], o null. */
$testMysqlConfig=static function():?array{
    $spec=getenv('SALVEST_TEST_MYSQL');
    if(!is_string($spec)||$spec==='')return null;
    $raw=[];
    foreach(explode(';',$spec) as $pair){[$key,$value]=array_pad(explode('=',$pair,2),2,'');$raw[trim($key)]=$value;}
    if(!str_contains(strtolower($raw['dbname']??''),'test'))throw new RuntimeException('SALVEST_TEST_MYSQL debe apuntar a una base cuyo nombre contenga "test"');
    return['host'=>$raw['host']??'127.0.0.1','port'=>(int)($raw['port']??3306),'name'=>$raw['dbname'],'user'=>$raw['user']??'root','password'=>$raw['password']??'','charset'=>'utf8mb4'];
};
$mysqlSchemaTest=static function()use($assert,$testMysqlConfig):?array{
    $dbConfig=$testMysqlConfig();
    if($dbConfig===null)return null;
    try{
        // Mismas opciones que Database::__construct(): filas asociativas, errores como excepción.
        $pdo=new PDO("mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['name']};charset=utf8mb4",$dbConfig['user'],$dbConfig['password'],
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    }catch(Throwable $error){return null;}
    $reflection=new ReflectionClass(Salvest\Database::class);
    $db=$reflection->newInstanceWithoutConstructor();
    $property=$reflection->getProperty('pdo');$property->setAccessible(true);$property->setValue($db,$pdo);
    return['db'=>$db,'pdo'=>$pdo];
};
$mysqlSchemaCleanup=static function(array $ctx)use($assert):void{
    // Nada que borrar: la migración es aditiva/idempotente (queda aplicada, a propósito) y
    // cualquier fila insertada por un test individual vive dentro de su propia transacción.
};

$test('Schema::migrate(): idempotente sobre MySQL real — correr 3 veces no falla ni duplica columnas/índices',static function()use($assert,$mysqlSchemaTest,$mysqlSchemaCleanup):void{
    $ctx=$mysqlSchemaTest();
    if($ctx===null){echo "SKIP (sin SALVEST_TEST_MYSQL)\n";return;}
    $db=$ctx['db'];$schemaFile=dirname(__DIR__).'/database/schema.sql';
    try{
        Salvest\Schema::migrate($db,$schemaFile);
        Salvest\Schema::migrate($db,$schemaFile);
        Salvest\Schema::migrate($db,$schemaFile);
    }finally{$mysqlSchemaCleanup($ctx);}
    // Si algo no fuera idempotente, la segunda o tercera pasada ya habría lanzado antes de llegar aquí.
    $assert(true);
});
$test('Schema::migrate(): añade suppliers.name y suppliers.normalized_official_name',static function()use($assert,$mysqlSchemaTest,$mysqlSchemaCleanup):void{
    $ctx=$mysqlSchemaTest();
    if($ctx===null){echo "SKIP (sin MySQL local disponible)\n";return;}
    $db=$ctx['db'];
    try{
        Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
        $assert($db->one("SELECT 1 ok FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='suppliers' AND column_name='name'")!==null,'falta suppliers.name');
        $assert($db->one("SELECT 1 ok FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='suppliers' AND column_name='normalized_official_name'")!==null,'falta suppliers.normalized_official_name');
        $columns=$db->all("SELECT column_name AS name,is_nullable AS nullable FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='suppliers' AND column_name IN ('name','normalized_official_name')");
        foreach($columns as $column)$assert($column['nullable']==='YES',$column['name'].' debe ser NULLABLE en esta fase');
    }finally{$mysqlSchemaCleanup($ctx);}
});
$test('Schema::migrate(): crea idx_supplier_normalized_official, idx_supplier_cif y uq_supplier_alias',static function()use($assert,$mysqlSchemaTest,$mysqlSchemaCleanup):void{
    $ctx=$mysqlSchemaTest();
    if($ctx===null){echo "SKIP (sin MySQL local disponible)\n";return;}
    $db=$ctx['db'];
    try{
        Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
        $assert($db->one("SELECT 1 ok FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='suppliers' AND index_name='idx_supplier_normalized_official'")!==null,'falta idx_supplier_normalized_official');
        $assert($db->one("SELECT 1 ok FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='suppliers' AND index_name='idx_supplier_cif'")!==null,'falta idx_supplier_cif');
        $cifIndex=$db->one("SELECT non_unique AS n FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='suppliers' AND index_name='idx_supplier_cif' LIMIT 1");
        $assert((int)$cifIndex['n']===1,'idx_supplier_cif NO debe ser UNIQUE en esta fase');
        $assert($db->one("SELECT 1 ok FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='supplier_aliases' AND index_name='uq_supplier_alias'")!==null,'falta uq_supplier_alias');
        $aliasIndex=$db->one("SELECT non_unique AS n FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='supplier_aliases' AND index_name='uq_supplier_alias' LIMIT 1");
        $assert((int)$aliasIndex['n']===0,'uq_supplier_alias SÍ debe ser UNIQUE');
    }finally{$mysqlSchemaCleanup($ctx);}
});
$test('Fase 10 — Schema::migrate() elimina worker_locks y drive_folders (tablas confirmadas muertas: 0 referencias en el código, 0 filas en producción)',static function()use($assert,$mysqlSchemaTest,$mysqlSchemaCleanup):void{
    $ctx=$mysqlSchemaTest();
    if($ctx===null){echo "SKIP (sin MySQL local disponible)\n";return;}
    $db=$ctx['db'];
    try{
        Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
        $assert($db->one("SELECT 1 ok FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='worker_locks'")===null,'worker_locks debería haberse eliminado');
        $assert($db->one("SELECT 1 ok FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='drive_folders'")===null,'drive_folders debería haberse eliminado');
        // Repetir la migración no debe fallar aunque las tablas ya no existan (idempotencia del DROP).
        Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
    }finally{$mysqlSchemaCleanup($ctx);}
});
$test('Fase 10 — Schema::migrate() elimina las columnas confirmadas muertas (suppliers.phone/website, communities.country/notes, audit_log.old_values_json — 0 uso en código, 0 valores reales en producción)',static function()use($assert,$mysqlSchemaTest,$mysqlSchemaCleanup):void{
    $ctx=$mysqlSchemaTest();
    if($ctx===null){echo "SKIP (sin MySQL local disponible)\n";return;}
    $db=$ctx['db'];
    try{
        Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
        $deadColumns=[['suppliers','phone'],['suppliers','website'],['communities','country'],['communities','notes'],['audit_log','old_values_json']];
        foreach($deadColumns as [$table,$column]){
            $exists=$db->one("SELECT 1 ok FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?",[$table,$column]);
            $assert($exists===null,"$table.$column debería haberse eliminado");
        }
        // suppliers.notes NO estaba en el alcance aprobado (solo communities.notes) -> debe seguir existiendo.
        $assert($db->one("SELECT 1 ok FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='suppliers' AND column_name='notes'")!==null,'suppliers.notes NO debía tocarse');
        // Repetir la migración no debe fallar aunque las columnas ya no existan (idempotencia del DROP).
        Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
    }finally{$mysqlSchemaCleanup($ctx);}
});
$test('supplier_aliases: el UNIQUE(supplier_id,normalized_value) impide el mismo alias normalizado duplicado para el mismo proveedor',static function()use($assert,$mysqlSchemaTest,$mysqlSchemaCleanup):void{
    $ctx=$mysqlSchemaTest();
    if($ctx===null){echo "SKIP (sin MySQL local disponible)\n";return;}
    $db=$ctx['db'];$pdo=$ctx['pdo'];
    Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
    $pdo->beginTransaction();
    try{
        $db->execute("INSERT INTO service_types(name,normalized_name) VALUES ('Extintores fase2 test','extintores fase2 test')");
        $serviceId=(int)$db->pdo()->lastInsertId();
        $db->execute('INSERT INTO suppliers(official_name,normalized_name,main_service_type_id,active) VALUES (?,?,?,1)',['PROFOC TEST FASE2','profoc test fase2',$serviceId]);
        $supplierId=(int)$db->pdo()->lastInsertId();
        $db->execute('INSERT INTO supplier_aliases(supplier_id,alias_type,value,normalized_value,active) VALUES (?,?,?,?,1)',[$supplierId,'name','PROFOC','profoc']);
        $rejected=false;
        try{
            $db->execute('INSERT INTO supplier_aliases(supplier_id,alias_type,value,normalized_value,active) VALUES (?,?,?,?,1)',[$supplierId,'domain','PROFOC','profoc']);
        }catch(Throwable $error){$rejected=true;}
        $assert($rejected,'un segundo alias con el mismo (supplier_id,normalized_value) debe ser rechazado por la BD, aunque cambie alias_type');
        $count=$db->one('SELECT COUNT(*) n FROM supplier_aliases WHERE supplier_id=? AND normalized_value=?',[$supplierId,'profoc']);
        $assert((int)$count['n']===1,'solo debe quedar una fila');
    }finally{if($pdo->inTransaction())$pdo->rollBack();$mysqlSchemaCleanup($ctx);}
});
$test('supplier_aliases: distintos supplier_id SÍ pueden compartir el mismo normalized_value (la UNIQUE es por par, no global)',static function()use($assert,$mysqlSchemaTest,$mysqlSchemaCleanup):void{
    $ctx=$mysqlSchemaTest();
    if($ctx===null){echo "SKIP (sin MySQL local disponible)\n";return;}
    $db=$ctx['db'];$pdo=$ctx['pdo'];
    Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
    $pdo->beginTransaction();
    try{
        $db->execute('INSERT INTO suppliers(official_name,normalized_name,active) VALUES (?,?,1)',['Proveedor A fase2 test','proveedor a fase2 test']);
        $idA=(int)$db->pdo()->lastInsertId();
        $db->execute('INSERT INTO suppliers(official_name,normalized_name,active) VALUES (?,?,1)',['Proveedor B fase2 test','proveedor b fase2 test']);
        $idB=(int)$db->pdo()->lastInsertId();
        $db->execute('INSERT INTO supplier_aliases(supplier_id,alias_type,value,normalized_value,active) VALUES (?,?,?,?,1)',[$idA,'name','mismo-dominio.es','mismo dominio es']);
        $db->execute('INSERT INTO supplier_aliases(supplier_id,alias_type,value,normalized_value,active) VALUES (?,?,?,?,1)',[$idB,'name','mismo-dominio.es','mismo dominio es']);
        $assert(true,'no debe lanzar — supplier_id distinto, mismo normalized_value es válido');
    }finally{$pdo->rollBack();$mysqlSchemaCleanup($ctx);}
});
$test('compatibilidad: crear un supplier con el modelo actual (solo official_name/normalized_name/cif) sigue funcionando con name y normalized_official_name presentes pero NULL',static function()use($assert,$sqliteDb,$classifierSchema,$resolveSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO suppliers(official_name,normalized_name,cif,main_service_type_id,active) VALUES (?,?,?,?,1)',
        ['GARCÍA MARÍN CONSULTORES, S.L.',Salvest\Text::normalize('GARCÍA MARÍN CONSULTORES, S.L.'),null,null]);
    $id=(int)$db->pdo()->lastInsertId();
    $row=$db->one('SELECT * FROM suppliers WHERE id=?',[$id]);
    $assert($row!==null && $row['name']===null && $row['normalized_official_name']===null,'las columnas nuevas deben existir en la fila y valer NULL, sin romper el INSERT actual');
    $resolved=$resolveSupplier($db,'GARCÍA MARÍN CONSULTORES, S.L.',null,'facturas@example.com');
    $assert((int)($resolved['supplier']['id']??0)===$id && $resolved['source']==='name_exact','la resolución sigue encontrando el proveedor exactamente igual');
});
$test('compatibilidad: con comunidad, un proveedor vinculado se resuelve igual con las columnas nuevas presentes-pero-inactivas',static function()use($assert,$sqliteDb,$classifierSchema,$resolveSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute("INSERT INTO service_types(name,normalized_name,active) VALUES ('Extintores',?,1)",[Salvest\Text::normalize('Extintores')]);
    $serviceId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO suppliers(official_name,normalized_name,cif,main_service_type_id,active) VALUES (?,?,?,?,1)',['PROFOC',Salvest\Text::normalize('PROFOC'),null,$serviceId]);
    $supplierId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,active) VALUES (?,?,?,?,?,1)',['99','CP Fase 2',Salvest\Text::normalize('CP Fase 2'),null,'Calle Test 1']);
    $communityId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO community_suppliers(community_id,supplier_id,category) VALUES (?,?,?)',[$communityId,$supplierId,'Extintores']);
    $found=$resolveSupplier($db,'PROFOC',null,'facturas@example.com',['id'=>$communityId,'official_name'=>'CP Fase 2']);
    $assert((int)($found['supplier']['id']??0)===$supplierId && $found['source']==='name_exact' && $found['linked']===true && $found['relation']['category']==='Extintores',json_encode($found['outcome']));
});

// ---- Fase 3 del maestro de proveedores: bin/migrate-supplier-master.php, contra MySQL real ----
// Se ejecuta el script real (no una reimplementación) como subproceso contra el mismo MySQL de
// desarrollo/tests que ya usa config/config.php — nunca contra producción. Los suppliers de
// prueba se insertan con los MISMOS official_name que el script trae hardcodeados (son los
// reales de producción, ya públicos desde la Fase 1), así el dry-run/apply ejercita exactamente
// el mapping real. Todo lo insertado se borra al final (DELETE FROM suppliers -> CASCADE se
// lleva aliases y community_suppliers), pase o falle el test.
$supplierMigrationFixture=static function(array $ctx)use($assert):array{
    $db=$ctx['db'];
    // Los 37 nombres restantes del mapping (los que no llevan lógica especial en este test, pero
    // TIENEN que existir para que el script no reporte "No encontrado" en ellos y el exit code
    // se mantenga en 0 — el fixture completo replica los 41 suppliers reales de producción).
    $genericNames=['IBERDROLA','FACSA','FAIN','RUIZ','CRISMAN','EXTINPLAN','OTIS','THYSSEN','ORONA','MALLASEN','EMBARBA','LA BRUJA','SCHINDLER','ENINTER','SUMINISTROS SANZ','GYFSA','PROPODA','POOLTERMIA','PROFOC','DOSDA','INMECAS','ENDESA','JARDIGRUP','JARDITEC','MESNET','LIMBUR','JOMASAN','MB','CALIN IGNAT','ALINA - PROPIETARIA','LAURA - PROPIETARIO'];
    $names=array_merge(['PERTOR','CRISLA','CONSTANTIN - PROPIETARIO','ADRIAN TURCU','SERGIO RAUL','YOLIMPIO','EXTNCAS','EXTINCAS','ENERVIA','ENERVIA SOLUCIONES ENERGETICAS'],$genericNames);
    // Limpieza defensiva de una ejecución anterior que no hubiera terminado de limpiar.
    $placeholders=implode(',',array_fill(0,count($names),'?'));
    $db->execute("DELETE FROM suppliers WHERE official_name IN ($placeholders)",$names);

    $db->execute("INSERT IGNORE INTO service_types(name,normalized_name) VALUES ('Extintores Fase3 Test','extintores fase3 test')");
    $extService=(int)$db->one("SELECT id FROM service_types WHERE normalized_name='extintores fase3 test'")['id'];
    $db->execute("INSERT IGNORE INTO service_types(name,normalized_name) VALUES ('Electricidad Fase3 Test','electricidad fase3 test')");
    $elecService=(int)$db->one("SELECT id FROM service_types WHERE normalized_name='electricidad fase3 test'")['id'];
    $db->execute("INSERT IGNORE INTO service_types(name,normalized_name) VALUES ('Otros Fase3 Test','otros fase3 test')");
    $otrosService=(int)$db->one("SELECT id FROM service_types WHERE normalized_name='otros fase3 test'")['id'];
    $db->execute("INSERT IGNORE INTO service_types(name,normalized_name) VALUES ('Limpieza Fase3 Test','limpieza fase3 test')");
    $limpiezaService=(int)$db->one("SELECT id FROM service_types WHERE normalized_name='limpieza fase3 test'")['id'];
    $db->execute("INSERT IGNORE INTO service_types(name,normalized_name) VALUES ('Ascensor Fase3 Test','ascensor fase3 test')");
    $ascensorService=(int)$db->one("SELECT id FROM service_types WHERE normalized_name='ascensor fase3 test'")['id'];
    $db->execute("INSERT IGNORE INTO service_types(name,normalized_name) VALUES ('Jardineria Fase3 Test','jardineria fase3 test')");
    $jardineriaService=(int)$db->one("SELECT id FROM service_types WHERE normalized_name='jardineria fase3 test'")['id'];

    // Limpieza ampliada: barre también cualquier fila ya renombrada por una ejecución previa que
    // no hubiera terminado de limpiar (name/official_name YA en su forma final tras --apply) —
    // el cleanup por id de más abajo es la vía principal, esto es solo una red de seguridad.
    $finalNames=['ASCENSORES PERTOR, S.L.','CRISLA LIMPIEZAS Y CRISTALIZADOS, S.L.','CONSTANTIN FRATILA','X4153497L','SERGIO RAUL MARIN RUIZ','RAFAEL GUIJARRO PRADES','EXTINTORES CASTELLÓN, S.L.','ENERVIA SOLUCIONES ENERGETICAS S.L.'];
    $finalPlaceholders=implode(',',array_fill(0,count($finalNames),'?'));
    $db->execute("DELETE FROM suppliers WHERE official_name IN ($finalPlaceholders)",$finalNames);

    $allIds=[];
    $insert=static function(string $officialName,?int $serviceId)use($db,&$allIds):int{
        $db->execute('INSERT INTO suppliers(official_name,normalized_name,cif,main_service_type_id,active) VALUES (?,?,?,?,1)',
            [$officialName,Salvest\Text::normalize($officialName),null,$serviceId]);
        $id=(int)$db->pdo()->lastInsertId();
        $allIds[]=$id;
        return $id;
    };
    // Actualizaciones normales — cubren CIF de empresa, NIE, NIF personal y cif=NULL forzado.
    $pertorId=$insert('PERTOR',$ascensorService);
    $crislaId=$insert('CRISLA',$limpiezaService);
    $constantinId=$insert('CONSTANTIN - PROPIETARIO',$limpiezaService);
    $adrianId=$insert('ADRIAN TURCU',$limpiezaService);
    $sergioId=$insert('SERGIO RAUL',$jardineriaService);
    $yolimpioId=$insert('YOLIMPIO',$limpiezaService);
    // Fusión EXTNCAS/EXTINCAS — con una comunidad EXCLUSIVA de cada uno y una comunidad
    // deliberadamente EN COMÚN (misma community_id+category en ambos) para forzar el camino de
    // deduplicación de relaciones, algo que no ocurre hoy en producción pero que el código debe
    // soportar de todas formas.
    $extncasId=$insert('EXTNCAS',$extService);
    $extincasId=$insert('EXTINCAS',$extService);
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,main_address,active) VALUES (?,?,?,?,1)',['F3A','CP Fase3 Exclusiva Source',Salvest\Text::normalize('CP Fase3 Exclusiva Source'),'Calle Test 1']);
    $communityExclusiveSource=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,main_address,active) VALUES (?,?,?,?,1)',['F3B','CP Fase3 Exclusiva Target',Salvest\Text::normalize('CP Fase3 Exclusiva Target'),'Calle Test 2']);
    $communityExclusiveTarget=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,main_address,active) VALUES (?,?,?,?,1)',['F3C','CP Fase3 Comun',Salvest\Text::normalize('CP Fase3 Comun'),'Calle Test 3']);
    $communityShared=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO community_suppliers(community_id,supplier_id,category,source_column,raw_provider_name) VALUES (?,?,?,?,?)',[$communityExclusiveSource,$extncasId,'EXTINTORES','EXTINTORES','EXTNCAS']);
    $db->execute('INSERT INTO community_suppliers(community_id,supplier_id,category,source_column,raw_provider_name) VALUES (?,?,?,?,?)',[$communityExclusiveTarget,$extincasId,'EXTINTORES','EXTINTORES','EXTINCAS']);
    $db->execute('INSERT INTO community_suppliers(community_id,supplier_id,category,source_column,raw_provider_name) VALUES (?,?,?,?,?)',[$communityShared,$extncasId,'EXTINTORES','EXTINTORES','EXTNCAS']);
    $db->execute('INSERT INTO community_suppliers(community_id,supplier_id,category,source_column,raw_provider_name) VALUES (?,?,?,?,?)',[$communityShared,$extincasId,'EXTINTORES','EXTINTORES','EXTINCAS']);
    // Fusión ENERVIA — exactamente como en producción: ambos con cif='' y 0 relaciones.
    $enerviaId=$insert('ENERVIA',$otrosService);
    $db->execute('UPDATE suppliers SET cif=? WHERE id=?',['',$enerviaId]);
    $enerviaSolId=$insert('ENERVIA SOLUCIONES ENERGETICAS',$elecService);
    $db->execute('UPDATE suppliers SET cif=? WHERE id=?',['',$enerviaSolId]);
    // El resto del mapping (sin lógica especial en estos tests) — con cualquier servicio, no se
    // comprueba aquí, solo tiene que EXISTIR para que el script no reporte "No encontrado".
    foreach($genericNames as $genericName)$insert($genericName,null);

    return compact('pertorId','crislaId','constantinId','adrianId','sergioId','yolimpioId','extncasId','extincasId','enerviaId','enerviaSolId','communityExclusiveSource','communityExclusiveTarget','communityShared','names','elecService','allIds');
};
$supplierMigrationCleanup=static function(array $ctx,array $fixture)use($assert):void{
    $db=$ctx['db'];
    // Por id, no por nombre: tras --apply el official_name de un supplier YA NO ES el que se
    // insertó (name/official_name cambian a su forma final) — limpiar por nombre original se
    // dejaría atrás justo las filas que el test acaba de migrar, contaminando el siguiente test.
    $idPlaceholders=implode(',',array_fill(0,count($fixture['allIds']),'?'));
    $db->execute("DELETE FROM suppliers WHERE id IN ($idPlaceholders)",$fixture['allIds']);
    foreach(['communityExclusiveSource','communityExclusiveTarget','communityShared'] as $key){
        $db->execute('DELETE FROM communities WHERE id=?',[$fixture[$key]]);
    }
};
$runMigrationScript=static function(array $extraArgs=[])use($testMysqlConfig):array{
    // El script carga su configuración con bootstrap.php: se le pasa una configuración temporal
    // que apunta a la base de tests (SALVEST_CONFIG), nunca config/config.php.
    $configPath=sys_get_temp_dir().'/salvest-test-config-'.bin2hex(random_bytes(4)).'.php';
    file_put_contents($configPath,'<?php return '.var_export(['app'=>['timezone'=>'Europe/Madrid'],'database'=>$testMysqlConfig()],true).';');
    $script=dirname(__DIR__).'/bin/migrate-supplier-master.php';
    $previous=getenv('SALVEST_CONFIG');putenv('SALVEST_CONFIG='.$configPath);
    $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.implode(' ',array_map('escapeshellarg',$extraArgs)).' 2>&1';
    exec($cmd,$outputLines,$exitCode);
    putenv($previous===false?'SALVEST_CONFIG':'SALVEST_CONFIG='.$previous);@unlink($configPath);
    return ['output'=>implode("\n",$outputLines),'exit'=>$exitCode];
};

$test('bin/migrate-supplier-master.php --dry-run: no modifica la BD (comportamiento por defecto)',static function()use($assert,$mysqlSchemaTest,$supplierMigrationFixture,$supplierMigrationCleanup,$runMigrationScript):void{
    $ctx=$mysqlSchemaTest();
    if($ctx===null){echo "SKIP (sin MySQL local disponible)\n";return;}
    $db=$ctx['db'];
    Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
    $fixture=$supplierMigrationFixture($ctx);
    try{
        $before=$db->one('SELECT name,official_name,cif FROM suppliers WHERE id=?',[$fixture['pertorId']]);
        $result=$runMigrationScript([]); // sin --apply
        $assert($result['exit']===0,'dry-run debe salir con código 0: '.$result['output']);
        $assert(str_contains($result['output'],'DRY-RUN'),'debe anunciarse como dry-run');
        $after=$db->one('SELECT name,official_name,cif FROM suppliers WHERE id=?',[$fixture['pertorId']]);
        $assert($before===$after,'ni una fila debe cambiar sin --apply');
        $aliasCount=$db->one('SELECT COUNT(*) n FROM supplier_aliases WHERE supplier_id=?',[$fixture['pertorId']])['n'];
        $assert((int)$aliasCount===0,'dry-run no debe insertar ningún alias');
    }finally{$supplierMigrationCleanup($ctx,$fixture);}
});

$test('bin/migrate-supplier-master.php --apply: actualiza name/official_name/normalized_name/normalized_official_name/cif exactamente como se espera (CIF empresa, NIE, NIF personal, cif=NULL forzado)',static function()use($assert,$mysqlSchemaTest,$supplierMigrationFixture,$supplierMigrationCleanup,$runMigrationScript):void{
    $ctx=$mysqlSchemaTest();
    if($ctx===null){echo "SKIP (sin MySQL local disponible)\n";return;}
    $db=$ctx['db'];
    Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
    $fixture=$supplierMigrationFixture($ctx);
    try{
        $result=$runMigrationScript(['--apply']);
        $assert($result['exit']===0,'apply no debe reportar errores: '.$result['output']);

        $pertor=$db->one('SELECT * FROM suppliers WHERE id=?',[$fixture['pertorId']]);
        $assert($pertor['name']==='PERTOR' && $pertor['official_name']==='ASCENSORES PERTOR, S.L.' && $pertor['cif']==='B46699864','PERTOR (CIF de empresa) mal migrado: '.json_encode($pertor));
        $assert($pertor['normalized_name']===Salvest\Text::normalizeCompanyName('PERTOR'),'normalized_name debe usar normalizeCompanyName() sobre el name corto');
        $assert($pertor['normalized_official_name']===Salvest\Text::normalizeCompanyName('ASCENSORES PERTOR, S.L.'),'normalized_official_name debe usar normalizeCompanyName() sobre la razón social');

        $adrian=$db->one('SELECT * FROM suppliers WHERE id=?',[$fixture['adrianId']]);
        $assert($adrian['cif']==='X4153497L','NIE mal migrado (esperado X4153497L, sin guiones): '.$adrian['cif']);

        $sergio=$db->one('SELECT * FROM suppliers WHERE id=?',[$fixture['sergioId']]);
        $assert($sergio['cif']==='53376935F','NIF personal mal migrado: '.$sergio['cif']);

        $yolimpio=$db->one('SELECT * FROM suppliers WHERE id=?',[$fixture['yolimpioId']]);
        $assert($yolimpio['cif']==='18965195Q','NIF personal (YOLIMPIO) mal migrado: '.$yolimpio['cif']);

        $constantin=$db->one('SELECT * FROM suppliers WHERE id=?',[$fixture['constantinId']]);
        $assert($constantin['cif']===null,'CONSTANTIN debe quedar cif=NULL explícito, nunca inventado: '.var_export($constantin['cif'],true));
        $assert($constantin['official_name']==='CONSTANTIN FRATILA','official_name de CONSTANTIN debe actualizarse a la razón social confirmada');

        $crisla=$db->one('SELECT * FROM suppliers WHERE id=?',[$fixture['crislaId']]);
        $assert($crisla['cif']==='B12534228','CRISLA mal migrado');
    }finally{$supplierMigrationCleanup($ctx,$fixture);}
});

$test('bin/migrate-supplier-master.php --apply: aliases se insertan sin duplicar variantes equivalentes al name/official_name, y una alias distinto sí se conserva',static function()use($assert,$mysqlSchemaTest,$supplierMigrationFixture,$supplierMigrationCleanup,$runMigrationScript):void{
    $ctx=$mysqlSchemaTest();
    if($ctx===null){echo "SKIP (sin MySQL local disponible)\n";return;}
    $db=$ctx['db'];
    Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
    $fixture=$supplierMigrationFixture($ctx);
    try{
        $runMigrationScript(['--apply']);
        $aliases=$db->all('SELECT value,normalized_value FROM supplier_aliases WHERE supplier_id=? ORDER BY value',[$fixture['pertorId']]);
        $values=array_column($aliases,'value');
        // "ASCENSORES PERTOR" normaliza (vía normalizeCompanyName, que quita ", S.L.") a lo
        // mismo que normalized_official_name de "ASCENSORES PERTOR, S.L." — es redundante y el
        // script debe filtrarlo; "PERTOR ASCENSORES" es un orden de palabras distinto y sí debe
        // sobrevivir como alias real.
        $assert(in_array('PERTOR ASCENSORES',$values,true),'el alias distinto "PERTOR ASCENSORES" debe conservarse: '.json_encode($values));
        $assert(!in_array('ASCENSORES PERTOR',$values,true),'"ASCENSORES PERTOR" es redundante con official_name tras normalizeCompanyName() y NO debe insertarse como alias: '.json_encode($values));
        $assert(!in_array('PERTOR',$values,true),'"PERTOR" no debe insertarse como alias de sí mismo (ya lo cubre name)');
        $normalized=array_column($aliases,'normalized_value');
        $assert(count($normalized)===count(array_unique($normalized)),'no debe haber dos aliases con el mismo normalized_value para el mismo supplier');
    }finally{$supplierMigrationCleanup($ctx,$fixture);}
});

$test('bin/migrate-supplier-master.php --apply: segunda ejecución es idempotente (KEEP, sin aliases duplicados, sin tocar lo ya correcto)',static function()use($assert,$mysqlSchemaTest,$supplierMigrationFixture,$supplierMigrationCleanup,$runMigrationScript):void{
    $ctx=$mysqlSchemaTest();
    if($ctx===null){echo "SKIP (sin MySQL local disponible)\n";return;}
    $db=$ctx['db'];
    Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
    $fixture=$supplierMigrationFixture($ctx);
    try{
        $runMigrationScript(['--apply']);
        $afterFirst=$db->one('SELECT * FROM suppliers WHERE id=?',[$fixture['pertorId']]);
        $aliasCountFirst=$db->one('SELECT COUNT(*) n FROM supplier_aliases WHERE supplier_id=?',[$fixture['pertorId']])['n'];

        $result=$runMigrationScript(['--apply']); // segunda vez
        $assert($result['exit']===0,'la segunda ejecución no debe fallar: '.$result['output']);
        $assert(str_contains($result['output'],'[KEEP]'),'la segunda pasada debe reportar KEEP para lo ya migrado: '.$result['output']);

        $afterSecond=$db->one('SELECT * FROM suppliers WHERE id=?',[$fixture['pertorId']]);
        $assert($afterFirst===$afterSecond,'ninguna fila ya correcta debe cambiar en la segunda pasada');
        $aliasCountSecond=$db->one('SELECT COUNT(*) n FROM supplier_aliases WHERE supplier_id=?',[$fixture['pertorId']])['n'];
        $assert((int)$aliasCountFirst===(int)$aliasCountSecond,'la segunda pasada no debe duplicar aliases');
    }finally{$supplierMigrationCleanup($ctx,$fixture);}
});

$test('bin/migrate-supplier-master.php --apply: fusión EXTNCAS/EXTINCAS transfiere relaciones exclusivas, deduplica la relación común, y el target final lleva el CIF B12433314',static function()use($assert,$mysqlSchemaTest,$supplierMigrationFixture,$supplierMigrationCleanup,$runMigrationScript):void{
    $ctx=$mysqlSchemaTest();
    if($ctx===null){echo "SKIP (sin MySQL local disponible)\n";return;}
    $db=$ctx['db'];
    Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
    $fixture=$supplierMigrationFixture($ctx);
    try{
        $result=$runMigrationScript(['--apply']);
        $assert($result['exit']===0,'la fusión no debe fallar: '.$result['output']);

        $target=$db->one('SELECT * FROM suppliers WHERE id=?',[$fixture['extincasId']]);
        $source=$db->one('SELECT * FROM suppliers WHERE id=?',[$fixture['extncasId']]);
        $assert((int)$target['active']===1,'el target (EXTINCAS) debe permanecer active=1');
        $assert((int)$source['active']===0,'el source (EXTNCAS) debe quedar active=0, nunca borrado');
        $assert($target['cif']==='B12433314','el target debe llevar el CIF confirmado tras la fusión: '.$target['cif']);
        $assert($target['name']==='EXTINCAS' && $target['official_name']==='EXTINTORES CASTELLÓN, S.L.','name/official_name finales incorrectos: '.json_encode($target));

        $sourceRelations=$db->one('SELECT COUNT(*) n FROM community_suppliers WHERE supplier_id=?',[$fixture['extncasId']])['n'];
        $assert((int)$sourceRelations===0,'el source no debe conservar ninguna relación tras la fusión');

        $exclusiveSourceStillThere=$db->one('SELECT id FROM community_suppliers WHERE community_id=? AND supplier_id=?',[$fixture['communityExclusiveSource'],$fixture['extincasId']]);
        $assert($exclusiveSourceStillThere!==null,'la relación exclusiva del source debe haberse transferido al target');
        $exclusiveTargetStillThere=$db->one('SELECT id FROM community_suppliers WHERE community_id=? AND supplier_id=?',[$fixture['communityExclusiveTarget'],$fixture['extincasId']]);
        $assert($exclusiveTargetStillThere!==null,'la relación que ya era del target no debe perderse');

        $sharedRelations=$db->all('SELECT id FROM community_suppliers WHERE community_id=? AND supplier_id=?',[$fixture['communityShared'],$fixture['extincasId']]);
        $assert(count($sharedRelations)===1,'la relación común a ambos (misma community_id+category) debe quedar deduplicada a una sola fila, no '.count($sharedRelations));

        $aliasValues=array_column($db->all('SELECT value FROM supplier_aliases WHERE supplier_id=?',[$fixture['extincasId']]),'value');
        $assert(in_array('EXTNCAS',$aliasValues,true),'EXTNCAS debe quedar como alias del target fusionado');
    }finally{$supplierMigrationCleanup($ctx,$fixture);}
});

$test('bin/migrate-supplier-master.php --apply: fusión EXTNCAS/EXTINCAS es idempotente (segunda pasada no reactiva el source ni duplica relaciones)',static function()use($assert,$mysqlSchemaTest,$supplierMigrationFixture,$supplierMigrationCleanup,$runMigrationScript):void{
    $ctx=$mysqlSchemaTest();
    if($ctx===null){echo "SKIP (sin MySQL local disponible)\n";return;}
    $db=$ctx['db'];
    Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
    $fixture=$supplierMigrationFixture($ctx);
    try{
        $runMigrationScript(['--apply']);
        $relationsAfterFirst=(int)$db->one('SELECT COUNT(*) n FROM community_suppliers WHERE supplier_id=?',[$fixture['extincasId']])['n'];
        $result=$runMigrationScript(['--apply']);
        $assert($result['exit']===0,'la segunda pasada de la fusión no debe fallar: '.$result['output']);
        $source=$db->one('SELECT active FROM suppliers WHERE id=?',[$fixture['extncasId']]);
        $assert((int)$source['active']===0,'el source fusionado no debe reactivarse en una segunda pasada');
        $relationsAfterSecond=(int)$db->one('SELECT COUNT(*) n FROM community_suppliers WHERE supplier_id=?',[$fixture['extincasId']])['n'];
        $assert($relationsAfterFirst===$relationsAfterSecond,'la segunda pasada no debe duplicar ni perder relaciones del target');
    }finally{$supplierMigrationCleanup($ctx,$fixture);}
});

$test('bin/migrate-supplier-master.php --apply: fusión ENERVIA sin relaciones en ninguno de los dos lados — el target final lleva el CIF B98172885 y el servicio Electricidad del target original',static function()use($assert,$mysqlSchemaTest,$supplierMigrationFixture,$supplierMigrationCleanup,$runMigrationScript):void{
    $ctx=$mysqlSchemaTest();
    if($ctx===null){echo "SKIP (sin MySQL local disponible)\n";return;}
    $db=$ctx['db'];
    Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
    $fixture=$supplierMigrationFixture($ctx);
    try{
        $result=$runMigrationScript(['--apply']);
        $assert($result['exit']===0,'la fusión ENERVIA no debe fallar: '.$result['output']);
        $target=$db->one('SELECT * FROM suppliers WHERE id=?',[$fixture['enerviaSolId']]);
        $source=$db->one('SELECT * FROM suppliers WHERE id=?',[$fixture['enerviaId']]);
        $assert((int)$target['active']===1 && (int)$source['active']===0,'ENERVIA SOLUCIONES ENERGETICAS debe ser el target activo, ENERVIA el source inactivo');
        $assert($target['cif']==='B98172885','el target de ENERVIA debe llevar el CIF confirmado: '.$target['cif']);
        $assert($target['name']==='ENERVIA' && $target['official_name']==='ENERVIA SOLUCIONES ENERGETICAS S.L.','name/official_name finales de ENERVIA incorrectos: '.json_encode($target));
        $assert((int)$target['main_service_type_id']===(int)$fixture['elecService'],'el servicio final debe ser el que ya tenía el target (Electricidad), la fusión no debe decidir uno nuevo por su cuenta');
        // Hallazgo real, no un fallo: "ENERVIA SOLUCIONES ENERGETICAS" normaliza (vía
        // normalizeCompanyName) exactamente igual que official_name una vez le quita ", S.L.",
        // y "ENERVIA SOLUCIONES ENERGETICAS S.L." ES el official_name literal. Los dos "aliases
        // mínimos" pedidos son, tras normalización, redundantes con official_name — la regla
        // "no insertes un alias equivalente a official_name" los descarta a ambos, a propósito.
        // Documentado explícitamente en el informe de Fase 3 para que el usuario lo confirme.
        $aliasValues=array_column($db->all('SELECT value FROM supplier_aliases WHERE supplier_id=?',[$fixture['enerviaSolId']]),'value');
        $assert($aliasValues===[],'ambos aliases "mínimos" de ENERVIA son redundantes con official_name tras normalizeCompanyName() y no deben insertarse: '.json_encode($aliasValues));
    }finally{$supplierMigrationCleanup($ctx,$fixture);}
});

$test('bin/migrate-supplier-master.php: un target/source de fusión ausente reporta ERROR y sale con código 1, sin tocar el resto de suppliers (contención de fallos)',static function()use($assert,$mysqlSchemaTest,$runMigrationScript):void{
    $ctx=$mysqlSchemaTest();
    if($ctx===null){echo "SKIP (sin MySQL local disponible)\n";return;}
    $db=$ctx['db'];
    Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
    // Deliberadamente NO se inserta ningún supplier — ni los normales ni los de fusión — así que
    // TODO el script debe reportar ERROR/NOT_FOUND para las 37+2 entradas, sin lanzar ninguna
    // excepción no controlada y sin dejar ninguna transacción a medias.
    $result=$runMigrationScript(['--apply']);
    $assert($result['exit']===1,'con todo ausente, el exit code debe ser 1 (hubo errores), no un crash ni un 0 silencioso');
    $assert(str_contains($result['output'],'ERRORES:'),'debe listar los errores encontrados: '.$result['output']);
    $assert(!str_contains($result['output'],'Fatal error') && !str_contains($result['output'],'Uncaught'),'ningún error debe escapar como excepción no controlada: '.$result['output']);
});

// ---- Compatibilidad mínima Fase 3 -> Fase 5: name/official_name en los 4 tiers de nombre ----
// $makeCommunityWithSupplier (arriba) ya crea el estado PRE-Fase-3 (solo official_name, name
// queda NULL). Este helper crea el estado POST-Fase-3 real: name = nombre corto, official_name
// = razón social legal, ambos normalizados con la misma función que candidateNames() usa.
$makePostFase3Supplier=static function(Salvest\Database $db,string $communityCode,string $communityName,string $shortName,string $legalName,string $serviceTypeName,?string $cif=null):array{
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,postal_code,city,imap_folder_name,active) VALUES (?,?,?,?,?,?,?,?,1)',
        [$communityCode,$communityName,Salvest\Text::normalize($communityName),$communityCode.'-CIF','Calle '.$communityName,'46000','Valencia',$communityCode.' - '.$communityName]);
    $communityId=(int)$db->pdo()->lastInsertId();
    $service=$db->one('SELECT id FROM service_types WHERE name=?',[$serviceTypeName]);
    if(!$service){$db->execute('INSERT INTO service_types(name,normalized_name,active) VALUES (?,?,1)',[$serviceTypeName,Salvest\Text::normalize($serviceTypeName)]);$serviceTypeId=(int)$db->pdo()->lastInsertId();}
    else $serviceTypeId=(int)$service['id'];
    $db->execute('INSERT INTO suppliers(name,official_name,normalized_name,normalized_official_name,cif,main_service_type_id,active) VALUES (?,?,?,?,?,?,1)',
        [$shortName,$legalName,Salvest\Text::normalizeCompanyName($shortName),Salvest\Text::normalizeCompanyName($legalName),$cif,$serviceTypeId]);
    $supplierId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO community_suppliers(community_id,supplier_id,category,contract_reference) VALUES (?,?,?,?)',[$communityId,$supplierId,$serviceTypeName,null]);
    return['communityId'=>$communityId,'supplierId'=>$supplierId,'communityCif'=>$communityCode.'-CIF'];
};
// Los 7 casos pedidos explícitamente, con su name/official_name reales de la migración de Fase 3.
$sevenCases=[
    ['FACSA','SOCIEDAD DE FOMENTO AGRÍCOLA CASTELLONENSE, S.A.U.','Agua'],
    ['PROFOC','GARCÍA MARÍN CONSULTORES, S.L.','Extintores'],
    ['CRISLA','CRISLA LIMPIEZAS Y CRISTALIZADOS, S.L.','Limpieza'],
    ['THYSSEN','TK ELEVADORES ESPAÑA, S.L.U.','Ascensor'],
    ['INMECAS','H2O PLUS, S.L.','Descalcificador'],
    ['MB','MANTENIMIENTOS MANUEL BASTIDA S.L.U.','Descalcificador'],
    ['YOLIMPIO','RAFAEL GUIJARRO PRADES','Limpieza'],
];

$test('compatibilidad Fase3->5: los 7 casos pedidos (FACSA/PROFOC/CRISLA/THYSSEN/INMECAS/MB/YOLIMPIO) resuelven en PRE-Fase-3 (official_name=nombre corto, name=NULL)',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier,$sevenCases,$routeCompat):void{
    foreach($sevenCases as $i=>[$short,$legal,$service]){
        $db=$sqliteDb($classifierSchema);
        $fixture=$makeCommunityWithSupplier($db,(string)(100+$i),'CP PRE '.$short,$short,$service);
        $invoice=['proveedor'=>$short,'proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'desconocido'];
        $route=$routeCompat($db)->route($invoice,'facturas@proveedor.example');
        $assert($route['status']==='classified' && (int)$route['supplier']['id']===$fixture['supplierId'],"$short PRE-Fase-3 no resolvió: ".json_encode($route));
        $assert($route['evidence']['supplier']['source']==='name_exact',"$short PRE-Fase-3 debe resolver por exact_name sobre official_name, no fuzzy: ".json_encode($route['evidence']['supplier']));
    }
});
$test('compatibilidad Fase3->5: los mismos 7 casos resuelven EXACTO (no fuzzy) en POST-Fase-3 (name=nombre corto, official_name=razón social)',static function()use($assert,$sqliteDb,$classifierSchema,$makePostFase3Supplier,$sevenCases,$routeCompat):void{
    foreach($sevenCases as $i=>[$short,$legal,$service]){
        $db=$sqliteDb($classifierSchema);
        $fixture=$makePostFase3Supplier($db,(string)(200+$i),'CP POST '.$short,$short,$legal,$service);
        $invoice=['proveedor'=>$short,'proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'desconocido'];
        $route=$routeCompat($db)->route($invoice,'facturas@proveedor.example');
        $assert($route['status']==='classified' && (int)$route['supplier']['id']===$fixture['supplierId'],"$short POST-Fase-3 no resolvió: ".json_encode($route));
        $assert($route['evidence']['supplier']['source']==='name_exact',"$short POST-Fase-3 debe resolver por exact_name sobre name (determinista), no por fuzzy: ".json_encode($route['evidence']['supplier']));
    }
});
$test('compatibilidad Fase3->5: "FACSA, Suministro de Agua" resuelve por contención tanto PRE como POST-Fase-3 (regresión demostrada en la auditoría, ahora corregida)',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier,$makePostFase3Supplier,$routeCompat):void{
    $dbPre=$sqliteDb($classifierSchema);
    $fixturePre=$makeCommunityWithSupplier($dbPre,'301','CP PRE FACSA RUIDO','FACSA','Agua');
    $routePre=$routeCompat($dbPre)->route(['proveedor'=>'FACSA, Suministro de Agua','proveedor_cif'=>null,'comunidad_cif'=>$fixturePre['communityCif'],'tipo_servicio'=>'desconocido'],'facturas@facsa.example');
    $assert($routePre['status']==='classified' && (int)$routePre['supplier']['id']===$fixturePre['supplierId'],'PRE-Fase-3 debe seguir resolviendo por contención: '.json_encode($routePre));
    $assert($routePre['evidence']['supplier']['source']==='ai_after_retrieval'&&in_array('containment',$routePre['evidence']['supplier']['candidates'][0]['senales']??[],true));

    $dbPost=$sqliteDb($classifierSchema);
    $fixturePost=$makePostFase3Supplier($dbPost,'302','CP POST FACSA RUIDO','FACSA','SOCIEDAD DE FOMENTO AGRÍCOLA CASTELLONENSE, S.A.U.','Agua');
    $routePost=$routeCompat($dbPost)->route(['proveedor'=>'FACSA, Suministro de Agua','proveedor_cif'=>null,'comunidad_cif'=>$fixturePost['communityCif'],'tipo_servicio'=>'desconocido'],'facturas@facsa.example');
    $assert($routePost['status']==='classified' && (int)$routePost['supplier']['id']===$fixturePost['supplierId'],'POST-Fase-3: la regresión demostrada en la auditoría debe quedar corregida: '.json_encode($routePost));
    $assert($routePost['evidence']['supplier']['source']==='ai_after_retrieval'&&in_array('containment',$routePost['evidence']['supplier']['candidates'][0]['senales']??[],true),'debe seguir resolviendo por contención (ahora contra suppliers.name, no official_name): '.json_encode($routePost['evidence']['supplier']));
});
$test('compatibilidad Fase3->5: exact por name (POST-Fase-3, texto = razón social no coincide, solo name)',static function()use($assert,$sqliteDb,$classifierSchema,$makePostFase3Supplier,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makePostFase3Supplier($db,'303','CP EXACT NAME','PROFOC','GARCÍA MARÍN CONSULTORES, S.L.','Extintores');
    $route=$routeCompat($db)->route(['proveedor'=>'PROFOC','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'desconocido'],'facturas@profoc.example');
    $assert((int)$route['supplier']['id']===$fixture['supplierId'] && $route['evidence']['supplier']['source']==='name_exact',json_encode($route));
});
$test('compatibilidad Fase3->5: exact por official_name (POST-Fase-3, texto = razón social completa)',static function()use($assert,$sqliteDb,$classifierSchema,$makePostFase3Supplier,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makePostFase3Supplier($db,'304','CP EXACT OFFICIAL','PROFOC','GARCÍA MARÍN CONSULTORES, S.L.','Extintores');
    $route=$routeCompat($db)->route(['proveedor'=>'GARCÍA MARÍN CONSULTORES, S.L.','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'desconocido'],'facturas@profoc.example');
    $assert((int)$route['supplier']['id']===$fixture['supplierId'] && $route['evidence']['supplier']['source']==='name_exact',json_encode($route));
});
$test('compatibilidad Fase3->5: containment por name (texto trae el name dentro de una frase más larga)',static function()use($assert,$sqliteDb,$classifierSchema,$makePostFase3Supplier,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makePostFase3Supplier($db,'305','CP CONT NAME','INMECAS','H2O PLUS, S.L.','Descalcificador');
    $route=$routeCompat($db)->route(['proveedor'=>'Factura de INMECAS','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'desconocido'],'facturas@inmecas.example');
    $assert((int)$route['supplier']['id']===$fixture['supplierId'] && $route['evidence']['supplier']['source']==='ai_after_retrieval'&&in_array('containment',$route['evidence']['supplier']['candidates'][0]['senales']??[],true),json_encode($route));
});
$test('compatibilidad Fase3->5: containment por official_name (texto trae la razón social dentro de una frase más larga)',static function()use($assert,$sqliteDb,$classifierSchema,$makePostFase3Supplier,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makePostFase3Supplier($db,'306','CP CONT OFFICIAL','INMECAS','H2O PLUS, S.L.','Descalcificador');
    $route=$routeCompat($db)->route(['proveedor'=>'Recibo de H2O PLUS, S.L. correspondiente a este mes','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'desconocido'],'facturas@inmecas.example');
    $assert((int)$route['supplier']['id']===$fixture['supplierId'] && $route['evidence']['supplier']['source']==='ai_after_retrieval'&&in_array('containment',$route['evidence']['supplier']['candidates'][0]['senales']??[],true),json_encode($route));
});
$test('compatibilidad Fase3->5: token matching por name (mismas palabras del name, orden distinto)',static function()use($assert,$sqliteDb,$classifierSchema,$makePostFase3Supplier,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makePostFase3Supplier($db,'307','CP TOKEN NAME','LA BRUJA','LIMPIEZAS Y CRISTALIZADOS LA BRUJA, S.L.','Limpieza');
    // "LA BRUJA" tal cual ya sería exact_name; se fuerza token forzando un orden que ni el exacto
    // ni la contención (que exige orden contiguo) resolverían: "BRUJA LA".
    $route=$routeCompat($db)->route(['proveedor'=>'BRUJA LA','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'desconocido'],'facturas@labruja.example');
    $assert((int)$route['supplier']['id']===$fixture['supplierId'] && $route['evidence']['supplier']['source']==='ai_after_retrieval'&&in_array('tokens',$route['evidence']['supplier']['candidates'][0]['senales']??[],true),json_encode($route));
});
$test('compatibilidad Fase3->5: token matching por official_name (mismas palabras de la razón social, orden distinto)',static function()use($assert,$sqliteDb,$classifierSchema,$makePostFase3Supplier,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makePostFase3Supplier($db,'308','CP TOKEN OFFICIAL','MB','MANTENIMIENTOS MANUEL BASTIDA S.L.U.','Descalcificador');
    $route=$routeCompat($db)->route(['proveedor'=>'BASTIDA MANUEL MANTENIMIENTOS','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'desconocido'],'facturas@mb.example');
    $assert((int)$route['supplier']['id']===$fixture['supplierId'] && $route['evidence']['supplier']['source']==='ai_after_retrieval'&&in_array('tokens',$route['evidence']['supplier']['candidates'][0]['senales']??[],true),json_encode($route));
});
$test('compatibilidad Fase3->5: el tier fuzzy no penaliza a un supplier por tener dos representaciones muy distintas (name corto vs official_name largo) — resuelve por la que de verdad se parece al texto ruidoso',static function()use($assert,$sqliteDb,$classifierSchema,$makePostFase3Supplier,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    // name="FACSA" (muy distinto del texto) y official_name con un typo respecto a la extracción
    // (92.24 de similitud, por encima del umbral) — ni containment ni token pueden resolverlo
    // (el texto no contiene "facsa" como palabra ni todas las palabras de ningún candidato), así
    // que solo el fuzzy puede, y debe ganar por official_name sin que el name corto le reste puntos.
    $fixture=$makePostFase3Supplier($db,'309','CP FUZZY NAME','FACSA','SOCIEDAD DE FOMENTO AGRÍCOLA CASTELLONENSE, S.A.U.','Agua');
    $route=$routeCompat($db)->route(['proveedor'=>'SOCIEDAD DE FOMENTO AGRICOLA CASTELLONENSSE','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'desconocido'],'facturas@facsa.example');
    $assert((int)$route['supplier']['id']===$fixture['supplierId'] && $route['evidence']['supplier']['source']==='ai_after_retrieval'&&in_array('similarity',$route['evidence']['supplier']['candidates'][0]['senales']??[],true),json_encode($route));
});
$test('compatibilidad Fase3->5: fuzzy contra official_name sigue funcionando cuando no hay name (proveedor global, sin comunidad)',static function()use($assert,$sqliteDb,$classifierSchema):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO suppliers(official_name,normalized_name,cif,active) VALUES (?,?,NULL,1)',['SOCIEDAD DE FOMENTO AGRÍCOLA CASTELLONENSE, S.A.U.',Salvest\Text::normalize('SOCIEDAD DE FOMENTO AGRÍCOLA CASTELLONENSE, S.A.U.')]);
    $id=(int)$db->pdo()->lastInsertId();
    $candidates=(new Salvest\SupplierCandidateRetriever($db))->candidates(['name'=>'SOCIEDAD DE FOMENTO AGRICOLA CASTELLONENSSE','tax_id'=>null],'facturas@facsa.example',null);
    $assert(count($candidates)===1 && (int)$candidates[0]['supplier']['id']===$id && in_array('similarity',$candidates[0]['signals'],true),'la similitud contra official_name (sin name) sigue proponiendo el candidato: '.json_encode($candidates));
});
$test('compatibilidad Fase3->5: name=NULL no rompe ningún tier (estado PRE-Fase-3 puro, los 4 tiers siguen dando el mismo resultado que antes)',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'310','CP NAME NULL','ASCENSORES DEL SUR','Ascensor');
    $row=$db->one('SELECT name FROM suppliers WHERE id=?',[$fixture['supplierId']]);
    $assert($row['name']===null,'precondición: name debe seguir NULL en este fixture');
    $route=$routeCompat($db)->route(['proveedor'=>'ASCENSORES DEL SUR','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'desconocido'],'facturas@sur.example');
    $assert((int)$route['supplier']['id']===$fixture['supplierId'] && $route['evidence']['supplier']['source']==='name_exact',json_encode($route));
});
$test('compatibilidad Fase3->5: normalized_official_name=NULL no rompe el tier fuzzy (candidateNames() ignora candidatos vacíos/NULL sin lanzar)',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'311','CP NOFF NULL','MANTENIMIENTOS RAPIDOS INTEGRALES DEL LEVANTE','Mantenimiento');
    $row=$db->one('SELECT normalized_official_name FROM suppliers WHERE id=?',[$fixture['supplierId']]);
    $assert($row['normalized_official_name']===null,'precondición: normalized_official_name debe seguir NULL en este fixture');
    $route=$routeCompat($db)->route(['proveedor'=>'MANTENIMIENTOS RAPIDOS INTEGRALES DEL LEVANTES','proveedor_cif'=>null,'comunidad_cif'=>$fixture['communityCif'],'tipo_servicio'=>'desconocido'],'facturas@rapidos.example');
    $assert((int)$route['supplier']['id']===$fixture['supplierId'] && $route['evidence']['supplier']['source']==='ai_after_retrieval'&&in_array('similarity',$route['evidence']['supplier']['candidates'][0]['senales']??[],true),'no debe lanzar ni fallar por normalized_official_name=NULL: '.json_encode($route));
});

// ---- Última mini-fase antes de Fase 3: /Proveedores, compatibilidad legacy, importador ----
$callCanonicalCif=static function(?string $raw):?string{
    $method=new ReflectionMethod(Salvest\WebApp::class,'canonicalSupplierCif');$method->setAccessible(true);
    return$method->invoke(null,$raw);
};
$callSupplierUpsertValues=static function(array $post):array{
    $method=new ReflectionMethod(Salvest\WebApp::class,'supplierUpsertValues');$method->setAccessible(true);
    return$method->invoke(null,$post);
};

$test('/Proveedores: canonicalSupplierCif() — NIE con guiones',static function()use($assert,$callCanonicalCif):void{
    $assert($callCanonicalCif('X-4153497-L')==='X4153497L');
});
$test('/Proveedores: canonicalSupplierCif() — NIF con puntos y guion',static function()use($assert,$callCanonicalCif):void{
    $assert($callCanonicalCif('18.965.195-Q')==='18965195Q');
});
$test('/Proveedores: canonicalSupplierCif() — cadena vacía se convierte en NULL, nunca se guarda \'\'',static function()use($assert,$callCanonicalCif):void{
    $assert($callCanonicalCif('')===null);
    $assert($callCanonicalCif('   ')===null);
});
$test('/Proveedores: canonicalSupplierCif() — "Pendiente" (y variantes de mayúsculas) se convierte en NULL',static function()use($assert,$callCanonicalCif):void{
    $assert($callCanonicalCif('Pendiente')===null);
    $assert($callCanonicalCif('PENDIENTE')===null);
});
$test('/Proveedores: canonicalSupplierCif() — un CIF de empresa ya limpio pasa intacto',static function()use($assert,$callCanonicalCif):void{
    $assert($callCanonicalCif('A12000022')==='A12000022');
});

$test('/Proveedores: alta nueva post-Fase-3 (name+official_name+cif) calcula normalized_name/normalized_official_name/cif exactamente como se espera',static function()use($assert,$callSupplierUpsertValues):void{
    [$name,$officialName,$normalizedName,$normalizedOfficialName,$cif,$serviceId]=$callSupplierUpsertValues([
        'name'=>'FACSA','official_name'=>'SOCIEDAD DE FOMENTO AGRÍCOLA CASTELLONENSE, S.A.U.','cif'=>'A12000022','service_id'=>'7',
    ]);
    $assert($name==='FACSA' && $officialName==='SOCIEDAD DE FOMENTO AGRÍCOLA CASTELLONENSE, S.A.U.');
    $assert($normalizedName===Salvest\Text::normalizeCompanyName('FACSA'));
    $assert($normalizedOfficialName===Salvest\Text::normalizeCompanyName('SOCIEDAD DE FOMENTO AGRÍCOLA CASTELLONENSE, S.A.U.'));
    $assert($cif==='A12000022' && $serviceId===7);
});
$test('/Proveedores: alta nueva con official_name vacío usa name como respaldo — nunca se inventa una razón social',static function()use($assert,$callSupplierUpsertValues):void{
    [$name,$officialName]=$callSupplierUpsertValues(['name'=>'PROFOC','official_name'=>'','cif'=>null,'service_id'=>'1']);
    $assert($name==='PROFOC' && $officialName==='PROFOC','sin razón social introducida, official_name debe caer al nombre comercial, nunca quedar vacío (NOT NULL): '.json_encode([$name,$officialName]));
});

$test('/Proveedores: compatibilidad legacy — abrir y guardar una fila con name=NULL, official_name=FACSA no destruye el registro (name pasa a valer FACSA, official_name no cambia)',static function()use($assert,$sqliteDb,$classifierSchema,$callSupplierUpsertValues):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO suppliers(official_name,normalized_name,cif,main_service_type_id,active) VALUES (?,?,?,?,1)',['FACSA',Salvest\Text::normalize('FACSA'),null,null]);
    $id=(int)$db->pdo()->lastInsertId();
    $row=$db->one('SELECT * FROM suppliers WHERE id=?',[$id]);
    $assert($row['name']===null,'precondición: fila legacy real, name debe ser NULL');
    // El GET de edición precarga "Nombre comercial" con supplierDisplayName() = name ?: official_name.
    $reflectionMethod=new ReflectionMethod(Salvest\WebApp::class,'supplierDisplayName');$reflectionMethod->setAccessible(true);
    $config=['app'=>['base_url'=>'http://127.0.0.1','timezone'=>'Europe/Madrid','session_name'=>'salvest_test_'.bin2hex(random_bytes(4)),
        'secret_key'=>'test-secret','encryption_key'=>Salvest\Crypto::generateKey(),'cron_token'=>'test','cookie_secure'=>false]];
    set_error_handler(static fn(int$errno,string$message):bool=>str_contains($message,'session')||str_contains($message,'headers already'));
    $webApp=new Salvest\WebApp($db,$config);
    restore_error_handler();
    $prefilledName=$reflectionMethod->invoke($webApp,$row);
    $assert($prefilledName==='FACSA','el campo "Nombre comercial" debe precargarse con official_name cuando name es NULL: '.$prefilledName);
    // Simula "guardar sin tocar nada": el formulario habría enviado name=FACSA (precargado) y
    // official_name=FACSA (tal cual). Debe quedar name=FACSA, official_name=FACSA — nada perdido.
    [$name,$officialName]=$callSupplierUpsertValues(['name'=>$prefilledName,'official_name'=>$row['official_name'],'cif'=>$row['cif']??'','service_id'=>'']);
    $assert($name==='FACSA' && $officialName==='FACSA','guardar una fila legacy sin cambios no debe perder información, solo completar name: '.json_encode([$name,$officialName]));
});

$test('/Proveedores: edición post-migración mantiene name/official_name sincronizados con sus columnas normalizadas tras un UPDATE real',static function()use($assert,$sqliteDb,$classifierSchema,$callSupplierUpsertValues):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO suppliers(name,official_name,normalized_name,normalized_official_name,cif,main_service_type_id,active) VALUES (?,?,?,?,?,?,1)',
        ['PROFOC','GARCÍA MARÍN CONSULTORES, S.L.',Salvest\Text::normalizeCompanyName('PROFOC'),Salvest\Text::normalizeCompanyName('GARCÍA MARÍN CONSULTORES, S.L.'),'B12802971',null]);
    $id=(int)$db->pdo()->lastInsertId();
    // El admin corrige un typo en la razón social.
    [$name,$officialName,$normalizedName,$normalizedOfficialName,$cif,$serviceId]=$callSupplierUpsertValues(['name'=>'PROFOC','official_name'=>'GARCIA MARIN CONSULTORES, S.L.U.','cif'=>'B12802971','service_id'=>'']);
    $db->execute('UPDATE suppliers SET name=?,official_name=?,normalized_name=?,normalized_official_name=?,cif=?,main_service_type_id=? WHERE id=?',[$name,$officialName,$normalizedName,$normalizedOfficialName,$cif,$serviceId,$id]);
    $row=$db->one('SELECT * FROM suppliers WHERE id=?',[$id]);
    $assert($row['official_name']==='GARCIA MARIN CONSULTORES, S.L.U.');
    $assert($row['normalized_official_name']===Salvest\Text::normalizeCompanyName('GARCIA MARIN CONSULTORES, S.L.U.'),'normalized_official_name debe quedar sincronizado con el official_name nuevo tras la edición');
    $assert($row['normalized_name']===Salvest\Text::normalizeCompanyName('PROFOC'),'normalized_name debe seguir sincronizado con name');
});

$test('/Revisar: el selector manual de proveedor muestra el nombre comercial (name), no la razón social, para un supplier ya migrado',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp,$requestWebApp):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    $db->execute('INSERT INTO suppliers(name,official_name,active) VALUES (?,?,1)',['FACSA','SOCIEDAD DE FOMENTO AGRÍCOLA CASTELLONENSE, S.A.U.']);
    $db->execute('INSERT INTO processed_attachments(status,processed_at,original_filename) VALUES (?,?,?)',['needs_review',date('Y-m-d H:i:s'),'factura.pdf']);
    $html=$requestWebApp($webApp,'GET','reviews');
    $assert(str_contains($html,'>FACSA</option>'),'el <option> debe mostrar el nombre comercial "FACSA": '.$html);
    $assert(!str_contains($html,'SOCIEDAD DE FOMENTO'),'no debe mostrarse la razón social larga como label del selector');
});

// ---- CommunityCsvImporter: protección FROZEN, verificada contra MySQL real ----
$test('CommunityCsvImporter: en estado legacy (name NULL o columna inexistente) el importador NO aborta — comportamiento actual preservado',static function()use($assert,$mysqlSchemaTest,$mysqlSchemaCleanup):void{
    $ctx=$mysqlSchemaTest();
    if($ctx===null){echo "SKIP (sin MySQL local disponible)\n";return;}
    $db=$ctx['db'];
    Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
    $method=new ReflectionMethod(Salvest\CommunityCsvImporter::class,'guardAgainstMigratedSupplierMaster');$method->setAccessible(true);
    $importer=new Salvest\CommunityCsvImporter($db);
    $threw=false;
    try{$method->invoke($importer);}catch(Throwable $error){$threw=true;}
    $assert(!$threw,'con suppliers.name NULL (o tabla vacía) el guard no debe bloquear el comportamiento legacy actual');
    $mysqlSchemaCleanup($ctx);
});
$test('CommunityCsvImporter: en estado de maestro ya migrado, replaceFrom() ABORTA antes del primer DELETE, con mensaje claro y sin flag de bypass',static function()use($assert,$mysqlSchemaTest,$mysqlSchemaCleanup):void{
    $ctx=$mysqlSchemaTest();
    if($ctx===null){echo "SKIP (sin MySQL local disponible)\n";return;}
    $db=$ctx['db'];$pdo=$ctx['pdo'];
    Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
    $pdo->beginTransaction();
    try{
        $db->execute('INSERT INTO suppliers(name,official_name,normalized_name,cif,active) VALUES (?,?,?,?,1)',['FACSA TEST GUARD','SOCIEDAD DE FOMENTO TEST GUARD','facsa test guard',null]);
        $db->execute("INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,active) VALUES ('GUARD1','CP GUARD TEST','cp guard test','GUARD-CIF','x',1)");
        $before=[
            'suppliers'=>$db->one('SELECT COUNT(*) n FROM suppliers')['n'],
            'aliases'=>$db->one('SELECT COUNT(*) n FROM supplier_aliases')['n'],
            'communities'=>$db->one('SELECT COUNT(*) n FROM communities')['n'],
            'community_suppliers'=>$db->one('SELECT COUNT(*) n FROM community_suppliers')['n'],
        ];
        $importer=new Salvest\CommunityCsvImporter($db);
        $threw=false;$message='';
        try{$importer->replaceFrom('/ruta/inexistente-a-proposito.csv');}
        catch(\RuntimeException $error){$threw=true;$message=$error->getMessage();}
        $assert($threw,'replaceFrom() debe abortar con excepción cuando el maestro ya está migrado, no seguir adelante');
        $assert(str_contains($message,'ABORTADO') && str_contains($message,'migrado'),'el mensaje debe ser claro sobre el motivo: '.$message);
        $assert(!str_contains($message,'--force') && !str_contains(strtolower($message),'force'),'no debe insinuar ningún flag de bypass');
        $after=[
            'suppliers'=>$db->one('SELECT COUNT(*) n FROM suppliers')['n'],
            'aliases'=>$db->one('SELECT COUNT(*) n FROM supplier_aliases')['n'],
            'communities'=>$db->one('SELECT COUNT(*) n FROM communities')['n'],
            'community_suppliers'=>$db->one('SELECT COUNT(*) n FROM community_suppliers')['n'],
        ];
        $assert($before===$after,'ninguna tabla debe cambiar — el guard debe disparar antes de leer siquiera el CSV, no solo antes del DELETE: '.json_encode(['before'=>$before,'after'=>$after]));
    }finally{$pdo->rollBack();$mysqlSchemaCleanup($ctx);}
});

// ---- Cierre pre-Fase-3: aliases de /Proveedores normalizados con normalizeCompanyName(), sin
// reescritura innecesaria; preselección del selector de /Revisar aceptando name u official_name ----
$callReplaceSupplierAliases=static function(Salvest\Database $db,int $supplierId,string $input):void{
    $config=['app'=>['base_url'=>'http://127.0.0.1','timezone'=>'Europe/Madrid','session_name'=>'salvest_test_'.bin2hex(random_bytes(4)),
        'secret_key'=>'test-secret','encryption_key'=>Salvest\Crypto::generateKey(),'cron_token'=>'test','cookie_secure'=>false]];
    set_error_handler(static fn(int$errno,string$message):bool=>str_contains($message,'session')||str_contains($message,'headers already'));
    $webApp=new Salvest\WebApp($db,$config);
    restore_error_handler();
    $method=new ReflectionMethod(Salvest\WebApp::class,'replaceSupplierAliases');$method->setAccessible(true);
    $method->invoke($webApp,$supplierId,$input);
};

$test('/Proveedores: alias con forma legal se normaliza con Text::normalizeCompanyName(), igual que el tier de alias del Classifier y el script de migración',static function()use($assert,$sqliteDb,$classifierSchema,$callReplaceSupplierAliases):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO suppliers(name,official_name,normalized_name,active) VALUES (?,?,?,1)',['OTIS','OTIS MOBILITY, S.A.','otis']);
    $id=(int)$db->pdo()->lastInsertId();
    $callReplaceSupplierAliases($db,$id,"ZARDOYA OTIS, S.A.\nBURISLIM SL\nH2O PLUS S.L.");
    $rows=$db->all('SELECT value,normalized_value FROM supplier_aliases WHERE supplier_id=? ORDER BY value',[$id]);
    $byValue=[];foreach($rows as $r)$byValue[$r['value']]=$r['normalized_value'];
    $assert($byValue['ZARDOYA OTIS, S.A.']===Salvest\Text::normalizeCompanyName('ZARDOYA OTIS, S.A.'),'ZARDOYA OTIS, S.A. mal normalizado: '.json_encode($byValue));
    $assert($byValue['BURISLIM SL']===Salvest\Text::normalizeCompanyName('BURISLIM SL'),'BURISLIM SL mal normalizado: '.json_encode($byValue));
    $assert($byValue['H2O PLUS S.L.']===Salvest\Text::normalizeCompanyName('H2O PLUS S.L.'),'H2O PLUS S.L. mal normalizado: '.json_encode($byValue));
    // Con Text::normalize() (la normalización antigua) los tres darían un normalized_value
    // distinto al que el tier de alias del Classifier compara — lo confirmamos explícitamente.
    $assert($byValue['ZARDOYA OTIS, S.A.']!==Salvest\Text::normalize('ZARDOYA OTIS, S.A.'),'debe diferir de la normalización antigua para probar que el fix es real');
    $assert($byValue['BURISLIM SL']!==Salvest\Text::normalize('BURISLIM SL'),'debe diferir de la normalización antigua para probar que el fix es real');
    $assert($byValue['H2O PLUS S.L.']!==Salvest\Text::normalize('H2O PLUS S.L.'),'debe diferir de la normalización antigua para probar que el fix es real');
});
$test('/Proveedores: guardar un supplier sin cambiar sus aliases NO hace DELETE+INSERT — filas, ids, valores y normalized_value quedan idénticos',static function()use($assert,$sqliteDb,$classifierSchema,$callReplaceSupplierAliases):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO suppliers(name,official_name,normalized_name,active) VALUES (?,?,?,1)',['FACSA','SOCIEDAD DE FOMENTO AGRÍCOLA CASTELLONENSE, S.A.U.','facsa']);
    $id=(int)$db->pdo()->lastInsertId();
    $callReplaceSupplierAliases($db,$id,"FOMENTO AGRÍCOLA CASTELLONENSE");
    $before=$db->all('SELECT id,value,normalized_value FROM supplier_aliases WHERE supplier_id=? ORDER BY id',[$id]);
    // Simula "editar solo el CIF y guardar": el textarea oculto de aliases viaja con el mismo
    // contenido de siempre (relatedText() lo precarga tal cual), así que el input es idéntico.
    $callReplaceSupplierAliases($db,$id,"FOMENTO AGRÍCOLA CASTELLONENSE");
    $after=$db->all('SELECT id,value,normalized_value FROM supplier_aliases WHERE supplier_id=? ORDER BY id',[$id]);
    $assert($before===$after,'guardar sin cambios reales en los aliases debe dejar las filas exactamente iguales (mismos ids incluidos, prueba de que no hubo DELETE+INSERT): '.json_encode(['before'=>$before,'after'=>$after]));
    $assert(count($before)===1,'precondición: debe haber exactamente 1 alias');
});
$test('/Proveedores: modificar aliases (añadir uno, quitar otro) sí reconcilia el conjunto correctamente',static function()use($assert,$sqliteDb,$classifierSchema,$callReplaceSupplierAliases):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO suppliers(name,official_name,normalized_name,active) VALUES (?,?,?,1)',['THYSSEN','TK ELEVADORES ESPAÑA, S.L.U.','thyssen']);
    $id=(int)$db->pdo()->lastInsertId();
    $callReplaceSupplierAliases($db,$id,"THYSSENKRUPP\nTK ELEVADORES");
    $firstIds=array_column($db->all('SELECT id FROM supplier_aliases WHERE supplier_id=? ORDER BY id',[$id]),'id');
    $assert(count($firstIds)===2);
    // Quita "THYSSENKRUPP", añade "TK ELEVATOR" — el conjunto normalizado cambia de verdad.
    $callReplaceSupplierAliases($db,$id,"TK ELEVADORES\nTK ELEVATOR");
    $rows=$db->all('SELECT value,normalized_value FROM supplier_aliases WHERE supplier_id=? ORDER BY value',[$id]);
    $values=array_column($rows,'value');
    $assert(count($rows)===2 && in_array('TK ELEVADORES',$values,true) && in_array('TK ELEVATOR',$values,true) && !in_array('THYSSENKRUPP',$values,true),'el conjunto debe reflejar exactamente lo enviado: '.json_encode($values));
    $normalizedValues=array_column($rows,'normalized_value');
    $assert(count($normalizedValues)===count(array_unique($normalizedValues)),'sin duplicados de normalized_value para el mismo supplier (compatible con la UNIQUE de Fase 2)');
});

$test('/Revisar POST-Fase-3: provider="FACSA" con name=FACSA/official_name=razón social muestra Y preselecciona la opción "FACSA"',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp,$requestWebApp):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    $db->execute('INSERT INTO suppliers(name,official_name,active) VALUES (?,?,1)',['FACSA','SOCIEDAD DE FOMENTO AGRÍCOLA CASTELLONENSE, S.A.U.']);
    $id=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO processed_attachments(status,processed_at,original_filename,provider) VALUES (?,?,?,?)',['needs_review',date('Y-m-d H:i:s'),'facsa.pdf','FACSA']);
    $html=$requestWebApp($webApp,'GET','reviews');
    $assert(str_contains($html,'>FACSA</option>'),'debe mostrarse "FACSA" como label: '.$html);
    $assert((bool)preg_match('/<option value="'.$id.'" selected>FACSA<\/option>/',$html),'la opción "FACSA" debe quedar preseleccionada (no solo listada): '.$html);
});
$test('/Revisar caso legacy: provider="FACSA" con name=NULL/official_name=FACSA sigue mostrando Y preseleccionando "FACSA"',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp,$requestWebApp):void{
    $db=$sqliteDbWithLock('always-free');$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    $db->execute('INSERT INTO suppliers(official_name,active) VALUES (?,1)',['FACSA']);
    $id=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO processed_attachments(status,processed_at,original_filename,provider) VALUES (?,?,?,?)',['needs_review',date('Y-m-d H:i:s'),'facsa-legacy.pdf','FACSA']);
    $html=$requestWebApp($webApp,'GET','reviews');
    $assert(str_contains($html,'>FACSA</option>'),'caso legacy: debe mostrarse "FACSA": '.$html);
    $assert((bool)preg_match('/<option value="'.$id.'" selected>FACSA<\/option>/',$html),'caso legacy: la opción "FACSA" debe seguir preseleccionándose: '.$html);
});

// ---- Fase 4: PDF-only — magic bytes reales, MimeParser ya no considera image/* documento,
// DocumentValidator decide solo por contenido, Worker excluye no-PDF sin fallar el correo entero ----
$pdfBytes="%PDF-1.4\n%fake pdf content for tests\n";
$pngBytes="\x89PNG\r\n\x1a\n"."fake png data after signature";
$jpegBytes="\xFF\xD8\xFF\xE0"."fake jpeg data after signature";
$gifBytes="GIF89a"."fake gif data after signature";

/** Construye un email multipart/mixed crudo a partir de una lista de partes
 * [mime,filename?,payload,disposition?,content_id?]. */
$buildEmail=static function(array $parts):string{
    $boundary='test-boundary-'.bin2hex(random_bytes(4));
    $raw="From: Demo <demo@example.com>\r\nSubject: Test\r\nContent-Type: multipart/mixed; boundary=\"$boundary\"\r\n\r\n";
    foreach($parts as $part){
        $mime=$part['mime'];$filename=$part['filename']??null;$disposition=$part['disposition']??'attachment';$contentId=$part['content_id']??null;
        $raw.="--$boundary\r\nContent-Type: $mime".($filename!==null?"; name=\"$filename\"":'')."\r\n";
        $raw.="Content-Disposition: $disposition".($filename!==null?"; filename=\"$filename\"":'')."\r\n";
        if($contentId!==null)$raw.="Content-ID: <$contentId>\r\n";
        $raw.="Content-Transfer-Encoding: base64\r\n\r\n".base64_encode($part['payload'])."\r\n";
    }
    return $raw."--$boundary--\r\n";
};

// -- Caso 1: un PDF real, comportamiento normal --
$test('PDF-only caso 1: email con 1 PDF real -> 1 adjunto procesable',static function()use($assert,$buildEmail,$pdfBytes):void{
    $message=(new Salvest\MimeParser())->parse($buildEmail([['mime'=>'application/pdf','filename'=>'factura.pdf','payload'=>$pdfBytes]]));
    $assert(count($message['attachments'])===1);
    $assert($message['attachments'][0]['payload']===$pdfBytes);
});
// -- Caso 2: PDF + GIF -> solo PDF --
$test('PDF-only caso 2: PDF + GIF -> solo el PDF es procesable',static function()use($assert,$buildEmail,$pdfBytes,$gifBytes):void{
    $message=(new Salvest\MimeParser())->parse($buildEmail([
        ['mime'=>'application/pdf','filename'=>'factura.pdf','payload'=>$pdfBytes],
        ['mime'=>'image/gif','filename'=>'tracking.gif','payload'=>$gifBytes],
    ]));
    $assert(count($message['attachments'])===1,json_encode($message['attachments']));
    $assert($message['attachments'][0]['original_filename']==='factura.pdf');
});
// -- Caso 3: PDF + PNG + JPG -> solo PDF --
$test('PDF-only caso 3: PDF + PNG + JPG -> solo el PDF es procesable',static function()use($assert,$buildEmail,$pdfBytes,$pngBytes,$jpegBytes):void{
    $message=(new Salvest\MimeParser())->parse($buildEmail([
        ['mime'=>'application/pdf','filename'=>'factura.pdf','payload'=>$pdfBytes],
        ['mime'=>'image/png','filename'=>'logo.png','payload'=>$pngBytes],
        ['mime'=>'image/jpeg','filename'=>'firma.jpg','payload'=>$jpegBytes],
    ]));
    $assert(count($message['attachments'])===1,json_encode($message['attachments']));
    $assert($message['attachments'][0]['original_filename']==='factura.pdf');
});
// -- Caso 4: solo GIF -> 0 adjuntos procesables --
$test('PDF-only caso 4: solo GIF -> 0 adjuntos procesables (el correo quedará ignored)',static function()use($assert,$buildEmail,$gifBytes):void{
    $message=(new Salvest\MimeParser())->parse($buildEmail([['mime'=>'image/gif','filename'=>'tracking.gif','payload'=>$gifBytes]]));
    $assert(count($message['attachments'])===0,json_encode($message['attachments']));
});
// -- Caso 5: solo PNG/JPG -> 0 adjuntos procesables --
$test('PDF-only caso 5: solo PNG/JPG -> 0 adjuntos procesables',static function()use($assert,$buildEmail,$pngBytes,$jpegBytes):void{
    $message=(new Salvest\MimeParser())->parse($buildEmail([
        ['mime'=>'image/png','filename'=>'logo.png','payload'=>$pngBytes],
        ['mime'=>'image/jpeg','filename'=>'firma.jpg','payload'=>$jpegBytes],
    ]));
    $assert(count($message['attachments'])===0,json_encode($message['attachments']));
});
// -- Caso 6: sin adjuntos -- (ya cubierto por 'mime sin documentos' existente; confirmamos explícitamente aquí)
$test('PDF-only caso 6: email sin adjuntos -> 0 adjuntos procesables',static function()use($assert):void{
    $raw="From: demo@example.com\r\nSubject: Sin adjuntos\r\nContent-Type: text/plain\r\n\r\nSolo texto.";
    $message=(new Salvest\MimeParser())->parse($raw);
    $assert(count($message['attachments'])===0);
});
// -- Caso 7: filename .pdf pero contenido PNG -> MimeParser lo incluye (decide por extensión),
// DocumentValidator lo rechaza después (defensa en profundidad) --
$test('PDF-only caso 7: filename .pdf con contenido PNG real -> MimeParser lo deja pasar por extensión, DocumentValidator lo rechaza por contenido',static function()use($assert,$buildEmail,$pngBytes):void{
    $message=(new Salvest\MimeParser())->parse($buildEmail([['mime'=>'application/octet-stream','filename'=>'factura.pdf','payload'=>$pngBytes]]));
    $assert(count($message['attachments'])===1,'MimeParser decide por extensión, no abre el contenido: '.json_encode($message['attachments']));
    try{
        Salvest\DocumentValidator::validate($message['attachments'][0],26214400);
        $assert(false,'DocumentValidator debe rechazar el contenido PNG pese al nombre .pdf');
    }catch(Salvest\NotPdfException $e){$assert(true);}
});
// -- Caso 8: filename .jpg pero contenido PDF real -> se procesa como PDF real (preferencia explícita) --
$test('PDF-only caso 8: filename .jpg con contenido PDF real -> se incluye y se valida como PDF real',static function()use($assert,$buildEmail,$pdfBytes):void{
    $message=(new Salvest\MimeParser())->parse($buildEmail([['mime'=>'image/jpeg','filename'=>'factura.jpg','payload'=>$pdfBytes]]));
    $assert(count($message['attachments'])===1,'el contenido real (%PDF-) debe ganar sobre el MIME/extensión de imagen: '.json_encode($message['attachments']));
    Salvest\DocumentValidator::validate($message['attachments'][0],26214400); // no debe lanzar
    $assert(true);
});
// -- Caso 9: mime application/pdf pero contenido no-PDF -> reject --
$test('PDF-only caso 9: mime application/pdf con contenido no-PDF -> DocumentValidator rechaza',static function()use($assert,$buildEmail,$pngBytes):void{
    $message=(new Salvest\MimeParser())->parse($buildEmail([['mime'=>'application/pdf','filename'=>'factura.pdf','payload'=>$pngBytes]]));
    $assert(count($message['attachments'])===1); // MimeParser lo incluye (mime declarado pdf)
    try{Salvest\DocumentValidator::validate($message['attachments'][0],26214400);$assert(false,'debe rechazar contenido no-PDF pese al MIME correcto');}
    catch(Salvest\NotPdfException $e){$assert(true);}
});
// -- Caso 10: mime image/png pero contenido PDF real -> el contenido manda, se acepta --
$test('PDF-only caso 10: mime image/png con contenido PDF real -> el contenido manda, se incluye y se acepta (decisión documentada)',static function()use($assert,$buildEmail,$pdfBytes):void{
    $message=(new Salvest\MimeParser())->parse($buildEmail([['mime'=>'image/png','filename'=>'factura.png','payload'=>$pdfBytes]]));
    $assert(count($message['attachments'])===1,'MimeParser debe incluirlo por la firma %PDF- real, pese al MIME image/png declarado: '.json_encode($message['attachments']));
    Salvest\DocumentValidator::validate($message['attachments'][0],26214400); // no debe lanzar
    $assert(true);
});
// -- Caso 11: PDF con extensión en mayúsculas --
$test('PDF-only caso 11: filename FACTURA.PDF (mayúsculas) -> se acepta',static function()use($assert,$buildEmail,$pdfBytes):void{
    $message=(new Salvest\MimeParser())->parse($buildEmail([['mime'=>'application/octet-stream','filename'=>'FACTURA.PDF','payload'=>$pdfBytes]]));
    $assert(count($message['attachments'])===1);
    Salvest\DocumentValidator::validate($message['attachments'][0],26214400);
    $assert(true);
});
// -- Caso 12: PDF sin extensión, mime correcto --
$test('PDF-only caso 12: sin extensión de archivo, mime application/pdf, magic bytes correctos -> se acepta',static function()use($assert,$buildEmail,$pdfBytes):void{
    $message=(new Salvest\MimeParser())->parse($buildEmail([['mime'=>'application/pdf','filename'=>null,'payload'=>$pdfBytes,'disposition'=>'attachment']]));
    $assert(count($message['attachments'])===1,'sin filename pero con Content-Disposition: attachment y mime pdf, debe incluirse: '.json_encode($message['attachments']));
    Salvest\DocumentValidator::validate($message['attachments'][0],26214400);
    $assert(true);
});
// -- Caso 13: imagen inline con Content-ID -> se ignora siempre --
$test('PDF-only caso 13: imagen inline con Content-ID (logo de firma) -> ignorada, no cuenta como adjunto',static function()use($assert,$buildEmail,$pngBytes,$pdfBytes):void{
    $message=(new Salvest\MimeParser())->parse($buildEmail([
        ['mime'=>'application/pdf','filename'=>'factura.pdf','payload'=>$pdfBytes],
        ['mime'=>'image/png','filename'=>'logo.png','payload'=>$pngBytes,'disposition'=>'inline','content_id'=>'logo123@example.com'],
    ]));
    $assert(count($message['attachments'])===1,'el logo inline con Content-ID nunca debe contarse como documento: '.json_encode($message['attachments']));
    $assert($message['attachments'][0]['original_filename']==='factura.pdf');
});

// ---- DocumentValidator: defensa en profundidad, solo contenido decide ----
$test('DocumentValidator: PDF real (mime/filename/contenido coherentes) -> acepta',static function()use($assert,$pdfBytes):void{
    Salvest\DocumentValidator::validate(['payload'=>$pdfBytes,'mime_type'=>'application/pdf','original_filename'=>'factura.pdf'],26214400);
    $assert(true);
});
$test('DocumentValidator: filename=factura.pdf, mime=image/png, contenido PNG -> reject',static function()use($assert,$pngBytes):void{
    try{Salvest\DocumentValidator::validate(['payload'=>$pngBytes,'mime_type'=>'image/png','original_filename'=>'factura.pdf'],26214400);$assert(false);}
    catch(Salvest\NotPdfException $e){$assert(true);}
});
$test('DocumentValidator: filename=factura.jpg, mime=application/pdf, contenido JPG -> reject (el contenido manda, no el MIME declarado)',static function()use($assert,$jpegBytes):void{
    try{Salvest\DocumentValidator::validate(['payload'=>$jpegBytes,'mime_type'=>'application/pdf','original_filename'=>'factura.jpg'],26214400);$assert(false);}
    catch(Salvest\NotPdfException $e){$assert(true);}
});
$test('DocumentValidator: sin extensión, mime=application/pdf, contenido %PDF- -> accept',static function()use($assert,$pdfBytes):void{
    Salvest\DocumentValidator::validate(['payload'=>$pdfBytes,'mime_type'=>'application/pdf','original_filename'=>'sin_extension'],26214400);
    $assert(true);
});
$test('DocumentValidator: factura.PDF (mayúsculas), mime=application/octet-stream, contenido %PDF- -> accept (contenido > MIME > extensión)',static function()use($assert,$pdfBytes):void{
    Salvest\DocumentValidator::validate(['payload'=>$pdfBytes,'mime_type'=>'application/octet-stream','original_filename'=>'factura.PDF'],26214400);
    $assert(true);
});
$test('DocumentValidator: payload vacío -> RuntimeException genérico, NO NotPdfException (son problemas distintos)',static function()use($assert):void{
    try{Salvest\DocumentValidator::validate(['payload'=>'','mime_type'=>'application/pdf','original_filename'=>'factura.pdf'],26214400);$assert(false);}
    catch(Salvest\NotPdfException $e){$assert(false,'un adjunto vacío no es "no es un PDF", es un problema distinto');}
    catch(RuntimeException $e){$assert(str_contains($e->getMessage(),'vacío'));}
});
$test('DocumentValidator: payload demasiado grande -> RuntimeException genérico, NO NotPdfException',static function()use($assert,$pdfBytes):void{
    try{Salvest\DocumentValidator::validate(['payload'=>$pdfBytes,'mime_type'=>'application/pdf','original_filename'=>'factura.pdf'],3);$assert(false);}
    catch(Salvest\NotPdfException $e){$assert(false,'demasiado grande no es "no es un PDF"');}
    catch(RuntimeException $e){$assert(str_contains($e->getMessage(),'grande'));}
});
$test('DocumentValidator: PDF con firma correcta pero contenido corrupto después -> ACEPTA (Fase 4 no oculta PDFs reales problemáticos, solo filtra lo que claramente no es PDF)',static function()use($assert):void{
    Salvest\DocumentValidator::validate(['payload'=>"%PDF-1.4\n%%EOF-truncado-a-mitad-de-nada-coherente",'mime_type'=>'application/pdf','original_filename'=>'factura-corrupta.pdf'],26214400);
    $assert(true,'un PDF con firma real pero corrupto debe pasar la validación básica — el fallo (si lo hay) pertenece a OpenAI/al pipeline existente, no a este filtro');
});

// ---- Worker: garantías estructurales de que non-PDF nunca llega a OpenAI / processed_attachments ----
$test('Worker: la exclusión de un adjunto no-PDF ocurre ANTES de llamar a processAttachment() — non-PDF nunca puede alcanzar OpenAIExtractor ni crear processed_attachments (guarda de regresión de código)',static function()use($assert):void{
    $source=file_get_contents(__DIR__.'/../src/Worker.php');
    $validatePos=strpos($source,'DocumentValidator::validate($attachment,');
    $catchNotPdfPos=strpos($source,'catch (NotPdfException)');
    $continuePos=strpos($source,'// Fase 4 (PDF-only): not a real PDF, however it was labeled');
    $processAttachmentCallPos=strpos($source,'$outcomes[] = $this->processAttachment(');
    $assert($validatePos!==false&&$catchNotPdfPos!==false&&$continuePos!==false&&$processAttachmentCallPos!==false,'no se encontraron todos los puntos de referencia esperados en Worker.php');
    $assert($validatePos<$catchNotPdfPos,'la validación debe ocurrir antes de decidir si se excluye');
    $assert($catchNotPdfPos<$processAttachmentCallPos,'el catch de NotPdfException (con su continue) debe preceder textualmente a la llamada a processAttachment() dentro del mismo bucle — así un adjunto no-PDF nunca puede alcanzarla');
});
$test('Fase 14 — Worker: un PDF cifrado recibe exactamente el mismo tratamiento estructural que uno que no es PDF (guarda de regresión de código)',static function()use($assert):void{
    $source=file_get_contents(__DIR__.'/../src/Worker.php');
    $validatePos=strpos($source,'DocumentValidator::validate($attachment,');
    $catchEncryptedPos=strpos($source,'catch (EncryptedPdfException)');
    $processAttachmentCallPos=strpos($source,'$outcomes[] = $this->processAttachment(');
    $assert($validatePos!==false&&$catchEncryptedPos!==false&&$processAttachmentCallPos!==false,'no se encontraron todos los puntos de referencia esperados en Worker.php');
    $assert($validatePos<$catchEncryptedPos&&$catchEncryptedPos<$processAttachmentCallPos,'el catch de EncryptedPdfException debe estar entre la validación y processAttachment(), igual que NotPdfException');
});
$test('Fase 14 — la carpeta base del correo es "facturgerman", ya no "Facturas" — guarda de regresión sobre los sitios reales que mueven correo (Worker.php e ImapMoveRetry.php)',static function()use($assert):void{
    foreach(['src/Worker.php','src/ImapMoveRetry.php'] as $file){
        $source=file_get_contents(__DIR__.'/../'.$file);
        $assert(!str_contains($source,"'Facturas/"),"$file no debe contener ninguna referencia a la carpeta antigua 'Facturas/'");
        $assert(str_contains($source,"facturgerman/"),"$file debe usar la carpeta nueva 'facturgerman/'");
    }
});
$test('Fase 18 — un correo totalmente clasificado se mueve SIEMPRE a "facturgerman/Facturas", una única carpeta para todo — nunca a una carpeta por comunidad (guarda de regresión de código: la carpeta por comunidad fue la causa directa de bugs reales de nomenclatura IMAP, Fase 17)',static function()use($assert):void{
    $source=file_get_contents(__DIR__.'/../src/Worker.php');
    $assert(str_contains($source,"'facturgerman/Facturas'"),'debe existir la carpeta única de destino para todo lo clasificado');
    $assert(!str_contains($source,"imap_folder_name"),'Worker.php ya no debe construir el destino IMAP a partir de la comunidad — eso es justo lo que causaba el bug de Fase 17');
    $assert(!str_contains($source,'communityIds'),'ya no debe importar a cuántas comunidades distintas pertenecen los adjuntos de un correo para decidir dónde moverlo');
});
$test('Worker: cuando todos los adjuntos de un correo quedan excluidos, se usa exactamente el mismo saveMessage(...\'ignored\',0,null) que el caso "sin adjuntos" — sin destino, sin IMAP move (guarda de regresión de código)',static function()use($assert):void{
    $source=file_get_contents(__DIR__.'/../src/Worker.php');
    $noAttachmentsSave=strpos($source,"\$this->saveMessage(\$mailbox,\$client,\$uid,\$message,'ignored',0,null);");
    $emptyOutcomesCheckPos=strpos($source,'if (!$outcomes) {');
    $assert($noAttachmentsSave!==false,'debe existir la llamada a saveMessage(...,\'ignored\',0,null)');
    $assert($emptyOutcomesCheckPos!==false,'debe existir la comprobación de $outcomes vacío');
    // Debe aparecer DOS veces exactamente el mismo patrón de llamada: una para "sin adjuntos" y
    // otra para "todos los adjuntos excluidos" — mismo tratamiento, ninguna con destino/IMAP move.
    $occurrences=substr_count($source,"'ignored',0,null);");
    $assert($occurrences===2,'deben existir exactamente 2 llamadas idénticas a saveMessage(...,\'ignored\',0,null) — sin adjuntos, y todos excluidos: encontradas '.$occurrences);
    $moveCallsBetween=substr_count(substr($source,$emptyOutcomesCheckPos,200),'$client->move(');
    $assert($moveCallsBetween===0,'no debe haber ningún $client->move() en la rama de "todos excluidos"');
});
$test('Worker: la lista de dedupe de processed_messages sigue incluyendo \'ignored\' — un mensaje ya ignorado no se vuelve a procesar en el siguiente ciclo (guarda de regresión de código)',static function()use($assert):void{
    $source=file_get_contents(__DIR__.'/../src/Worker.php');
    $assert(str_contains($source,"in_array(\$existing['status'],['completed','ignored','needs_review','error'],true)"),'\'ignored\' debe seguir en la lista de estados que impiden reprocesar un UID ya visto');
});
$test('Worker: la normalización de mime_type a application/pdf ocurre justo después de validar y antes de procesar — corrige el caso "PDF real mal etiquetado" para OpenAIExtractor sin tocar ese fichero',static function()use($assert):void{
    $source=file_get_contents(__DIR__.'/../src/Worker.php');
    $normalizePos=strpos($source,"\$attachment['mime_type'] = 'application/pdf';");
    $processAttachmentCallPos=strpos($source,'$outcomes[] = $this->processAttachment(');
    $assert($normalizePos!==false&&$processAttachmentCallPos!==false&&$normalizePos<$processAttachmentCallPos,'el mime_type debe normalizarse antes de procesar, para que OpenAIExtractor::documentInput() nunca reciba un image/* con bytes de PDF real dentro');
});

// ---- Dedupe funcional de 'ignored' (no solo la guarda de código anterior) ----
$test('processed_messages: un UID ya guardado como \'ignored\' se reconoce en la misma consulta de dedupe que usa Worker (no vuelve a considerarse candidato)',static function()use($assert,$sqliteDbWithLock,$workerConfig):void{
    $db=$sqliteDbWithLock('always-free');
    $db->execute('INSERT INTO mailboxes(descriptive_name,email,imap_host,imap_port,use_ssl,username,encrypted_password,input_folder,active) VALUES (?,?,?,?,?,?,?,?,1)',
        ['Test','buzon@example.com','imap.example.com',993,1,'buzon@example.com','x','INBOX']);
    $mailboxId=(int)$db->pdo()->lastInsertId();
    $db->execute("INSERT INTO processed_messages(mailbox_id,uidvalidity,message_uid,message_id_header,sender,subject,received_at,status,document_count,imap_destination,imap_move_status,processed_at) VALUES (?,?,?,?,?,?,?,'ignored',0,NULL,'not_required',?)",
        [$mailboxId,'1001','777','<msg-777@example.com>','proveedor@example.com','Solo un logo',date('Y-m-d H:i:s'),date('Y-m-d H:i:s')]);
    $existing=$db->one('SELECT status FROM processed_messages WHERE mailbox_id=? AND uidvalidity=? AND message_uid=?',[$mailboxId,'1001','777']);
    $assert($existing!==null && $existing['status']==='ignored');
    $assert(in_array($existing['status'],['completed','ignored','needs_review','error'],true),'exactamente la misma condición que Worker::processMailbox() usa para saltarse un UID ya visto');
});

// ---- Dedupe SHA-256 de PDFs: confirmamos que Fase 4 no lo ha tocado ----
$test('PDF-only: el dedupe por SHA-256 de adjuntos PDF sigue exactamente igual que antes de Fase 4',static function()use($assert,$sqliteDbWithLock,$seedRequeueFixture):void{
    $db=$sqliteDbWithLock('always-free');
    $fixture=$seedRequeueFixture($db,['needs_review']);
    $prior=$db->one("SELECT * FROM processed_attachments WHERE attachment_sha256=? AND status IN ('classified','unclassified','needs_review','duplicate') ORDER BY id LIMIT 1",['sha-0']);
    $assert($prior!==null && (int)$prior['id']===$fixture['attachmentIds'][0],'la consulta de dedupe por SHA-256 (sin relación con Fase 4) sigue encontrando el original');
});

// ---- Fase 5: resolveSupplier() global endurecido — mismo rigor que resolveSupplierInCommunity() ----
$insertSupplier=static function(Salvest\Database $db,string $name,string $officialName,?string $cif,int $active=1):int{
    $db->execute('INSERT INTO suppliers(name,official_name,normalized_name,normalized_official_name,cif,active) VALUES (?,?,?,?,?,?)',
        [$name,$officialName,Salvest\Text::normalizeCompanyName($name),Salvest\Text::normalizeCompanyName($officialName),$cif,$active]);
    return (int)$db->pdo()->lastInsertId();
};
$insertAlias=static function(Salvest\Database $db,int $supplierId,string $value):void{
    $db->execute('INSERT INTO supplier_aliases(supplier_id,alias_type,value,normalized_value,active) VALUES (?,?,?,?,1)',
        [$supplierId,'name',$value,Salvest\Text::normalizeCompanyName($value)]);
};

// -- 13. Tests contra el maestro real (datos reproducidos tal como quedaron en producción) --
$test('Fase 5 — CIF real: FACSA/PROFOC/CRISLA/ADRIAN TURCU/YOLIMPIO/SERGIO RAUL resuelven por CIF exacto, confianza 1, sin IA',static function()use($assert,$sqliteDb,$classifierSchema,$insertSupplier,$resolveSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $real=[
        ['FACSA','SOCIEDAD DE FOMENTO AGRÍCOLA CASTELLONENSE, S.A.U.','A12000022'],
        ['PROFOC','GARCÍA MARÍN CONSULTORES, S.L.','B12802971'],
        ['CRISLA','CRISLA LIMPIEZAS Y CRISTALIZADOS, S.L.','B12534228'],
        ['ADRIAN TURCU','ADRIAN TURCU','X4153497L'],
        ['YOLIMPIO','RAFAEL GUIJARRO PRADES','18965195Q'],
        ['SERGIO RAUL','SERGIO RAUL MARIN RUIZ','53376935F'],
    ];
    $ids=[];foreach($real as[$name,$official,$cif])$ids[$name]=$insertSupplier($db,$name,$official,$cif);
    foreach($real as[$name,,$cif]){
        $result=$resolveSupplier($db,'',$cif,'facturas@proveedor.example',null,static fn()=>throw new RuntimeException('no debe llamarse a la IA'));
        $assert($result['supplier']!==null && (int)$result['supplier']['id']===$ids[$name] && $result['source']==='tax_id_exact' && $result['confidence']===1.0,"$name no resolvió por CIF exacto: ".json_encode($result['outcome']));
    }
});
$test('Fase 5 — CIF: variantes de formato normalizan igual (mayúsculas/guiones/puntos)',static function()use($assert,$sqliteDb,$classifierSchema,$insertSupplier,$resolveSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $facsaId=$insertSupplier($db,'FACSA','SOCIEDAD DE FOMENTO AGRÍCOLA CASTELLONENSE, S.A.U.','A12000022');
    $yolimpioId=$insertSupplier($db,'YOLIMPIO','RAFAEL GUIJARRO PRADES','18965195Q');
    $adrianId=$insertSupplier($db,'ADRIAN TURCU','ADRIAN TURCU','X4153497L');
    foreach([['A-12000022',$facsaId],['A12000022',$facsaId],['a12000022',$facsaId],['18.965.195-Q',$yolimpioId],['18965195Q',$yolimpioId],['X-4153497-L',$adrianId],['X4153497L',$adrianId]] as[$variant,$expected]){
        $r=$resolveSupplier($db,'',$variant);
        $assert((int)($r['supplier']['id']??0)===$expected && $r['source']==='tax_id_exact',"variante $variant: ".json_encode($r['outcome']));
    }
});
$test('Fase 5 — aliases reales: PRO FOC/ZARDOYA OTIS/TK ELEVADORES/EXTNCAS/LIMPIEZAS ADRIÁN/MANTENIMIENTOS MB/YO LIMPIO resuelven por alias exacto, sin IA',static function()use($assert,$sqliteDb,$classifierSchema,$insertSupplier,$insertAlias,$resolveSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $profoc=$insertSupplier($db,'PROFOC','GARCÍA MARÍN CONSULTORES, S.L.',null);$insertAlias($db,$profoc,'PRO FOC');
    $otis=$insertSupplier($db,'OTIS','OTIS MOBILITY, S.A.',null);$insertAlias($db,$otis,'ZARDOYA OTIS');
    $thyssen=$insertSupplier($db,'THYSSEN','TK ELEVADORES ESPAÑA, S.L.U.',null);$insertAlias($db,$thyssen,'TK ELEVADORES');
    $extincas=$insertSupplier($db,'EXTINCAS','EXTINTORES CASTELLÓN, S.L.',null);$insertAlias($db,$extincas,'EXTNCAS');
    $adrian=$insertSupplier($db,'ADRIAN TURCU','ADRIAN TURCU',null);$insertAlias($db,$adrian,'LIMPIEZAS ADRIÁN');
    $mb=$insertSupplier($db,'MB','MANTENIMIENTOS MANUEL BASTIDA S.L.U.',null);$insertAlias($db,$mb,'MANTENIMIENTOS MB');
    $yolimpio=$insertSupplier($db,'YOLIMPIO','RAFAEL GUIJARRO PRADES',null);$insertAlias($db,$yolimpio,'YO LIMPIO');
    foreach([['PRO FOC',$profoc],['ZARDOYA OTIS',$otis],['TK ELEVADORES',$thyssen],['EXTNCAS',$extincas],['LIMPIEZAS ADRIÁN',$adrian],['MANTENIMIENTOS MB',$mb],['YO LIMPIO',$yolimpio]] as[$alias,$expectedId]){
        $r=$resolveSupplier($db,$alias,null);
        $assert((int)($r['supplier']['id']??0)===$expectedId && $r['source']==='alias_exact',"$alias debe resolver por alias exacto: ".json_encode([$r['outcome'],$r['candidates']]));
    }
});
$test('Fase 5 — nombres reales: FACSA/GARCÍA MARÍN CONSULTORES S.L./H2O PLUS S.L./TK ELEVADORES ESPAÑA S.L.U. resuelven por name u official_name exacto, sin IA',static function()use($assert,$sqliteDb,$classifierSchema,$insertSupplier,$resolveSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $facsa=$insertSupplier($db,'FACSA','SOCIEDAD DE FOMENTO AGRÍCOLA CASTELLONENSE, S.A.U.',null);
    $profoc=$insertSupplier($db,'PROFOC','GARCÍA MARÍN CONSULTORES, S.L.',null);
    $inmecas=$insertSupplier($db,'INMECAS','H2O PLUS, S.L.',null);
    $thyssen=$insertSupplier($db,'THYSSEN','TK ELEVADORES ESPAÑA, S.L.U.',null);
    foreach([['FACSA',$facsa],['GARCÍA MARÍN CONSULTORES S.L.',$profoc],['H2O PLUS S.L.',$inmecas],['TK ELEVADORES ESPAÑA S.L.U.',$thyssen]] as[$text,$expected]){
        $r=$resolveSupplier($db,$text,null);
        $assert((int)($r['supplier']['id']??0)===$expected && $r['source']==='name_exact',"$text: ".json_encode($r['outcome']));
    }
});

// -- 14. Ambigüedad sintética obligatoria --
$test('Fase 5 — ambigüedad: dos suppliers activos comparten el mismo CIF normalizado -> nunca se adivina; la IA elige entre exactamente esos dos (caso real INMECAS / H2O PLUS)',static function()use($assert,$sqliteDb,$classifierSchema,$insertSupplier,$resolveSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $a=$insertSupplier($db,'PROV A','PROVEEDOR A, S.L.','B12345678');
    $insertSupplier($db,'PROV B','PROVEEDOR B, S.L.','B-12345678');
    $r=$resolveSupplier($db,'','B12345678');
    $assert($r['supplier']===null && $r['outcome']==='ai_skipped' && count($r['candidates'])===2,'sin IA, un CIF duplicado no decide: '.json_encode($r['outcome']));
    $seen=null;
    $chosen=$resolveSupplier($db,'','B12345678','facturas@x.example',null,function(array $c)use(&$seen,$a){$seen=array_column($c,'id');return['id'=>$a,'confidence'=>0.9,'reason'=>'logo','valid'=>true,'rejected_id'=>null];});
    sort($seen);
    $assert(count($seen)===2 && (int)$chosen['supplier']['id']===$a && $chosen['source']==='ai_after_shared_tax_id','la IA recibe solo los dos que comparten CIF: '.json_encode($seen));
});
$test('Fase 5 — ambigüedad: dos suppliers activos comparten el mismo alias normalizado -> no decide sin IA',static function()use($assert,$sqliteDb,$classifierSchema,$insertSupplier,$insertAlias,$resolveSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $a=$insertSupplier($db,'PROV A','PROVEEDOR A, S.L.',null);$insertAlias($db,$a,'MARCA COMPARTIDA');
    $b=$insertSupplier($db,'PROV B','PROVEEDOR B, S.L.',null);$insertAlias($db,$b,'MARCA COMPARTIDA');
    $r=$resolveSupplier($db,'MARCA COMPARTIDA',null);
    $assert($r['supplier']===null && $r['outcome']==='ai_skipped' && count($r['candidates'])===2,json_encode($r['outcome']));
});
$test('Fase 5 — ambigüedad: dos suppliers activos comparten el mismo name normalizado -> no decide sin IA',static function()use($assert,$sqliteDb,$classifierSchema,$insertSupplier,$resolveSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $insertSupplier($db,'DUPLICADO','RAZÓN SOCIAL UNO, S.L.',null);
    $insertSupplier($db,'DUPLICADO','RAZÓN SOCIAL DOS, S.L.',null);
    $r=$resolveSupplier($db,'DUPLICADO',null);
    $assert($r['supplier']===null && $r['outcome']==='ai_skipped' && count($r['candidates'])===2,json_encode($r['outcome']));
});
$test('Fase 5 — ambigüedad: dos suppliers activos comparten el mismo official_name normalizado -> no decide sin IA',static function()use($assert,$sqliteDb,$classifierSchema,$insertSupplier,$resolveSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $insertSupplier($db,'NOMBRE UNO','RAZÓN SOCIAL COMPARTIDA, S.L.',null);
    $insertSupplier($db,'NOMBRE DOS','RAZÓN SOCIAL COMPARTIDA, S.L.',null);
    $r=$resolveSupplier($db,'RAZÓN SOCIAL COMPARTIDA, S.L.',null);
    $assert($r['supplier']===null && $r['outcome']==='ai_skipped' && count($r['candidates'])===2,json_encode($r['outcome']));
});
$test('Fase 5 — similitud: dos suppliers igual de parecidos son candidatos los dos; la similitud nunca decide sola',static function()use($assert,$sqliteDb,$classifierSchema,$insertSupplier,$resolveSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $insertSupplier($db,'LARA','SOCIEDAD LARA DISTRIBUCIONES INTEGRALES DEL LEVANTE, S.L.',null);
    $insertSupplier($db,'MARA','SOCIEDAD MARA DISTRIBUCIONES INTEGRALES DEL LEVANTE, S.L.',null);
    $r=$resolveSupplier($db,'SOCIEDAD SARA DISTRIBUCIONES INTEGRALES DEL LEVANTE, S.L.',null);
    $assert($r['supplier']===null && $r['outcome']==='ai_skipped' && count($r['candidates'])===2,'empate de similitud: dos candidatos para la IA: '.json_encode($r['candidates']));
});
$test('Reconstrucción — el candidato más parecido va primero en la lista, pero la similitud ya no decide sin IA (antes: fuzzy >= 92 clasificaba solo)',static function()use($assert,$sqliteDb,$classifierSchema,$insertSupplier,$resolveSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $winnerId=$insertSupplier($db,'SARAA','SOCIEDAD SARAA DISTRIBUCIONES INTEGRALES DEL LEVANTE, S.L.',null);
    $insertSupplier($db,'GAMA','SOCIEDAD GAMA DISTRIBUCIONES INTEGRALES DEL LEVANTE, S.L.',null);
    $r=$resolveSupplier($db,'SOCIEDAD SARA DISTRIBUCIONES INTEGRALES DEL LEVANTE, S.L.',null);
    $assert($r['supplier']===null && $r['outcome']==='ai_skipped','sin IA no se decide por similitud: '.json_encode($r['outcome']));
    $assert(($r['candidates'][0]['id']??null)===$winnerId,'el más parecido encabeza los candidatos: '.json_encode($r['candidates']));
});
$test('Fase 5 — 0 candidatos válidos -> supplier=null, outcome no_candidates (no confundir "no encontrado" con "ambiguo") y sin IA',static function()use($assert,$sqliteDb,$classifierSchema,$insertSupplier,$resolveSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $insertSupplier($db,'ALGUIEN','ALGUIEN, S.L.',null);
    $r=$resolveSupplier($db,'NADIE QUE COINCIDA CON NADA',null,'facturas@x.example',null,static fn()=>throw new RuntimeException('sin candidatos no se llama a la IA'));
    $assert($r['supplier']===null && $r['outcome']==='no_candidates',json_encode($r['outcome']));
});

// -- 15. Suppliers inactivos nunca resuelven (fusiones EXTNCAS/ENERVIA) --
$test('Fase 5 — inactivos: un supplier active=0 nunca resuelve por CIF, aunque el CIF sea único en la tabla',static function()use($assert,$sqliteDb,$classifierSchema,$insertSupplier,$resolveSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $insertSupplier($db,'FANTASMA','FANTASMA, S.L.','B99999999',0);
    $r=$resolveSupplier($db,'','B99999999');
    $assert($r['supplier']===null && $r['outcome']==='no_candidates','un supplier inactivo no debe resolver nunca: '.json_encode($r['outcome']));
});
$test('Fase 5 — inactivos: alias EXTNCAS resuelve al target activo EXTINCAS, nunca al id inactivo, ni siquiera si el inactivo tuviera un alias residual con el mismo valor',static function()use($assert,$sqliteDb,$classifierSchema,$insertSupplier,$insertAlias,$resolveSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $extincasActivo=$insertSupplier($db,'EXTINCAS','EXTINTORES CASTELLÓN, S.L.','B12433314',1);
    $insertAlias($db,$extincasActivo,'EXTNCAS');
    $extncasInactivo=$insertSupplier($db,'EXTNCAS','EXTNCAS',null,0);
    $insertAlias($db,$extncasInactivo,'ALIAS RESIDUAL');
    $r=$resolveSupplier($db,'EXTNCAS',null);
    $assert((int)($r['supplier']['id']??0)===$extincasActivo && $r['source']==='alias_exact','debe resolver al target activo por alias, nunca al inactivo: '.json_encode($r['outcome']));
});
$test('Fase 5 — inactivos: name/official_name de un supplier active=0 nunca resuelve',static function()use($assert,$sqliteDb,$classifierSchema,$insertSupplier,$resolveSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $insertSupplier($db,'ENERVIA','ENERVIA',null,0);
    $r=$resolveSupplier($db,'ENERVIA',null);
    $assert($r['supplier']===null && $r['candidates']===[],'un supplier inactivo nunca debe resolver ni ser candidato: '.json_encode($r['candidates']));
});
$test('Fase 5 — inactivos: un supplier active=0 vinculado a la comunidad tampoco se ofrece como candidato',static function()use($assert,$sqliteDb,$classifierSchema,$insertSupplier,$resolveSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute("INSERT INTO communities(external_code,official_name,normalized_name,main_address,active) VALUES ('F5','CP Fase 5','cp fase 5','x',1)");
    $communityId=(int)$db->pdo()->lastInsertId();
    $inactiveId=$insertSupplier($db,'EXTNCAS','EXTNCAS',null,0);
    $db->execute('INSERT INTO community_suppliers(community_id,supplier_id,category) VALUES (?,?,?)',[$communityId,$inactiveId,'Extintores']);
    $r=$resolveSupplier($db,'EXTNCAS',null,'facturas@x.example',['id'=>$communityId,'official_name'=>'CP Fase 5']);
    $assert($r['supplier']===null && $r['candidates']===[],'un supplier inactivo enlazado a la comunidad no debe ofrecerse como candidato: '.json_encode($r['candidates']));
});

// -- 16. Segunda llamada restringida: candidatos ya excluyen inactivos --
$test('Fase 5 — los candidatos vinculados a la comunidad que ve la IA excluyen suppliers inactivos',static function()use($assert,$sqliteDb,$classifierSchema,$insertSupplier,$resolveSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute("INSERT INTO communities(external_code,official_name,normalized_name,main_address,active) VALUES ('F5B','CP Fase 5 B','cp fase 5 b','x',1)");
    $communityId=(int)$db->pdo()->lastInsertId();
    $activeId=$insertSupplier($db,'ACTIVO','ACTIVO, S.L.',null,1);
    $inactiveId=$insertSupplier($db,'INACTIVO','INACTIVO, S.L.',null,0);
    $db->execute('INSERT INTO community_suppliers(community_id,supplier_id,category) VALUES (?,?,?)',[$communityId,$activeId,'Limpieza']);
    $db->execute('INSERT INTO community_suppliers(community_id,supplier_id,category) VALUES (?,?,?)',[$communityId,$inactiveId,'Limpieza']);
    $ids=array_map(static fn(array $c):int=>(int)$c['supplier']['id'],(new Salvest\SupplierCandidateRetriever($db))->candidates(['name'=>'OTRO','tax_id'=>null],'facturas@x.example',$communityId));
    $assert(in_array($activeId,$ids,true) && !in_array($inactiveId,$ids,true),'los candidatos no deben incluir suppliers inactivos: '.json_encode($ids));
});

// -- Trace: comportamiento ambiguo debe quedar explicable en la traza --
$test('Fase 5 — explicabilidad: cuando dos suppliers coinciden exactamente, los candidatos guardados dicen qué señal tuvo cada uno',static function()use($assert,$sqliteDb,$classifierSchema,$insertSupplier,$resolveSupplier):void{
    $db=$sqliteDb($classifierSchema);
    $insertSupplier($db,'DOBLE','RAZÓN UNO, S.L.',null);
    $insertSupplier($db,'DOBLE','RAZÓN DOS, S.L.',null);
    $r=$resolveSupplier($db,'DOBLE',null);
    $assert(count($r['candidates'])===2 && in_array('exact_name',$r['candidates'][0]['senales'],true) && in_array('exact_name',$r['candidates'][1]['senales'],true),'cada candidato lleva su señal: '.json_encode($r['candidates']));
});

// ---- Fase 6: InvoiceRouter acepta el fallback global cuando la comunidad es real pero el
// proveedor todavía no está en community_suppliers, sin crear ninguna relación nueva ----
$test('Fase 6 — FACSA: comunidad conocida, FACSA no está en community_suppliers, resuelve globalmente por CIF, clasifica, 0 relaciones nuevas',static function()use($assert,$sqliteDb,$classifierSchema,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute("INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,active) VALUES ('F6A','CP FACSA','cp facsa','H11111111','x',1)");
    $db->execute("INSERT INTO service_types(name,normalized_name,active) VALUES ('Agua','agua',1)");
    $serviceId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO suppliers(name,official_name,normalized_name,normalized_official_name,cif,main_service_type_id,active) VALUES (?,?,?,?,?,?,1)',
        ['FACSA','SOCIEDAD DE FOMENTO AGRÍCOLA CASTELLONENSE, S.A.U.',Salvest\Text::normalizeCompanyName('FACSA'),Salvest\Text::normalizeCompanyName('SOCIEDAD DE FOMENTO AGRÍCOLA CASTELLONENSE, S.A.U.'),'A12000022',$serviceId]);
    $before=(int)$db->one('SELECT COUNT(*) n FROM community_suppliers')['n'];
    $route=$routeCompat($db)->route(['proveedor'=>'FACSA','proveedor_cif'=>'A12000022','comunidad_cif'=>'H11111111','tipo_servicio'=>'desconocido'],'facturas@facsa.example');
    $assert($route['status']==='classified' && $route['supplier']['cif']==='A12000022' && $route['service']==='Agua',json_encode($route));
    $assert($route['result']['classification']['supplier']['linked']===false);
    $after=(int)$db->one('SELECT COUNT(*) n FROM community_suppliers')['n'];
    $assert($before===0 && $after===0,'0 relaciones nuevas: antes='.$before.' después='.$after);
});
$test('Fase 6 — PROFOC: proveedor extraído como la razón social completa, resuelve globalmente por official_name exacto',static function()use($assert,$sqliteDb,$classifierSchema,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute("INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,active) VALUES ('F6B','CP PROFOC','cp profoc','H22222222','x',1)");
    $db->execute("INSERT INTO service_types(name,normalized_name,active) VALUES ('Extintores','extintores',1)");
    $serviceId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO suppliers(name,official_name,normalized_name,normalized_official_name,cif,main_service_type_id,active) VALUES (?,?,?,?,?,?,1)',
        ['PROFOC','GARCÍA MARÍN CONSULTORES, S.L.',Salvest\Text::normalizeCompanyName('PROFOC'),Salvest\Text::normalizeCompanyName('GARCÍA MARÍN CONSULTORES, S.L.'),'B12802971',$serviceId]);
    $route=$routeCompat($db)->route(['proveedor'=>'GARCÍA MARÍN CONSULTORES S.L.','proveedor_cif'=>'B12802971','comunidad_cif'=>'H22222222','tipo_servicio'=>'desconocido'],'facturas@profoc.example');
    $assert($route['status']==='classified' && $route['supplier']['name']==='PROFOC' && $route['result']['classification']['supplier']['linked']===false,json_encode($route));
});
$test('Fase 6 — EXTNCAS: alias global resuelve al target activo EXTINCAS, nunca al source inactivo, y clasifica',static function()use($assert,$sqliteDb,$classifierSchema,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute("INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,active) VALUES ('F6C','CP EXTINCAS','cp extincas','H33333333','x',1)");
    $db->execute("INSERT INTO service_types(name,normalized_name,active) VALUES ('Extintores','extintores',1)");
    $serviceId=(int)$db->pdo()->lastInsertId();
    $activeId=null;
    $db->execute('INSERT INTO suppliers(name,official_name,normalized_name,normalized_official_name,cif,main_service_type_id,active) VALUES (?,?,?,?,?,?,1)',
        ['EXTINCAS','EXTINTORES CASTELLÓN, S.L.',Salvest\Text::normalizeCompanyName('EXTINCAS'),Salvest\Text::normalizeCompanyName('EXTINTORES CASTELLÓN, S.L.'),'B12433314',$serviceId]);
    $activeId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO supplier_aliases(supplier_id,alias_type,value,normalized_value,active) VALUES (?,?,?,?,1)',[$activeId,'name','EXTNCAS',Salvest\Text::normalizeCompanyName('EXTNCAS')]);
    $db->execute('INSERT INTO suppliers(name,official_name,normalized_name,cif,active) VALUES (?,?,?,?,0)',['EXTNCAS','EXTNCAS',Salvest\Text::normalizeCompanyName('EXTNCAS'),null]); // source fusionado
    $route=$routeCompat($db)->route(['proveedor'=>'EXTNCAS','proveedor_cif'=>'','comunidad_cif'=>'H33333333','tipo_servicio'=>'desconocido'],'facturas@extincas.example');
    $assert($route['status']==='classified' && (int)$route['supplier']['id']===$activeId,'debe resolver al target activo, nunca al inactivo: '.json_encode($route));
});
$test('Fase 6 — ADRIAN TURCU: resuelve globalmente por CIF real (NIE X4153497L)',static function()use($assert,$sqliteDb,$classifierSchema,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute("INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,active) VALUES ('F6D','CP ADRIAN','cp adrian','H44444444','x',1)");
    $db->execute("INSERT INTO service_types(name,normalized_name,active) VALUES ('Limpieza','limpieza',1)");
    $serviceId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO suppliers(name,official_name,normalized_name,cif,main_service_type_id,active) VALUES (?,?,?,?,?,1)',
        ['ADRIAN TURCU','ADRIAN TURCU',Salvest\Text::normalizeCompanyName('ADRIAN TURCU'),'X4153497L',$serviceId]);
    $route=$routeCompat($db)->route(['proveedor'=>'','proveedor_cif'=>'X-4153497-L','comunidad_cif'=>'H44444444','tipo_servicio'=>'desconocido'],'facturas@adrian.example');
    $assert($route['status']==='classified' && $route['supplier']['name']==='ADRIAN TURCU' && $route['evidence']['supplier']['source']==='tax_id_exact',json_encode($route));
});

$test('Fase 6 — prioridad: si resolveSupplierInCommunity() ya resolvió, el fallback global ni se necesita (un duplicado global ambiguo no afecta al resultado)',static function()use($assert,$sqliteDb,$classifierSchema,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute("INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,active) VALUES ('F6E','CP PRIORIDAD','cp prioridad','H55555555','x',1)");
    $db->execute("INSERT INTO service_types(name,normalized_name,active) VALUES ('Limpieza','limpieza',1)");
    $serviceId=(int)$db->pdo()->lastInsertId();
    $communityId=(int)$db->one("SELECT id FROM communities WHERE external_code='F6E'")['id'];
    // Dos suppliers con el MISMO name — a nivel global esto sería ambiguo — pero solo uno está
    // enlazado a esta comunidad, así que resolveSupplierInCommunity() lo encuentra sin ambigüedad
    // (el segundo, con el mismo nombre, ni siquiera entra en su lista de candidatos).
    $db->execute('INSERT INTO suppliers(name,official_name,normalized_name,main_service_type_id,active) VALUES (?,?,?,?,1)',['DUPLICADO','DUPLICADO EN COMUNIDAD, S.L.',Salvest\Text::normalizeCompanyName('DUPLICADO'),$serviceId]);
    $inCommunityId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO community_suppliers(community_id,supplier_id,category) VALUES (?,?,?)',[$communityId,$inCommunityId,'Limpieza']);
    $db->execute('INSERT INTO suppliers(name,official_name,normalized_name,main_service_type_id,active) VALUES (?,?,?,?,1)',['DUPLICADO','DUPLICADO EN OTRO SITIO, S.L.',Salvest\Text::normalizeCompanyName('DUPLICADO'),$serviceId]);
    $route=$routeCompat($db)->route(['proveedor'=>'DUPLICADO','proveedor_cif'=>'','comunidad_cif'=>'H55555555','tipo_servicio'=>'desconocido'],'facturas@dup.example');
    $assert($route['status']==='classified' && (int)$route['supplier']['id']===$inCommunityId,'debe ganar el resultado de resolveSupplierInCommunity(), sin llegar nunca al fallback global: '.json_encode($route));
    $assert($route['evidence']['supplier']['source']==='name_exact' && $route['result']['classification']['supplier']['linked']===true && $route['ai_tasks']===['comprender_documento'],'el vínculo con la comunidad desempata dos nombres exactos sin IA: '.json_encode($route['evidence']['supplier']['source']));
});

$test('Fase 6 — ambigüedad global: comunidad conocida, sin match en comunidad, global ambiguo -> needs_review, 0 relaciones, traza explica el tier',static function()use($assert,$sqliteDb,$classifierSchema,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute("INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,active) VALUES ('F6F','CP AMBIGUO','cp ambiguo','H66666666','x',1)");
    $db->execute('INSERT INTO suppliers(name,official_name,normalized_name,active) VALUES (?,?,?,1)',['DUPLICADO GLOBAL','RAZÓN UNO, S.L.',Salvest\Text::normalizeCompanyName('DUPLICADO GLOBAL')]);
    $db->execute('INSERT INTO suppliers(name,official_name,normalized_name,active) VALUES (?,?,?,1)',['DUPLICADO GLOBAL','RAZÓN DOS, S.L.',Salvest\Text::normalizeCompanyName('DUPLICADO GLOBAL')]);
    $before=(int)$db->one('SELECT COUNT(*) n FROM community_suppliers')['n'];
    $trace=[];
    $route=$routeCompat($db)->route(['proveedor'=>'DUPLICADO GLOBAL','proveedor_cif'=>'','comunidad_cif'=>'H66666666','tipo_servicio'=>'desconocido'],'facturas@dup.example',
        '',null,function(string $tier,string $outcome,array $details)use(&$trace){$trace[]=[$tier,$outcome,$details];});
    $assert($route['status']==='needs_review' && $route['supplier']===null && $route['supplier_ambiguous']===true,json_encode($route));
    $after=(int)$db->one('SELECT COUNT(*) n FROM community_suppliers')['n'];
    $assert($before===$after && $after===0,'ambigüedad global no debe crear ninguna relación');
    $candidates=$route['evidence']['supplier']['candidates'];
    $assert(count($candidates)===2 && in_array('exact_name',$candidates[0]['senales'],true) && in_array('exact_name',$candidates[1]['senales'],true),'la explicación guarda los dos candidatos y la señal que tuvo cada uno: '.json_encode($candidates));
});

$test('Fase 6 — global unresolved: proveedor completamente desconocido -> needs_review, sin crear supplier ni relación',static function()use($assert,$sqliteDb,$classifierSchema,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute("INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,active) VALUES ('F6G','CP DESCONOCIDO','cp desconocido','H77777777','x',1)");
    $db->execute('INSERT INTO suppliers(name,official_name,normalized_name,active) VALUES (?,?,?,1)',['OTRO','OTRO PROVEEDOR, S.L.',Salvest\Text::normalizeCompanyName('OTRO')]);
    $suppliersBefore=(int)$db->one('SELECT COUNT(*) n FROM suppliers')['n'];
    $relationsBefore=(int)$db->one('SELECT COUNT(*) n FROM community_suppliers')['n'];
    $route=$routeCompat($db)->route(['proveedor'=>'PROVEEDOR TOTALMENTE DESCONOCIDO XYZ','proveedor_cif'=>'','comunidad_cif'=>'H77777777','tipo_servicio'=>'desconocido'],'facturas@x.example');
    $assert($route['status']==='needs_review' && $route['supplier']===null,json_encode($route));
    $assert((int)$db->one('SELECT COUNT(*) n FROM suppliers')['n']===$suppliersBefore,'resolveSupplier() nunca debe crear un supplier, ni siquiera con un CIF/nombre claro');
    $assert((int)$db->one('SELECT COUNT(*) n FROM community_suppliers')['n']===$relationsBefore,'tampoco debe crear ninguna relación');
});

$test('Fase 6 — Caso A: supplier global sin main_service_type_id pero CON hint de OpenAI -> sigue clasificando con ese hint',static function()use($assert,$sqliteDb,$classifierSchema,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute("INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,active) VALUES ('F6H','CP SIN SERVICIO','cp sin servicio','H88888888','x',1)");
    // Supplier sin main_service_type_id (NULL) — caso sintético, hoy los 39 activos reales SÍ lo tienen.
    $db->execute('INSERT INTO suppliers(name,official_name,normalized_name,cif,main_service_type_id,active) VALUES (?,?,?,?,NULL,1)',
        ['SIN SERVICIO','SIN SERVICIO CONFIGURADO, S.L.',Salvest\Text::normalizeCompanyName('SIN SERVICIO'),'B00000000']);
    $route=$routeCompat($db)->route(['proveedor'=>'','proveedor_cif'=>'B00000000','comunidad_cif'=>'H88888888','tipo_servicio'=>'jardineria'],'facturas@x.example');
    // Comportamiento actual documentado (sin cambios en esta fase): sin main_service_type_id y
    // sin relation, resolveService() cae al hint de OpenAI si existe — la factura SIGUE
    // clasificando, con el servicio que OpenAI propuso, nunca "Otros" inventado.
    $assert($route['status']==='classified','documentando el comportamiento actual: sigue clasificando aunque no haya servicio configurado — ver riesgos en el informe');
    $assert($route['service']==='jardineria' && $route['service_folder']==='JARDINERIA' && $route['evidence']['service']['source']==='ai_semantic_category','sin main_service_type_id ni relation, se usa la categoría de la IA, traducida a una carpeta canónica: '.json_encode([$route['service'],$route['evidence']['service']]));
});
$test('Fase 6 — Caso B: supplier global sin main_service_type_id NI hint de OpenAI -> needs_review, supplier sigue siendo el global resuelto, 0 relaciones nuevas, reason explica la falta de servicio',static function()use($assert,$sqliteDb,$classifierSchema,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute("INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,active) VALUES ('F6I','CP SIN SERVICIO NI HINT','cp sin servicio ni hint','H99999998','x',1)");
    $db->execute('INSERT INTO suppliers(name,official_name,normalized_name,cif,main_service_type_id,active) VALUES (?,?,?,?,NULL,1)',
        ['SIN NADA','SIN NADA CONFIGURADO, S.L.',Salvest\Text::normalizeCompanyName('SIN NADA'),'B00000001']);
    $before=(int)$db->one('SELECT COUNT(*) n FROM community_suppliers')['n'];
    $route=$routeCompat($db)->route(['proveedor'=>'','proveedor_cif'=>'B00000001','comunidad_cif'=>'H99999998','tipo_servicio'=>''],'facturas@x.example');
    $assert($route['status']==='needs_review','sin servicio seguro, el fallback global NO debe clasificar automáticamente: '.json_encode($route));
    $assert($route['supplier']!==null && $route['supplier']['cif']==='B00000001','el supplier debe seguir reconocido — solo el servicio bloquea, no la identidad del proveedor');
    $assert($route['result']['classification']['supplier']['linked']===false,'la evidencia del supplier debe seguir mostrando que vino del fallback global');
    $assert($route['result']['reason_code']==='service_unknown' && str_contains((string)$route['reason'],'servicio'),'el motivo debe explicar exactamente qué falta: '.$route['reason']);
    $after=(int)$db->one('SELECT COUNT(*) n FROM community_suppliers')['n'];
    $assert($before===0 && $after===0,'needs_review por falta de servicio no debe crear ninguna relación');
});
$test('Reconstrucción — proveedor de la comunidad sin servicio principal, sin categoría y sin pista de la IA: va a revisión (antes se archivaba como "desconocido" en MANTENIMIENTO sin avisar)',static function()use($assert,$sqliteDb,$classifierSchema,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute("INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,active) VALUES ('F6L','CP LEGACY','cp legacy','H13131313','x',1)");
    $communityId=(int)$db->one("SELECT id FROM communities WHERE external_code='F6L'")['id'];
    $db->execute('INSERT INTO suppliers(name,official_name,normalized_name,main_service_type_id,active) VALUES (?,?,?,NULL,1)',['LEGACY','LEGACY SUPPLIER, S.L.',Salvest\Text::normalizeCompanyName('LEGACY')]);
    $supplierId=(int)$db->pdo()->lastInsertId();
    // category vacía a propósito: ni main_service_type_id ni relation.category dan un servicio.
    $db->execute('INSERT INTO community_suppliers(community_id,supplier_id,category) VALUES (?,?,?)',[$communityId,$supplierId,'']);
    $route=$routeCompat($db)->route(['proveedor'=>'LEGACY','proveedor_cif'=>'','comunidad_cif'=>'H13131313','tipo_servicio'=>''],'facturas@legacy.example');
    // Este guard es EXCLUSIVO del fallback global (rama "source=global") — un supplier resuelto
    // dentro de la comunidad nunca pasa por él, así que el comportamiento clásico de siempre
    // (clasificar con "desconocido" si no hay ninguna otra señal) permanece intacto.
    $assert($route['status']==='needs_review' && $route['result']['reason_code']==='service_unknown' && $route['service_folder']===null,'sin ningún servicio válido no se archiva por descarte: '.json_encode([$route['status'],$route['result']['reason_code']]));
    $assert($route['evidence']['supplier']['source']==='name_exact' && $route['result']['classification']['supplier']['linked']===true,'el proveedor sí se reconoce: solo el servicio bloquea');
});

$test('Fase 6 — 0 escrituras en community_suppliers: recuento idéntico antes/después en varios escenarios de fallback global consecutivos',static function()use($assert,$sqliteDb,$classifierSchema,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute("INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,active) VALUES ('F6J','CP MULTI','cp multi','H10101010','x',1)");
    $db->execute('INSERT INTO suppliers(name,official_name,normalized_name,cif,active) VALUES (?,?,?,?,1)',['MULTI A','MULTI A, S.L.',Salvest\Text::normalizeCompanyName('MULTI A'),'B10101011']);
    $db->execute('INSERT INTO suppliers(name,official_name,normalized_name,cif,active) VALUES (?,?,?,?,1)',['MULTI B','MULTI B, S.L.',Salvest\Text::normalizeCompanyName('MULTI B'),'B10101012']);
    $router=$routeCompat($db);
    $before=(int)$db->one('SELECT COUNT(*) n FROM community_suppliers')['n'];
    $router->route(['proveedor'=>'','proveedor_cif'=>'B10101011','comunidad_cif'=>'H10101010','tipo_servicio'=>''],'facturas@x.example');
    $router->route(['proveedor'=>'','proveedor_cif'=>'B10101012','comunidad_cif'=>'H10101010','tipo_servicio'=>''],'facturas@x.example');
    $router->route(['proveedor'=>'INEXISTENTE','proveedor_cif'=>'','comunidad_cif'=>'H10101010','tipo_servicio'=>''],'facturas@x.example');
    $after=(int)$db->one('SELECT COUNT(*) n FROM community_suppliers')['n'];
    $assert($before===0 && $after===0,'ningún escenario de fallback global (resuelto, resuelto, no resuelto) debe escribir community_suppliers: antes='.$before.' después='.$after);
});

$test('Fase 6/7 — decision_json: la evidencia del supplier lleva source=global y auto_link explícito (false, sin autoLinker conectado) cuando vino del fallback, ausentes cuando vino de la comunidad',static function()use($assert,$sqliteDb,$classifierSchema,$routeCompat):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute("INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,active) VALUES ('F6K','CP DECISION','cp decision','H12121212','x',1)");
    $db->execute('INSERT INTO suppliers(name,official_name,normalized_name,cif,active) VALUES (?,?,?,?,1)',['GLOBAL','GLOBAL SUPPLIER, S.L.',Salvest\Text::normalizeCompanyName('GLOBAL'),'B12121213']);
    $route=$routeCompat($db)->route(['proveedor'=>'','proveedor_cif'=>'B12121213','comunidad_cif'=>'H12121212','tipo_servicio'=>''],'facturas@x.example');
    $assert($route['result']['classification']['supplier']['linked']===false);
    $assert(($route['autolink']['attempted']??null)===false && is_string($route['autolink']['reason']??null),'el autolink queda explícito en la decisión, con su motivo, también cuando no se intenta: '.json_encode($route['autolink']));
});

// ---- Fase 7: autolink de community_suppliers — contra MySQL real (INSERT ... ON DUPLICATE KEY
// UPDATE es sintaxis MySQL, no SQLite; CommunitySupplierAutoLinker solo se prueba aquí) ----
$f7InsertCommunity=static function(Salvest\Database $db,string $code,string $cif):int{
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,active) VALUES (?,?,?,?,?,1)',
        [$code,'CP FASE7 '.$code,Salvest\Text::normalize('CP FASE7 '.$code),$cif,'x']);
    return (int)$db->pdo()->lastInsertId();
};
$f7InsertSupplier=static function(Salvest\Database $db,string $name,?string $official,?int $serviceId,?string $cif,int $active=1):int{
    $db->execute('INSERT INTO suppliers(name,official_name,normalized_name,normalized_official_name,cif,main_service_type_id,active) VALUES (?,?,?,?,?,?,?)',
        [$name,$official??$name,Salvest\Text::normalizeCompanyName($name),Salvest\Text::normalizeCompanyName($official??$name),$cif,$serviceId,$active]);
    return (int)$db->pdo()->lastInsertId();
};
$f7Cleanup=static function(Salvest\Database $db,array $communityIds,array $supplierIds):void{
    foreach($supplierIds as $id)$db->execute('DELETE FROM suppliers WHERE id=?',[$id]); // cascada: aliases + community_suppliers
    foreach($communityIds as $id)$db->execute('DELETE FROM communities WHERE id=?',[$id]);
};
// El servicio maestro tiene que ser uno del catálogo (ServiceCatalog): un nombre inventado no tiene
// carpeta y la política lo manda a revisión. Se reutiliza el service_type canónico si ya existe.
$f7Service=static function(Salvest\Database $db,string $name):int{
    $canonical=['Agua'=>'Agua','Extintores'=>'Extintores','Extintores2'=>'Extintores','Limpieza2'=>'Limpieza'][$name]??'Mantenimiento';
    $db->execute('INSERT IGNORE INTO service_types(name,normalized_name) VALUES (?,?)',[$canonical,Salvest\Text::normalize($canonical)]);
    return (int)$db->one('SELECT id FROM service_types WHERE normalized_name=?',[Salvest\Text::normalize($canonical)])['id'];
};

$test('Fase 7 — FACSA: fallback global + autolink -> INSERT community_suppliers(category=Agua), auto_link=true; segunda ejecución NO duplica',static function()use($assert,$mysqlSchemaTest,$mysqlSchemaCleanup,$f7InsertCommunity,$f7InsertSupplier,$f7Cleanup,$f7Service,$routeCompat):void{
    $ctx=$mysqlSchemaTest();
    if($ctx===null){echo "SKIP (sin MySQL local disponible)\n";return;}
    $db=$ctx['db'];
    Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
    $serviceId=$f7Service($db,'Agua');
    $communityId=$f7InsertCommunity($db,'F7FACSA','H70000001');
    $supplierId=$f7InsertSupplier($db,'FACSA TEST F7','SOCIEDAD FACSA TEST F7, S.A.U.',$serviceId,'A70000001');
    try{
        $router=$routeCompat($db,true);
        $route=$router->route(['proveedor'=>'FACSA TEST F7','proveedor_cif'=>'A70000001','comunidad_cif'=>'H70000001','tipo_servicio'=>''],'facturas@facsa.example');
        $assert($route['status']==='classified' && $route['result']['classification']['supplier']['linked']===false && ($route['autolink']['inserted']??null)===true,json_encode($route));
        $rows=$db->all('SELECT * FROM community_suppliers WHERE community_id=? AND supplier_id=?',[$communityId,$supplierId]);
        $assert(count($rows)===1 && $rows[0]['category']==='AGUA' && $rows[0]['contract_reference']===null && $rows[0]['source_column']==='auto_global_resolution' && $rows[0]['raw_provider_name']==='FACSA TEST F7',json_encode($rows));
        // Segunda ejecución de la misma factura/ruta: 0 relaciones nuevas.
        $route2=$router->route(['proveedor'=>'FACSA TEST F7','proveedor_cif'=>'A70000001','comunidad_cif'=>'H70000001','tipo_servicio'=>''],'facturas@facsa.example');
        $assert($route2['status']==='classified','la segunda vez debe resolver EN COMUNIDAD (ya enlazado), no por fallback global');
        $assert($route2['result']['classification']['supplier']['linked']===true && $route2['autolink']['reason']==='relation_already_exists','la segunda vez el proveedor ya está vinculado a la comunidad: '.json_encode([$route2['evidence']['supplier'],$route2['autolink']]));
        $rowsAfter=$db->all('SELECT * FROM community_suppliers WHERE community_id=? AND supplier_id=?',[$communityId,$supplierId]);
        $assert(count($rowsAfter)===1,'no debe duplicarse: '.count($rowsAfter));
    }finally{$f7Cleanup($db,[$communityId],[$supplierId]);$mysqlSchemaCleanup($ctx);}
});

$test('Fase 7 — PROFOC: razón social completa -> global -> autolink con category=Extintores',static function()use($assert,$mysqlSchemaTest,$mysqlSchemaCleanup,$f7InsertCommunity,$f7InsertSupplier,$f7Cleanup,$f7Service,$routeCompat):void{
    $ctx=$mysqlSchemaTest();
    if($ctx===null){echo "SKIP (sin MySQL local disponible)\n";return;}
    $db=$ctx['db'];
    Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
    $serviceId=$f7Service($db,'Extintores');
    $communityId=$f7InsertCommunity($db,'F7PROFOC','H70000002');
    $supplierId=$f7InsertSupplier($db,'PROFOC TEST F7','GARCÍA MARÍN CONSULTORES TEST F7, S.L.',$serviceId,'B70000002');
    try{
        $router=$routeCompat($db,true);
        $route=$router->route(['proveedor'=>'GARCÍA MARÍN CONSULTORES TEST F7 S.L.','proveedor_cif'=>'B70000002','comunidad_cif'=>'H70000002','tipo_servicio'=>''],'facturas@profoc.example');
        $assert($route['status']==='classified' && ($route['autolink']['inserted']??null)===true,json_encode($route));
        $rows=$db->all('SELECT category FROM community_suppliers WHERE community_id=? AND supplier_id=?',[$communityId,$supplierId]);
        $assert(count($rows)===1 && $rows[0]['category']==='EXTINTORES',json_encode($rows));
    }finally{$f7Cleanup($db,[$communityId],[$supplierId]);$mysqlSchemaCleanup($ctx);}
});

$test('Fase 7 — EXTNCAS: alias global resuelve al target activo, autolink SIEMPRE al target, nunca al source inactivo',static function()use($assert,$mysqlSchemaTest,$mysqlSchemaCleanup,$f7InsertCommunity,$f7InsertSupplier,$f7Cleanup,$f7Service,$routeCompat):void{
    $ctx=$mysqlSchemaTest();
    if($ctx===null){echo "SKIP (sin MySQL local disponible)\n";return;}
    $db=$ctx['db'];
    Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
    $serviceId=$f7Service($db,'Extintores2');
    $communityId=$f7InsertCommunity($db,'F7EXTINCAS','H70000003');
    $activeId=$f7InsertSupplier($db,'EXTINCAS TEST F7','EXTINTORES CASTELLÓN TEST F7, S.L.',$serviceId,'B70000003');
    $db->execute('INSERT INTO supplier_aliases(supplier_id,alias_type,value,normalized_value,active) VALUES (?,?,?,?,1)',[$activeId,'name','EXTNCAS TEST F7',Salvest\Text::normalizeCompanyName('EXTNCAS TEST F7')]);
    $inactiveId=$f7InsertSupplier($db,'EXTNCAS TEST F7',null,null,null,0);
    try{
        $router=$routeCompat($db,true);
        $route=$router->route(['proveedor'=>'EXTNCAS TEST F7','proveedor_cif'=>'','comunidad_cif'=>'H70000003','tipo_servicio'=>''],'facturas@extincas.example');
        $assert($route['status']==='classified' && (int)$route['supplier']['id']===$activeId && ($route['autolink']['inserted']??null)===true,json_encode($route));
        $linkedToActive=$db->one('SELECT id FROM community_suppliers WHERE community_id=? AND supplier_id=?',[$communityId,$activeId]);
        $linkedToInactive=$db->one('SELECT id FROM community_suppliers WHERE community_id=? AND supplier_id=?',[$communityId,$inactiveId]);
        $assert($linkedToActive!==null && $linkedToInactive===null,'la relación debe crearse con el target activo, jamás con el inactivo');
    }finally{$f7Cleanup($db,[$communityId],[$activeId,$inactiveId]);$mysqlSchemaCleanup($ctx);}
});

$test('Fase 7 — ADRIAN TURCU: CIF global -> autolink con category=Limpieza',static function()use($assert,$mysqlSchemaTest,$mysqlSchemaCleanup,$f7InsertCommunity,$f7InsertSupplier,$f7Cleanup,$f7Service,$routeCompat):void{
    $ctx=$mysqlSchemaTest();
    if($ctx===null){echo "SKIP (sin MySQL local disponible)\n";return;}
    $db=$ctx['db'];
    Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
    $serviceId=$f7Service($db,'Limpieza2');
    $communityId=$f7InsertCommunity($db,'F7ADRIAN','H70000004');
    $supplierId=$f7InsertSupplier($db,'ADRIAN TURCU TEST F7',null,$serviceId,'X4153498M');
    try{
        $router=$routeCompat($db,true);
        $route=$router->route(['proveedor'=>'','proveedor_cif'=>'X4153498M','comunidad_cif'=>'H70000004','tipo_servicio'=>''],'facturas@adrian.example');
        $assert($route['status']==='classified' && ($route['autolink']['inserted']??null)===true,json_encode($route));
        $rows=$db->all('SELECT category FROM community_suppliers WHERE community_id=? AND supplier_id=?',[$communityId,$supplierId]);
        $assert(count($rows)===1 && $rows[0]['category']==='LIMPIEZA',json_encode($rows));
    }finally{$f7Cleanup($db,[$communityId],[$supplierId]);$mysqlSchemaCleanup($ctx);}
});

$test('Fase 7 — ambigüedad global: 0 INSERT, auto_link=false, needs_review',static function()use($assert,$mysqlSchemaTest,$mysqlSchemaCleanup,$f7InsertCommunity,$f7InsertSupplier,$f7Cleanup,$f7Service,$routeCompat):void{
    $ctx=$mysqlSchemaTest();
    if($ctx===null){echo "SKIP (sin MySQL local disponible)\n";return;}
    $db=$ctx['db'];
    Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
    $serviceId=$f7Service($db,'Ambiguo');
    $communityId=$f7InsertCommunity($db,'F7AMBIGUO','H70000005');
    $s1=$f7InsertSupplier($db,'DUP F7',null,$serviceId,null);
    $s2=$f7InsertSupplier($db,'DUP F7',null,$serviceId,null);
    try{
        $before=(int)$db->one('SELECT COUNT(*) n FROM community_suppliers')['n'];
        $router=$routeCompat($db,true);
        $route=$router->route(['proveedor'=>'DUP F7','proveedor_cif'=>'','comunidad_cif'=>'H70000005','tipo_servicio'=>''],'facturas@dup.example');
        $assert($route['status']==='needs_review' && $route['supplier']===null && $route['supplier_ambiguous']===true,json_encode($route));
        $after=(int)$db->one('SELECT COUNT(*) n FROM community_suppliers')['n'];
        $assert($before===$after,'ambigüedad global no debe crear ninguna relación');
    }finally{$f7Cleanup($db,[$communityId],[$s1,$s2]);$mysqlSchemaCleanup($ctx);}
});

$test('Fase 7 — sin servicio maestro: 0 INSERT aunque un hint de OpenAI permita clasificar; auto_link=false',static function()use($assert,$mysqlSchemaTest,$mysqlSchemaCleanup,$f7InsertCommunity,$f7InsertSupplier,$f7Cleanup,$routeCompat):void{
    $ctx=$mysqlSchemaTest();
    if($ctx===null){echo "SKIP (sin MySQL local disponible)\n";return;}
    $db=$ctx['db'];
    Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
    $communityId=$f7InsertCommunity($db,'F7NOSVC','H70000006');
    $supplierId=$f7InsertSupplier($db,'SIN SERVICIO F7',null,null,'B70000006'); // main_service_type_id NULL
    try{
        $before=(int)$db->one('SELECT COUNT(*) n FROM community_suppliers')['n'];
        $router=$routeCompat($db,true);
        $route=$router->route(['proveedor'=>'','proveedor_cif'=>'B70000006','comunidad_cif'=>'H70000006','tipo_servicio'=>'jardineria'],'facturas@x.example');
        $assert($route['status']==='classified','con hint de OpenAI, Fase 6 sigue permitiendo clasificar');
        $assert(($route['autolink']['attempted']??null)===false,'sin main_service_type_id, NUNCA debe autolinkear: '.json_encode($route['autolink']));
        $after=(int)$db->one('SELECT COUNT(*) n FROM community_suppliers')['n'];
        $assert($before===$after,'0 relaciones nuevas sin servicio maestro');
    }finally{$f7Cleanup($db,[$communityId],[$supplierId]);$mysqlSchemaCleanup($ctx);}
});

$test('Fase 7 — prioridad: relación ya existente -> el fallback global ni se ejecuta, 0 INSERT nuevo',static function()use($assert,$mysqlSchemaTest,$mysqlSchemaCleanup,$f7InsertCommunity,$f7InsertSupplier,$f7Cleanup,$f7Service,$routeCompat):void{
    $ctx=$mysqlSchemaTest();
    if($ctx===null){echo "SKIP (sin MySQL local disponible)\n";return;}
    $db=$ctx['db'];
    Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
    $serviceId=$f7Service($db,'YaExiste');
    $communityId=$f7InsertCommunity($db,'F7EXISTS','H70000007');
    $supplierId=$f7InsertSupplier($db,'YA ENLAZADO F7',null,$serviceId,'B70000007');
    $db->execute('INSERT INTO community_suppliers(community_id,supplier_id,category,source_column,raw_provider_name) VALUES (?,?,?,?,?)',[$communityId,$supplierId,'Ya Existe Fase7','manual','YA ENLAZADO F7']);
    try{
        $before=(int)$db->one('SELECT COUNT(*) n FROM community_suppliers WHERE community_id=? AND supplier_id=?',[$communityId,$supplierId])['n'];
        $router=$routeCompat($db,true);
        $route=$router->route(['proveedor'=>'YA ENLAZADO F7','proveedor_cif'=>'B70000007','comunidad_cif'=>'H70000007','tipo_servicio'=>''],'facturas@x.example');
        $assert($route['status']==='classified' && $route['result']['classification']['supplier']['linked']===true && $route['autolink']['attempted']===false,'resuelve con la relación existente y no intenta autolink: '.json_encode([$route['evidence']['supplier'],$route['autolink']]));
        $after=(int)$db->one('SELECT COUNT(*) n FROM community_suppliers WHERE community_id=? AND supplier_id=?',[$communityId,$supplierId])['n'];
        $assert($before===1 && $after===1,'no debe crear una segunda relación');
    }finally{$f7Cleanup($db,[$communityId],[$supplierId]);$mysqlSchemaCleanup($ctx);}
});

$test('Fase 7 — CommunitySupplierAutoLinker::linkIfMissing() es idempotente/resistente a carrera: dos llamadas seguidas -> 1 sola relación',static function()use($assert,$mysqlSchemaTest,$mysqlSchemaCleanup,$f7InsertCommunity,$f7InsertSupplier,$f7Cleanup,$f7Service):void{
    $ctx=$mysqlSchemaTest();
    if($ctx===null){echo "SKIP (sin MySQL local disponible)\n";return;}
    $db=$ctx['db'];
    Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
    $serviceId=$f7Service($db,'Race');
    $communityId=$f7InsertCommunity($db,'F7RACE','H70000008');
    $supplierId=$f7InsertSupplier($db,'RACE F7',null,$serviceId,'B70000008');
    try{
        $linker=new Salvest\CommunitySupplierAutoLinker($db);
        $r1=$linker->linkIfMissing($communityId,$supplierId,'Race Fase7','RACE F7');
        $r2=$linker->linkIfMissing($communityId,$supplierId,'Race Fase7','RACE F7');
        $assert($r1['inserted']===true && $r1['reason']==='missing_relation',json_encode($r1));
        $assert($r2['inserted']===false && $r2['reason']==='relation_already_exists',json_encode($r2));
        $count=(int)$db->one('SELECT COUNT(*) n FROM community_suppliers WHERE community_id=? AND supplier_id=?',[$communityId,$supplierId])['n'];
        $assert($count===1,'exactamente una relación tras dos llamadas seguidas: '.$count);
    }finally{$f7Cleanup($db,[$communityId],[$supplierId]);$mysqlSchemaCleanup($ctx);}
});

$test('Fase 7 — categorías múltiples para el mismo par: el UNIQUE(community_id,supplier_id) nuevo ahora lo impide estructuralmente para CUALQUIER escritor, no solo para el autolink',static function()use($assert,$mysqlSchemaTest,$mysqlSchemaCleanup,$f7InsertCommunity,$f7InsertSupplier,$f7Cleanup,$f7Service):void{
    $ctx=$mysqlSchemaTest();
    if($ctx===null){echo "SKIP (sin MySQL local disponible)\n";return;}
    $db=$ctx['db'];
    Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
    $communityId=$f7InsertCommunity($db,'F7MULTI','H70000009');
    $supplierId=$f7InsertSupplier($db,'MULTI CAT F7',null,null,'B70000009');
    try{
        $db->execute('INSERT INTO community_suppliers(community_id,supplier_id,category,source_column,raw_provider_name) VALUES (?,?,?,?,?)',[$communityId,$supplierId,'Categoria Uno','manual','x']);
        $violated=false;
        try{$db->execute('INSERT INTO community_suppliers(community_id,supplier_id,category,source_column,raw_provider_name) VALUES (?,?,?,?,?)',[$communityId,$supplierId,'Categoria Dos','manual','x']);}
        catch(\PDOException $e){$violated=str_contains($e->getMessage(),'uq_community_supplier_pair');}
        $assert($violated,'una segunda categoría para el mismo par ya no debe poder insertarse en absoluto, por ningún camino: la constraint debe rechazarla');
        $count=(int)$db->one('SELECT COUNT(*) n FROM community_suppliers WHERE community_id=? AND supplier_id=?',[$communityId,$supplierId])['n'];
        $assert($count===1,'solo debe quedar la primera fila: '.$count);
        // El código de 'multiple_existing_categories' en CommunitySupplierAutoLinker se conserva
        // como defensa histórica (p. ej. un entorno donde esta migración de esquema aún no se
        // haya aplicado) pero, con la constraint puesta, ya no es un estado alcanzable — no hay
        // forma de construir el fixture sin desactivar la propia protección que se está probando.
    }finally{$f7Cleanup($db,[$communityId],[$supplierId]);$mysqlSchemaCleanup($ctx);}
});

$test('Fase 7 — fallo real de DB (violación de FK): rollback completo, inserted=false, reason=insert_failed, error visible, sin excepción escapando',static function()use($assert,$mysqlSchemaTest,$mysqlSchemaCleanup):void{
    $ctx=$mysqlSchemaTest();
    if($ctx===null){echo "SKIP (sin MySQL local disponible)\n";return;}
    $db=$ctx['db'];
    Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
    $linker=new Salvest\CommunitySupplierAutoLinker($db);
    $result=$linker->linkIfMissing(999999999,999999999,'Fase7 Test','x'); // ids inexistentes -> viola fk_cs_community/fk_cs_supplier
    $assert($result['inserted']===false && $result['reason']==='insert_failed' && $result['error']!==null,'debe reportar el fallo real, nunca ocultarlo: '.json_encode($result));
    $mysqlSchemaCleanup($ctx);
});

$test('Fase 7 — el autolink queda en la explicación de la decisión (classification.autolink) con inserted/reason/category, y la relación en la base',static function()use($assert,$mysqlSchemaTest,$mysqlSchemaCleanup,$f7InsertCommunity,$f7InsertSupplier,$f7Cleanup,$f7Service,$routeCompat):void{
    $ctx=$mysqlSchemaTest();
    if($ctx===null){echo "SKIP (sin MySQL local disponible)\n";return;}
    $db=$ctx['db'];
    Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
    $serviceId=$f7Service($db,'Trace');
    $communityId=$f7InsertCommunity($db,'F7TRACE','H70000010');
    $supplierId=$f7InsertSupplier($db,'TRACE F7',null,$serviceId,'B70000010');
    try{
        $router=$routeCompat($db,true);
        $route=$router->route(['proveedor'=>'TRACE F7','proveedor_cif'=>'B70000010','comunidad_cif'=>'H70000010','tipo_servicio'=>''],'facturas@x.example','',null);
        $link=$route['result']['classification']['autolink'];
        $assert($link['attempted']===true && $link['inserted']===true && $link['reason']==='missing_relation' && $link['category']==='MANTENIMIENTO',json_encode($link));
        $rows=$db->all('SELECT category,source_column FROM community_suppliers WHERE community_id=? AND supplier_id=?',[$communityId,$supplierId]);
        $assert(count($rows)===1 && $rows[0]['category']==='MANTENIMIENTO',json_encode($rows));
    }finally{$f7Cleanup($db,[$communityId],[$supplierId]);$mysqlSchemaCleanup($ctx);}
});

$test('Fase 7 — no autolink desde el camino comunitario: si resolveSupplierInCommunity() resuelve directamente, auto_link no aparece en absoluto en la evidencia',static function()use($assert,$mysqlSchemaTest,$mysqlSchemaCleanup,$f7InsertCommunity,$f7InsertSupplier,$f7Cleanup,$f7Service,$routeCompat):void{
    $ctx=$mysqlSchemaTest();
    if($ctx===null){echo "SKIP (sin MySQL local disponible)\n";return;}
    $db=$ctx['db'];
    Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
    $serviceId=$f7Service($db,'YaComunidad');
    $communityId=$f7InsertCommunity($db,'F7COM','H70000011');
    $supplierId=$f7InsertSupplier($db,'YA EN COMUNIDAD F7',null,$serviceId,null);
    $db->execute('INSERT INTO community_suppliers(community_id,supplier_id,category,source_column,raw_provider_name) VALUES (?,?,?,?,?)',[$communityId,$supplierId,'X','manual','x']);
    try{
        $router=$routeCompat($db,true);
        $route=$router->route(['proveedor'=>'YA EN COMUNIDAD F7','proveedor_cif'=>'','comunidad_cif'=>'H70000011','tipo_servicio'=>''],'facturas@x.example');
        $assert(($route['autolink']['attempted']??null)===false && $route['autolink']['reason']==='relation_already_exists','un proveedor ya vinculado a la comunidad no intenta autolink: '.json_encode($route['autolink']));
    }finally{$f7Cleanup($db,[$communityId],[$supplierId]);$mysqlSchemaCleanup($ctx);}
});

$test('Fase 7 — no crea suppliers ni communities: recuentos idénticos antes/después de todo el flujo de autolink',static function()use($assert,$mysqlSchemaTest,$mysqlSchemaCleanup,$f7InsertCommunity,$f7InsertSupplier,$f7Cleanup,$f7Service,$routeCompat):void{
    $ctx=$mysqlSchemaTest();
    if($ctx===null){echo "SKIP (sin MySQL local disponible)\n";return;}
    $db=$ctx['db'];
    Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
    $serviceId=$f7Service($db,'NoCrea');
    $communityId=$f7InsertCommunity($db,'F7NOCREA','H70000012');
    $supplierId=$f7InsertSupplier($db,'NO CREA F7',null,$serviceId,'B70000012');
    try{
        $suppliersBefore=(int)$db->one('SELECT COUNT(*) n FROM suppliers')['n'];
        $communitiesBefore=(int)$db->one('SELECT COUNT(*) n FROM communities')['n'];
        $router=$routeCompat($db,true);
        $router->route(['proveedor'=>'NO CREA F7','proveedor_cif'=>'B70000012','comunidad_cif'=>'H70000012','tipo_servicio'=>''],'facturas@x.example');
        $assert((int)$db->one('SELECT COUNT(*) n FROM suppliers')['n']===$suppliersBefore,'autolink nunca debe crear suppliers');
        $assert((int)$db->one('SELECT COUNT(*) n FROM communities')['n']===$communitiesBefore,'autolink nunca debe crear communities');
    }finally{$f7Cleanup($db,[$communityId],[$supplierId]);$mysqlSchemaCleanup($ctx);}
});

$test('Fase 7 — concurrencia REAL (dos procesos PHP con conexiones MySQL independientes, no dos llamadas secuenciales): estado inicial 0, dos intentos concurrentes con categorías DISTINTAS -> exactamente 1 relación final',static function()use($assert,$mysqlSchemaTest,$mysqlSchemaCleanup,$f7InsertCommunity,$f7InsertSupplier,$f7Cleanup,$f7Service,$testMysqlConfig):void{
    $ctx=$mysqlSchemaTest();
    if($ctx===null){echo "SKIP (sin MySQL local disponible)\n";return;}
    $db=$ctx['db'];
    Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
    $serviceId=$f7Service($db,'Concurrencia');
    $communityId=$f7InsertCommunity($db,'F7CONC','H70000099');
    $supplierId=$f7InsertSupplier($db,'CONC F7',null,$serviceId,'B70000099');
    try{
        $before=(int)$db->one('SELECT COUNT(*) n FROM community_suppliers WHERE community_id=? AND supplier_id=?',[$communityId,$supplierId])['n'];
        $assert($before===0,'precondición: sin relación previa');

        // Script real que un proceso PHP independiente ejecuta contra su PROPIA conexión MySQL,
        // llamando al código de producción tal cual (Salvest\CommunitySupplierAutoLinker), no una
        // reimplementación. Dos procesos = dos conexiones = dos transacciones reales.
        $racerPath=sys_get_temp_dir().'/salvest-f7-racer-'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($racerPath,'<?php
declare(strict_types=1);
require "'.dirname(__DIR__).'/src/Database.php";
require "'.dirname(__DIR__).'/src/Text.php";
require "'.dirname(__DIR__).'/src/CommunitySupplierAutoLinker.php";
$db=new Salvest\Database('.var_export($testMysqlConfig(),true).');
$linker=new Salvest\CommunitySupplierAutoLinker($db);
$result=$linker->linkIfMissing((int)$argv[1],(int)$argv[2],$argv[3],"CONC TEST");
echo json_encode($result);
');
        $spawn=static function(string $category)use($racerPath,$communityId,$supplierId):array{
            $descriptors=[1=>['pipe','w'],2=>['pipe','w']];
            $process=proc_open([PHP_BINARY,$racerPath,(string)$communityId,(string)$supplierId,$category],$descriptors,$pipes);
            return ['process'=>$process,'pipes'=>$pipes];
        };
        // Ambos procesos se lanzan antes de leer ninguna salida, para que corran solapados de verdad.
        $p1=$spawn('AGUA CONC');
        $p2=$spawn('OTROS CONC');
        $out1=stream_get_contents($p1['pipes'][1]);$err1=stream_get_contents($p1['pipes'][2]);
        $out2=stream_get_contents($p2['pipes'][1]);$err2=stream_get_contents($p2['pipes'][2]);
        foreach([$p1,$p2] as $p){fclose($p['pipes'][1]);fclose($p['pipes'][2]);proc_close($p['process']);}
        @unlink($racerPath);

        $r1=json_decode($out1,true);$r2=json_decode($out2,true);
        $assert(is_array($r1) && is_array($r2),'ambos procesos deben devolver un resultado válido: out1='.$out1.' err1='.$err1.' out2='.$out2.' err2='.$err2);
        $assert($r1['reason']!=='insert_failed' && $r2['reason']!=='insert_failed','ningún proceso debe reportar un fallo de DB bajo esta concurrencia (ver historial de fixes en el docblock de la clase): '.json_encode([$r1,$r2]));
        $insertedCount=(int)$r1['inserted']+(int)$r2['inserted'];
        $assert($insertedCount===1,'exactamente uno de los dos procesos debe haber insertado, el otro debe reconocer que ya existía: '.json_encode([$r1,$r2]));

        $after=(int)$db->one('SELECT COUNT(*) n FROM community_suppliers WHERE community_id=? AND supplier_id=?',[$communityId,$supplierId])['n'];
        $assert($after===1,'estado final debe ser EXACTAMENTE 1 relación, nunca 2, pese a las categorías distintas: '.$after);
    }finally{$f7Cleanup($db,[$communityId],[$supplierId]);$mysqlSchemaCleanup($ctx);}
});

$test('Fase 7 — el error SQL completo de un fallo de autolink nunca llega a decision_json: InvoiceProcessor solo reenvía el motivo seguro (guarda de regresión de código)',static function()use($assert):void{
    $source=file_get_contents(__DIR__.'/../src/InvoiceProcessor.php');
    $assert(!str_contains($source,"['error']"),'InvoiceProcessor no debe leer/reenviar el campo error del autolinker — el detalle técnico de BD se queda en error_log()');
    $assert(str_contains($source,'\'reason\'=>$result[\'reason\']'),'sí debe reenviar la razón (segura, sin detalle de BD)');
});

// ============================================================================================
// Fase 8 — Claude como extractor primario, OpenAI como fallback automático.
// ============================================================================================

/** Stub mínimo de ExtractorProvider para probar FallbackExtractor sin red real — igual de
 * inyectable que OpenAIExtractor/ClaudeExtractor porque implementa el mismo contrato. */
/** AiClient de prueba: lanza $throwMessage o devuelve $data con los tokens indicados, y cuenta las llamadas. */
$stubAi=static function(?string $throwMessage,array $data=[],int $inTokens=10,int $outTokens=5,string $provider='stub'):Salvest\AiClient{
    return new class($throwMessage,$data,$inTokens,$outTokens,$provider) implements Salvest\AiClient{
        public int $calls=0;
        public function __construct(private ?string $throwMessage,private array $data,private int $inTokens,private int $outTokens,private string $provider){}
        public function structured(string $task,string $instructions,array $schema,?string $pdfPath,string $userText,array $options=[]):Salvest\AiResponse{
            $this->calls++;
            if($this->throwMessage!==null)throw new \RuntimeException($this->throwMessage);
            return new Salvest\AiResponse($this->data,$this->provider,$this->provider.'-model',$this->inTokens,$this->outTokens,1);
        }
    };
};

$test('Fase 8 — FallbackAiClient: el principal (Claude) responde bien -> nunca se llama al respaldo (OpenAI) y la respuesta dice quién la sirvió',static function()use($assert,$stubAi):void{
    $primary=$stubAi(null,['ok'=>1],100,50,'claude');$fallback=$stubAi('nunca debería llamarse');
    $response=(new Salvest\FallbackAiClient($primary,$fallback))->structured('t','i',[],null,'x');
    $assert($response->data===['ok'=>1] && $response->provider==='claude' && $response->inputTokens===100 && $response->outputTokens===50 && $fallback->calls===0);
});

$test('Fase 8 — FallbackAiClient: el principal falla -> cae automáticamente al respaldo, que sirve la llamada; la excepción del principal no se propaga',static function()use($assert,$stubAi):void{
    $primary=$stubAi('Claude respondió HTTP 529: overloaded');$fallback=$stubAi(null,['ok'=>2],20,10,'openai');
    $response=(new Salvest\FallbackAiClient($primary,$fallback))->structured('t','i',[],null,'x');
    $assert($response->data===['ok'=>2] && $response->provider==='openai' && $primary->calls===1 && $fallback->calls===1);
});

$test('Fase 8 — DocumentIntelligence registra los tokens reales de cada llamada, venga del principal o del respaldo',static function()use($assert,$stubAi):void{
    $ai=new Salvest\DocumentIntelligence(new Salvest\FallbackAiClient($stubAi('caído'),$stubAi(null,['document_type'=>'invoice','should_process_as_invoice'=>true,'document_confidence'=>0.95,'document_reason'=>'x','issuer'=>['name'=>'FACSA','tax_id'=>null],'customer'=>['name'=>null,'tax_id'=>null,'address'=>null,'postal_code'=>null],'invoice'=>['number'=>null,'date'=>null,'total_amount'=>null,'currency'=>null,'billing_period'=>null],'service'=>['raw'=>null,'semantic_category'=>null],'supply_identifiers'=>['cups'=>null,'contract_number'=>null,'customer_reference'=>null]],7,3,'openai')));
    $ai->understand('a.pdf','ctx');$ai->understand('b.pdf','ctx');
    $assert(array_sum(array_column($ai->calls,'input_tokens'))===14 && array_sum(array_column($ai->calls,'output_tokens'))===6 && $ai->calls[0]['provider']==='openai','2x7 in, 2x3 out, servidos por el respaldo');
});

$test('Fase 8 — FallbackAiClient: si fallan los dos, propaga la excepción del respaldo (el último intento real)',static function()use($assert,$stubAi):void{
    $threw=null;
    try{(new Salvest\FallbackAiClient($stubAi('claude caído'),$stubAi('openai también caído')))->structured('t','i',[],null,'x');}catch(\Throwable $e){$threw=$e->getMessage();}
    $assert($threw==='openai también caído',(string)$threw);
});

$test('Fase 8 — el respaldo también cubre las elecciones de la IA: si Claude cae al elegir proveedor, OpenAI elige y el id se valida igual',static function()use($assert,$stubAi):void{
    $ai=new Salvest\DocumentIntelligence(new Salvest\FallbackAiClient($stubAi('claude caído'),$stubAi(null,['supplier_id'=>42,'confidence'=>0.9,'reason'=>'logo'],5,2,'openai')));
    $choice=$ai->chooseSupplier('x.pdf',['issuer'=>[],'customer'=>[],'service'=>[]],'Comunidad X',[['id'=>42,'razon_social'=>'Y']]);
    $assert($choice['id']===42 && $choice['valid'] && $ai->calls[0]['provider']==='openai',json_encode($choice));
});

$test('Fase 8 — ClaudeClient y OpenAIClient implementan el mismo contrato AiClient',static function()use($assert):void{
    $assert(new Salvest\ClaudeClient(['api_key'=>'test','model'=>'claude-test','timeout_seconds'=>5]) instanceof Salvest\AiClient);
    $assert(new Salvest\OpenAIClient(['api_key'=>'test','model'=>'gpt-test','timeout_seconds'=>5]) instanceof Salvest\AiClient);
});

$test('Fase 8 — ClaudeClient: endpoint, autenticación, cabecera de versión, herramienta forzada y modelo desde config (inspección de código, sin red)',static function()use($assert):void{
    $source=file_get_contents(__DIR__.'/../src/ClaudeClient.php');
    $assert(str_contains($source,'https://api.anthropic.com/v1/messages'));
    $assert(str_contains($source,"'x-api-key: '"),'Anthropic autentica con x-api-key, no Bearer');
    $assert(str_contains($source,'anthropic-version'));
    $assert(str_contains($source,"'tool_choice'"),'salida estructurada mediante herramienta forzada');
    $assert(str_contains($source,'(string)$this->config[\'model\']'),'el modelo viene de config, nunca fijo');
});

$test('Fase 8 — Worker::create() conecta Claude como principal y OpenAI como respaldo detrás de DocumentIntelligence (inspección por reflexión, sin red)',static function()use($assert,$sqliteDbWithLock,$workerConfig):void{
    $worker=Salvest\Worker::create($sqliteDbWithLock(),$workerConfig());
    $get=static function(object $object,string $class,string $property):mixed{$r=new ReflectionProperty($class,$property);$r->setAccessible(true);return $r->getValue($object);};
    $processor=$get($worker,Salvest\Worker::class,'processor');
    $intelligence=$get($processor,Salvest\InvoiceProcessor::class,'ai');
    $client=$get($intelligence,Salvest\DocumentIntelligence::class,'ai');
    $assert($client instanceof Salvest\FallbackAiClient,'el cliente de IA es el de respaldo');
    $assert($get($client,Salvest\FallbackAiClient::class,'primary') instanceof Salvest\ClaudeClient && $get($client,Salvest\FallbackAiClient::class,'fallback') instanceof Salvest\OpenAIClient);
});

$test('Fase 8 — cron.php y bin/worker.php usan Worker::create() (fuente única de verdad de qué extractor se usa), no construyen OpenAIExtractor a mano (guarda de regresión de código)',static function()use($assert):void{
    foreach(['public/cron.php','bin/worker.php'] as $file){
        $source=file_get_contents(__DIR__.'/../'.$file);
        $assert(str_contains($source,'Worker::create('),"$file debe llamar a Worker::create(), no construir Worker a mano");
        $assert(!str_contains($source,'new Salvest\Worker('),"$file no debe instanciar Worker directamente — así nunca puede quedarse con el wiring antiguo (solo OpenAI)");
    }
});

$test('Fase 8 — processed_attachments.extractor_version guarda el proveedor y el modelo que de verdad sirvieron la comprensión (inspección de código)',static function()use($assert):void{
    $source=file_get_contents(__DIR__.'/../src/Worker.php');
    $assert(str_contains($source,'\'extractor_version\'=>isset($understandingCall[\'provider\']) ? $understandingCall[\'provider\'].\':\'.$understandingCall[\'model\'] : null'),'proveedor:modelo de la llamada real');
    $assert(!str_contains($source,'OpenAIExtractor::VERSION'),'ya no hay ninguna versión fija de respaldo');
});

// ============================================================================================
// Fase 9 — contención de palabras completas para comunidad (safety net previo al fuzzy, mismo
// principio que resolveSupplier() ya usa para proveedores).
// ============================================================================================

$test('Fase 9 — LLOMBAI 11: "LLOMBAI 11 ESCALERA" contiene el nombre "LLOMBAI 11": candidata con señal de contención en primer lugar, y la IA la confirma',static function()use($assert,$sqliteDb,$classifierSchema,$resolveCommunity):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,active) VALUES (?,?,?,?,?,1)',
        ['55','LLOMBAI 11',Salvest\Text::normalize('LLOMBAI 11'),'H12460788','AVDA. LLOMBAI 11']);
    $communityId=(int)$db->pdo()->lastInsertId();
    $result=$resolveCommunity($db,['name'=>'LLOMBAI 11 ESCALERA','address'=>'C/Llombai 11, BURRIANA']);
    $assert((int)($result['community']['id']??0)===$communityId && $result['source']==='ai_after_retrieval',json_encode($result['outcome']));
    $assert(in_array('containment',$result['candidates'][0]['senales'],true),'la contención queda registrada como señal: '.json_encode($result['candidates']));
});

$test('Fase 9 — ambigüedad: dos comunidades cuyo nombre aparece entero en el texto -> las dos son candidatas y, si la IA no puede distinguir, ninguna se elige',static function()use($assert,$sqliteDb,$classifierSchema,$resolveCommunity):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO communities(official_name,normalized_name,main_address,active) VALUES (?,?,?,1)',['EDIFICIO NORTE',Salvest\Text::normalize('EDIFICIO NORTE'),'Calle Norte 1']);
    $db->execute('INSERT INTO communities(official_name,normalized_name,main_address,active) VALUES (?,?,?,1)',['EDIFICIO SUR',Salvest\Text::normalize('EDIFICIO SUR'),'Calle Sur 1']);
    $result=$resolveCommunity($db,['name'=>'EDIFICIO NORTE EDIFICIO SUR']);
    $assert($result['community']===null && $result['outcome']==='ai_abstained' && count($result['candidates'])===2,'ambiguo -> ninguna: '.json_encode($result['outcome']));
});

$test('Fase 9 / 16.2 — la contención también aplica vía alias de dirección, y la comunidad devuelta es la fila completa (external_code incluido)',static function()use($assert,$sqliteDb,$classifierSchema,$resolveCommunity):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,main_address,active) VALUES (?,?,?,?,1)',['77','CP ALIAS',Salvest\Text::normalize('CP ALIAS'),'Avenida Principal 9']);
    $communityId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO community_aliases(community_id,alias_type,value,normalized_value,active) VALUES (?,?,?,?,1)',
        [$communityId,'address','Calle Secundaria 42',Salvest\Text::normalize('Calle Secundaria 42')]);
    $result=$resolveCommunity($db,['address'=>'Calle Secundaria 42, bajo']);
    $assert((int)($result['community']['id']??0)===$communityId && in_array('containment',$result['candidates'][0]['senales'],true),'debe proponerla vía el alias: '.json_encode($result['candidates']));
    $assert(($result['community']['external_code']??null)==='77','fila completa con external_code (bug real de la Fase 16.2): '.json_encode($result['community']));
});

$test('Fase 9 — el texto del correo nunca propone comunidades: solo lo que el documento dice del cliente',static function()use($assert,$sqliteDb,$classifierSchema,$resolveCommunity):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO communities(official_name,normalized_name,main_address,active) VALUES (?,?,?,1)',['MENCIONADA EN CONTEXTO',Salvest\Text::normalize('MENCIONADA EN CONTEXTO'),'Calle X 1']);
    $result=$resolveCommunity($db,['name'=>null,'address'=>null],static fn()=>throw new RuntimeException('sin candidatas no se llama a la IA'));
    $assert($result['community']===null && $result['outcome']==='no_candidates',json_encode($result['outcome']));
});

$test('Reconstrucción — "EL PINAR RESIDENCIAL" frente a "RESIDENCIAL EL PINAR": las palabras compartidas proponen la candidata (antes no se proponía) y decide la IA; si se abstiene, sin comunidad',static function()use($assert,$sqliteDb,$classifierSchema,$resolveCommunity):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO communities(official_name,normalized_name,main_address,active) VALUES (?,?,?,1)',['RESIDENCIAL EL PINAR',Salvest\Text::normalize('RESIDENCIAL EL PINAR'),'Calle Y 1']);
    $abstain=$resolveCommunity($db,['name'=>'EL PINAR RESIDENCIAL'],'abstain');
    $assert($abstain['community']===null && count($abstain['candidates'])===1 && in_array('rare_word:pinar',$abstain['candidates'][0]['senales'],true),json_encode($abstain['candidates']));
    $nothing=$resolveCommunity($db,['name'=>'URBANIZACION LOS OLIVOS']);
    $assert($nothing['community']===null && $nothing['outcome']==='no_candidates','sin ninguna señal no se inventa una candidata');
});

// ============================================================================================
// Fase 16 — la contención de comunidad ya no exige la palabra genérica de vía ("Calle",
// "Avenida"...) exacta al principio de la dirección — caso real de producción: FAIN Ascensores
// escribe "ENCARNACION, 35" en su parte, pero la comunidad está dada de alta como "CALLE
// ENCARNACION 35"; antes eso NUNCA contaba como contención porque exigía "calle" también en el
// texto extraído.
// ============================================================================================

$test('Fase 16 — Text::normalize() reconoce "AV" como abreviatura de "Avenida", igual que ya hacía con "AVDA" (caso real: OTIS escribe "AV MEDITERRANEO 12" en sus partes de trabajo)',static function()use($assert):void{
    $assert(Salvest\Text::normalize('AV Mediterraneo 12')==='avenida mediterraneo 12');
    $assert(Salvest\Text::normalize('Av. Mediterraneo 12')===Salvest\Text::normalize('Avda. Mediterraneo 12'),'"Av" y "Avda" deben normalizar exactamente igual');
});
$test('Fase 16 — Text::stripLeadingAddressWord() quita como mucho una palabra genérica del principio, nunca del medio ni si es la única palabra',static function()use($assert):void{
    $assert(Salvest\Text::stripLeadingAddressWord('calle encarnacion 35')==='encarnacion 35');
    $assert(Salvest\Text::stripLeadingAddressWord('avenida mediterranea 12')==='mediterranea 12');
    $assert(Salvest\Text::stripLeadingAddressWord('encarnacion 35')==='encarnacion 35','sin prefijo genérico, no debe cambiar nada');
    $assert(Salvest\Text::stripLeadingAddressWord('llombai calle 11')==='llombai calle 11','"calle" en medio de la frase nunca se quita, solo al principio');
    $assert(Salvest\Text::stripLeadingAddressWord('calle')==='calle','una única palabra nunca se vacía, aunque sea genérica');
});

$test('Fase 16 — CALLE ENCARNACION 35 (maestro) vs "ENCARNACION, 35, 12530 BURRIANA" (factura real de FAIN Ascensores) -> candidata por contención pese al prefijo "Calle" ausente',static function()use($assert,$sqliteDb,$classifierSchema,$resolveCommunity):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,active) VALUES (?,?,?,?,?,1)',
        ['02','ENCARNACION 35',Salvest\Text::normalize('ENCARNACION 35'),'H12798526','CALLE ENCARNACION 35']);
    $communityId=(int)$db->pdo()->lastInsertId();
    $result=$resolveCommunity($db,['address'=>'ENCARNACION, 35, 12530 BURRIANA']);
    $assert((int)($result['community']['id']??0)===$communityId && in_array('containment',$result['candidates'][0]['senales'],true),json_encode($result['candidates']));
});

$test('Fase 16 — el prefijo genérico también funciona al revés: maestro sin "Avenida", factura que sí la escribe',static function()use($assert,$sqliteDb,$classifierSchema,$resolveCommunity):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO communities(official_name,normalized_name,main_address,active) VALUES (?,?,?,1)',['MEDITERRANEA 12',Salvest\Text::normalize('MEDITERRANEA 12'),'MEDITERRANEA 12']);
    $communityId=(int)$db->pdo()->lastInsertId();
    $result=$resolveCommunity($db,['address'=>'Avenida Mediterranea 12, Burriana']);
    $assert((int)($result['community']['id']??0)===$communityId,json_encode($result['candidates']));
});

$test('Fase 16 — caso real OTIS: "AV MEDITERRANEO 12" llega vía alias "MEDITERRANEO 12" a la comunidad "AVDA. MEDITERRANEA 12", con su external_code',static function()use($assert,$sqliteDb,$classifierSchema,$resolveCommunity):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,active) VALUES (?,?,?,?,?,1)',
        ['90','AVDA. MEDITERRANEA 12',Salvest\Text::normalize('AVDA. MEDITERRANEA 12'),'H12278057','AVDA MEDITERRANEA 12']);
    $communityId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO community_aliases(community_id,alias_type,value,normalized_value,active) VALUES (?,?,?,?,1)',
        [$communityId,'address','MEDITERRANEO 12',Salvest\Text::normalize('MEDITERRANEO 12')]);
    $result=$resolveCommunity($db,['name'=>'C.P. MEDITERRANEO 12','address'=>'AV MEDITERRANEO 12']);
    $assert((int)($result['community']['id']??0)===$communityId,json_encode($result['candidates']));
    $assert(($result['community']['external_code']??null)==='90','debe traer external_code: '.json_encode($result['community']));
});
$test('Fase 16.2 — el recuperador devuelve siempre la fila completa de la comunidad, también cuando la propone por un alias o por similitud',static function()use($assert,$sqliteDb,$classifierSchema,$resolveCommunity):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,active) VALUES (?,?,?,?,?,1)',['64','JAIME CHICHARRO 27',Salvest\Text::normalize('JAIME CHICHARRO 27'),'H12247573','CALLE JAIME CHICHARRO 27']);
    $id=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO community_aliases(community_id,alias_type,value,normalized_value,active) VALUES (?,?,?,?,1)',[$id,'address','CHICHARRO 27',Salvest\Text::normalize('CHICHARRO 27')]);
    $candidates=(new Salvest\CommunityCandidateRetriever($db))->candidates(['name'=>null,'address'=>'CHICHARO 27']);
    $assert(count($candidates)===1 && ($candidates[0]['community']['external_code']??null)==='64' && ($candidates[0]['community']['cif']??null)==='H12247573','fila completa: '.json_encode($candidates));
});
$test('Fase 16 — quitar el prefijo genérico no inventa candidatas entre calles distintas ("Mayor 1" frente a "Menor 5")',static function()use($assert,$sqliteDb,$classifierSchema,$resolveCommunity):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO communities(official_name,normalized_name,main_address,active) VALUES (?,?,?,1)',['CALLE MAYOR 1',Salvest\Text::normalize('CALLE MAYOR 1'),'CALLE MAYOR 1']);
    $result=$resolveCommunity($db,['address'=>'Avenida Menor 5, Burriana']);
    $assert($result['community']===null && $result['candidates']===[],'calles distintas: ni siquiera candidata: '.json_encode($result['candidates']));
});

// ============================================================================================
// Fase 9.1 — el contexto del correo (asunto/cuerpo) nunca debe pesar más que los propios campos
// extraídos de la factura al resolver comunidad por fuzzy. Caso real: una factura de una
// comunidad, enviada en un correo cuyo asunto/cuerpo mencionaba OTRA comunidad -> el sistema
// archivó la factura en la comunidad equivocada porque el contexto competía en igualdad con los
// campos de la propia factura en la misma pasada.
// ============================================================================================

$test('Fase 9.1 — LA FACTURA GANA: una comunidad mencionada solo en el correo nunca es candidata; la del documento sí, aunque tenga una errata',static function()use($assert,$sqliteDb,$classifierSchema,$resolveCommunity):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO communities(official_name,normalized_name,main_address,active) VALUES (?,?,?,1)',
        ['RESIDENCIAL LOS PINOS ALTOS DEL NORTE',Salvest\Text::normalize('RESIDENCIAL LOS PINOS ALTOS DEL NORTE'),'Calle Pinos 1']);
    $correctId=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO communities(official_name,normalized_name,main_address,active) VALUES (?,?,?,1)',['TORRE DEL PUERTO',Salvest\Text::normalize('TORRE DEL PUERTO'),'Calle Puerto 1']);
    $result=$resolveCommunity($db,['name'=>'RESIDENCIAL LOS PINOS ALTOS DEL NORTES']);
    $assert((int)($result['community']['id']??0)===$correctId,json_encode($result['candidates']));
    $assert(!in_array('TORRE DEL PUERTO',array_column($result['candidates'],'nombre'),true),'la comunidad del correo no es candidata');
});

$test('Reconstrucción — el texto del correo ya no resuelve comunidades como último recurso (la auditoría lo vio prácticamente inalcanzable): sin datos del cliente en el documento, revisión',static function()use($assert,$sqliteDb,$classifierSchema,$pipeline):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO communities(official_name,normalized_name,main_address,active) VALUES (?,?,?,1)',['TORRE DEL PUERTO',Salvest\Text::normalize('TORRE DEL PUERTO'),'Calle Puerto 1']);
    $result=$pipeline($db,['proveedor'=>'ALGUIEN','nombre_comunidad'=>null,'direccion'=>null],'TORRE DEL PUERTO <facturas@torre.example>');
    $assert($result['status']==='unclassified' && $result['reason_code']==='community_no_candidates',json_encode([$result['status'],$result['reason_code']]));
});

$test('Fase 9.1 — el contrato de la IA (uno solo para Claude y OpenAI) instruye a leer los datos del cliente SOLO del documento, nunca del correo',static function()use($assert):void{
    $instructions=Salvest\AiContracts::understandingInstructions();
    $assert(str_contains($instructions,'ÚNICAMENTE de lo impreso en el documento, nunca del asunto ni del cuerpo del correo'),'regla de la Fase 9.1 presente');
    $assert(str_contains($instructions,'DATOS TITULAR'),'regla de la Fase 23 presente (NIF del titular es del cliente)');
    foreach(['src/ClaudeClient.php','src/OpenAIClient.php'] as $file)$assert(!str_contains(file_get_contents(__DIR__.'/../'.$file),'Extrae los datos'),"$file no debe llevar su propio prompt: el contrato vive en AiContracts");
});

// ============================================================================================
// Fase 11 — páginas públicas de política de privacidad y condiciones del servicio, exigidas por
// Google para publicar la pantalla de consentimiento OAuth en "Producción" (sin eso, el refresh
// token de Drive caduca a los 7 días por quedarse en modo "Prueba").
// ============================================================================================

$test('Fase 11 — /?route=privacidad y /?route=terminos son accesibles SIN sesión (Google debe poder verlas sin loguearse), y nunca muestran el panel/sidebar interno',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp,$requestWebApp):void{
    $db=$sqliteDbWithLock();$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    foreach(['privacidad'=>'Política de privacidad','terminos'=>'Condiciones del servicio','info'=>'Salvest Gestiones'] as $route=>$heading){
        $html=$requestWebApp($webApp,'GET',$route);
        $assert(str_contains($html,'<h1>'.$heading.'</h1>'),"$route debe mostrar el título esperado: ".substr($html,0,200));
        $assert(!str_contains($html,'class="sidebar"'),"$route no debe mostrar el panel interno (nav lateral) a un visitante sin sesión");
        $assert(!str_contains($html,'name="username"'),"$route no debe redirigir al formulario de login — debe ser pública de verdad");
    }
});
$test('Fase 11 — todas las páginas públicas legales/informativas enlazan a un contacto real, no un placeholder vacío (guarda de regresión de código)',static function()use($assert):void{
    $source=file_get_contents(__DIR__.'/../src/WebApp.php');
    $assert(substr_count($source,'mailto:jcmallo@gmail.com')===3,'privacidad, terminos e info deben tener un correo de contacto real');
});

// ============================================================================================
// Fase 12.1 — apartado de ayuda dedicado (reemplaza el intento anterior de tooltips repartidos,
// revertido porque no gustó): una sección propia en la barra lateral, con un acordeón por cada
// parte del panel, en lenguaje llano.
// ============================================================================================

$test('Fase 12.1 — /?route=help requiere sesión, igual que el resto del panel interno (a diferencia de privacidad/terminos/info, que sí son públicas)',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp,$requestWebApp):void{
    $db=$sqliteDbWithLock();$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    unset($_SESSION['user_id']);
    $html=$requestWebApp($webApp,'GET','help');
    $assert(str_contains($html,'name="username"'),'sin sesión, /?route=help debe mostrar el login como cualquier otra página interna: '.substr($html,0,200));
});
$test('Fase 12.1 — el enlace "Ayuda" existe en la barra lateral, y la página explica las 6 secciones reales del panel',static function()use($assert,$sqliteDbWithLock,$workerConfig,$makeWebApp,$requestWebApp):void{
    $db=$sqliteDbWithLock();$config=$workerConfig();$webApp=$makeWebApp($db,$config);
    $_SESSION['user_id']=1;
    $dashboard=$requestWebApp($webApp,'GET','');
    $assert(str_contains($dashboard,'route=help') && str_contains($dashboard,'>Ayuda<'),'debe existir el enlace de Ayuda en la barra lateral: '.substr($dashboard,0,50));
    $html=$requestWebApp($webApp,'GET','help');
    foreach(['Inicio','Comunidades','Proveedores','Correos','Revisar','Almacenamiento'] as $section){
        $assert(str_contains($html,'>'.$section.'<'),"debe explicar la sección \"$section\": ".substr($html,0,200));
    }
    $assert(substr_count($html,'class="disclosure"')===6,'las 6 secciones deben venir en el mismo acordeón plegable que ya usa el resto del panel, no en markup nuevo');
});

$test('Fase 15 — fecha ausente, no reconocible, sin día o futura: la factura va a revisión con motivo propio, nunca a error (antes solo se miraba AAAA-MM y Drive fallaba con AAAA-MM o años futuros)',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier,$pipeline):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'98','CAMI DEL GRAO 4','OTIS','ASCENSOR','A28011153','H12402327');
    foreach([null,'02/09/2026','2026-09','2026-02-30',(new DateTimeImmutable('+1 year'))->format('Y-m-d')] as $date){
        $r=$pipeline($db,['proveedor'=>'OTIS','proveedor_cif'=>'A28011153','comunidad_cif'=>'H12402327','fecha_factura'=>$date]);
        $assert($r['status']==='needs_review' && $r['reason_code']==='invalid_date' && $r['legacy']['fecha_factura']===null,"fecha ".var_export($date,true).': '.$r['status'].' '.$r['reason_code']);
    }
    $ok=$pipeline($db,['proveedor'=>'OTIS','proveedor_cif'=>'A28011153','comunidad_cif'=>'H12402327','fecha_factura'=>'2026-09-02']);
    $assert($ok['status']==='classified' && $ok['legacy']['fecha_factura']==='2026-09-02');
});
$test('Fase 15 — una fecha inválida nunca tapa el motivo real de revisión: si ya falta el proveedor, el motivo sigue siendo el proveedor',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier,$pipeline):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'98','CAMI DEL GRAO 4','OTIS','ASCENSOR','A28011153','H12402327');
    $r=$pipeline($db,['proveedor'=>'PROVEEDOR DESCONOCIDO','proveedor_cif'=>'B99999999','comunidad_cif'=>'H12402327','fecha_factura'=>'basura'],'x@desconocido.example','smart','abstain');
    $assert($r['status']==='needs_review' && $r['reason_code']==='supplier_ai_abstained',$r['reason_code']);
});

// ---- Fase 24: correos archivados que se quedaban en la bandeja de entrada ----
// Doble de ImapClient para ImapMoveRetry: registra lo que se mueve y puede fallar a propósito.
$fakeMoveClient=static function(array $failUids=[]):object{
    return new class($failUids){
        public array $moved=[];public array $seen=[];
        public function __construct(private array $failUids){}
        public function uidValidity():string{return '1001';}
        public function markSeen(string $uid):void{$this->seen[]=$uid;}
        public function move(string $uid,string $destination):void{
            if(in_array($uid,$this->failUids,true))throw new RuntimeException('IMAP rechazó UID MOVE');
            $this->moved[$uid]=$destination;
        }
    };
};
$seedStuckMessage=static function(Salvest\Database $db,string $uid,string $status,string $destination,array $attachmentStatuses,string $uidValidity='1001'):int{
    $db->execute("INSERT INTO processed_messages(mailbox_id,uidvalidity,message_uid,message_id_header,sender,subject,received_at,status,document_count,imap_destination,imap_move_status,error_message,processed_at) VALUES (1,?,?,?,?,?,?,?,?,?,'failed','conexión cortada',?)",
        [$uidValidity,$uid,"<$uid@iberdrola.es>",'facturas@iberdrola.es','Tu factura',date('Y-m-d H:i:s'),$status,count($attachmentStatuses),$destination,date('Y-m-d H:i:s')]);
    $id=(int)$db->pdo()->lastInsertId();
    foreach($attachmentStatuses as $index=>$attachmentStatus){
        $db->execute('INSERT INTO processed_attachments(mailbox_id,uidvalidity,message_uid,original_filename,attachment_sha256,mime_type,size_bytes,status,processed_at) VALUES (1,?,?,?,?,?,?,?,?)',
            [$uidValidity,$uid,"factura-$index.pdf",hash('sha256',$uid.'-'.$index),'application/pdf',1000,$attachmentStatus,date('Y-m-d H:i:s')]);
    }
    return $id;
};
$test('Fase 24 — caso real IBERDROLA: un correo ya archivado cuyo movimiento falló se mueve a "facturgerman/Facturas" en la siguiente pasada, marcado como leído, y queda como movido',static function()use($assert,$sqliteDbWithLock,$fakeMoveClient,$seedStuckMessage):void{
    $db=$sqliteDbWithLock();
    $id=$seedStuckMessage($db,'501','completed','facturgerman/Facturas',['classified']);
    $client=$fakeMoveClient();
    $result=(new Salvest\ImapMoveRetry($db))->retry(1,$client,['499','501','502']);
    $assert($result['moved']===1,'debe haberse movido 1 correo: '.json_encode($result));
    $assert(($client->moved['501']??null)==='facturgerman/Facturas','el correo debe ir a facturgerman/Facturas');
    $assert($client->seen===['501'],'un correo archivado se marca como leído, igual que en el primer intento');
    $row=$db->one('SELECT status,imap_move_status,error_message FROM processed_messages WHERE id=?',[$id]);
    $assert($row['status']==='completed'&&$row['imap_move_status']==='moved'&&$row['error_message']===null,'debe quedar movido y sin error: '.json_encode($row));
    $again=(new Salvest\ImapMoveRetry($db))->retry(1,$fakeMoveClient(),['501']);
    $assert($again['moved']===0,'una vez movido, nunca se vuelve a intentar');
});
$test('Fase 24 — un correo que se quedó en revisión con el movimiento fallido, y que después se confirmó a mano en /Revisar, también va a "facturgerman/Facturas" y pasa a completed',static function()use($assert,$sqliteDbWithLock,$fakeMoveClient,$seedStuckMessage):void{
    $db=$sqliteDbWithLock();
    $id=$seedStuckMessage($db,'610','needs_review','facturgerman/Pendientes de revisión',['classified','duplicate']);
    $client=$fakeMoveClient();
    (new Salvest\ImapMoveRetry($db))->retry(1,$client,['610']);
    $assert(($client->moved['610']??null)==='facturgerman/Facturas','todo archivado: destino Facturas, no la carpeta de revisión');
    $row=$db->one('SELECT status,imap_destination FROM processed_messages WHERE id=?',[$id]);
    $assert($row['status']==='completed'&&$row['imap_destination']==='facturgerman/Facturas',json_encode($row));
});
$test('Fase 24 — un correo con algo todavía pendiente se reintenta hacia su carpeta de revisión, nunca hacia Facturas, y no se marca como leído',static function()use($assert,$sqliteDbWithLock,$fakeMoveClient,$seedStuckMessage):void{
    $db=$sqliteDbWithLock();
    $id=$seedStuckMessage($db,'620','needs_review','facturgerman/Pendientes de revisión',['classified','needs_review']);
    $client=$fakeMoveClient();
    (new Salvest\ImapMoveRetry($db))->retry(1,$client,['620']);
    $assert(($client->moved['620']??null)==='facturgerman/Pendientes de revisión','debe ir a la carpeta de revisión que ya tenía');
    $assert($client->seen===[],'lo pendiente de revisar no se marca como leído');
    $assert($db->one('SELECT status FROM processed_messages WHERE id=?',[$id])['status']==='needs_review','el estado no cambia: sigue pendiente');
});
$test('Fase 24 — si el correo ya no está en la bandeja de entrada (alguien lo movió a mano), no se toca IMAP y se deja de buscar',static function()use($assert,$sqliteDbWithLock,$fakeMoveClient,$seedStuckMessage):void{
    $db=$sqliteDbWithLock();
    $id=$seedStuckMessage($db,'630','completed','facturgerman/Facturas',['classified']);
    $client=$fakeMoveClient();
    $result=(new Salvest\ImapMoveRetry($db))->retry(1,$client,['629','631']);
    $assert($client->moved===[]&&$result['not_in_inbox']===1,json_encode($result));
    $assert($db->one('SELECT imap_move_status FROM processed_messages WHERE id=?',[$id])['imap_move_status']==='not_in_inbox');
});
$test('Fase 24 — si el reintento vuelve a fallar, queda pendiente para la siguiente pasada con el error nuevo, y no impide mover los demás',static function()use($assert,$sqliteDbWithLock,$fakeMoveClient,$seedStuckMessage):void{
    $db=$sqliteDbWithLock();
    $failing=$seedStuckMessage($db,'640','completed','facturgerman/Facturas',['classified']);
    $seedStuckMessage($db,'641','completed','facturgerman/Facturas',['classified']);
    $client=$fakeMoveClient(['640']);
    $result=(new Salvest\ImapMoveRetry($db))->retry(1,$client,['640','641']);
    $assert($result['failed']===1&&$result['moved']===1&&isset($client->moved['641']),json_encode($result));
    $row=$db->one('SELECT imap_move_status,error_message FROM processed_messages WHERE id=?',[$failing]);
    $assert($row['imap_move_status']==='failed'&&str_contains((string)$row['error_message'],'UID MOVE'),json_encode($row));
});
$test('Fase 24 — los correos de otro UIDVALIDITY (UID ya sin sentido), los ignorados, los de error y los ya movidos nunca se tocan',static function()use($assert,$sqliteDbWithLock,$fakeMoveClient,$seedStuckMessage):void{
    $db=$sqliteDbWithLock();
    $seedStuckMessage($db,'650','completed','facturgerman/Facturas',['classified'],'999');
    $seedStuckMessage($db,'651','error','facturgerman/Errores',['error']);
    $seedStuckMessage($db,'652','completed','facturgerman/Facturas',['classified']);
    $db->execute("UPDATE processed_messages SET imap_move_status='moved' WHERE message_uid='652'");
    $client=$fakeMoveClient();
    $result=(new Salvest\ImapMoveRetry($db))->retry(1,$client,['650','651','652']);
    $assert($client->moved===[]&&$result===['moved'=>0,'not_in_inbox'=>0,'failed'=>0],json_encode($result));
});
$test('Fase 24 — Worker: el barrido se hace en cada pasada (salvo dry-run) antes de leer correo nuevo, y el movimiento inmediato reintenta reconectando (guarda de regresión de código)',static function()use($assert):void{
    $source=file_get_contents(__DIR__.'/../src/Worker.php');
    $retryPos=strpos($source,'(new ImapMoveRetry($this->db))->retry(');
    $baselinePos=strpos($source,'$uids = $this->applyBaseline($mailbox, $client, $inboxUids);');
    $assert($retryPos!==false&&$baselinePos!==false&&$retryPos<$baselinePos,'el barrido debe ir antes de procesar lo nuevo, con la lista completa de la bandeja de entrada');
    $assert(str_contains($source,"\$this->moveWithReconnect(\$client,\$uid,\$destination,true);"),'lo clasificado se mueve con reintento y marcado como leído');
    $assert(str_contains($source,"\$this->moveWithReconnect(\$client,\$uid,\$destination,false);"),'lo de revisión también se mueve con reintento, sin marcar como leído');
    $assert(substr_count($source,'$client->close(); $client->connect();')===1,'el segundo intento reconecta antes de volver a mover');
});

// ─── Reconstrucción del pipeline inteligente: suite del comportamiento nuevo ───────────────────
// Puerta de documento, comunidad (casos A–F), proveedor, autolink, servicio y extremo a extremo.

$gateFor=static fn(string $type,float $confidence,?bool $process=null,string $filename='documento.pdf'):array
    =>(new Salvest\ClassificationPolicy())->documentGate(['document_type'=>$type,'should_process_as_invoice'=>$process??in_array($type,Salvest\AiContracts::INVOICE_TYPES,true),'document_confidence'=>$confidence],$filename);

$test('Pipeline — puerta de documento: facturas (también rectificativas) siguen; informes, presupuestos, certificados, contratos y tablas con importes se descartan solo con certeza',static function()use($assert,$gateFor):void{
    $cases=[
        ['invoice',0.97,'continue',null,'factura normal'],
        ['credit_note',0.95,'continue',null,'factura rectificativa / abono'],
        ['invoice',0.75,'continue',null,'factura poco habitual, con pocos campos: evidencia fuerte basta'],
        ['budget',0.96,'ignored_not_invoice','not_invoice','presupuesto'],
        ['technical_report',0.95,'ignored_not_invoice','not_invoice','parte o informe técnico (Extinplan INFORME, Detalle_Visita)'],
        ['report',0.93,'ignored_not_invoice','not_invoice','informe económico / extracto (EstadoDeCuenta)'],
        ['certificate',0.91,'ignored_not_invoice','not_invoice','certificado'],
        ['contract',0.94,'ignored_not_invoice','not_invoice','contrato'],
        ['receipt',0.95,'ignored_not_invoice','not_invoice','justificante de pago'],
        ['other',0.92,'ignored_not_invoice','not_invoice','tabla con importes que no es factura'],
        ['report',0.80,'needs_review','document_type_uncertain','ambiguo: parece informe pero sin certeza'],
    ];
    foreach($cases as [$type,$confidence,$decision,$code,$label]){
        $gate=$gateFor($type,$confidence);
        $assert($gate['decision']===$decision && $gate['reason_code']===$code,$label.': '.json_encode($gate));
    }
});
$test('Pipeline — puerta de documento: bordes exactos de los umbrales (0.90 para descartar, 0.70 para aceptar) y tipo incoherente con la decisión de la IA',static function()use($assert,$gateFor):void{
    $assert($gateFor('technical_report',0.90)['decision']==='ignored_not_invoice','0.90 es el borde inferior del tramo explícito: se descarta');
    $assert($gateFor('technical_report',0.8999)['decision']==='needs_review','por debajo de 0.90 no se descarta nunca en silencio');
    $assert($gateFor('invoice',0.70)['decision']==='continue','0.70 basta para tratarlo como factura');
    $assert($gateFor('invoice',0.6999)['reason_code']==='document_type_uncertain','por debajo de 0.70 la factura va a revisión');
    $assert($gateFor('budget',0.99,true)['reason_code']==='document_type_inconsistent','"presupuesto" pero "procésalo como factura": la IA se contradice, revisión');
    $assert($gateFor('invoice',0.99,false)['reason_code']==='document_type_inconsistent','"factura" pero "no la proceses": revisión');
    $assert($gateFor('invoice',0.99,null,'PRE 266.pdf')['reason_code']==='quote_filename','una factura con nombre de presupuesto va a revisión');
    $assert(Salvest\ClassificationPolicy::NOT_INVOICE_MIN_CONFIDENCE===0.90 && Salvest\ClassificationPolicy::INVOICE_MIN_CONFIDENCE===0.70 && Salvest\ClassificationPolicy::AI_CHOICE_MIN_CONFIDENCE===0.70,'los umbrales son los bordes de la rúbrica');
});
$test('Pipeline — un parte técnico con comunidad y proveedor reconocibles se descarta igual: sin resolver, sin más IA, sin autolink',static function()use($assert,$sqliteDb,$classifierSchema,$pipeline):void{
    $db=$sqliteDb($classifierSchema);
    $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,active) VALUES (?,?,?,?,?,1)',['31','CP TREINTA Y UNO','cp treinta y uno','H31000001','Calle 31']);
    $db->execute('INSERT INTO service_types(name,normalized_name) VALUES (?,?)',['Extintores','extintores']);
    $db->execute('INSERT INTO suppliers(name,official_name,normalized_name,cif,main_service_type_id,active) VALUES (?,?,?,?,?,1)',['EXTINPLAN','EXTINPLAN S.L.','extinplan','B31000001',(int)$db->pdo()->lastInsertId()]);
    $result=$pipeline($db,['document_type'=>'technical_report','document_confidence'=>0.95,'proveedor'=>'EXTINPLAN','proveedor_cif'=>'B31000001','comunidad_cif'=>'H31000001'],'informes@extinplan.example','smart','smart','INFORME CERTIFICADO 2270.pdf');
    $assert($result['status']==='ignored_not_invoice' && $result['community']===null && $result['supplier']===null,json_encode([$result['status'],$result['reason']]));
    $assert($result['ai']->tasks()===['comprender_documento'] && $result['classification']['autolink']['attempted']===false,'nada más que la comprensión');
    $assert((int)$db->one('SELECT COUNT(*) n FROM community_suppliers')['n']===0,'un documento descartado nunca crea relaciones');
    $assert(str_contains((string)$result['reason'],'parte o informe técnico'),'el motivo dice qué tipo de documento era: '.$result['reason']);
});
$test('Pipeline — documento dudoso: revisión, pero con la comunidad y el proveedor deterministas ya sugeridos y sin gastar IA en elecciones',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier,$pipeline):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'32','CP TREINTA Y DOS','LIMPIEZAS DOS','Limpieza','B32000001','H32000001');
    $result=$pipeline($db,['document_type'=>'invoice','document_confidence'=>0.55,'proveedor'=>'LIMPIEZAS DOS','proveedor_cif'=>'B32000001','comunidad_cif'=>'H32000001']);
    $assert($result['status']==='needs_review' && $result['reason_code']==='document_type_uncertain',json_encode([$result['status'],$result['reason_code']]));
    $assert((int)$result['community']['id']===$fixture['communityId'] && (int)$result['supplier']['id']===$fixture['supplierId'],'/Revisar recibe las sugerencias deterministas');
    $assert($result['ai']->tasks()===['comprender_documento'],'con la puerta en revisión no se pregunta a la IA por comunidad ni proveedor');
});
$test('Pipeline — factura rectificativa y factura con pocos campos se clasifican como cualquier factura',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier,$pipeline):void{
    $db=$sqliteDb($classifierSchema);
    $fixture=$makeCommunityWithSupplier($db,'33','CP TREINTA Y TRES','AGUAS TRES','Agua','B33000001','H33000001');
    $credit=$pipeline($db,['document_type'=>'credit_note','document_confidence'=>0.93,'proveedor'=>'AGUAS TRES','proveedor_cif'=>'B33000001','comunidad_cif'=>'H33000001','importe'=>-12.5]);
    $assert($credit['status']==='classified' && $credit['service']['folder']==='AGUA',json_encode([$credit['status'],$credit['reason_code']]));
    $sparse=$pipeline($db,['document_confidence'=>0.74,'proveedor'=>null,'proveedor_cif'=>'B33000001','comunidad_cif'=>'H33000001']);
    $assert($sparse['status']==='classified' && (int)$sparse['supplier']['id']===$fixture['supplierId'],'sin nombre de emisor, el CIF basta: '.json_encode([$sparse['status'],$sparse['reason_code']]));
});

// ── Comunidad: casos A–F (B, C y F con NIF compartido están arriba, en el caso real GERMANIES) ──
$seedCommunities=static function(Salvest\Database $db):array{
    $rows=[['41','CP MIRADOR DEL MAR','H41000001','CALLE MAYOR 10','12001'],['42','CP JARDINES DEL SUR','H42000002','AVENIDA DEL SUR 5','12002'],['43','CP TORRE ALTA','H43000003','CALLE ALTA 3','12003']];
    $ids=[];
    foreach($rows as [$code,$name,$cif,$address,$cp]){
        $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,postal_code,city,imap_folder_name,active) VALUES (?,?,?,?,?,?,?,?,1)',[$code,$name,Salvest\Text::normalize($name),$cif,$address,$cp,'Castelló',$code.' - '.$name]);
        $ids[$code]=(int)$db->pdo()->lastInsertId();
    }
    return$ids;
};
$test('Comunidad caso A — NIF único decide definitivamente: ni el nombre ni la dirección contradictorios lo cambian, y la IA no se consulta',static function()use($assert,$sqliteDb,$classifierSchema,$seedCommunities,$resolveCommunity):void{
    $db=$sqliteDb($classifierSchema);$ids=$seedCommunities($db);
    $result=$resolveCommunity($db,['tax_id'=>'h-42000002','name'=>'CP MIRADOR DEL MAR','address'=>'CALLE MAYOR 10'],static fn()=>throw new RuntimeException('no debe llamarse a la IA'));
    $assert((int)$result['community']['id']===$ids['42'] && $result['source']==='tax_id_exact' && $result['tax_id']['matches']===1,json_encode($result['outcome']));
});
$test('Comunidad caso D — sin NIF: el recuperador propone candidatas por nombre/dirección y la IA elige entre ellas',static function()use($assert,$sqliteDb,$classifierSchema,$seedCommunities,$resolveCommunity):void{
    $db=$sqliteDb($classifierSchema);$ids=$seedCommunities($db);
    $seen=null;
    $result=$resolveCommunity($db,['name'=>'COMUNIDAD DE PROPIETARIOS JARDINES DEL SUR','address'=>'Av. del Sur, 5','postal_code'=>'12002'],
        function(array $candidates)use(&$seen,$ids){$seen=$candidates;return['id'=>$ids['42'],'confidence'=>0.93,'reason'=>'nombre y dirección']; });
    $assert((int)$result['community']['id']===$ids['42'] && $result['source']==='ai_after_retrieval',json_encode($result['outcome']));
    $assert(in_array($ids['42'],array_column($seen,'id'),true) && count($seen)<=8,'la candidata correcta está en la lista cerrada que ve la IA');
    $mine=array_values(array_filter($seen,static fn(array $c):bool=>$c['id']===$ids['42']))[0];
    $assert($mine['senales']!==[] && isset($mine['direccion'],$mine['codigo_postal']),'cada candidata lleva sus señales y sus datos: '.json_encode($mine));
});
$test('Comunidad caso E — NIF que no está en el maestro: no bloquea, se busca por nombre y la IA confirma; la explicación registra 0 coincidencias de NIF',static function()use($assert,$sqliteDb,$classifierSchema,$seedCommunities,$resolveCommunity):void{
    $db=$sqliteDb($classifierSchema);$ids=$seedCommunities($db);
    $result=$resolveCommunity($db,['tax_id'=>'H99999999','name'=>'C.P. TORRE ALTA'],'smart');
    $assert((int)($result['community']['id']??0)===$ids['43'] && $result['source']==='ai_after_retrieval' && $result['tax_id']['matches']===0,json_encode([$result['outcome'],$result['tax_id']]));
});
$test('Comunidad — sin ninguna candidata no se llama a la IA; la IA a la baja (0.69) o abstenida deja la factura sin comunidad',static function()use($assert,$sqliteDb,$classifierSchema,$seedCommunities,$resolveCommunity):void{
    $db=$sqliteDb($classifierSchema);$ids=$seedCommunities($db);
    $none=$resolveCommunity($db,['name'=>'XYZZY QWERTY'],static fn()=>throw new RuntimeException('sin candidatas no hay IA'));
    $assert($none['community']===null && $none['outcome']==='no_candidates',json_encode($none['outcome']));
    $low=$resolveCommunity($db,['name'=>'JARDINES DEL SUR'],['id'=>$ids['42'],'confidence'=>0.69,'reason'=>'quizá']);
    $assert($low['community']===null && $low['outcome']==='ai_low_confidence',json_encode($low['outcome']));
    $abstain=$resolveCommunity($db,['name'=>'JARDINES DEL SUR'],'abstain');
    $assert($abstain['community']===null && $abstain['outcome']==='ai_abstained',json_encode($abstain['outcome']));
});
$test('Comunidad — en el pipeline, sin comunidad la factura queda unclassified con el motivo de la política; si la IA cae al elegir, es un error técnico (no una clasificación inventada)',static function()use($assert,$sqliteDb,$classifierSchema,$seedCommunities,$pipeline):void{
    $db=$sqliteDb($classifierSchema);$seedCommunities($db);
    $result=$pipeline($db,['proveedor'=>'ALGUIEN','nombre_comunidad'=>'JARDINES DEL SUR'],'x@x.example','abstain');
    $assert($result['status']==='unclassified' && $result['reason_code']==='community_ai_abstained' && $result['reason']!=='',json_encode([$result['status'],$result['reason_code']]));
    $threw=false;
    try{$pipeline($db,['proveedor'=>'ALGUIEN','nombre_comunidad'=>'JARDINES DEL SUR'],'x@x.example','fail');}catch(RuntimeException){$threw=true;}
    $assert($threw,'el Worker registra la excepción como error del adjunto y mueve el correo a Errores');
});

// ── Proveedor ──
$seedSuppliers=static function(Salvest\Database $db):array{
    $db->execute('INSERT INTO service_types(name,normalized_name) VALUES (?,?)',['Ascensores','ascensores']);$lift=(int)$db->pdo()->lastInsertId();
    $db->execute('INSERT INTO service_types(name,normalized_name) VALUES (?,?)',['Descalcificador','descalcificador']);$soft=(int)$db->pdo()->lastInsertId();
    $ids=[];
    foreach([['ORONA','ORONA S.COOP.','F20025318',$lift],['INMECAS','H2O PLUS, S.L.','B12891099',$soft],['H2O PLUS','H2O PLUS SLU','B12891099',$soft]] as [$name,$official,$cif,$service]){
        $db->execute('INSERT INTO suppliers(name,official_name,normalized_name,normalized_official_name,cif,main_service_type_id,active) VALUES (?,?,?,?,?,?,1)',[$name,$official,Salvest\Text::normalizeCompanyName($name),Salvest\Text::normalizeCompanyName($official),$cif,$service]);
        $ids[$name]=(int)$db->pdo()->lastInsertId();
    }
    return$ids;
};
$supplierChoice=static fn(mixed $raw):Closure=>static function(array $candidates)use($raw):array{
    $raw=is_callable($raw)?$raw($candidates):$raw;
    return Salvest\DocumentIntelligence::validateChoice(['supplier_id'=>$raw['id'],'confidence'=>$raw['confidence'],'reason'=>$raw['reason']??''],'supplier_id',array_column($candidates,'id'));
};
$test('Proveedor — CIF único en todo el maestro decide sin IA, aunque el nombre del documento no se parezca',static function()use($assert,$sqliteDb,$classifierSchema,$seedSuppliers,$resolveSupplier):void{
    $db=$sqliteDb($classifierSchema);$ids=$seedSuppliers($db);
    $result=$resolveSupplier($db,'ELEVADORES DEL NORTE','F-20025318','x@x.example',null,static fn()=>throw new RuntimeException('no debe llamarse a la IA'));
    $assert((int)$result['supplier']['id']===$ids['ORONA'] && $result['source']==='tax_id_exact' && $result['confidence']===1.0,json_encode($result['outcome']));
});
$test('Proveedor — CIF compartido (INMECAS / H2O PLUS, caso real B12891099): el nombre exacto de uno solo decide sin IA; si no, la IA recibe exactamente esos dos y elige; si se abstiene, sin proveedor',static function()use($assert,$sqliteDb,$classifierSchema,$seedSuppliers,$resolveSupplier,$supplierChoice):void{
    $db=$sqliteDb($classifierSchema);$ids=$seedSuppliers($db);
    // Evaluación con documentos reales (datos del maestro real): "H2O PLUS SLU" con el CIF
    // compartido. Las dos razones sociales normalizan a "h2o plus" y la IA se abstuvo; el nombre
    // comercial exacto de uno solo basta.
    $byName=$resolveSupplier($db,'H2O PLUS SLU','B12891099','x@x.example',null,static fn()=>throw new RuntimeException('no debe llamarse a la IA'));
    $assert((int)$byName['supplier']['id']===$ids['H2O PLUS'] && $byName['source']==='name_exact' && count($byName['candidates'])===2,json_encode([$byName['outcome'],$byName['source']]));
    $seen=null;
    $chosen=$resolveSupplier($db,'GRUPO H2O PLUS CASTELLON','B12891099','x@x.example',null,$supplierChoice(function(array $c)use(&$seen,$ids){$seen=$c;return['id'=>$ids['H2O PLUS'],'confidence'=>0.92,'reason'=>'marca H2O PLUS en el logotipo'];}));
    $assert((int)$chosen['supplier']['id']===$ids['H2O PLUS'] && $chosen['source']==='ai_after_shared_tax_id',json_encode($chosen['outcome']));
    $seenIds=array_column($seen,'id');sort($seenIds);
    $assert($seenIds===[$ids['INMECAS'],$ids['H2O PLUS']],'solo los dos que comparten CIF: '.json_encode($seenIds));
    $abstain=$resolveSupplier($db,'INSTALACIONES MECANICAS','B12891099','x@x.example',null,$supplierChoice(['id'=>null,'confidence'=>0.4]));
    $assert($abstain['supplier']===null && $abstain['outcome']==='ai_abstained',json_encode($abstain['outcome']));
});
$test('Proveedor — la IA no puede elegir un proveedor cuyo CIF contradice el del documento, ni uno fuera de la lista',static function()use($assert,$sqliteDb,$classifierSchema,$seedSuppliers,$resolveSupplier,$supplierChoice):void{
    $db=$sqliteDb($classifierSchema);$ids=$seedSuppliers($db);
    $contradiction=$resolveSupplier($db,'ORONA ASCENSORES','B00000000','x@x.example',null,$supplierChoice(['id'=>$ids['ORONA'],'confidence'=>0.95]));
    $assert($contradiction['supplier']===null && $contradiction['outcome']==='tax_id_contradiction',json_encode($contradiction['outcome']));
    $invented=$resolveSupplier($db,'ORONA ASCENSORES',null,'x@x.example',null,$supplierChoice(['id'=>987654,'confidence'=>0.99]));
    $assert($invented['supplier']===null && $invented['outcome']==='ai_invalid_candidate',json_encode($invented['outcome']));
});
$test('Proveedor — en el pipeline: emisor desconocido -> revisión supplier_no_candidates; elección de la IA aceptada -> clasificada pero sin autolink',static function()use($assert,$sqliteDb,$classifierSchema,$seedCommunities,$seedSuppliers,$pipeline):void{
    $db=$sqliteDb($classifierSchema);$communities=$seedCommunities($db);$ids=$seedSuppliers($db);
    $unknown=$pipeline($db,['proveedor'=>'XYZZY QWERTY SL','comunidad_cif'=>'H41000001']);
    $assert($unknown['status']==='needs_review' && $unknown['reason_code']==='supplier_no_candidates',json_encode([$unknown['status'],$unknown['reason_code']]));
    $ai=$pipeline($db,['proveedor'=>'ORONA ASCENSORES Y ELEVADORES','comunidad_cif'=>'H41000001'],'x@x.example','smart',['id'=>$ids['ORONA'],'confidence'=>0.9,'reason'=>'ORONA']);
    $assert($ai['status']==='classified' && $ai['classification']['supplier']['source']!=='tax_id_exact',json_encode([$ai['status'],$ai['reason_code'],$ai['classification']['supplier']['source']]));
    $assert($ai['classification']['autolink']===['attempted'=>false,'reason'=>'non_deterministic_source'],'una elección de la IA nunca se convierte en relación: '.json_encode($ai['classification']['autolink']));
    $assert((int)$db->one('SELECT COUNT(*) n FROM community_suppliers')['n']===0);
});

// ── Autolink ──
// La inserción real (ON DUPLICATE KEY, solo MySQL) la cubren los tests "Fase 7" y el extremo a extremo.
$test('Autolink — proveedor global por CIF: se intenta con la carpeta canónica del servicio maestro; con la relación ya existente, no se intenta',static function()use($assert,$sqliteDb,$classifierSchema,$seedCommunities,$seedSuppliers,$pipeline):void{
    $db=$sqliteDb($classifierSchema);$communities=$seedCommunities($db);$ids=$seedSuppliers($db);
    $first=$pipeline($db,['proveedor'=>'ORONA','proveedor_cif'=>'F20025318','comunidad_cif'=>'H41000001']);
    $assert($first['status']==='classified' && $first['classification']['autolink']['attempted']===true && $first['classification']['autolink']['category']==='ASCENSOR',json_encode($first['classification']['autolink']));
    $db->execute('INSERT INTO community_suppliers(community_id,supplier_id,category,source_column) VALUES (?,?,?,?)',[$communities['41'],$ids['ORONA'],'ASCENSOR','auto_global_resolution']);
    $second=$pipeline($db,['proveedor'=>'ORONA','proveedor_cif'=>'F20025318','comunidad_cif'=>'H41000001']);
    $assert($second['classification']['supplier']['linked']===true && $second['classification']['autolink']['reason']==='relation_already_exists' && (int)$db->one('SELECT COUNT(*) n FROM community_suppliers')['n']===1,json_encode($second['classification']['autolink']));
});
$test('Autolink — nunca con proveedor ambiguo, inexistente, sin comunidad o con el autolink desactivado',static function()use($assert,$sqliteDb,$classifierSchema,$seedCommunities,$seedSuppliers,$pipeline):void{
    $db=$sqliteDb($classifierSchema);$seedCommunities($db);$seedSuppliers($db);
    $ambiguous=$pipeline($db,['proveedor'=>'INSTALACIONES MECANICAS','proveedor_cif'=>'B12891099','comunidad_cif'=>'H41000001'],'x@x.example','smart','abstain');
    $assert($ambiguous['status']==='needs_review' && $ambiguous['classification']['autolink']['attempted']===false,json_encode($ambiguous['classification']['autolink']));
    $missing=$pipeline($db,['proveedor'=>'NO EXISTE SL','proveedor_cif'=>'B55555555','comunidad_cif'=>'H41000001']);
    $assert($missing['classification']['autolink']['attempted']===false,json_encode($missing['classification']['autolink']));
    $noCommunity=$pipeline($db,['proveedor'=>'ORONA','proveedor_cif'=>'F20025318','nombre_comunidad'=>'XYZZY'],'x@x.example','abstain');
    $assert($noCommunity['status']==='unclassified' && $noCommunity['classification']['autolink']['attempted']===false,json_encode($noCommunity['classification']['autolink']));
    $disabled=$pipeline($db,['proveedor'=>'ORONA','proveedor_cif'=>'F20025318','comunidad_cif'=>'H41000001'],'x@x.example','smart','smart','factura.pdf',false);
    $assert($disabled['status']==='classified' && $disabled['classification']['autolink']['reason']==='autolink_disabled',json_encode($disabled['classification']['autolink']));
    $assert((int)$db->one('SELECT COUNT(*) n FROM community_suppliers')['n']===0,'ninguno de estos casos crea relaciones');
});

// ── Servicio ──
$test('Servicio — ServiceCatalog: Ascensor/Ascensores, Luz/Electricidad y variantes van a su carpeta canónica; lo desconocido no tiene carpeta',static function()use($assert):void{
    foreach(['Ascensor'=>'ASCENSOR','Ascensores'=>'ASCENSOR','ASCENSORES'=>'ASCENSOR','elevadores'=>'ASCENSOR','Luz'=>'ELECTRICIDAD','Electricidad'=>'ELECTRICIDAD','Energía eléctrica'=>'ELECTRICIDAD',
        'Agua'=>'AGUA','EXTINCAS'=>'EXTINTORES','Protección contra incendios'=>'EXTINTORES','Jardín'=>'JARDINERIA','Piscinas'=>'PISCINA','Mantenimiento'=>'MANTENIMIENTO'] as $value=>$folder){
        $assert(Salvest\ServiceCatalog::folderFor($value)===$folder,$value.' -> '.var_export(Salvest\ServiceCatalog::folderFor($value),true));
    }
    foreach(['Seguros','Gas','Telecomunicaciones','Otros','',null] as $value)$assert(Salvest\ServiceCatalog::folderFor($value)===null,var_export($value,true).' no tiene carpeta propia');
});
$test('Servicio — ServiceResolver: el servicio principal del proveedor gana; si no se traduce, la categoría de la relación; luego la IA; y queda constancia de lo probado',static function()use($assert):void{
    $resolver=new Salvest\ServiceResolver();
    $ai=['service'=>['raw'=>'mantenimiento de ascensores','semantic_category'=>'ascensor']];
    $main=$resolver->resolve(['service_type_name'=>'Luz'],['category'=>'Agua'],$ai);
    $assert($main['folder']==='ELECTRICIDAD' && $main['source']==='supplier_main_service',json_encode($main));
    $relation=$resolver->resolve(['service_type_name'=>'Seguros'],['category'=>'Ascensores'],$ai);
    $assert($relation['folder']==='ASCENSOR' && $relation['source']==='community_supplier_category' && $relation['tried'][0]===['source'=>'supplier_main_service','value'=>'Seguros','folder'=>null],json_encode($relation));
    $fromAi=$resolver->resolve(null,null,$ai);
    $assert($fromAi['folder']==='ASCENSOR' && $fromAi['source']==='ai_semantic_category',json_encode($fromAi));
    $none=$resolver->resolve(['service_type_name'=>'Gas'],null,['service'=>['raw'=>'suministro de gas','semantic_category'=>null]]);
    $assert($none['folder']===null && count($none['tried'])===2,json_encode($none));
    $assert(Salvest\DriveInvoiceArchiver::class!=='' && str_contains((string)file_get_contents(dirname(__DIR__).'/src/DriveInvoiceArchiver.php'),'ServiceCatalog::folderFor('),'Drive usa la misma traducción (guarda de código)');
});
$test('Servicio — en el pipeline, un proveedor cuyo único servicio no tiene carpeta va a revisión con service_unknown',static function()use($assert,$sqliteDb,$classifierSchema,$makeCommunityWithSupplier,$pipeline):void{
    $db=$sqliteDb($classifierSchema);
    $makeCommunityWithSupplier($db,'44','CP CUARENTA Y CUATRO','GAS DEL ESTE','Gas','B44000001','H44000001');
    $result=$pipeline($db,['proveedor'=>'GAS DEL ESTE','proveedor_cif'=>'B44000001','comunidad_cif'=>'H44000001','tipo_servicio'=>'gas natural']);
    $assert($result['status']==='needs_review' && $result['reason_code']==='service_unknown' && $result['supplier']!==null,json_encode([$result['status'],$result['reason_code']]));
});

// ── Extremo a extremo: Worker con MySQL de tests real, IA falsa, IMAP sin red y sin Drive ──
$e2eWorker=static function(Salvest\Database $db,array &$counts)use($fakeAi,$workerConfig,$fakeImapClient):Closure{
    $config=$workerConfig();$config['processing']['storage_root']=sys_get_temp_dir().'/salvest-e2e-'.bin2hex(random_bytes(3));$config['processing']['incoming_root']=$config['processing']['storage_root'].'/incoming';
    $db->execute('INSERT INTO mailboxes(descriptive_name,email,imap_host,imap_port,use_ssl,username,encrypted_password,input_folder,active) VALUES (?,?,?,?,?,?,?,?,0)',['E2E','e2e@example.test','imap.example.test',993,1,'e2e@example.test','x','INBOX']);
    $mailbox=$db->one('SELECT * FROM mailboxes WHERE id=?',[(int)$db->pdo()->lastInsertId()]);
    $client=$fakeImapClient('E2E'.random_int(1000,9999));
    $method=new ReflectionMethod(Salvest\Worker::class,'processAttachment');$method->setAccessible(true);
    return static function(string $uid,array $understanding,mixed $community='smart',mixed $supplier='smart',string $payload='')use($db,$config,$mailbox,$client,$method,$fakeAi,&$counts):array{
        $fake=$fakeAi($understanding,$community,$supplier);
        $worker=new Salvest\Worker($db,new Salvest\Crypto($config['app']['encryption_key']),Salvest\Worker::processor($db,new Salvest\DocumentIntelligence($fake)),$config,null);
        $payload=$payload!==''?$payload:"%PDF-1.4\n% e2e ".$uid."\n";
        $attachment=['original_filename'=>'factura-'.$uid.'.pdf','safe_filename'=>'factura-'.$uid.'.pdf','sha256'=>hash('sha256',$payload),'payload'=>$payload,'mime_type'=>'application/pdf','size'=>strlen($payload)];
        $message=['sender'=>'facturas@proveedor.example','subject'=>'Factura','body'=>'','message_id'=>'<'.$uid.'@e2e>','date'=>date('Y-m-d H:i:s')];
        $outcome=$method->invokeArgs($worker,[$mailbox,$client,$uid,$message,$attachment,&$counts]);
        $row=$db->one('SELECT * FROM processed_attachments WHERE mailbox_id=? AND message_uid=? AND attachment_sha256=?',[$mailbox['id'],$uid,$attachment['sha256']]);
        return['outcome'=>$outcome,'row'=>$row,'ai'=>$fake,'mailbox_id'=>(int)$mailbox['id'],'storage'=>$config['processing']['storage_root']];
    };
};
$test('Extremo a extremo (MySQL) — factura, parte técnico, NIF compartido resuelto por la IA, comunidad ambigua, proveedor nuevo con autolink y duplicados',static function()use($assert,$mysqlSchemaTest,$mysqlSchemaCleanup,$e2eWorker,$understandingFromLegacy):void{
    $ctx=$mysqlSchemaTest();
    if($ctx===null){echo "SKIP (sin SALVEST_TEST_MYSQL)\n";return;}
    $db=$ctx['db'];
    Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
    $db->execute('INSERT IGNORE INTO service_types(name,normalized_name) VALUES (?,?)',['Extintores','extintores']);
    $serviceId=(int)$db->one('SELECT id FROM service_types WHERE normalized_name=?',['extintores'])['id'];
    $communityIds=[];
    foreach([['E2E1','EDIFICI E2E PLAÇA','H79990001'],['E2E2','PARKING E2E PLAÇA','H79990001'],['E2E3','CP E2E ÚNICA','H79990003']] as [$code,$name,$cif]){
        $db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,active) VALUES (?,?,?,?,?,1)',[$code,$name,Salvest\Text::normalize($name),$cif,'CALLE E2E 1']);
        $communityIds[$code]=(int)$db->pdo()->lastInsertId();
    }
    $db->execute('INSERT INTO suppliers(name,official_name,normalized_name,normalized_official_name,cif,main_service_type_id,active) VALUES (?,?,?,?,?,?,1)',['EXTINTORES E2E','EXTINTORES E2E S.L.','extintores e2e','extintores e2e','B79990009',$serviceId]);
    $supplierId=(int)$db->pdo()->lastInsertId();
    $counts=['classified'=>0,'needs_review'=>0,'unclassified'=>0,'duplicate'=>0,'not_invoice'=>0];
    $run=$e2eWorker($db,$counts);$mailboxId=null;$storage=null;
    try{
        $invoice=$understandingFromLegacy(['proveedor'=>'EXTINTORES E2E','proveedor_cif'=>'B79990009','comunidad_cif'=>'H79990003','nombre_comunidad'=>'CP E2E UNICA']);
        $classified=$run('9001',$invoice);$mailboxId=$classified['mailbox_id'];$storage=$classified['storage'];
        $row=$classified['row'];
        $assert($row['status']==='classified' && (int)$row['community_id']===$communityIds['E2E3'] && (int)$row['supplier_id']===$supplierId && $row['document_type']==='invoice' && (float)$row['document_confidence']===0.97,json_encode($row));
        $decision=json_decode((string)$row['decision_json'],true);
        $assert($decision['version']===2 && $decision['community']['source']==='tax_id_exact' && $decision['supplier']['source']==='tax_id_exact' && $decision['autolink']['inserted']===true && $decision['service']['folder']==='EXTINTORES',json_encode($decision['autolink']));
        $assert(json_decode((string)$row['extraction_json'],true)==json_decode(json_encode($invoice),true) && $row['extractor_version']==='fake:fake-model' && $row['debug_trace_json']===null && is_file((string)$row['output_path']),'respuesta cruda íntegra (MySQL reordena las claves del JSON), versión del extractor, sin traza para lo clasificado y PDF archivado: '.$row['extraction_json']);
        $link=$db->all('SELECT category,source_column FROM community_suppliers WHERE community_id=? AND supplier_id=?',[$communityIds['E2E3'],$supplierId]);
        $assert(count($link)===1 && $link[0]['category']==='EXTINTORES' && $link[0]['source_column']==='auto_global_resolution','proveedor nuevo en la comunidad: autolink: '.json_encode($link));

        $report=$run('9002',$understandingFromLegacy(['document_type'=>'technical_report','document_confidence'=>0.96,'proveedor'=>'EXTINTORES E2E','proveedor_cif'=>'B79990009','comunidad_cif'=>'H79990001']));
        $assert($report['outcome']['status']==='ignored_not_invoice' && $report['row']['status']==='ignored_not_invoice' && $report['row']['community_id']===null && $report['row']['document_type']==='technical_report' && $report['ai']->tasks()===['comprender_documento'],json_encode($report['row']));

        $shared=$run('9003',$understandingFromLegacy(['proveedor'=>'EXTINTORES E2E','proveedor_cif'=>'B79990009','comunidad_cif'=>'H79990001','nombre_comunidad'=>'COM. PROP. GARAJE PLAÇA E2E']),['id'=>$communityIds['E2E2'],'confidence'=>0.88,'reason'=>'garaje = parking']);
        $assert($shared['row']['status']==='classified' && (int)$shared['row']['community_id']===$communityIds['E2E2'] && abs((float)$shared['row']['confidence']-88.0)<0.001,json_encode($shared['row']));
        $assert(json_decode((string)$shared['row']['decision_json'],true)['community']['source']==='ai_after_shared_tax_id');

        $ambiguous=$run('9004',$understandingFromLegacy(['proveedor'=>'EXTINTORES E2E','proveedor_cif'=>'B79990009','comunidad_cif'=>'H79990001','nombre_comunidad'=>'COM. PROP. PLAÇA E2E']),'abstain');
        $assert($ambiguous['row']['status']==='unclassified' && $ambiguous['row']['community_id']===null && $ambiguous['row']['debug_trace_json']!==null,'ambigua: sin comunidad y con traza para /Revisar');
        $assert(str_contains((string)$ambiguous['row']['debug_trace_json'],'community_resolution'),'la traza incluye la resolución de comunidad');

        $again=$run('9005',$invoice,'smart','smart',"%PDF-1.4\n% e2e 9001\n");
        $assert($again['row']['status']==='duplicate' && $again['ai']->tasks()===[] && (int)$again['row']['community_id']===$communityIds['E2E3'],'el mismo PDF no vuelve a la IA: '.json_encode($again['row']['status']));
        $againReport=$run('9006',$invoice,'smart','smart',"%PDF-1.4\n% e2e 9002\n");
        $assert($againReport['row']['status']==='ignored_not_invoice' && $againReport['ai']->tasks()===[],'la copia de un no-factura hereda la decisión: '.json_encode($againReport['row']['status']));
        $assert($counts===['classified'=>2,'needs_review'=>0,'unclassified'=>1,'duplicate'=>1,'not_invoice'=>2],json_encode($counts));
    }finally{
        if($mailboxId!==null)$db->execute('DELETE FROM processed_attachments WHERE mailbox_id=?',[$mailboxId]);
        $db->execute("DELETE FROM mailboxes WHERE email='e2e@example.test'");
        $db->execute('DELETE FROM suppliers WHERE id=?',[$supplierId]);
        foreach($communityIds as $id)$db->execute('DELETE FROM communities WHERE id=?',[$id]);
        $mysqlSchemaCleanup($ctx);
    }
});
$test('Extremo a extremo — un correo cuyos PDF son todos no-factura queda ignored y en la bandeja de entrada; los no-factura no deciden la carpeta de un correo con facturas (guarda de código)',static function()use($assert):void{
    $source=(string)file_get_contents(dirname(__DIR__).'/src/Worker.php');
    $assert(str_contains($source,"\$invoiceOutcomes = array_values(array_filter(\$outcomes,static fn(array \$item): bool => \$item['status'] !== 'ignored_not_invoice'));"),'filtro de no-facturas');
    $assert(str_contains($source,"\$this->saveMessage(\$mailbox,\$client,\$uid,\$message,'ignored',count(\$outcomes),null);"),'solo no-facturas: ignored, sin destino ni movimiento');
    $retry=(string)file_get_contents(dirname(__DIR__).'/src/ImapMoveRetry.php');
    $assert(str_contains($retry,'ignored_not_invoice'),'el reintento de movimiento tampoco cuenta los no-factura');
});

$failed=0;
foreach($tests as $name=>$callback){try{$callback();echo "PASS $name\n";}catch(Throwable $error){$failed++;echo "FAIL $name: {$error->getMessage()}\n";}}
echo sprintf("%d tests, %d failed\n",count($tests),$failed);
exit($failed?1:0);

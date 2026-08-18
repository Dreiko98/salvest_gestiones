<?php
declare(strict_types=1);

namespace Salvest;

final class WebApp
{
    private Auth $auth;
    private Crypto $crypto;
    /** @param array<string,mixed> $config */
    public function __construct(private Database $db, private array $config)
    {
        $this->auth = new Auth($db,$config['app']); $this->crypto = new Crypto($config['app']['encryption_key']);
    }

    public function run(): void
    {
        $path = trim((string)($_GET['route'] ?? (parse_url($_SERVER['REQUEST_URI'] ?? '/',PHP_URL_PATH) ?: '/')),'/');
        if ($path === 'health') { header('Content-Type: application/json'); echo json_encode(['status'=>'ok']); return; }
        if ($path === 'logout') { $this->auth->logout(); $this->redirect('/'); }
        if (!$this->auth->userId()) { $this->login(); return; }
        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') $this->auth->verifyCsrf((string)($_POST['csrf'] ?? ''));
            match (true) {
                $path === '', $path === 'index.php' => $this->dashboard(),
                $path === 'communities' => $this->communities(),
                $path === 'suppliers' => $this->suppliers(),
                $path === 'mailboxes' => $this->mailboxes(),
                $path === 'reviews' => $this->reviews(),
                $path === 'storage' => $this->storage(),
                $path === 'storage-children' => $this->storageChildren(),
                $path === 'run-worker' => $this->runWorker(),
                $path === 'download' => $this->download((int)($_GET['id'] ?? 0)),
                str_starts_with($path,'download/') => $this->download((int)basename($path)),
                default => $this->notFound(),
            };
        } catch (\Throwable $error) { $this->page('Error','<section class="card error">'.$this->e($error->getMessage()).'</section>'); }
    }

    private function login(): void
    {
        $error='';
        if ($_SERVER['REQUEST_METHOD']==='POST') {
            try { $this->auth->verifyCsrf((string)($_POST['csrf']??'')); }
            catch (\Throwable $exception) { $error=$exception->getMessage(); }
            try {
                if ($error==='' && $this->auth->login((string)($_POST['username']??''),(string)($_POST['password']??''))) $this->redirect('/');
            } catch (\Throwable $exception) { $error=$exception->getMessage(); }
            if ($error==='') $error='Usuario o contraseña incorrectos.';
        }
        $body='<section class="login-wrap"><img class="login-logo" src="/assets/logoSalvest.png" alt="Salvest"><div class="card login"><div class="eyebrow">Panel de administración</div><h1>Gestión de facturas</h1><p class="muted">Accede para revisar y organizar la documentación de las comunidades.</p>'.($error?'<p class="error">'.$this->e($error).'</p>':'').
            '<form method="post"><input type="hidden" name="csrf" value="'.$this->auth->csrf().'"><label>Usuario<input name="username" autocomplete="username" required autofocus></label><label>Contraseña<input type="password" name="password" autocomplete="current-password" required></label><button>Entrar al panel</button></form></div></section>';
        $this->page('Acceso',$body,false);
    }

    private function dashboard(): void
    {
        $classified=(int)$this->db->one("SELECT COUNT(*) n FROM processed_attachments WHERE status='classified' AND DATE(processed_at)=CURDATE()")['n'];
        $communities=(int)$this->db->one('SELECT COUNT(*) n FROM communities WHERE active=1')['n'];
        $suppliers=(int)$this->db->one('SELECT COUNT(*) n FROM suppliers WHERE active=1')['n'];
        $attention=(int)$this->db->one("SELECT COUNT(*) n FROM processed_attachments WHERE status IN ('unclassified','needs_review','error')")['n'];
        $status=$attention?'<section class="status warning"><div><span class="status-ring"><i></i></span><span><strong>'.$attention.' facturas pendientes de revisar</strong><small>Comprueba la comunidad y el proveedor antes de archivarlas.</small></span></div><a class="button" href="/?route=reviews">Revisar facturas</a></section>'
            :'<section class="status ok"><span class="status-ring"><i></i></span><span><strong>Todo está al día</strong><small>No hay facturas pendientes de revisar.</small></span></section>';
        $this->page('Inicio','<div class="page-heading"><div><span class="eyebrow">Resumen de hoy</span><h1>Gestión de facturas</h1><p>El sistema revisa y archiva automáticamente las facturas recibidas.</p></div></div>'.$status.
            '<div class="metrics"><article><span class="metric-label">Archivadas hoy</span><strong>'.$classified.'</strong></article><article><span class="metric-label">Comunidades activas</span><strong>'.$communities.'</strong></article><article><span class="metric-label">Proveedores activos</span><strong>'.$suppliers.'</strong></article></div>'.
            $this->botStatusCard());
    }

    private function botStatusCard(): string
    {
        $runningNow=(bool)$this->db->one("SELECT 1 ok FROM processing_runs WHERE status='running' ORDER BY id DESC LIMIT 1");
        $lastRun=$this->db->one('SELECT * FROM processing_runs WHERE finished_at IS NOT NULL ORDER BY id DESC LIMIT 1');
        $badge=$runningNow?'<span class="badge warning">En ejecución</span>':'<span class="badge success">En reposo</span>';
        $when=$lastRun?$this->formatRunTime((string)$lastRun['finished_at']):null;
        $lastLine=$when?'<p class="bot-status-line">Última ejecución: <strong>'.$this->e($when).'</strong></p>':'<p class="bot-status-line">Todavía no se ha ejecutado.</p>';
        $resultLine='';
        if($lastRun){
            $archivadas=(int)$lastRun['classified_count'];$pendientes=(int)$lastRun['needs_review_count'];$errores=(int)$lastRun['error_count'];
            $resultLine='<p class="bot-status-line">Resultado: <strong>'.$archivadas.' '.($archivadas===1?'archivada':'archivadas').' · '.$pendientes.' '.($pendientes===1?'pendiente':'pendientes').' · '.$errores.' '.($errores===1?'error':'errores').'</strong></p>';
        }
        $label=$runningNow?'Bot ejecutándose…':'Ejecutar bot ahora';
        $button='<button type="button" class="button" id="run-worker-btn" data-run-worker data-csrf="'.$this->auth->csrf().'" data-idle-label="Ejecutar bot ahora" data-busy-label="Bot ejecutándose…"'.($runningNow?' disabled':'').'>'.$this->e($label).'</button>';
        return '<section class="card bot-status"><div class="section-heading flat"><div><span class="eyebrow">Automatización</span><h2>Estado del bot</h2></div>'.$badge.'</div>'.$lastLine.$resultLine.
            '<div class="bot-actions">'.$button.'<p class="bot-message" id="run-worker-message" role="status" hidden></p></div></section>';
    }

    private function formatRunTime(string $datetime): string
    {
        try { $date=new \DateTimeImmutable($datetime); } catch (\Throwable) { return '—'; }
        $time=$date->format('H:i');
        $today=new \DateTimeImmutable('today');
        if ($date >= $today) return 'hoy '.$time;
        if ($date >= $today->modify('-1 day')) return 'ayer '.$time;
        return $date->format('d/m/Y').' '.$time;
    }

    /** Runs the exact same Worker used by the cron endpoint, triggered from the dashboard button. */
    private function runWorker(): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['status'=>'error','message'=>'Método no permitido']); return; }
        try {
            $counts = Worker::create($this->db,$this->config)->run(false,(int)$this->config['imap']['max_messages_per_mailbox'],null,'manual',$this->auth->userId());
            $this->audit('run','worker','manual',$counts);
            echo json_encode(['status'=>'ok','summary'=>[
                'classified'=>$counts['classified'],'needs_review'=>$counts['needs_review'],'errors'=>$counts['errors'],
            ]],JSON_UNESCAPED_UNICODE);
        } catch (WorkerBusyException) {
            http_response_code(409);
            echo json_encode(['status'=>'busy','message'=>'El bot ya se está ejecutando. Espera a que termine.']);
        } catch (\Throwable $error) {
            error_log('run_worker status=error '.$error->getMessage());
            http_response_code(500);
            echo json_encode(['status'=>'error','message'=>'No se pudo completar la ejecución. Inténtalo de nuevo en unos minutos.']);
        }
    }

    private function communities(): void
    {
        if ($_SERVER['REQUEST_METHOD']==='POST') {
            if(($_POST['action']??'')==='archive'){
                $id=(int)$_POST['id'];$this->db->execute('UPDATE communities SET active=0 WHERE id=?',[$id]);$this->audit('archive','community',$id);$this->redirect('/?route=communities');
            }
            if(($_POST['action']??'')==='delete'){
                $this->requireDeleteConfirmation();
                $id=(int)$_POST['id'];$row=$this->db->one('SELECT external_code,official_name FROM communities WHERE id=?',[$id]);
                if(!$row)throw new \RuntimeException('Comunidad no encontrada');
                $pdo=$this->db->pdo();$pdo->beginTransaction();try{$this->audit('delete','community',$id,$row);$this->db->execute('DELETE FROM communities WHERE id=?',[$id]);$pdo->commit();}catch(\Throwable$error){if($pdo->inTransaction())$pdo->rollBack();throw$error;}$this->redirect('/?route=communities');
            }
            $id=(int)($_POST['id']??0); $updating=$id>0; $code=CommunityCsvImporter::code((string)($_POST['external_code']??''));$values=[$code,trim((string)$_POST['official_name']),Text::normalize((string)$_POST['official_name']),trim((string)($_POST['cif']??'')),trim((string)$_POST['main_address']),trim((string)($_POST['postal_code']??'')),trim((string)($_POST['city']??'')),$code.' - '.trim((string)$_POST['official_name']),1];
            if ($id) $this->db->execute('UPDATE communities SET external_code=?,official_name=?,normalized_name=?,cif=?,main_address=?,postal_code=?,city=?,imap_folder_name=?,active=? WHERE id=?',[...$values,$id]);
            else {$this->db->execute('INSERT INTO communities(external_code,official_name,normalized_name,cif,main_address,postal_code,city,imap_folder_name,active) VALUES (?,?,?,?,?,?,?,?,?)',$values);$id=(int)$this->db->pdo()->lastInsertId();}
            $this->replaceRelated('community_aliases','community_id',$id,'address',(string)($_POST['address_aliases']??''));
            $this->replaceIdentifiers($id,(string)($_POST['identifiers']??''));
            $this->audit($updating ? 'update' : 'create','community',$id);
            $this->redirect('/?route=communities');
        }
        $edit=isset($_GET['edit'])?$this->db->one('SELECT * FROM communities WHERE id=?',[(int)$_GET['edit']]):null;
        $rows=$this->db->all('SELECT * FROM communities ORDER BY active DESC,official_name'); $table='';
        foreach($rows as $row){
            $initial=mb_strtoupper(mb_substr(trim((string)$row['official_name']),0,1));
            $detailId='community-detail-'.$row['id'];
            $entity='<div class="entity"><span class="entity-mark'.($row['active']?'':' inactive').'">'.$this->e($initial).'<i></i></span><strong>'.$this->e($row['official_name']).'</strong></div>';
            $table.='<tr class="row-summary"><td class="mono code-cell">'.RowDetail::toggle($detailId,$this->e($row['external_code']?:'—')).'</td><td>'.$entity.'</td><td class="secondary">'.$this->e($row['main_address']).'</td><td><span class="badge '.($row['active']?'success':'neutral').'">'.($row['active']?'Activa':'Desactivada').'</span></td><td class="actions"><a href="/?route=communities&edit='.$row['id'].'">Editar</a>'.($row['active']?'<form class="inline" method="post" action="/?route=communities"><input type="hidden" name="csrf" value="'.$this->auth->csrf().'"><input type="hidden" name="action" value="archive"><input type="hidden" name="id" value="'.$row['id'].'"><button class="button-quiet">Desactivar</button></form>':'').$this->deleteForm('communities',(int)$row['id'],'la comunidad «'.(string)$row['official_name'].'»').'</td></tr>';
            $table.=RowDetail::row($detailId,[
                ['Código',(string)($row['external_code']??'')],
                ['Nombre oficial',(string)($row['official_name']??'')],
                ['CIF',(string)($row['cif']??'')],
                ['Dirección principal',(string)($row['main_address']??'')],
                ['Código postal',(string)($row['postal_code']??'')],
                ['Ciudad',(string)($row['city']??'')],
                ['Estado',$row['active']?'Activa':'Desactivada'],
            ],5);
        }
        $addressAliases=$edit?$this->relatedText('community_aliases','community_id',(int)$edit['id']):'';
        $identifiers=$edit?$this->identifierText((int)$edit['id']):'';
        $form='<form method="post" action="/?route=communities" class="card grid form-card"><input type="hidden" name="csrf" value="'.$this->auth->csrf().'"><input type="hidden" name="id" value="'.$this->e($edit['id']??'').'"><input type="hidden" name="address_aliases" value="'.$this->e($addressAliases).'"><input type="hidden" name="identifiers" value="'.$this->e($identifiers).'"><div class="form-heading wide"><span class="eyebrow">Datos de clasificación</span><h2>'.($edit?'Editar comunidad':'Nueva comunidad').'</h2></div><label>Código<input class="mono" name="external_code" value="'.$this->e($edit['external_code']??'').'" required></label><label>Nombre oficial<input name="official_name" value="'.$this->e($edit['official_name']??'').'" required></label><label>CIF<input class="mono" name="cif" value="'.$this->e($edit['cif']??'').'"></label><label class="wide">Dirección principal<input name="main_address" value="'.$this->e($edit['main_address']??'').'" required></label><label>Código postal<input class="mono" name="postal_code" value="'.$this->e($edit['postal_code']??'').'"></label><label>Ciudad<input name="city" value="'.$this->e($edit['city']??'').'"></label><div class="form-actions wide"><button>Guardar cambios</button><a class="button button-secondary" href="/?route=communities">Cancelar</a></div></form>';
        $list=$table!==''?'<div class="table-wrap"><table><thead><tr><th>Código</th><th>Comunidad</th><th>Dirección</th><th>Estado</th><th><span class="sr-only">Acciones</span></th></tr></thead><tbody>'.$table.'</tbody></table></div>':'<section class="empty-state"><span class="empty-ring"><i></i></span><h2>Todavía no hay comunidades</h2><p>Añade la primera para empezar a clasificar facturas.</p></section>';
        $panel=$this->disclosurePanel($edit?'+ Editar comunidad':'+ Nueva comunidad',$form,(bool)$edit);
        $this->page('Comunidades','<div class="page-heading"><div><span class="eyebrow">Configuración</span><h1>Comunidades</h1><p>Consulta las comunidades dadas de alta y modifica solo cuando haga falta.</p></div><span class="count-badge">'.count($rows).' registradas</span></div>'.$panel.'<section class="card list-card">'.$list.'</section>');
    }

    private function suppliers(): void
    {
        if ($_SERVER['REQUEST_METHOD']==='POST') {
            if(($_POST['action']??'')==='archive'){$id=(int)$_POST['id'];$this->db->execute('UPDATE suppliers SET active=0 WHERE id=?',[$id]);$this->audit('archive','supplier',$id);$this->redirect('/?route=suppliers');}
            if(($_POST['action']??'')==='delete'){$this->requireDeleteConfirmation();$id=(int)$_POST['id'];$row=$this->db->one('SELECT official_name FROM suppliers WHERE id=?',[$id]);if(!$row)throw new \RuntimeException('Proveedor no encontrado');$pdo=$this->db->pdo();$pdo->beginTransaction();try{$this->audit('delete','supplier',$id,$row);$this->db->execute('DELETE FROM suppliers WHERE id=?',[$id]);$pdo->commit();}catch(\Throwable$error){if($pdo->inTransaction())$pdo->rollBack();throw$error;}$this->redirect('/?route=suppliers');}
            $id=(int)($_POST['id']??0);$values=[trim((string)$_POST['official_name']),Text::normalize((string)$_POST['official_name']),trim((string)($_POST['cif']??'')),(int)$_POST['service_id']?:null];
            if($id)$this->db->execute('UPDATE suppliers SET official_name=?,normalized_name=?,cif=?,main_service_type_id=?,active=1 WHERE id=?',[...$values,$id]);
            else{$this->db->execute('INSERT INTO suppliers(official_name,normalized_name,cif,main_service_type_id,active) VALUES (?,?,?,?,1)',$values);$id=(int)$this->db->pdo()->lastInsertId();}
            $this->db->execute('DELETE FROM supplier_service_types WHERE supplier_id=?',[$id]);if((int)$_POST['service_id'])$this->db->execute('INSERT INTO supplier_service_types(supplier_id,service_type_id) VALUES (?,?)',[$id,(int)$_POST['service_id']]);
            $this->replaceRelated('supplier_aliases','supplier_id',$id,'name',(string)($_POST['aliases']??''));
            $this->audit(isset($_POST['id'])&&$_POST['id']?'update':'create','supplier',$id);
            $this->redirect('/?route=suppliers');
        }
        $edit=isset($_GET['edit'])?$this->db->one('SELECT * FROM suppliers WHERE id=?',[(int)$_GET['edit']]):null;
        $services=$this->db->all('SELECT * FROM service_types WHERE active=1 ORDER BY name'); $options=''; foreach($services as $service)$options.='<option value="'.$service['id'].'"'.((int)($edit['main_service_type_id']??0)===(int)$service['id']?' selected':'').'>'.$this->e($service['name']).'</option>';
        $rows=$this->db->all('SELECT s.*,st.name service FROM suppliers s LEFT JOIN service_types st ON st.id=s.main_service_type_id ORDER BY s.active DESC,s.official_name'); $table='';
        foreach($rows as $row){
            $detailId='supplier-detail-'.$row['id'];
            $table.='<tr class="row-summary"><td>'.RowDetail::toggle($detailId,'<strong>'.$this->e($row['official_name']).'</strong>').'</td><td><span class="badge neutral">'.$this->e($row['service']?:'Sin asignar').'</span></td><td><span class="badge '.($row['active']?'success':'neutral').'">'.($row['active']?'Activo':'Desactivado').'</span></td><td class="actions"><a href="/?route=suppliers&edit='.$row['id'].'">Editar</a>'.($row['active']?'<form class="inline" method="post" action="/?route=suppliers"><input type="hidden" name="csrf" value="'.$this->auth->csrf().'"><input type="hidden" name="action" value="archive"><input type="hidden" name="id" value="'.$row['id'].'"><button class="button-quiet">Desactivar</button></form>':'').$this->deleteForm('suppliers',(int)$row['id'],'el proveedor «'.(string)$row['official_name'].'»').'</td></tr>';
            $table.=RowDetail::row($detailId,[
                ['Nombre oficial',(string)($row['official_name']??'')],
                ['Servicio',(string)($row['service']??'Sin asignar')],
                ['Estado',$row['active']?'Activo':'Desactivado'],
            ],4);
        }
        $aliases=$edit?$this->relatedText('supplier_aliases','supplier_id',(int)$edit['id']):'';
        $form='<form method="post" action="/?route=suppliers" class="card grid form-card"><input type="hidden" name="csrf" value="'.$this->auth->csrf().'"><input type="hidden" name="id" value="'.$this->e($edit['id']??'').'"><input type="hidden" name="cif" value="'.$this->e($edit['cif']??'').'"><input type="hidden" name="aliases" value="'.$this->e($aliases).'"><div class="form-heading wide"><span class="eyebrow">Directorio</span><h2>'.($edit?'Editar proveedor':'Nuevo proveedor').'</h2></div><label>Nombre oficial<input name="official_name" value="'.$this->e($edit['official_name']??'').'" required></label><label>Servicio<select name="service_id" required><option value="">Seleccionar</option>'.$options.'</select></label><div class="form-actions wide"><button>Guardar cambios</button><a class="button button-secondary" href="/?route=suppliers">Cancelar</a></div></form>';
        $list=$table!==''?'<div class="table-wrap"><table><thead><tr><th>Proveedor</th><th>Servicio</th><th>Estado</th><th><span class="sr-only">Acciones</span></th></tr></thead><tbody>'.$table.'</tbody></table></div>':'<section class="empty-state"><span class="empty-ring"><i></i></span><h2>Todavía no hay proveedores</h2><p>Añade el primero para vincularlo a sus comunidades.</p></section>';
        $panel=$this->disclosurePanel($edit?'+ Editar proveedor':'+ Nuevo proveedor',$form,(bool)$edit);
        $this->page('Proveedores','<div class="page-heading"><div><span class="eyebrow">Configuración</span><h1>Proveedores</h1><p>Consulta proveedores, servicios y estados sin formularios permanentes.</p></div><span class="count-badge">'.count($rows).' registrados</span></div>'.$panel.'<section class="card list-card">'.$list.'</section>');
    }

    private function mailboxes(): void
    {
        $formError='';
        if ($_SERVER['REQUEST_METHOD']==='POST') {
            if(($_POST['action']??'')==='archive'){$id=(int)$_POST['id'];$this->db->execute('UPDATE mailboxes SET active=0 WHERE id=?',[$id]);$this->audit('archive','mailbox',$id);$this->redirect('/?route=mailboxes');}
            if(($_POST['action']??'')==='delete'){
                $this->requireDeleteConfirmation();
                $id=(int)$_POST['id'];$row=$this->db->one('SELECT email FROM mailboxes WHERE id=?',[$id]);if(!$row)throw new \RuntimeException('Correo no encontrado');
                $pdo=$this->db->pdo();$pdo->beginTransaction();
                try{$this->audit('delete','mailbox',$id,$row);$this->db->execute('DELETE FROM processed_attachments WHERE mailbox_id=?',[$id]);$this->db->execute('DELETE FROM processed_messages WHERE mailbox_id=?',[$id]);$this->db->execute('DELETE FROM mailboxes WHERE id=?',[$id]);$pdo->commit();}
                catch(\Throwable$error){if($pdo->inTransaction())$pdo->rollBack();throw$error;}
                $this->redirect('/?route=mailboxes');
            }
            if (($_POST['action'] ?? '') === 'test') {
                $row=$this->db->one('SELECT * FROM mailboxes WHERE id=?',[(int)$_POST['id']]);
                if(!$row)throw new \RuntimeException('Correo no encontrado');
                try{
                    $client=new ImapClient((string)$row['imap_host'],(int)$row['imap_port'],(string)$row['username'],$this->crypto->decrypt((string)$row['encrypted_password']),(string)$row['input_folder'],15);
                    $client->connect();$client->close();
                    $this->db->execute('UPDATE mailboxes SET last_connection_at=NOW(),last_connection_ok=1,last_error=NULL WHERE id=?',[$row['id']]);
                }catch(\Throwable $error){
                    $this->db->execute('UPDATE mailboxes SET last_connection_at=NOW(),last_connection_ok=0,last_error=? WHERE id=?',[mb_substr($error->getMessage(),0,2000),$row['id']]);
                }
                $this->redirect('/?route=mailboxes');
            }
            ['id'=>$id,'formError'=>$formError]=$this->saveMailboxFromPost();
            if($formError===''){$this->redirect('/?route=mailboxes');}
            $_GET['edit']=(string)$id;
        }
        $edit=isset($_GET['edit'])?$this->db->one('SELECT id,descriptive_name,email,imap_host,active,process_existing_on_activate FROM mailboxes WHERE id=?',[(int)$_GET['edit']]):null;
        $rows=$this->db->all('SELECT id,descriptive_name,email,imap_host,active,last_connection_at,last_connection_ok,last_error FROM mailboxes ORDER BY descriptive_name'); $table='';
        foreach($rows as $row){$state=!$row['active']?'Desactivado':($row['last_connection_ok']?'Conectado':'Sin comprobar');$stateClass=$row['active']&&$row['last_connection_ok']?'success':'neutral';$table.='<tr><td><strong>'.$this->e($row['descriptive_name']).'</strong></td><td>'.$this->e($row['email']).'</td><td><span class="badge neutral">'.$this->e(MailboxProvider::fromHost((string)$row['imap_host'])==='gmail'?'Gmail':'IONOS').'</span></td><td><span class="badge '.$stateClass.'">'.$state.'</span></td><td class="actions"><a href="/?route=mailboxes&edit='.$row['id'].'">Editar</a><form class="inline" method="post" action="/?route=mailboxes"><input type="hidden" name="csrf" value="'.$this->auth->csrf().'"><input type="hidden" name="action" value="test"><input type="hidden" name="id" value="'.$row['id'].'"><button class="button-quiet">Probar</button></form>'.($row['active']?'<form class="inline" method="post" action="/?route=mailboxes"><input type="hidden" name="csrf" value="'.$this->auth->csrf().'"><input type="hidden" name="action" value="archive"><input type="hidden" name="id" value="'.$row['id'].'"><button class="button-quiet">Desactivar</button></form>':'').$this->deleteForm('mailboxes',(int)$row['id'],'el correo «'.(string)$row['email'].'» y todo su historial de procesamiento').'</td></tr>';}
        $provider=$edit?MailboxProvider::fromHost((string)$edit['imap_host']):'gmail';
        $form='<form method="post" action="/?route=mailboxes" class="card grid form-card"><input type="hidden" name="csrf" value="'.$this->auth->csrf().'"><input type="hidden" name="id" value="'.$this->e($edit['id']??'').'"><div class="form-heading wide"><span class="eyebrow">Entrada de facturas</span><h2>'.($edit?'Editar correo':'Nuevo correo').'</h2></div><label>Proveedor<select name="provider"><option value="gmail"'.($provider==='gmail'?' selected':'').'>Gmail</option><option value="ionos"'.($provider==='ionos'?' selected':'').'>IONOS</option></select></label><label>Nombre<input name="name" value="'.$this->e($edit['descriptive_name']??'').'" required></label><label>Dirección<input type="email" name="email" value="'.$this->e($edit['email']??'').'" required></label><label>Contraseña de aplicación<input type="password" name="password" '.($edit?'':'required').' autocomplete="new-password"><small>'.($edit?'Déjala vacía para conservarla.':'En Gmail usa una contraseña de aplicación; se guardará cifrada.').'</small></label><label class="check-label"><input type="checkbox" name="active" value="1" '.((int)($edit['active']??0)===1?'checked':'').'><span>Activar procesamiento automático</span></label>'.'<div class="wide">'.$this->disclosurePanel('+ Opciones avanzadas','<label class="check-label"><input type="checkbox" name="process_existing" value="1" '.((int)($edit['process_existing_on_activate']??0)===1?'checked':'').'><span>Procesar correos existentes al activar</span></label><small>Desactivado por defecto: al dar de alta este correo, Salvest ignora todo lo que ya hubiera en la bandeja y solo procesa lo que llegue a partir de ahora. Actívalo únicamente si quieres que también revise el historial existente.</small>',false).'</div><div class="form-actions wide"><button>Guardar cambios</button><a class="button button-secondary" href="/?route=mailboxes">Cancelar</a></div></form>';
        $list=$table!==''?'<div class="table-wrap"><table><thead><tr><th>Nombre</th><th>Dirección</th><th>Proveedor</th><th>Estado</th><th><span class="sr-only">Acciones</span></th></tr></thead><tbody>'.$table.'</tbody></table></div>':'<section class="empty-state"><span class="empty-ring"><i></i></span><h2>Todavía no hay correos</h2><p>Añade una cuenta y prueba su conexión antes de activarla.</p></section>';
        $panel=$this->disclosurePanel($edit?'+ Editar correo':'+ Nuevo correo',$form,(bool)$edit||$formError!=='');
        $errorBanner=$formError!==''?'<section class="card error">'.$this->e($formError).'</section>':'';
        $this->page('Correos','<div class="page-heading"><div><span class="eyebrow">Configuración</span><h1>Correos</h1><p>Consulta las cuentas conectadas y cambia credenciales solo cuando sea necesario.</p></div></div>'.$errorBanner.$panel.'<section class="card list-card">'.$list.'</section>');
    }

    /**
     * Pure decision of whether saving this mailbox must (re)capture its baseline right now, kept
     * free of I/O so it can be unit-tested on its own. Rules:
     *  - a protected mailbox (process_existing_on_activate=0) being activated for the first time
     *    (no baseline yet) must capture before it is allowed to go active;
     *  - flipping process_existing_on_activate from 1 to 0 always (re)captures "from this exact
     *    moment", regardless of active — an old/stale baseline could hide real backlog;
     *  - anything else (0→0 with an existing baseline, 0→1, already active+protected+baselined)
     *    never needs a synchronous capture here.
     * @return array{transitioned1to0:bool,mustCapture:bool}
     */
    private static function mailboxCaptureDecision(?int $priorProcessExisting,bool $hadBaseline,int $processExisting,int $active):array
    {
        $transitioned1to0=$priorProcessExisting===1&&$processExisting===0;
        $needsFirstCapture=$processExisting===0&&$active===1&&!$hadBaseline&&!$transitioned1to0;
        return['transitioned1to0'=>$transitioned1to0,'mustCapture'=>$processExisting===0&&($transitioned1to0||$needsFirstCapture)];
    }

    /**
     * Reads $_POST, saves the mailbox (insert or update) and, when required, captures its baseline
     * synchronously first — kept apart from mailboxes() so it never calls redirect()/exit itself,
     * which makes it directly callable (and testable) without a real HTTP round trip.
     * @return array{id:int,formError:string}
     */
    private function saveMailboxFromPost():array
    {
        $email=mb_strtolower(trim((string)$_POST['email']));
        $connection=MailboxProvider::connection((string)($_POST['provider']??'ionos'));
        $active=isset($_POST['active'])?1:0;
        $processExisting=isset($_POST['process_existing'])?1:0;
        $id=(int)($_POST['id']??0);$password=(string)($_POST['password']??'');
        $prior=$id?$this->db->one('SELECT encrypted_password,process_existing_on_activate,baseline_captured_at FROM mailboxes WHERE id=?',[$id]):null;
        if($id&&!$prior)throw new \RuntimeException('Correo no encontrado');
        if($id===0&&$password==='')throw new \RuntimeException('La contraseña es obligatoria');
        $encrypted=$password!==''?$this->crypto->encrypt($password):(string)($prior['encrypted_password']??'');
        // Point of no return for "process everything from here on": capture it synchronously,
        // before the mailbox can ever be saved active, so there is no window between saving and
        // the first Worker cycle where a real new message could be mistaken for old backlog.
        $priorProcessExisting=$prior?(int)$prior['process_existing_on_activate']:null;
        $hadBaseline=$prior&&$prior['baseline_captured_at']!==null;
        $decision=self::mailboxCaptureDecision($priorProcessExisting,$hadBaseline,$processExisting,$active);
        $baseline=null;$formError='';
        if($decision['mustCapture']){
            try{$baseline=$this->captureMailboxBaseline($connection['host'],$connection['port'],$email,$password!==''?$password:$this->crypto->decrypt($encrypted),(int)$this->config['imap']['timeout_seconds']);}
            catch(\Throwable$error){
                error_log('mailbox_baseline_capture status=failed email='.$email.' '.$error->getMessage());
                $formError='No se pudo conectar con el buzón para establecer a partir de qué correo empezar. El correo se ha guardado desactivado; revisa las credenciales y vuelve a intentarlo.';
                $active=0;
            }
        }
        $fields=['descriptive_name'=>trim((string)$_POST['name']),'email'=>$email,'imap_host'=>$connection['host'],'imap_port'=>$connection['port'],
            'use_ssl'=>$connection['use_ssl'],'username'=>$email,'encrypted_password'=>$encrypted,'active'=>$active,'process_existing_on_activate'=>$processExisting];
        if($baseline!==null){$fields['baseline_uidvalidity']=$baseline['uidvalidity'];$fields['baseline_uid']=$baseline['uid'];}
        if($id){
            $set=implode(',',array_map(static fn(string$column):string=>"$column=?",array_keys($fields)));
            if($baseline!==null)$set.=',baseline_captured_at=NOW()';
            $this->db->execute("UPDATE mailboxes SET $set WHERE id=?",[...array_values($fields),$id]);
        }else{
            $columns=implode(',',array_keys($fields)).',input_folder'.($baseline!==null?',baseline_captured_at':'');
            $placeholders=implode(',',array_fill(0,count($fields),'?')).",'INBOX'".($baseline!==null?',NOW()':'');
            $this->db->execute("INSERT INTO mailboxes($columns) VALUES ($placeholders)",array_values($fields));$id=(int)$this->db->pdo()->lastInsertId();
        }
        $this->audit($prior?'update':'create','mailbox',$id,['email'=>$email,'baseline_captured'=>$baseline!==null,'baseline_capture_failed'=>$formError!=='']);
        return['id'=>$id,'formError'=>$formError];
    }

    /**
     * Snapshots "everything that exists right now" for a protected mailbox, synchronously, as part
     * of saving the mailbox form — never deferred to the Worker's first cycle. That gap is exactly
     * the race this exists to close: a message arriving between save and the first cron/manual run
     * must never be mistaken for pre-existing backlog.
     * @return array{uidvalidity:string,uid:int}
     */
    private function captureMailboxBaseline(string $host,int $port,string $username,string $password,int $timeoutSeconds):array
    {
        $client=new ImapClient($host,$port,$username,$password,'INBOX',$timeoutSeconds);
        try{
            $client->connect();
            return MailboxBaseline::fromUids($client->uidValidity(),$client->listUids());
        }finally{$client->close();}
    }

    private function reviews(): void
    {
        if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='requeue'){
            if(!hash_equals('REQUEUE',(string)($_POST['confirm_requeue']??'')))throw new \RuntimeException('La acción no fue confirmada');
            $result=(new InboxRequeue($this->db,$this->crypto,$this->config))->requeue((int)($_POST['id']??0));
            if($result['ok'])$this->audit('requeue','attachment',(int)($_POST['id']??0));
            $this->redirect('/?route=reviews&'.($result['ok']?'requeued=1':'requeue_error='.rawurlencode($result['message'])));
        }
        if($_SERVER['REQUEST_METHOD']==='POST'){
            $attachment=$this->db->one('SELECT * FROM processed_attachments WHERE id=?',[(int)$_POST['id']]);
            $community=$this->db->one('SELECT * FROM communities WHERE id=? AND active=1',[(int)$_POST['community_id']]);
            $supplier=$this->db->one('SELECT s.*,cs.category FROM community_suppliers cs JOIN suppliers s ON s.id=cs.supplier_id WHERE cs.community_id=? AND s.id=? AND s.active=1',[$community['id'],(int)$_POST['supplier_id']]);
            if(!$attachment||!$community||!$supplier||!$attachment['output_path']||!is_file($attachment['output_path']))throw new \RuntimeException('El proveedor seleccionado no está configurado para esa comunidad');
            $invoice=['fecha_factura'=>(string)$_POST['invoice_date'],'tipo_servicio'=>mb_strtolower((string)$supplier['category']),'proveedor'=>(string)$supplier['official_name'],'numero_factura'=>(string)($_POST['invoice_number']??'')];
            $target=(new Archiver((string)$this->config['processing']['storage_root']))->archive((string)$attachment['output_path'],(string)$attachment['original_filename'],$invoice,$community,'classified');
            $drive=null;if((bool)($this->config['google_drive']['enabled']??false))$drive=$this->driveArchiver()->archive($target,$community,$supplier,(string)$supplier['category'],$invoice);
            $this->db->execute("UPDATE processed_attachments SET community_id=?,provider=?,service_type=?,invoice_date=?,amount=?,invoice_number=?,confidence=100,
                final_filename=?,output_path=?,drive_file_id=?,drive_path=?,drive_status=?,status='classified',error_message=NULL,processed_at=NOW() WHERE id=?",[$community['id'],$supplier['official_name'],mb_strtolower((string)$supplier['category']),$_POST['invoice_date'],$_POST['amount']?:null,$_POST['invoice_number']?:null,basename($target),$target,$drive['id']??null,$drive['path']??null,$drive?'uploaded':null,$attachment['id']]);
            $this->audit('confirm_classification','attachment',$attachment['id'],['community_id'=>$community['id'],'supplier_id'=>$supplier['id']]);
            $this->redirect('/?route=reviews');
        }
        $rows=$this->db->all("SELECT pa.*,c.official_name FROM processed_attachments pa LEFT JOIN communities c ON c.id=pa.community_id WHERE pa.status IN ('unclassified','needs_review','error') ORDER BY pa.processed_at DESC LIMIT 200");
        $communities=$this->db->all('SELECT id,official_name FROM communities WHERE active=1 ORDER BY official_name');$suppliers=$this->db->all('SELECT id,official_name FROM suppliers WHERE active=1 ORDER BY official_name');$services=$this->db->all('SELECT normalized_name,name FROM service_types WHERE active=1 ORDER BY name');
        $banner='';
        if(($_GET['requeued']??'')==='1')$banner='<section class="status ok"><span class="status-ring"><i></i></span><span><strong>Factura devuelta a la bandeja de entrada</strong><small>Se procesará de nuevo en la próxima ejecución del bot.</small></span></section>';
        elseif(($_GET['requeue_error']??'')!=='')$banner='<section class="status warning"><span class="status-ring"><i></i></span><span><strong>No se pudo volver a procesar</strong><small>'.$this->e((string)$_GET['requeue_error']).'</small></span></section>';
        $cards='';
        foreach($rows as $row){
            // The system already resolved community_id (a real FK, not just OpenAI's text
            // guess) whenever the classifier found one — pre-select it instead of leaving
            // "Seleccionar" and making a correct automatic match look like it found nothing.
            $communityOptions='';foreach($communities as $item)$communityOptions.='<option value="'.$item['id'].'"'.((int)$item['id']===(int)($row['community_id']??0)?' selected':'').'>'.$this->e($item['official_name']).'</option>';
            // processed_attachments has no supplier_id column, only the free-text provider name
            // saved at classification time — pre-select only on an unambiguous exact match.
            $providerNormalized=Text::normalizeCompanyName((string)($row['provider']??''));
            $supplierOptions='';foreach($suppliers as $item)$supplierOptions.='<option value="'.$item['id'].'"'.($providerNormalized!==''&&Text::normalizeCompanyName((string)$item['official_name'])===$providerNormalized?' selected':'').'>'.$this->e($item['official_name']).'</option>';
            $serviceNormalized=Text::normalize((string)($row['service_type']??''));
            $serviceOptions='';foreach($services as $item)$serviceOptions.='<option value="'.$this->e($item['normalized_name']).'"'.($serviceNormalized!==''&&$item['normalized_name']===$serviceNormalized?' selected':'').'>'.$this->e($item['name']).'</option>';
            // A supplier is only ever a confirmed row from suppliers — never OpenAI's raw text.
            // Show them as two clearly distinct facts, not one field pretending to be the other.
            $cards.='<article class="card review-card"><div class="review-head"><div><span class="badge warning">Pendiente de revisión</span><h2>'.$this->e($row['original_filename']).'</h2></div>'.($row['output_path']?'<a class="button button-secondary" href="/?route=download&id='.$row['id'].'">Descargar PDF</a>':'').'</div><div class="review-meta"><span>Proveedor resuelto<strong>'.($row['provider']?$this->e($row['provider']):'Pendiente').'</strong></span><span>Texto detectado<strong>'.$this->e($row['raw_supplier_name']?:'Desconocido').'</strong></span><span>Comunidad sugerida<strong>'.$this->e($row['official_name']?:'Sin asignar').'</strong></span><span>Importe<strong class="mono">'.($row['amount']!==null?$this->e($row['amount']).' €':'—').'</strong></span><span>N.º factura<strong class="mono">'.$this->e($row['invoice_number']?:'—').'</strong></span></div><p class="review-reason">'.$this->e($row['error_message']?:'Comprueba los datos antes de archivar la factura.').'</p>'.
                '<form method="post" action="/?route=reviews" class="grid review-form"><input type="hidden" name="csrf" value="'.$this->auth->csrf().'"><input type="hidden" name="id" value="'.$row['id'].'"><label>Comunidad<select name="community_id" required><option value="">Seleccionar</option>'.$communityOptions.'</select></label><label>Proveedor<select name="supplier_id" required><option value="">Seleccionar</option>'.$supplierOptions.'</select></label><label>Servicio<select name="service_type" required>'.$serviceOptions.'</select></label><label>Fecha<input type="date" name="invoice_date" value="'.$this->e($row['invoice_date']).'" required></label><label>Importe<input class="mono" name="amount" value="'.$this->e($row['amount']).'"></label><label>Número de factura<input class="mono" name="invoice_number" value="'.$this->e($row['invoice_number']).'"></label><button>Confirmar y archivar</button></form>'.
                ($row['status']==='needs_review'?$this->requeueForm($row):'').
                $this->technicalDetail($row['debug_trace_json']??null).'</article>';
        }
        $empty='<section class="card empty-state review-empty"><span class="empty-ring"><i></i></span><h2>No hay facturas pendientes de revisar</h2><p>Las nuevas incidencias aparecerán aquí cuando necesiten una decisión.</p></section>';
        $this->page('Revisar','<div class="page-heading"><div><span class="eyebrow">Control manual</span><h1>Revisar facturas</h1><p>Confirma únicamente los documentos que el sistema no ha podido clasificar.</p></div>'.($rows?'<span class="count-badge warning">'.count($rows).' pendientes</span>':'').'</div>'.$banner.($cards!==''?'<div class="review-list">'.$cards.'</div>':$empty));
    }

    private function storage(): void
    {
        $drive=$this->config['google_drive']??[];$enabled=(bool)($drive['enabled']??false);
        try{
            if(!$enabled)throw new \RuntimeException('Google Drive no está activado');
            $tokens=new GoogleUserOAuthProvider((string)$drive['oauth_client_file'],(string)$drive['oauth_token_file']);
            $client=new GoogleDriveClient($tokens);
            $items=$client->children((string)$drive['root_folder_id']);
            $folders=count(array_filter($items,static fn(array$item):bool=>DriveTree::isFolder($item)));
            $body='<div class="page-heading"><div><span class="eyebrow">Destino documental</span><h1>Almacenamiento</h1><p>Estado de la conexión utilizada para archivar las facturas.</p></div></div><section class="status ok"><span class="status-ring"><i></i></span><span><strong>Google Drive correctamente configurado</strong><small>Las facturas se archivan automáticamente en la carpeta COMUNIDADES.</small></span></section>'.
                '<section class="card storage-card"><div class="section-heading flat"><div><span class="eyebrow">Carpeta de destino</span><h2>COMUNIDADES</h2><p><strong class="mono">'.$folders.'</strong> comunidades preparadas</p></div></div>'.$this->driveTree($items).'</section>';
        }catch(\Throwable$error){
            $body='<div class="page-heading"><div><span class="eyebrow">Destino documental</span><h1>Almacenamiento</h1></div></div><section class="status warning"><div><span class="status-ring"><i></i></span><span><strong>No se puede conectar con Google Drive</strong><small>Revisa la autorización y vuelve a intentarlo.</small></span></div></section>';
            error_log('storage_drive_check status=error '.$error->getMessage());
        }
        $this->page('Almacenamiento',$body);
    }

    private function download(int $id): void
    {
        $row=$this->db->one('SELECT output_path,final_filename FROM processed_attachments WHERE id=?',[$id]); if(!$row||!$row['output_path'])$this->notFound();
        $root=realpath((string)$this->config['processing']['storage_root']); $path=realpath((string)$row['output_path']);
        if(!$root||!$path||!str_starts_with($path,$root.DIRECTORY_SEPARATOR)||!is_file($path))$this->notFound();
        header('Content-Type: application/octet-stream'); header('Content-Disposition: attachment; filename="'.addslashes((string)$row['final_filename']).'"'); readfile($path); exit;
    }

    private function page(string $title,string $body,bool $navigation=true): void
    {
        header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: SAMEORIGIN'); header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'");
        $head='<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#0A0A0A"><title>'.$this->e($title).' · Salvest</title><link rel="icon" href="/assets/logoSalvest.png"><link rel="stylesheet" href="/assets/fonts.css"><link rel="stylesheet" href="/assets/app.css"><script src="/assets/app.js" defer></script>';
        if(!$navigation){echo'<!doctype html><html lang="es"><head>'.$head.'</head><body class="login-page"><main class="login-main">'.$body.'</main></body></html>';return;}
        $route=trim((string)($_GET['route']??''));if($route==='index.php')$route='';
        $nav=$this->navLink('','Inicio','home',$route).$this->navLink('communities','Comunidades','communities',$route).$this->navLink('suppliers','Proveedores','suppliers',$route).$this->navLink('mailboxes','Correos','mail',$route).$this->navLink('reviews','Revisar','review',$route).$this->navLink('storage','Almacenamiento','storage',$route);
        $sidebar='<aside class="sidebar" id="sidebar"><a class="brand" href="/" aria-label="Salvest, inicio"><img src="/assets/logoSalvest.png" alt="Salvest"></a><nav aria-label="Navegación principal">'.$nav.'</nav><a class="logout" href="/?route=logout">'.$this->icon('logout').'<span>Salir</span></a></aside>';
        $mobile='<header class="mobile-header"><a href="/" aria-label="Salvest, inicio"><img src="/assets/logoSalvest.png" alt="Salvest"></a><button class="menu-toggle" type="button" aria-controls="sidebar" aria-expanded="false"><span class="sr-only">Abrir menú</span><i></i><i></i><i></i></button></header><button class="nav-scrim" type="button" aria-label="Cerrar menú" tabindex="-1"></button>';
        echo'<!doctype html><html lang="es"><head>'.$head.'</head><body class="app-page">'.$mobile.'<div class="app-shell">'.$sidebar.'<main class="main-content">'.$body.'</main></div></body></html>';
    }
    private function e(mixed $value): string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}

    /** "Volver a procesar": only ever rendered for status==='needs_review' (checked by the
     * caller) — InboxRequeue revalidates that server-side regardless. Looks at this attachment's
     * siblings (same mailbox/uidvalidity/message_uid, i.e. the same email) purely to pick the
     * right confirmation wording: how many of them are still pending vs already classified, so
     * the person confirming knows exactly what's about to happen before they click. @param array<string,mixed> $row */
    private function requeueForm(array $row): string
    {
        $siblings=$this->db->all('SELECT status FROM processed_attachments WHERE mailbox_id=? AND uidvalidity=? AND message_uid=?',
            [$row['mailbox_id'],$row['uidvalidity'],$row['message_uid']]);
        $pending=count(array_filter($siblings,static fn(array $s):bool=>!in_array($s['status'],['classified','duplicate'],true)));
        $confirm=$pending>1
            ?'Este correo contiene varios adjuntos. Los pendientes se volverán a procesar; los ya clasificados se conservarán y no volverán a clasificarse. Se conservará el historial técnico de los intentos anteriores. ¿Continuar?'
            :'Esta factura volverá a la bandeja de entrada y Salvest intentará procesarla de nuevo en la próxima ejecución. Se conservará el historial técnico del intento anterior. ¿Continuar?';
        return '<form method="post" action="/?route=reviews" class="inline requeue-form" data-confirm="'.$this->e($confirm).'"><input type="hidden" name="csrf" value="'.$this->auth->csrf().'"><input type="hidden" name="action" value="requeue"><input type="hidden" name="confirm_requeue" value=""><input type="hidden" name="id" value="'.$row['id'].'"><button type="submit" class="button-secondary">Volver a procesar</button></form>';
    }

    /** Renders processed_attachments.debug_trace_json (a chronological array of {timestamp,step,data},
     * see ReviewTrace) as a closed-by-default <details> block under a review card: one human-readable
     * summary line per step, each with its own nested "JSON completo" for the full technical payload.
     * NULL/unreadable trace (old rows from before this feature, or a row that never went through
     * needs_review) renders a plain explanatory sentence instead — never an error. */
    private function technicalDetail(?string $traceJson): string
    {
        $unavailable='<details class="tech-trace"><summary>Detalle técnico</summary><p class="muted">No hay detalle técnico disponible para esta factura.</p></details>';
        if($traceJson===null||$traceJson==='')return $unavailable;
        $steps=json_decode($traceJson,true);
        if(!is_array($steps)||!$steps)return $unavailable;
        $labels=['document'=>'Documento recibido','openai_request'=>'1ª llamada OpenAI','openai_response'=>'Respuesta OpenAI',
            'community_resolution'=>'Resolución de comunidad','supplier_resolution'=>'Resolución de proveedor',
            'service_resolution'=>'Resolución de servicio','restricted_openai'=>'2ª llamada OpenAI (restringida)',
            'final_decision'=>'Decisión final'];
        $items='';
        foreach($steps as $step){
            if(!is_array($step))continue;
            $kind=(string)($step['step']??'');
            $data=is_array($step['data']??null)?$step['data']:[];
            $label=$labels[$kind]??($kind!==''?$kind:'Paso');
            $json=json_encode($step['data']??null,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_PARTIAL_OUTPUT_ON_ERROR);
            $items.='<details class="tech-step"><summary><span class="tech-step-time mono">'.$this->e((string)($step['timestamp']??'')).'</span> · '.$this->e($label).'</summary>'.
                '<p class="tech-step-summary">'.$this->technicalStepSummary($kind,$data).'</p>'.
                '<details class="tech-step-json"><summary>JSON completo</summary><pre class="mono">'.$this->e((string)$json).'</pre></details></details>';
        }
        return '<details class="tech-trace"><summary>Detalle técnico</summary><div class="tech-steps">'.$items.'</div></details>';
    }

    /** @param array<string,mixed> $data */
    private function technicalStepSummary(string $kind,array $data):string
    {
        $v=fn(mixed $value):string=>$this->e($value===null||$value===''?'—':$value);
        $evidence=fn(?array $ev):string=>$ev?$this->e(($ev['field']??'').'/'.($ev['type']??'')):'—';
        return match($kind){
            'document'=>$v($data['filename']??null).' · '.$v($data['mime']??null).' · '.$v($data['size_bytes']??null).' bytes',
            'openai_request'=>'modelo='.$v($data['model']??null).', reasoning='.$v($data['reasoning']??null),
            'openai_response'=>$v($data['latency_ms']??null).' ms · '.$v($data['input_tokens']??null).' in / '.$v($data['output_tokens']??null).' out tokens',
            'community_resolution'=>$v($data['official_name']??'sin resolver').' ('.$evidence($data['evidence']??null).')',
            'supplier_resolution'=>$v($data['supplier_name']??'sin resolver').' ('.$evidence($data['evidence']??null).'), ambiguo='.(($data['ambiguous']??false)?'sí':'no'),
            'service_resolution'=>$v($data['final_service']??null).' ('.$evidence($data['evidence']??null).')',
            'restricted_openai'=>'supplier_id devuelto='.$v($data['chosen_supplier_id']??null).', validado='.(($data['validated']??false)?'sí':'no'),
            'final_decision'=>$v($data['status']??null).(!empty($data['reason'])?' — '.$v($data['reason']):''),
            default=>'',
        };
    }
    private function disclosurePanel(string $label,string $content,bool $open):string
    {
        $id='panel-'.bin2hex(random_bytes(5));
        return'<section class="disclosure" data-disclosure'.($open?' data-initial-open="true"':'').'><button class="disclosure-toggle" type="button" aria-expanded="'.($open?'true':'false').'" aria-controls="'.$id.'">'.$this->e($label).'</button><div id="'.$id.'" class="disclosure-panel"'.($open?'':' hidden').'>'.$content.'</div></section>';
    }
    /** @param list<array<string,mixed>> $items */
    private function driveTree(array $items):string
    {
        return DriveTree::renderRoot($items);
    }
    private function storageChildren():void
    {
        header('Content-Type: application/json; charset=UTF-8');
        $drive=$this->config['google_drive']??[];if(!(bool)($drive['enabled']??false)){http_response_code(503);echo json_encode(['error'=>'Google Drive no está activado']);return;}
        $folderId=(string)($_GET['folder_id']??'');$level=max(1,min(5,(int)($_GET['level']??1)));
        if(!preg_match('/^[A-Za-z0-9_-]+$/',$folderId)){http_response_code(400);echo json_encode(['error'=>'Carpeta no válida']);return;}
        try{
            $tokens=new GoogleUserOAuthProvider((string)$drive['oauth_client_file'],(string)$drive['oauth_token_file']);
            $items=(new GoogleDriveClient($tokens))->children($folderId);
            error_log('storage_children status=ok folder_id='.$folderId.' items='.count($items).' names='.implode(',',array_map(static fn(array$item):string=>(string)($item['name']??'?'),$items)));
            echo json_encode(['html'=>DriveTree::renderNodes($items,$level+1)],JSON_UNESCAPED_UNICODE);
        }catch(\Throwable$error){
            http_response_code(500);error_log('storage_children status=error folder_id='.$folderId.' '.$error->getMessage());echo json_encode(['error'=>'No se pudo cargar esta carpeta'],JSON_UNESCAPED_UNICODE);
        }
    }
    private function navLink(string$route,string$label,string$icon,string$current):string
    {
        $active=$route===$current;$href=$route===''?'/':'/?route='.$route;
        return'<a class="nav-link'.($active?' active':'').'" href="'.$href.'"'.($active?' aria-current="page"':'').'>'.$this->icon($icon).'<span>'.$label.'</span></a>';
    }
    private function icon(string$name):string
    {
        $path=match($name){
            'home'=>'<path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10v10h13V10M9 20v-6h6v6"/>',
            'communities'=>'<path d="M4 20V8l8-4 8 4v12M8 11h.01M12 11h.01M16 11h.01M8 15h.01M12 15h.01M16 15h.01M10 20v-2h4v2"/>',
            'suppliers'=>'<path d="M4 7h16v13H4zM8 7V4h8v3M4 12h16M10 12v2h4v-2"/>',
            'mail'=>'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/>',
            'review'=>'<path d="M9 4h6l1 2h3v15H5V6h3zM8 11h8M8 15h5"/>',
            'storage'=>'<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v7c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12v7c0 1.7 3.6 3 8 3s8-1.3 8-3v-7"/>',
            'logout'=>'<path d="M10 5H5v14h5M14 8l4 4-4 4M8 12h10"/>',default=>''};
        return'<svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'.$path.'</svg>';
    }
    private function deleteForm(string$route,int$id,string$description):string
    {
        return'<form class="inline delete-form" method="post" action="/?route='.$route.'" data-confirm="¿Eliminar permanentemente '.$this->e($description).'? Esta acción no se puede deshacer."><input type="hidden" name="csrf" value="'.$this->auth->csrf().'"><input type="hidden" name="action" value="delete"><input type="hidden" name="confirm_delete" value=""><input type="hidden" name="id" value="'.$id.'"><button class="danger">Eliminar</button></form>';
    }
    private function requireDeleteConfirmation():void
    {
        if(!hash_equals('DELETE',(string)($_POST['confirm_delete']??'')))throw new \RuntimeException('La eliminación no fue confirmada');
    }
    /** @param array<string,mixed>|null $values */
    private function audit(string $action,string $entity,string|int $id,?array $values=null):void
    {
        $this->db->execute('INSERT INTO audit_log(user_id,action,entity_type,entity_id,new_values_json,ip_address) VALUES (?,?,?,?,?,?)',[
            $this->auth->userId(),$action,$entity,(string)$id,$values?json_encode($values,JSON_UNESCAPED_UNICODE):null,$_SERVER['REMOTE_ADDR']??null]);
    }
    private function driveArchiver():DriveInvoiceArchiver
    {
        $drive=$this->config['google_drive']??[];
        $tokens=new GoogleUserOAuthProvider((string)$drive['oauth_client_file'],(string)$drive['oauth_token_file']);
        return new DriveInvoiceArchiver(new GoogleDriveClient($tokens),(string)$drive['root_folder_id']);
    }
    private function relatedText(string $table,string $owner,int $id):string
    {
        $allowed=['community_aliases'=>'community_id','supplier_aliases'=>'supplier_id'];
        if(($allowed[$table]??null)!==$owner)throw new \InvalidArgumentException('Relación no permitida');
        return implode("\n",array_column($this->db->all("SELECT value FROM $table WHERE $owner=? AND active=1 ORDER BY id",[$id]),'value'));
    }
    private function replaceRelated(string $table,string $owner,int $id,string $type,string $input):void
    {
        $allowed=['community_aliases'=>'community_id','supplier_aliases'=>'supplier_id'];
        if(($allowed[$table]??null)!==$owner)throw new \InvalidArgumentException('Relación no permitida');
        $this->db->execute("DELETE FROM $table WHERE $owner=?",[$id]);
        foreach(array_unique(array_filter(array_map('trim',preg_split('/\R/u',$input)?:[])))as$value){
            $this->db->execute("INSERT INTO $table($owner,alias_type,value,normalized_value,active) VALUES (?,?,?,?,1)",[$id,$type,$value,Text::normalize($value)]);
        }
    }
    private function identifierText(int $id):string
    {
        $rows=$this->db->all('SELECT identifier_type,value FROM community_identifiers WHERE community_id=? AND active=1 ORDER BY id',[$id]);
        return implode("\n",array_map(static fn(array$row):string=>$row['identifier_type'].': '.$row['value'],$rows));
    }
    private function replaceIdentifiers(int $id,string $input):void
    {
        $values=[];
        foreach(array_filter(array_map('trim',preg_split('/\R/u',$input)?:[]))as$line){
            if(!str_contains($line,':'))throw new \InvalidArgumentException('Cada identificador debe tener formato tipo: valor');
            [$type,$value]=array_map('trim',explode(':',$line,2));
            if(!in_array($type,['cups','contract','customer_reference'],true)||$value==='')throw new \InvalidArgumentException('Tipo de identificador no válido');
            $values[]=[$type,$value,Text::normalize($value)];
        }
        $this->db->execute('DELETE FROM community_identifiers WHERE community_id=?',[$id]);
        foreach($values as[$type,$value,$normalized])$this->db->execute('INSERT INTO community_identifiers(community_id,identifier_type,value,normalized_value,active) VALUES (?,?,?,?,1)',[$id,$type,$value,$normalized]);
    }
    private function redirect(string $path): never{header('Location: '.$path, true, 302);exit;}
    private function notFound(): never{http_response_code(404);echo 'No encontrado';exit;}
}

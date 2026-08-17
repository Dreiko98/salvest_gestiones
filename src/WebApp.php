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
            if ($error==='' && $this->auth->login((string)($_POST['username']??''),(string)($_POST['password']??''))) $this->redirect('/');
            if ($error==='') $error='Usuario o contraseña incorrectos.';
        }
        $body='<section class="card login"><h1>Gestión de facturas</h1>'.($error?'<p class="error">'.$this->e($error).'</p>':'').
            '<form method="post"><input type="hidden" name="csrf" value="'.$this->auth->csrf().'"><label>Usuario<input name="username" required autofocus></label><label>Contraseña<input type="password" name="password" required></label><button>Entrar</button></form></section>';
        $this->page('Acceso',$body,false);
    }

    private function dashboard(): void
    {
        $classified=(int)$this->db->one("SELECT COUNT(*) n FROM processed_attachments WHERE status='classified' AND DATE(processed_at)=CURDATE()")['n'];
        $communities=(int)$this->db->one('SELECT COUNT(*) n FROM communities WHERE active=1')['n'];
        $suppliers=(int)$this->db->one('SELECT COUNT(*) n FROM suppliers WHERE active=1')['n'];
        $attention=(int)$this->db->one("SELECT COUNT(*) n FROM processed_attachments WHERE status IN ('unclassified','needs_review','error')")['n'];
        $status=$attention?'<section class="status warning"><div><b>!</b><span><strong>Hay '.$attention.' facturas que necesitan tu ayuda</strong><small>El sistema no ha podido archivarlas automáticamente.</small></span></div><a class="button" href="/?route=reviews">Revisar</a></section>'
            :'<section class="status ok"><b>✓</b><span><strong>Todo está al día</strong><small>No hay facturas que necesiten tu atención.</small></span></section>';
        $this->page('Facturas','<h1>Facturas</h1><p>El sistema revisa y archiva automáticamente las facturas recibidas.</p>'.$status.
            '<div class="metrics"><article><strong>'.$classified.'</strong><span>Archivadas hoy</span></article><article><strong>'.$communities.'</strong><span>Comunidades</span></article><article><strong>'.$suppliers.'</strong><span>Proveedores</span></article></div>');
    }

    private function communities(): void
    {
        if ($_SERVER['REQUEST_METHOD']==='POST') {
            $id=(int)($_POST['id']??0); $values=[trim((string)$_POST['official_name']),Text::normalize((string)$_POST['official_name']),trim((string)($_POST['cif']??'')),trim((string)$_POST['main_address']),trim((string)($_POST['postal_code']??'')),trim((string)($_POST['city']??'')),1];
            if ($id) $this->db->execute('UPDATE communities SET official_name=?,normalized_name=?,cif=?,main_address=?,postal_code=?,city=?,active=? WHERE id=?',[...$values,$id]);
            else $this->db->execute('INSERT INTO communities(official_name,normalized_name,cif,main_address,postal_code,city,active) VALUES (?,?,?,?,?,?,?)',$values);
            $this->audit($id ? 'update' : 'create','community',$id ?: $this->db->pdo()->lastInsertId());
            $this->redirect('/?route=communities');
        }
        $rows=$this->db->all('SELECT * FROM communities ORDER BY active DESC,official_name'); $table='';
        foreach($rows as $row)$table.='<tr><td>'.$this->e($row['official_name']).'</td><td>'.$this->e($row['main_address']).'</td><td>'.($row['active']?'Activa':'Archivada').'</td></tr>';
        $form='<form method="post" class="card grid"><input type="hidden" name="csrf" value="'.$this->auth->csrf().'"><h2>Añadir comunidad</h2><label>Nombre oficial<input name="official_name" required></label><label>CIF<input name="cif"></label><label class="wide">Dirección principal<input name="main_address" required></label><label>Código postal<input name="postal_code"></label><label>Ciudad<input name="city"></label><button>Guardar</button></form>';
        $this->page('Comunidades','<h1>Comunidades</h1>'.$form.'<section class="card"><table><tr><th>Nombre</th><th>Dirección</th><th>Estado</th></tr>'.$table.'</table></section>');
    }

    private function suppliers(): void
    {
        if ($_SERVER['REQUEST_METHOD']==='POST') {
            $this->db->execute('INSERT INTO suppliers(official_name,normalized_name,cif,main_service_type_id,active) VALUES (?,?,?,?,1)',[trim((string)$_POST['official_name']),Text::normalize((string)$_POST['official_name']),trim((string)($_POST['cif']??'')),(int)$_POST['service_id']?:null]);
            $id=(int)$this->db->pdo()->lastInsertId(); if((int)$_POST['service_id'])$this->db->execute('INSERT INTO supplier_service_types(supplier_id,service_type_id) VALUES (?,?)',[$id,(int)$_POST['service_id']]);
            $this->audit('create','supplier',$id);
            $this->redirect('/?route=suppliers');
        }
        $services=$this->db->all('SELECT * FROM service_types WHERE active=1 ORDER BY name'); $options=''; foreach($services as $service)$options.='<option value="'.$service['id'].'">'.$this->e($service['name']).'</option>';
        $rows=$this->db->all('SELECT s.*,st.name service FROM suppliers s LEFT JOIN service_types st ON st.id=s.main_service_type_id ORDER BY s.official_name'); $table=''; foreach($rows as $row)$table.='<tr><td>'.$this->e($row['official_name']).'</td><td>'.$this->e($row['cif']?:'—').'</td><td>'.$this->e($row['service']?:'—').'</td></tr>';
        $form='<form method="post" class="card grid"><input type="hidden" name="csrf" value="'.$this->auth->csrf().'"><h2>Añadir proveedor</h2><label>Nombre oficial<input name="official_name" required></label><label>CIF<input name="cif"></label><label>Servicio<select name="service_id" required><option value="">Seleccionar</option>'.$options.'</select></label><button>Guardar</button></form>';
        $this->page('Proveedores','<h1>Proveedores</h1>'.$form.'<section class="card"><table><tr><th>Nombre</th><th>CIF</th><th>Servicio</th></tr>'.$table.'</table></section>');
    }

    private function mailboxes(): void
    {
        if ($_SERVER['REQUEST_METHOD']==='POST') {
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
            $email=mb_strtolower(trim((string)$_POST['email']));
            $this->db->execute('INSERT INTO mailboxes(descriptive_name,email,imap_host,imap_port,use_ssl,username,encrypted_password,input_folder,active) VALUES (?,?,?,993,1,?,?,\'INBOX\',1)',[trim((string)$_POST['name']),$email,'imap.ionos.es',$email,$this->crypto->encrypt((string)$_POST['password'])]);
            $this->audit('create','mailbox',$this->db->pdo()->lastInsertId(),['email'=>$email]);
            $this->redirect('/?route=mailboxes');
        }
        $rows=$this->db->all('SELECT id,descriptive_name,email,active,last_connection_at,last_connection_ok,last_error FROM mailboxes ORDER BY descriptive_name'); $table='';
        foreach($rows as $row)$table.='<tr><td>'.$this->e($row['descriptive_name']).'</td><td>'.$this->e($row['email']).'</td><td>'.(!$row['active']?'Desactivado':($row['last_connection_ok']?'Conectado':'Sin comprobar/Error')).'</td><td><form method="post" action="/?route=mailboxes"><input type="hidden" name="csrf" value="'.$this->auth->csrf().'"><input type="hidden" name="action" value="test"><input type="hidden" name="id" value="'.$row['id'].'"><button>Probar conexión</button></form></td></tr>';
        $form='<form method="post" class="card grid"><input type="hidden" name="csrf" value="'.$this->auth->csrf().'"><h2>Añadir correo IONOS</h2><label>Nombre<input name="name" required></label><label>Dirección<input type="email" name="email" required></label><label>Contraseña<input type="password" name="password" required autocomplete="new-password"></label><button>Guardar cifrado</button></form>';
        $this->page('Correos','<h1>Correos</h1><p>El worker revisará estas cuentas en cada ejecución programada.</p>'.$form.'<section class="card"><table><tr><th>Nombre</th><th>Dirección</th><th>Estado</th><th></th></tr>'.$table.'</table></section>');
    }

    private function reviews(): void
    {
        if($_SERVER['REQUEST_METHOD']==='POST'){
            $attachment=$this->db->one('SELECT * FROM processed_attachments WHERE id=?',[(int)$_POST['id']]);
            $community=$this->db->one('SELECT * FROM communities WHERE id=? AND active=1',[(int)$_POST['community_id']]);
            $supplier=$this->db->one('SELECT * FROM suppliers WHERE id=? AND active=1',[(int)$_POST['supplier_id']]);
            if(!$attachment||!$community||!$supplier||!$attachment['output_path']||!is_file($attachment['output_path']))throw new \RuntimeException('No se puede confirmar esta factura');
            $invoice=['fecha_factura'=>(string)$_POST['invoice_date'],'tipo_servicio'=>(string)$_POST['service_type'],'proveedor'=>(string)$supplier['official_name']];
            $target=(new Archiver((string)$this->config['processing']['storage_root']))->archive((string)$attachment['output_path'],(string)$attachment['original_filename'],$invoice,$community,'classified');
            $this->db->execute("UPDATE processed_attachments SET community_id=?,provider=?,service_type=?,invoice_date=?,amount=?,invoice_number=?,confidence=100,
                final_filename=?,output_path=?,status='classified',error_message=NULL,processed_at=NOW() WHERE id=?",[$community['id'],$supplier['official_name'],$_POST['service_type'],$_POST['invoice_date'],$_POST['amount']?:null,$_POST['invoice_number']?:null,basename($target),$target,$attachment['id']]);
            $this->audit('confirm_classification','attachment',$attachment['id'],['community_id'=>$community['id'],'supplier_id'=>$supplier['id']]);
            $this->redirect('/?route=reviews');
        }
        $rows=$this->db->all("SELECT pa.*,c.official_name FROM processed_attachments pa LEFT JOIN communities c ON c.id=pa.community_id WHERE pa.status IN ('unclassified','needs_review','error') ORDER BY pa.processed_at DESC LIMIT 200");
        $communities=$this->db->all('SELECT id,official_name FROM communities WHERE active=1 ORDER BY official_name');$suppliers=$this->db->all('SELECT id,official_name FROM suppliers WHERE active=1 ORDER BY official_name');$services=$this->db->all('SELECT normalized_name,name FROM service_types WHERE active=1 ORDER BY name');
        $communityOptions='';foreach($communities as $item)$communityOptions.='<option value="'.$item['id'].'">'.$this->e($item['official_name']).'</option>';
        $supplierOptions='';foreach($suppliers as $item)$supplierOptions.='<option value="'.$item['id'].'">'.$this->e($item['official_name']).'</option>';
        $serviceOptions='';foreach($services as $item)$serviceOptions.='<option value="'.$this->e($item['normalized_name']).'">'.$this->e($item['name']).'</option>';
        $cards=''; foreach($rows as $row)$cards.='<article class="card"><h2>'.$this->e($row['original_filename']).'</h2><p>Proveedor detectado: <strong>'.$this->e($row['provider']?:'Desconocido').'</strong> · Comunidad: '.$this->e($row['official_name']?:'Sin asignar').'</p><p>'.$this->e($row['error_message']?:'Necesita confirmación manual').'</p>'.($row['output_path']?'<a class="button" href="/?route=download&id='.$row['id'].'">Descargar</a>':'').
            '<form method="post" action="/?route=reviews" class="grid"><input type="hidden" name="csrf" value="'.$this->auth->csrf().'"><input type="hidden" name="id" value="'.$row['id'].'"><label>Comunidad<select name="community_id" required><option value="">Seleccionar</option>'.$communityOptions.'</select></label><label>Proveedor<select name="supplier_id" required><option value="">Seleccionar</option>'.$supplierOptions.'</select></label><label>Servicio<select name="service_type" required>'.$serviceOptions.'</select></label><label>Fecha<input type="date" name="invoice_date" value="'.$this->e($row['invoice_date']).'" required></label><label>Importe<input name="amount" value="'.$this->e($row['amount']).'"></label><label>Número<input name="invoice_number" value="'.$this->e($row['invoice_number']).'"></label><button>Confirmar y archivar</button></form></article>';
        $this->page('Revisar','<h1>Facturas que necesitan ayuda</h1>'.($cards !== '' ? $cards : '<section class="status ok"><b>✓</b><span><strong>Todo está al día</strong></span></section>'));
    }

    private function storage(): void
    {
        $root=(string)$this->config['processing']['storage_root'];
        $this->page('Almacenamiento','<h1>Almacenamiento</h1><section class="card"><p>Las facturas se guardan en la carpeta privada configurada por el administrador del hosting.</p><code>'.$this->e($root).'</code><p>Estado: <strong>'.(is_dir($root)&&is_writable($root)?'Disponible':'No escribible').'</strong></p></section>');
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
        header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: SAMEORIGIN'); header("Content-Security-Policy: default-src 'self'; style-src 'self'");
        $nav=$navigation?'<nav><a href="/">Inicio</a><a href="/?route=communities">Comunidades</a><a href="/?route=suppliers">Proveedores</a><a href="/?route=mailboxes">Correos</a><a href="/?route=storage">Almacenamiento</a><a href="/?route=reviews">Revisar</a><a href="/?route=logout">Salir</a></nav>':'';
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$this->e($title).' · Salvest</title><link rel="stylesheet" href="/assets/app.css"></head><body><header><a class="brand" href="/">Gestión de facturas</a>'.$nav.'</header><main>'.$body.'</main></body></html>';
    }
    private function e(mixed $value): string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    /** @param array<string,mixed>|null $values */
    private function audit(string $action,string $entity,string|int $id,?array $values=null):void
    {
        $this->db->execute('INSERT INTO audit_log(user_id,action,entity_type,entity_id,new_values_json,ip_address) VALUES (?,?,?,?,?,?)',[
            $this->auth->userId(),$action,$entity,(string)$id,$values?json_encode($values,JSON_UNESCAPED_UNICODE):null,$_SERVER['REMOTE_ADDR']??null]);
    }
    private function redirect(string $path): never{header('Location: '.$path, true, 302);exit;}
    private function notFound(): never{http_response_code(404);echo 'No encontrado';exit;}
}

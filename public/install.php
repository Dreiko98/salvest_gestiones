<?php
declare(strict_types=1);

$root = is_file(__DIR__ . '/bootstrap.php') ? __DIR__ : dirname(__DIR__);
$config = require $root . '/bootstrap.php';
$lock = $root . '/storage/install.lock';
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
if ($token === '' || !hash_equals((string)$config['app']['cron_token'],$token)) { http_response_code(404); exit('No encontrado'); }
$message=''; $error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if (is_file($lock)) throw new RuntimeException('La instalación ya está bloqueada.');
        $username=mb_strtolower(trim((string)($_POST['username']??''))); $password=(string)($_POST['password']??'');
        if($username===''||strlen($password)<12)throw new RuntimeException('Usuario obligatorio y contraseña de al menos 12 caracteres.');
        $db=new Salvest\Database($config['database']); Salvest\Schema::migrate($db,$root.'/database/schema.sql');
        $db->execute("INSERT INTO users(username,password_hash,display_name) VALUES (?,?,?) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash),active=1",
            [$username,password_hash($password,PASSWORD_DEFAULT),'Administrador']);
        if(file_put_contents($lock,date(DATE_ATOM),LOCK_EX)===false)throw new RuntimeException('No se pudo crear el bloqueo de instalación.');
        $message='Instalación terminada. Ya puedes entrar en la aplicación.';
    }catch(Throwable $exception){$error=$exception->getMessage();}
}
$e=static fn(string $value):string=>htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalar Salvest</title><link rel="stylesheet" href="/assets/app.css"></head><body><main><section class="card login"><h1>Instalación inicial</h1><?php if($message):?><p><?=$e($message)?></p><a class="button" href="/">Abrir aplicación</a><?php elseif($error):?><p class="error"><?=$e($error)?></p><?php endif;?><?php if(!$message):?><form method="post"><input type="hidden" name="token" value="<?=$e($token)?>"><label>Usuario administrador<input name="username" required></label><label>Contraseña inicial<input type="password" name="password" minlength="12" required></label><button>Crear tablas y administrador</button></form><?php endif;?></section></main></body></html>

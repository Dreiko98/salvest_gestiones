<?php
declare(strict_types=1);

$root = is_file(__DIR__ . '/bootstrap.php') ? __DIR__ : dirname(__DIR__);
$config = require $root . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
$token = (string)($_GET['token'] ?? '');
if ($token === '' || !hash_equals((string)$config['app']['cron_token'], $token)) {
    http_response_code(404); echo json_encode(['error'=>'not_found']); exit;
}
ignore_user_abort(true); set_time_limit(0);
try {
    $db = new Salvest\Database($config['database']);
    Salvest\Schema::migrate($db,$root.'/database/schema.sql');
    $worker = new Salvest\Worker($db,new Salvest\Crypto($config['app']['encryption_key']),new Salvest\OpenAIExtractor($config['openai']),$config);
    echo json_encode(['status'=>'ok','counts'=>$worker->run(false,(int)$config['imap']['max_messages_per_mailbox'])],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('cron status=error '.$error->getMessage());
    http_response_code(500); echo json_encode(['status'=>'error','message'=>'El ciclo no pudo completarse']);
}

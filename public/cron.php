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
    $rollover=null;
    if((bool)($config['google_drive']['enabled']??false)){
        $tokens=new Salvest\GoogleUserOAuthProvider((string)$config['google_drive']['oauth_client_file'],(string)$config['google_drive']['oauth_token_file']);
        $rollover=(new Salvest\DriveYearRollover($db,new Salvest\GoogleDriveClient($tokens),(string)$config['google_drive']['root_folder_id']))->runIfNeeded();
    }
    // Fase 8: Worker::create() is the single place that wires which extractor is actually used
    // (Claude primary, OpenAI fallback) — never duplicate that wiring here, so cron can never
    // drift from the manual "Ejecutar bot ahora" button or bin/worker.php.
    $worker = Salvest\Worker::create($db,$config);
    echo json_encode(['status'=>'ok','year_rollover'=>$rollover,'counts'=>$worker->run(false,(int)$config['imap']['max_messages_per_mailbox'])],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('cron status=error '.$error->getMessage());
    http_response_code(500); echo json_encode(['status'=>'error','message'=>'El ciclo no pudo completarse']);
}

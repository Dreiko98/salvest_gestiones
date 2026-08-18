#!/usr/bin/env php
<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/bootstrap.php';
$dryRun = in_array('--dry-run',$argv,true);
// --debug: verbose, real-time, timestamped stdout of the whole pipeline for a single run —
// message/UID detection, attachment/SHA-256, both OpenAI calls (model/latency/tokens/full JSON),
// community/supplier/service resolution tier by tier, final decision and effects. Never changes
// what the Worker decides — it only observes via DebugTracer, which is a no-op when disabled.
// Never prints secrets (API keys, IMAP passwords, cron token, OAuth tokens, cookies) — see
// DebugTracer's redaction pattern. Combine with --mailbox and --max-emails to follow one message
// through the whole pipeline comfortably:
//   php bin/worker.php --debug --mailbox correo@ejemplo.com --max-emails 1
$debug = in_array('--debug',$argv,true);
$limit = null;
$mailbox = null;
foreach ($argv as $index=>$value) if ($value === '--max-emails' && isset($argv[$index+1])) $limit=(int)$argv[$index+1];
foreach ($argv as $index=>$value) if ($value === '--mailbox' && isset($argv[$index+1])) $mailbox=$argv[$index+1];
$db = new Salvest\Database($config['database']);
Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
if(!$dryRun&&(bool)($config['google_drive']['enabled']??false)){
    $tokens=new Salvest\GoogleUserOAuthProvider((string)$config['google_drive']['oauth_client_file'],(string)$config['google_drive']['oauth_token_file']);
    (new Salvest\DriveYearRollover($db,new Salvest\GoogleDriveClient($tokens),(string)$config['google_drive']['root_folder_id']))->runIfNeeded();
}
$worker = new Salvest\Worker($db,new Salvest\Crypto($config['app']['encryption_key']),new Salvest\OpenAIExtractor($config['openai']),$config,new Salvest\DebugTracer($debug));
$counts = $worker->run($dryRun,$limit,$mailbox);
fwrite(STDOUT,json_encode($counts,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL);

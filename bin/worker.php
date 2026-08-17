#!/usr/bin/env php
<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/bootstrap.php';
$dryRun = in_array('--dry-run',$argv,true);
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
$worker = new Salvest\Worker($db,new Salvest\Crypto($config['app']['encryption_key']),new Salvest\AnthropicExtractor($config['anthropic']),$config);
$counts = $worker->run($dryRun,$limit,$mailbox);
fwrite(STDOUT,json_encode($counts,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL);

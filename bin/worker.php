#!/usr/bin/env php
<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/bootstrap.php';
$dryRun = in_array('--dry-run',$argv,true);
$limit = null;
foreach ($argv as $index=>$value) if ($value === '--max-emails' && isset($argv[$index+1])) $limit=(int)$argv[$index+1];
$db = new Salvest\Database($config['database']);
$worker = new Salvest\Worker($db,new Salvest\Crypto($config['app']['encryption_key']),new Salvest\OpenAIExtractor($config['openai']),$config);
$counts = $worker->run($dryRun,$limit);
fwrite(STDOUT,json_encode($counts,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL);

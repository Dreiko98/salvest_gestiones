#!/usr/bin/env php
<?php
declare(strict_types=1);

$config=require dirname(__DIR__).'/bootstrap.php';
$checks=[];
foreach(['curl','json','mbstring','openssl','pdo','pdo_mysql','sodium'] as $extension)$checks['ext_'.$extension]=extension_loaded($extension);
foreach(['storage/incoming','storage/invoices'] as $folder){$path=dirname(__DIR__).'/'.$folder;$checks[$folder]=is_dir($path)&&is_writable($path);}
try{$db=new Salvest\Database($config['database']);$checks['mysql']=$db->one('SELECT VERSION() version')['version'];}catch(Throwable $error){$checks['mysql']='ERROR: '.$error->getMessage();}
foreach($checks as $name=>$result)echo $name.'='.(is_bool($result)?($result?'OK':'MISSING'):$result).PHP_EOL;

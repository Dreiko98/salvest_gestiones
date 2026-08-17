#!/usr/bin/env php
<?php
declare(strict_types=1);

$config=require dirname(__DIR__).'/bootstrap.php';
$path=$argv[1]??'';
if($path===''||!is_file($path)){fwrite(STDERR,"Uso: php bin/import-communities.php /ruta/comunidades.csv\n");exit(2);}
$db=new Salvest\Database($config['database']);
Salvest\Schema::migrate($db,dirname(__DIR__).'/database/schema.sql');
$result=(new Salvest\CommunityCsvImporter($db))->replaceFrom($path);
fwrite(STDOUT,json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL);

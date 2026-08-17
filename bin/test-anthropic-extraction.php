#!/usr/bin/env php
<?php
declare(strict_types=1);

$config=require dirname(__DIR__).'/bootstrap.php';
$path=$argv[1]??'';
if($path===''||!is_file($path)){fwrite(STDERR,"Uso: php bin/test-anthropic-extraction.php /ruta/factura.pdf\n");exit(2);}
if(($config['anthropic']['api_key']??'')===''){fwrite(STDERR,"Falta configurar ANTHROPIC_API_KEY.\n");exit(2);}
$extractor=new Salvest\AnthropicExtractor($config['anthropic']);
$result=$extractor->extract($path,'application/pdf','Prueba sintética aislada; no procede de ningún buzón real.');
fwrite(STDOUT,json_encode(['model'=>$config['anthropic']['model'],'extraction'=>$result,'usage'=>['input_tokens'=>$extractor->inputTokens,'output_tokens'=>$extractor->outputTokens]],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL);

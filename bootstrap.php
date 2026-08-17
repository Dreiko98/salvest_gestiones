<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Salvest\\';
    if (!str_starts_with($class, $prefix)) return;
    $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) require $path;
});

$configFile = getenv('SALVEST_CONFIG') ?: __DIR__ . '/config/config.php';
if (!is_file($configFile)) {
    throw new RuntimeException('Falta config/config.php; copia config/config.example.php y completa los secretos.');
}
/** @var array<string,mixed> $config */
$config = require $configFile;
$driveConfigFile=__DIR__.'/config/google_drive.php';
if(is_file($driveConfigFile)){
    $driveConfig=require $driveConfigFile;
    if(!is_array($driveConfig))throw new RuntimeException('config/google_drive.php no devuelve una configuración válida');
    $config['google_drive']=array_replace($config['google_drive']??[],$driveConfig);
}
date_default_timezone_set((string)($config['app']['timezone'] ?? 'Europe/Madrid'));
return $config;

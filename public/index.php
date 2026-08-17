<?php
declare(strict_types=1);

$root = is_file(__DIR__ . '/bootstrap.php') ? __DIR__ : dirname(__DIR__);
$config = require $root . '/bootstrap.php';
(new Salvest\WebApp(new Salvest\Database($config['database']),$config))->run();

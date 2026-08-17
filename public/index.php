<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/bootstrap.php';
(new Salvest\WebApp(new Salvest\Database($config['database']),$config))->run();

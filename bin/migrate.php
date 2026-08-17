#!/usr/bin/env php
<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/bootstrap.php';
$database = new Salvest\Database($config['database']);
Salvest\Schema::migrate($database, dirname(__DIR__) . '/database/schema.sql');
fwrite(STDOUT, "Migración MySQL completada.\n");

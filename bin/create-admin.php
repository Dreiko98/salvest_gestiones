#!/usr/bin/env php
<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/bootstrap.php';
$username = $argv[1] ?? '';
$password = $argv[2] ?? '';
if ($username === '' || strlen($password) < 12) {
    fwrite(STDERR, "Uso: php bin/create-admin.php usuario contraseña-de-12-o-más\n"); exit(2);
}
$database = new Salvest\Database($config['database']);
$database->execute("INSERT INTO users(username,password_hash,display_name) VALUES (?,?,?)
    ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash),active=1",
    [mb_strtolower(trim($username)), password_hash($password, PASSWORD_DEFAULT), 'Administrador']);
fwrite(STDOUT, "Administrador creado o actualizado.\n");

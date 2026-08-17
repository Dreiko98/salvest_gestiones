<?php
declare(strict_types=1);

namespace Salvest;

final class Schema
{
    public static function migrate(Database $database, string $file): void
    {
        $sql = file_get_contents($file);
        if ($sql === false) throw new \RuntimeException("No se pudo leer $file");
        $database->pdo()->exec($sql);
        $database->execute("INSERT IGNORE INTO schema_migrations(version) VALUES ('0001_initial')");
    }
}

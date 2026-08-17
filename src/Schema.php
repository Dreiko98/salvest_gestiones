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
        self::column($database,'communities','external_code','VARCHAR(20) NULL AFTER id');
        self::column($database,'communities','drive_folder_id','VARCHAR(190) NULL AFTER imap_folder_name');
        self::column($database,'processed_attachments','drive_file_id','VARCHAR(190) NULL AFTER extractor_version');
        self::column($database,'processed_attachments','drive_path','VARCHAR(1000) NULL AFTER drive_file_id');
        self::column($database,'processed_attachments','drive_status','VARCHAR(50) NULL AFTER drive_path');
        $index=$database->one("SELECT 1 ok FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='communities' AND index_name='uq_communities_external_code'");
        if(!$index)$database->execute('ALTER TABLE communities ADD UNIQUE KEY uq_communities_external_code(external_code)');
        $database->execute("INSERT IGNORE INTO schema_migrations(version) VALUES ('0001_initial')");
        $database->execute("INSERT IGNORE INTO schema_migrations(version) VALUES ('0002_real_drive_structure')");
    }

    private static function column(Database $database,string $table,string $column,string $definition):void
    {
        $exists=$database->one('SELECT 1 ok FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',[$table,$column]);
        if(!$exists)$database->execute("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

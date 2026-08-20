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
        self::column($database,'processed_attachments','raw_supplier_name','VARCHAR(255) NULL AFTER provider');
        self::column($database,'processed_attachments','drive_file_id','VARCHAR(190) NULL AFTER extractor_version');
        self::column($database,'processed_attachments','drive_path','VARCHAR(1000) NULL AFTER drive_file_id');
        self::column($database,'processed_attachments','drive_status','VARCHAR(50) NULL AFTER drive_path');
        self::column($database,'processed_attachments','debug_trace_json','JSON NULL AFTER decision_json');
        self::column($database,'processed_attachments','requeued_at','DATETIME NULL AFTER debug_trace_json');
        self::column($database,'processing_runs','triggered_by_user_id','INT UNSIGNED NULL AFTER trigger_type');
        self::column($database,'processing_runs','needs_review_count','INT UNSIGNED NOT NULL DEFAULT 0 AFTER unclassified_count');
        $index=$database->one("SELECT 1 ok FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='communities' AND index_name='uq_communities_external_code'");
        if(!$index)$database->execute('ALTER TABLE communities ADD UNIQUE KEY uq_communities_external_code(external_code)');
        // Backs Worker::isDismissedNotInvoice()'s lookup — recognising a dismissed email by
        // Message-ID across an IMAP move happens on every processed message, every cycle.
        self::index($database,'processed_messages','idx_processed_messages_message_id','message_id_header');
        self::mailboxBaselineMigration($database);
        self::supplierMasterDataScaffolding($database);
        $database->execute("INSERT IGNORE INTO schema_migrations(version) VALUES ('0001_initial')");
        $database->execute("INSERT IGNORE INTO schema_migrations(version) VALUES ('0002_real_drive_structure')");
    }

    /**
     * Fase 2 del maestro de proveedores: solo estructura, ninguna columna se lee ni se escribe
     * todavía. `name`/`normalized_official_name` quedan NULL para todos los proveedores
     * existentes hasta la migración de datos de Fase 3 — Classifier sigue usando exactamente
     * `official_name`/`normalized_name` como hoy, sin ningún cambio de comportamiento.
     */
    private static function supplierMasterDataScaffolding(Database $database): void
    {
        self::column($database,'suppliers','name','VARCHAR(255) NULL AFTER id');
        self::column($database,'suppliers','normalized_official_name','VARCHAR(255) NULL AFTER official_name');
        self::index($database,'suppliers','idx_supplier_normalized_official','normalized_official_name');
        // No UNIQUE en cif: hay duplicados conocidos (EXTNCAS/EXTINCAS, ENERVIA) pendientes de
        // fusionar en Fase 3; forzarla ahora bloquearía esa migración.
        self::index($database,'suppliers','idx_supplier_cif','cif');
        self::uniqueIndex($database,'supplier_aliases','uq_supplier_alias','supplier_id,normalized_value');
    }

    /**
     * One-time migration (guarded by schema_migrations, never re-runs): adds the baseline columns
     * used to stop new mailboxes from processing pre-existing mail, and — critically — backfills
     * every mailbox that already exists at this point with process_existing_on_activate=1, so
     * pre-existing mailboxes keep behaving exactly as before. Only mailboxes created *after* this
     * migration ran get the new safe-by-default behaviour (process_existing_on_activate=0 unless
     * the admin opts in from the mailbox form). Must stay a plain UPDATE guarded by the version
     * flag, never an unconditional one: run on every cron cycle it would also flip newly created
     * mailboxes that deliberately kept the default off.
     */
    private static function mailboxBaselineMigration(Database $database): void
    {
        $done=$database->one("SELECT 1 ok FROM schema_migrations WHERE version='0003_mailbox_baseline'");
        if($done)return;
        self::column($database,'mailboxes','process_existing_on_activate','TINYINT(1) NOT NULL DEFAULT 0 AFTER active');
        self::column($database,'mailboxes','baseline_uidvalidity','VARCHAR(50) NULL AFTER process_existing_on_activate');
        self::column($database,'mailboxes','baseline_uid','BIGINT UNSIGNED NULL AFTER baseline_uidvalidity');
        self::column($database,'mailboxes','baseline_captured_at','DATETIME NULL AFTER baseline_uid');
        $database->execute('UPDATE mailboxes SET process_existing_on_activate=1');
        $database->execute("INSERT IGNORE INTO schema_migrations(version) VALUES ('0003_mailbox_baseline')");
    }

    private static function column(Database $database,string $table,string $column,string $definition):void
    {
        $exists=$database->one('SELECT 1 ok FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',[$table,$column]);
        if(!$exists)$database->execute("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }

    private static function index(Database $database,string $table,string $indexName,string $column):void
    {
        $exists=$database->one('SELECT 1 ok FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?',[$table,$indexName]);
        if(!$exists)$database->execute("ALTER TABLE `$table` ADD INDEX `$indexName` (`$column`)");
    }

    /** Same idempotency contract as index(), for a UNIQUE constraint. $columns is a raw,
     * comma-separated, already-backtick-free column list (e.g. "supplier_id,normalized_value"). */
    private static function uniqueIndex(Database $database,string $table,string $indexName,string $columns):void
    {
        $exists=$database->one('SELECT 1 ok FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?',[$table,$indexName]);
        if(!$exists)$database->execute("ALTER TABLE `$table` ADD UNIQUE KEY `$indexName` ($columns)");
    }
}

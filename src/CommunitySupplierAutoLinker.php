<?php
declare(strict_types=1);

namespace Salvest;

/**
 * Fase 7: the only place in the codebase that ever INSERTs into `community_suppliers`
 * automatically. Deliberately narrow — it never decides WHETHER a relation should be learned
 * (that eligibility check — global source, unambiguous, active supplier, a real master-
 * configured service — lives in InvoiceRouter::route(), which already has everything it needs
 * to decide that without touching the database itself). This class only knows how to link two
 * IDs that already exist, safely: never creates a supplier or a community, never touches an
 * existing relation, never guesses a category — the caller must already know it.
 *
 * Race safety, corrected twice after empirical findings — both verified with two independent
 * MySQL connections/transactions racing for real, not two sequential calls on one connection:
 *
 * 1) The original design trusted `SELECT ... FOR UPDATE WHERE community_id=? AND supplier_id=?`
 *    alone against the *3-column* UNIQUE(community_id,supplier_id,category) to block a
 *    concurrent insert of a *different* category for the same pair. It didn't: both
 *    transactions acquired their lock immediately and both proceeded to INSERT, and MySQL
 *    resolved the conflict with a genuine deadlock (error 1213) rather than a clean
 *    serialisation. Fixed by adding a real `UNIQUE(community_id, supplier_id)` to the schema
 *    (see Schema.php's migration comment — confirmed safe: 0 of the 292 real production pairs
 *    violated it).
 *
 * 2) That alone still wasn't enough. Doing a `SELECT ... FOR UPDATE` first (to decide whether to
 *    insert) and only then running the `INSERT` is the textbook InnoDB anti-pattern for
 *    INSERT-INSERT deadlocks: two transactions racing into the same *gap* (no row yet) each
 *    acquire a gap lock on that first SELECT, then each try to upgrade to an insert lock on the
 *    same gap when they INSERT — a real, still-reproducible deadlock, even with the correct
 *    UNIQUE constraint in place. The fix is to never take that detour: go straight to
 *    `INSERT ... ON DUPLICATE KEY UPDATE id=id` as the *only* write attempt. Two concurrent
 *    inserts for the same key now genuinely serialise on that one real unique key — one gets
 *    affected-rows=1 (it inserted), the other blocks briefly then resolves as an untouched
 *    duplicate (affected-rows=0) — never a deadlock. Re-verified empirically after this second
 *    fix with the same two-connection race, including different categories — see
 *    tests/run.php's dedicated concurrency test.
 */
final class CommunitySupplierAutoLinker
{
    public function __construct(private Database $db) {}

    /** Historical `community_suppliers.category` values are plain ASCII uppercase (confirmed
     * against all 292 real rows: "AGUA", "EXTINTORES", "JARDINERIA" — no accents), while
     * `service_types.name` is Title Case with accents ("Agua", "Jardinería"). Autolinked rows
     * follow the existing historical convention, not the master's cosmetic casing, so a mixed
     * community_suppliers table stays consistent for anything that already reads `category` as
     * a plain key (Classifier::resolveSupplierInCommunity()'s community+service tier normalizes
     * it anyway, so this is about consistency for humans/diagnostics, not a functional need). */
    public static function canonicalCategory(string $serviceTypeName): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $serviceTypeName) ?: $serviceTypeName;
        return mb_strtoupper($ascii, 'UTF-8');
    }

    /**
     * @return array{inserted:bool,reason:string,relation:?array,error:?string} reason is one of:
     *   'missing_relation' (inserted=true), 'relation_already_exists', 'multiple_existing_categories'
     *   (kept as defensive/historical reporting only — with UNIQUE(community_id,supplier_id) in
     *   place, no writer can create this state going forward; the check still runs in case this
     *   ever executes against a database where that migration hasn't landed yet), or
     *   'insert_failed' (a genuine, unexpected DB error — caught here so a failed *learning*
     *   write can never turn an otherwise-valid classification into an error). `error` carries the
     *   full exception message for error_log() only — callers must never forward it into
     *   trace/decision_json/UI, only `reason`.
     */
    public function linkIfMissing(int $communityId, int $supplierId, string $category, string $rawProviderName): array
    {
        $category = self::canonicalCategory($category);
        try {
            // Single atomic write attempt — deliberately NOT preceded by its own SELECT ... FOR
            // UPDATE (see class docblock, point 2): that two-step pattern is what caused a real
            // deadlock under concurrency even with the correct UNIQUE constraint in place.
            $affected = $this->db->execute(
                'INSERT INTO community_suppliers(community_id,supplier_id,category,contract_reference,source_column,raw_provider_name)
                 VALUES (?,?,?,NULL,?,?) ON DUPLICATE KEY UPDATE id=id',
                [$communityId, $supplierId, $category, 'auto_global_resolution', $rawProviderName]
            );
            if ($affected === 1) {
                $relation = $this->db->one('SELECT * FROM community_suppliers WHERE community_id=? AND supplier_id=?', [$communityId, $supplierId]);
                return ['inserted' => true, 'reason' => 'missing_relation', 'relation' => $relation, 'error' => null];
            }
            // affected === 0: the unique key already existed, nothing changed — read back
            // what's actually there now to decide which "already resolved" reason applies.
            $allForPair = $this->db->all('SELECT * FROM community_suppliers WHERE community_id=? AND supplier_id=?', [$communityId, $supplierId]);
            if (count($allForPair) > 1) {
                return ['inserted' => false, 'reason' => 'multiple_existing_categories', 'relation' => null, 'error' => null];
            }
            return ['inserted' => false, 'reason' => 'relation_already_exists', 'relation' => $allForPair[0] ?? null, 'error' => null];
        } catch (\Throwable $error) {
            error_log('community_supplier_auto_link status=failed community_id='.$communityId.' supplier_id='.$supplierId.' error='.$error->getMessage());
            return ['inserted' => false, 'reason' => 'insert_failed', 'relation' => null, 'error' => $error->getMessage()];
        }
    }
}

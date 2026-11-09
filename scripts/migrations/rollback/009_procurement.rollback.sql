-- 009 · procurement — ROLLBACK
--
-- Lives in scripts/migrations/rollback/ rather than beside its migration on
-- purpose. Three scripts treat every *.sql file in scripts/migrations/ as a
-- migration and would apply this one in forward order if it sat there:
--
--   scripts/migrate.mjs          (the runner — checksums and applies)
--   scripts/pg-migrate.mjs       (the SQLite→PostgreSQL rehearsal)
--   scripts/test-integrity.mjs   (applies all migrations, then asserts)
--
-- check-migration-coverage.mjs, verify-docs.mjs and the schema-drift audit also
-- enumerate that directory, so a rollback in place would corrupt the table
-- count, the constraint count and the applied checksum ledger. The subdirectory
-- is invisible to all of them.
--
-- HOW TO USE IT
--
--   node scripts/migrate.mjs --dsn <url> --rollback 009
--
-- A rollback is NOT a routine operation. It is the documented recovery path for
-- a migration that has gone wrong within its first minutes, before any real
-- procurement data exists. Once suppliers and purchase orders carry history,
-- rolling back destroys that history — and the ledger rows that reference it
-- would survive as orphans, because stock_movements carries no FK to the
-- receipt that caused it (deliberately: an audit trail outlives its subject).
--
-- Consequently this file does not silently cascade. It refuses to run while
-- procurement rows exist, and says how many.

DO $$
DECLARE
  n_po  BIGINT;
  n_grn BIGINT;
BEGIN
  SELECT count(*) INTO n_po  FROM purchase_orders;
  SELECT count(*) INTO n_grn FROM goods_receipts;

  IF n_po > 0 OR n_grn > 0 THEN
    RAISE EXCEPTION
      'refusing to roll back 009: % purchase order(s) and % goods receipt(s) exist. Ledger rows written by those receipts would be orphaned. Delete the procurement data deliberately first if that is genuinely intended.',
      n_po, n_grn;
  END IF;
END $$;

/* Ledger rows are only expected here from a rehearsal, but the check above
   covers the case that matters. Delete defensively anyway so a rollback on a
   freshly-migrated environment leaves no trace. */
DROP INDEX IF EXISTS uq_stock_movements_receipt_ref;

DROP TABLE IF EXISTS goods_receipt_lines;
DROP TABLE IF EXISTS goods_receipts;
DROP TABLE IF EXISTS purchase_order_lines;
DROP TABLE IF EXISTS purchase_orders;
DROP TABLE IF EXISTS suppliers;

/* Restore the role vocabulary 002 declared. Any user holding the purchasing
   role would make this fail — correctly, because silently demoting a user is
   worse than a refused rollback. */
DO $$
DECLARE
  n_purchasing BIGINT;
BEGIN
  SELECT count(*) INTO n_purchasing FROM users WHERE "role" = 'purchasing';
  IF n_purchasing > 0 THEN
    RAISE EXCEPTION
      'refusing to roll back 009: % user(s) hold the purchasing role. Reassign them first.',
      n_purchasing;
  END IF;
END $$;

ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_known;
ALTER TABLE users ADD CONSTRAINT users_role_known
  CHECK ("role" IN ('owner','editor','sales','finance','viewer'));

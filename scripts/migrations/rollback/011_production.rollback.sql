-- 011 · production — ROLLBACK
--
-- Lives in scripts/migrations/rollback/ because every *.sql file in
-- scripts/migrations/ is applied as a forward migration by migrate.mjs,
-- pg-migrate.mjs and test-integrity.mjs.
--
--   node scripts/migrate.mjs --dsn <url> --rollback 011
--
-- Production is the one module where rolling back is materially destructive:
-- job cards are the record of what was BUILT, and the ledger rows they wrote
-- are the record of what was consumed. Dropping the tables while those rows
-- exist would leave stock movements nobody can explain, pointing at a receipt
-- of work that has been erased.
--
-- This refuses unless the factory floor is genuinely empty.

DO $$
DECLARE
  n_jobs    BIGINT;
  n_issued  BIGINT;
BEGIN
  SELECT count(*) INTO n_jobs FROM job_cards;
  SELECT count(*) INTO n_issued FROM stock_movements
   WHERE reason = 'production';

  IF n_jobs > 0 OR n_issued > 0 THEN
    RAISE EXCEPTION
      'refusing to roll back 011: % job card(s) and % production ledger row(s) exist. Consumption that already happened cannot be un-happened by a schema change.',
      n_jobs, n_issued;
  END IF;
END $$;

/* Both names: an early revision of 011 created the issue guard as
   uq_stock_movements_production_issue with a predicate that forbade top-ups.
   Dropping whichever exists means a rollback is clean from either state. */
DROP INDEX IF EXISTS uq_stock_movements_production_issue;
DROP INDEX IF EXISTS uq_stock_movements_idem_key;
DROP INDEX IF EXISTS uq_stock_movements_production_output;
/* The column 011 added to stock_movements. Dropped last, after the index that
   used it is gone. */
ALTER TABLE stock_movements DROP COLUMN IF EXISTS idem_key;

DROP TABLE IF EXISTS job_card_materials;
DROP TABLE IF EXISTS job_cards;
DROP TABLE IF EXISTS bom_lines;
DROP TABLE IF EXISTS boms;

/* Restore 003's reason vocabulary. Any 'production' row would make this fail,
   which is correct — silently rewriting history to fit the old CHECK is worse. */
ALTER TABLE stock_movements DROP CONSTRAINT IF EXISTS stock_movements_reason_known;
ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_reason_known
  CHECK (reason IN ('receipt','sale','return','adjustment','transfer','wastage'));

/* Restore the pre-011 role vocabulary. */
DO $$
DECLARE
  n_prod BIGINT;
BEGIN
  SELECT count(*) INTO n_prod FROM users WHERE "role" = 'production';
  IF n_prod > 0 THEN
    RAISE EXCEPTION 'refusing to roll back 011: % user(s) hold the production role. Reassign them first.', n_prod;
  END IF;
END $$;

ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_known;
ALTER TABLE users ADD CONSTRAINT users_role_known
  CHECK ("role" IN ('owner','editor','sales','finance','purchasing','viewer'));

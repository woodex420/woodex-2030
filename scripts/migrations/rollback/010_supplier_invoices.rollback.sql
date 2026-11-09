-- 010 · supplier invoices — ROLLBACK
--
-- Lives in scripts/migrations/rollback/ for the same reason as 009: every *.sql
-- file in scripts/migrations/ is treated as a forward migration by
-- scripts/migrate.mjs, scripts/pg-migrate.mjs, scripts/test-integrity.mjs and
-- the docs verifier.
--
--   node scripts/migrate.mjs --dsn <url> --rollback 010
--
-- Money has moved or it has not. Once an invoice is posted or paid, rolling
-- back destroys the record of a payment that actually left the account, and the
-- supplier's statement will still show it. This refuses in that case rather
-- than producing a ledger that disagrees with the bank.

DO $$
DECLARE
  n_posted BIGINT;
  n_paid   BIGINT;
BEGIN
  SELECT count(*) INTO n_posted FROM supplier_invoices WHERE status <> 'draft';
  SELECT count(*) INTO n_paid   FROM supplier_payments;

  IF n_posted > 0 OR n_paid > 0 THEN
    RAISE EXCEPTION
      'refusing to roll back 010: % posted invoice(s) and % payment(s) exist. Money that left the bank cannot be un-recorded by a schema change.',
      n_posted, n_paid;
  END IF;
END $$;

DROP TABLE IF EXISTS supplier_payments;
DROP TABLE IF EXISTS supplier_invoice_lines;
DROP TABLE IF EXISTS supplier_invoices;

DELETE FROM meta WHERE key = 'procurement.match_tolerance';

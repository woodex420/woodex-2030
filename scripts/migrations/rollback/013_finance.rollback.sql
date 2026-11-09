-- 013 · finance depth — ROLLBACK
--
--   node scripts/migrate.mjs --dsn <url> --rollback 013
--
-- This is the most destructive rollback in the set, because a journal is not
-- derived data: it is the record of money that moved. Dropping it does not
-- "undo" the accounting, it destroys the only evidence that it happened, while
-- invoices and payments — which the journal explains — remain.
--
-- So it refuses while any entry exists. An empty journal rolls back cleanly,
-- which is what makes this usable on a database where the migration was applied
-- but nothing has posted yet.

DO $$
DECLARE
  n_entries BIGINT;
  n_expenses BIGINT;
BEGIN
  SELECT count(*) INTO n_entries FROM journal_entries;
  SELECT count(*) INTO n_expenses FROM expenses;

  IF n_entries > 0 OR n_expenses > 0 THEN
    RAISE EXCEPTION
      'refusing to roll back 013: % journal entrie(s) and % expense(s) exist. A ledger is evidence; dropping it erases the record of money that actually moved.',
      n_entries, n_expenses;
  END IF;
END $$;

DROP TABLE IF EXISTS payment_schedules;
DROP TABLE IF EXISTS tax_records;
DROP TABLE IF EXISTS expenses;
DROP TABLE IF EXISTS credit_notes;

/* The triggers go with the tables they guard; the functions are separate
   objects and have to be dropped explicitly. */
DROP TRIGGER IF EXISTS trg_journal_entry_balanced ON journal_lines;
DROP TRIGGER IF EXISTS trg_journal_lines_no_update ON journal_lines;
DROP TRIGGER IF EXISTS trg_journal_entries_stable ON journal_entries;
DROP FUNCTION IF EXISTS journal_entry_must_balance();
DROP FUNCTION IF EXISTS journal_lines_are_immutable();
DROP FUNCTION IF EXISTS journal_entries_header_is_stable();

DROP TABLE IF EXISTS journal_lines;
DROP TABLE IF EXISTS journal_entries;
DROP TABLE IF EXISTS accounts;

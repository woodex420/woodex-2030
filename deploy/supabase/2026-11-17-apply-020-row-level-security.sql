-- Migration 020 (row-level security) as it was applied to the shop's live database.
--
-- WHERE AND WHEN: Supabase project "woodex-2030", 17 November 2026, 16:07:45 UTC, through Supabase's own SQL
-- endpoint, as the role "postgres". It took 167 ms. By the owner's answer of that day (decision DL-026, answer 3;
-- how it was carried out: DL-028; master list T-163).
--
-- WHAT THIS FILE IS: the exact text that was sent, kept as the record. It is NOT a second migration and is not run
-- again: sent a second time it refuses, because row 20 of the ledger exists. The migration itself is
-- scripts/migrations/020_row_level_security.sql; its block is in the middle of this file, word for word. Around it:
--   · a lock timeout, so that the change gives up after five seconds instead of queueing behind a long query;
--   · a guard that keeps nothing unless every table is locked, every table has the policy, and the role the shop's
--     API uses still counts the same products as before;
--   · row 20 of the migration ledger in the form the runner writes (scripts/migrate.mjs): the file's name and the
--     sha256 of the file.
-- All of it is one transaction.
--
-- READ BEFORE (16:05 UTC): 73 tables, all owned by "postgres"; none with row-level security; no policy; 19 ledger
-- rows; 148 products; the shop's API connected as "postgres" (seen in the database's own list of sessions).
-- REHEARSED FIRST on the embedded PostgreSQL built from migrations 001 to 019: applied; a failing guard kept
-- nothing; a second sending was refused.
-- READ AFTER (16:07:58 UTC): 73 of 73 tables with row-level security, none forced; the policy
-- "no_access_through_the_data_api" (for all commands, to public, false) on each; 20 ledger rows; 148 products for
-- the API's role. Asked from outside 67 seconds after the change, the shop served its 148 products
-- (.github/workflows/vercel-live-check.yml, run 44122391808).
--
-- THE WAY BACK: scripts/migrations/rollback/020_row_level_security.rollback.sql, then delete row 20 of the ledger
-- (the runner does both: node scripts/migrate.mjs --dsn <postgres-url> --rollback 020). No row of any table was
-- changed.

BEGIN;
SET LOCAL lock_timeout = '5s';
SET LOCAL search_path = public;
SELECT set_config('woodex.products_before', (SELECT count(*) FROM public.products)::text, true), set_config('woodex.started', extract(epoch from clock_timestamp())::text, true);
DO $$
DECLARE
  item      record;
  runner    text := current_user;
  foreign_n integer;
BEGIN
  SELECT count(*) INTO foreign_n
    FROM pg_class c
    JOIN pg_namespace n ON n.oid = c.relnamespace
   WHERE n.nspname = current_schema()
     AND c.relkind IN ('r', 'p')
     AND pg_get_userbyid(c.relowner) <> runner;

  IF foreign_n > 0 AND NOT EXISTS (
       SELECT 1 FROM pg_roles WHERE rolname = runner AND (rolsuper OR rolbypassrls)
     ) THEN
    RAISE EXCEPTION 'migration 020 refused: % table(s) of schema "%" are not owned by "%", and that role cannot bypass row-level security. Applied like this, the application would read nothing. Run it as the owner of the tables.',
      foreign_n, current_schema(), runner;
  END IF;

  FOR item IN
    SELECT c.relname
      FROM pg_class c
      JOIN pg_namespace n ON n.oid = c.relnamespace
     WHERE n.nspname = current_schema()
       AND c.relkind IN ('r', 'p')
     ORDER BY c.relname
  LOOP
    EXECUTE format('ALTER TABLE %I.%I ENABLE ROW LEVEL SECURITY', current_schema(), item.relname);

    IF NOT EXISTS (
         SELECT 1 FROM pg_policies
          WHERE schemaname = current_schema()
            AND tablename = item.relname
            AND policyname = 'no_access_through_the_data_api'
       ) THEN
      EXECUTE format(
        'CREATE POLICY no_access_through_the_data_api ON %I.%I FOR ALL TO PUBLIC USING (false) WITH CHECK (false)',
        current_schema(), item.relname);
    END IF;
  END LOOP;
END
$$;
DO $guard$
DECLARE
  unlocked integer;
  unpolicied integer;
  seen bigint;
BEGIN
  SELECT count(*) INTO unlocked FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND NOT c.relrowsecurity;
  SELECT count(*) INTO unpolicied FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND NOT EXISTS (SELECT 1 FROM pg_policies p WHERE p.schemaname = 'public' AND p.tablename = c.relname AND p.policyname = 'no_access_through_the_data_api');
  SELECT count(*) INTO seen FROM public.products;
  IF unlocked > 0 OR unpolicied > 0 OR seen::text <> current_setting('woodex.products_before') THEN
    RAISE EXCEPTION 'migration 020 not kept: % table(s) without row-level security, % without the policy, the application role sees % products (before: %)', unlocked, unpolicied, seen, current_setting('woodex.products_before');
  END IF;
END
$guard$;
INSERT INTO schema_migrations(version, name, checksum, applied_at, duration_ms) VALUES (20, '020_row_level_security.sql', '01910f1065815e69e17fa4dcc0800e39e31875050f9deb9247a4594818f8d83c', to_char(clock_timestamp() AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.MS"Z"'), round((extract(epoch from clock_timestamp()) - current_setting('woodex.started')::double precision) * 1000)::integer);
COMMIT;

-- 020 · row-level security — ROLLBACK
--
--   node scripts/migrate.mjs --dsn <url> --rollback 020
--
-- Takes the second lock off again: drops the policy this migration created and
-- switches row-level security off on every table of the schema. It holds no
-- data, so nothing is lost; the first lock (no privilege for Supabase's two API
-- roles, deploy/supabase/2026-11-17-close-data-api.sql) is not touched.
--
-- Only tables that carry THIS migration's policy are changed. A table that
-- somebody gave policies of its own keeps them, and keeps row-level security.
--
-- There is one reason to run this: the application has been moved to a role
-- that is not the owner of the tables and reads nothing. The better repair is
-- to connect as the owner again.

DO $$
DECLARE
  item record;
BEGIN
  FOR item IN
    SELECT c.relname,
           (SELECT count(*) FROM pg_policies p
             WHERE p.schemaname = n.nspname AND p.tablename = c.relname
               AND p.policyname <> 'no_access_through_the_data_api') AS other_policies
      FROM pg_class c
      JOIN pg_namespace n ON n.oid = c.relnamespace
     WHERE n.nspname = current_schema()
       AND c.relkind IN ('r', 'p')
     ORDER BY c.relname
  LOOP
    EXECUTE format('DROP POLICY IF EXISTS no_access_through_the_data_api ON %I.%I', current_schema(), item.relname);

    IF item.other_policies = 0 THEN
      EXECUTE format('ALTER TABLE %I.%I DISABLE ROW LEVEL SECURITY', current_schema(), item.relname);
    END IF;
  END LOOP;
END
$$;

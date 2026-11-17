-- 020 · row-level security: the second lock on every table
--
-- Why. This database is hosted by Supabase, and Supabase serves the public
-- schema over HTTPS (its "Data API") to anyone who holds the project's
-- publishable key. On 17 November 2026 it was found that the two roles behind
-- that API could read and write every table: users, sessions, orders, payments.
-- The first lock was put on the same day: every privilege was taken from the
-- two roles (deploy/supabase/2026-11-17-close-data-api.sql, master list T-162).
--
-- That lock is one GRANT away from being open again — a later hand, a dashboard
-- button, a tool that "fixes permissions". This migration adds the second lock,
-- which a GRANT does not open: row-level security on every table, with one
-- policy that says in the database itself what is meant — nobody reaches these
-- rows through a role that is subject to row-level security.
--
-- What it changes for the application: nothing. The Node server connects as the
-- owner of the tables, and PostgreSQL does not apply row-level security to the
-- owner of a table (it is not FORCEd here, on purpose). Supabase's secret
-- "service_role" carries BYPASSRLS and is not affected either. Everybody else,
-- whatever is granted to them, sees no row and can write none.
--
-- ── THE GUARD ────────────────────────────────────────────────────────────────
--
-- The migration runs as the same role the application uses. If that role were
-- NOT the owner of every table (and could not bypass row-level security), the
-- shop would read an empty catalogue the moment this is committed. So the
-- migration looks first and refuses, changing nothing, when that is the case.
--
-- ── TABLES CREATED LATER ─────────────────────────────────────────────────────
--
-- A table that a later migration creates does not get row-level security by
-- itself. scripts/test-rls.mjs builds the complete schema and fails when any
-- table lacks it: a migration that adds a table must enable it and add the
-- policy in the same file (copy the two statements from the loop below).
--
-- Idempotent: running it twice changes nothing the second time.
-- Undo: scripts/migrations/rollback/020_row_level_security.rollback.sql

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

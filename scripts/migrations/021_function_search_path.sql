-- 021 · a fixed search path for every function of this schema
--
-- Why. A function that names a table or another function without its schema
-- looks the name up along the search path of whoever is calling it. All nine
-- functions of this schema were created without a path of their own, so what
-- they did depended on the session that set them off:
--
--   • journal_entry_must_balance reads "journal_lines". A session whose path
--     puts another schema first makes it add up the lines of whatever table of
--     that name stands there. With an empty table there, debits and credits
--     are both zero, the check passes, and an entry that does not balance is
--     kept. scripts/test-search-path.mjs does exactly this, and requires the
--     entry to be refused.
--   • wx_gc_final calls array_to_string, datetime casts to timestamp: the same
--     lookup, for a function instead of a table. The same suite puts another
--     array_to_string in front and requires the true one to be used.
--
-- Nobody but the owner of this schema can create anything in it, and nobody
-- but a signed-in database role can set a session's path, so this was not a
-- hole somebody could reach from outside. It is closed because a rule of the
-- books should not hang on a setting of the session, and because Supabase's
-- security advisor lists each such function (its lint 0011; master list
-- T-172, defect D-029).
--
-- What it does. One statement for each of the nine, named one by one below:
--
--     ALTER FUNCTION <this schema>.<function> SET search_path = <this schema>, pg_temp
--
-- From then on the function looks names up in the system catalogue first (as
-- always), then in this schema, and in temporary objects last, so that a
-- temporary table cannot stand in for a real one either. No body is changed,
-- no trigger is touched, no row is read or written.
--
-- ── THE AGGREGATE ────────────────────────────────────────────────────────────
--
-- group_concat is the tenth routine of the schema and is not in the list:
-- PostgreSQL gives an aggregate no setting ("is an aggregate function", tried
-- on PostgreSQL 17 and 18). It has no body of its own; it runs wx_gc_trans for
-- every row and wx_gc_final at the end, and those two are in the list. The
-- advisor's rule does not leave aggregates out, so it will go on listing this
-- one name.
--
-- ── WHAT IT COSTS ────────────────────────────────────────────────────────────
--
-- A function with a setting is entered through a small wrapper that puts the
-- setting in place and takes it away again. datetime() is the one that is
-- called for every row of a list, to put the list in order. Measured on the
-- embedded PostgreSQL, ordering 20,000 rows by it, four times each way: 79 ms
-- without the setting, 98 ms with it, which is about one millionth of a second
-- more for each row. (The planner never wrote this function into the calling
-- query, with or without a setting: its body is less constant than its label
-- IMMUTABLE says. So nothing is lost there.) The lists that use it hold tens
-- of rows.
--
-- ── FUNCTIONS CREATED LATER ──────────────────────────────────────────────────
--
-- A function that a later migration creates does not get a path by itself.
-- scripts/test-search-path.mjs builds the complete schema and fails when any
-- function of it has none: write the setting into the CREATE FUNCTION,
--
--     CREATE FUNCTION … RETURNS … LANGUAGE … SET search_path = public, pg_temp AS $$ … $$;
--
-- and remember that CREATE OR REPLACE takes away a setting it does not state.
-- That is why scripts/schema.postgres.sql, which is run again and again, sets
-- the path of its three functions itself, after it has created them.
--
-- Idempotent: running it twice changes nothing the second time.
-- Undo: scripts/migrations/rollback/021_function_search_path.rollback.sql

DO $$
DECLARE
  home    text := current_schema();
  routine text;
BEGIN
  FOREACH routine IN ARRAY ARRAY[
    -- scripts/schema.postgres.sql: what lets the SQL written for SQLite run here
    'datetime(text)',
    'wx_gc_trans(text[], text)',
    'wx_gc_final(text[])',
    -- 013 finance: the ledger cannot be rewritten, and an entry balances
    'journal_lines_are_immutable()',
    'journal_entries_header_is_stable()',
    'journal_entry_must_balance()',
    -- 014 crm: a history is only added to
    'activities_are_append_only()',
    -- 015 delivery: a run that has left keeps its departure time
    'dispatch_runs_keep_departure()',
    -- 016 support: a deadline is set once
    'tickets_freeze_sla()'
  ] LOOP
    EXECUTE format('ALTER FUNCTION %I.%s SET search_path = %I, pg_temp', home, routine, home);
  END LOOP;
END
$$;

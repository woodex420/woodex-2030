-- Migration 021 (a fixed search path for every function) as it was applied to the shop's live database.
--
-- WHERE AND WHEN: Supabase project "woodex-2030", 20 November 2026, 06:57:44 UTC, through Supabase's own SQL
-- endpoint, as the role "postgres", on PostgreSQL 17.11. It took 29 ms. On the owner's word of that day
-- ("Start next": the plan's round for master list T-172 says it is applied as migration 020 was; how it was
-- carried out: decision DL-041).
--
-- WHAT THIS FILE IS: the exact text that was sent, kept as the record. It is NOT a second migration and is not run
-- again: sent a second time it refuses, because row 21 of the ledger exists. The migration itself is
-- scripts/migrations/021_function_search_path.sql; its block is in the middle of this file, word for word. Around it:
--   · a lock timeout, so that the change gives up after five seconds instead of queueing;
--   · a look at the database first: nothing is done unless the ledger holds 20 rows and the schema holds the ten
--     routines that were read, none of them with a setting;
--   · a guard after the change, which keeps nothing unless each of the nine functions has the path, no body
--     changed, the six triggers are switched on, datetime() and group_concat() answer as before, the name
--     "journal_lines" under the new path is the table of the books, and the products count the same;
--   · row 21 of the migration ledger in the form the runner writes (scripts/migrate.mjs): the file's name and the
--     sha256 of the file.
-- It is ONE statement, so it is one transaction: either all of it is kept or nothing.
--
-- READ BEFORE (06:57:06 UTC): 20 ledger rows, the last being 20; nine functions and the aggregate group_concat,
-- none with a setting; six triggers, each switched on; 148 products; 2 entries and 4 lines in the books.
-- REHEARSED FIRST on the embedded PostgreSQL (18.3, not 17) built from migrations 001 to 020, by
-- scripts/test-search-path.mjs, in CI on the commit that carries this text (run 44690692458): a database that is
-- not as it was read is left alone; a guard that fails after the change keeps nothing; sent as it is, it is kept,
-- with the row the runner expects; a second sending is refused.
-- READ AFTER (06:57:56 UTC): each of the nine functions has "search_path=public, pg_temp"; the aggregate has no
-- setting, because PostgreSQL gives an aggregate none; no body changed (the same fingerprint of the ten bodies as
-- before); the six triggers switched on; 21 ledger rows, row 21 with the migration file's name and its sha256;
-- 148 products; 2 entries and 4 lines in the books. Asked from outside 19 seconds after the change
-- (06:58:03 UTC), the shop served its 148 products, and the address that calls datetime() in the database
-- (/api/products/overrides) answered with its prices.
--
-- THE WAY BACK: scripts/migrations/rollback/021_function_search_path.rollback.sql, then delete row 21 of the ledger
-- (the runner does both: node scripts/migrate.mjs --dsn <postgres-url> --rollback 021). No row of any table was
-- changed, except the one row added to the ledger.

DO $apply$
DECLARE
  started  double precision := extract(epoch from clock_timestamp());
  ours     constant text := 'search_path=public, pg_temp';
  expected constant text := 'activities_are_append_only(), datetime(t text), dispatch_runs_keep_departure(), group_concat(text), journal_entries_header_is_stable(), journal_entry_must_balance(), journal_lines_are_immutable(), tickets_freeze_sla(), wx_gc_final(state text[]), wx_gc_trans(state text[], v text)';
  found    text;
  bodies   text;
  products bigint;
  n        integer;
  last     integer;
BEGIN
  PERFORM set_config('lock_timeout', '5s', true);
  PERFORM set_config('search_path', 'public', true);

  -- 1. The database must be as it was read. If it is not, nothing is done.
  SELECT count(*), coalesce(max(version), 0) INTO n, last FROM public.schema_migrations;
  IF n <> 20 OR last <> 20 THEN
    RAISE EXCEPTION 'migration 021 not sent: the ledger holds % rows and its last is % (20 and 20 were read)', n, last;
  END IF;
  SELECT string_agg(p.proname || '(' || pg_get_function_identity_arguments(p.oid) || ')', ', ' ORDER BY p.proname) INTO found
    FROM pg_proc p JOIN pg_namespace ns ON ns.oid = p.pronamespace WHERE ns.nspname = 'public';
  IF found IS DISTINCT FROM expected THEN
    RAISE EXCEPTION 'migration 021 not sent: the routines of the schema are not the ten that were read: %', found;
  END IF;
  SELECT count(*) INTO n FROM pg_proc p JOIN pg_namespace ns ON ns.oid = p.pronamespace WHERE ns.nspname = 'public' AND p.proconfig IS NOT NULL;
  IF n > 0 THEN
    RAISE EXCEPTION 'migration 021 not sent: % routine(s) of the schema have a setting already (none had one when it was read)', n;
  END IF;
  SELECT string_agg(p.proname || ':' || md5(p.prosrc), ',' ORDER BY p.proname) INTO bodies
    FROM pg_proc p JOIN pg_namespace ns ON ns.oid = p.pronamespace WHERE ns.nspname = 'public';
  SELECT count(*) INTO products FROM public.products;

  -- 2. The migration, word for word: scripts/migrations/021_function_search_path.sql.
  EXECUTE $migration$
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
$migration$;

  -- 3. Nothing is kept unless all of this is so.
  SELECT count(*) INTO n FROM pg_proc p JOIN pg_namespace ns ON ns.oid = p.pronamespace
   WHERE ns.nspname = 'public' AND p.prokind = 'f' AND p.proconfig = ARRAY[ours];
  IF n <> 9 THEN
    RAISE EXCEPTION 'migration 021 not kept: % of the 9 functions have the path', n;
  END IF;
  SELECT count(*) INTO n FROM pg_proc p JOIN pg_namespace ns ON ns.oid = p.pronamespace
   WHERE ns.nspname = 'public' AND p.prokind <> 'a' AND NOT (coalesce(p.proconfig, '{}') @> ARRAY[ours]);
  IF n > 0 THEN
    RAISE EXCEPTION 'migration 021 not kept: % function(s) of the schema are without the path', n;
  END IF;
  IF bodies IS DISTINCT FROM (SELECT string_agg(p.proname || ':' || md5(p.prosrc), ',' ORDER BY p.proname)
                                FROM pg_proc p JOIN pg_namespace ns ON ns.oid = p.pronamespace WHERE ns.nspname = 'public') THEN
    RAISE EXCEPTION 'migration 021 not kept: the body of a routine is not what it was';
  END IF;
  SELECT count(*) INTO n FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace ns ON ns.oid = c.relnamespace
   WHERE ns.nspname = 'public' AND NOT t.tgisinternal AND t.tgenabled = 'O';
  IF n <> 6 THEN
    RAISE EXCEPTION 'migration 021 not kept: % triggers are attached and switched on (6 were read)', n;
  END IF;
  IF public.datetime('2026-11-20T06:00:00.000Z') IS DISTINCT FROM timestamp '2026-11-20 06:00:00' OR public.datetime('') IS NOT NULL THEN
    RAISE EXCEPTION 'migration 021 not kept: datetime() does not answer as before';
  END IF;
  IF (SELECT public.group_concat(x) FROM (VALUES ('a'), ('b'), (NULL), ('c')) v(x)) IS DISTINCT FROM 'a,b,c' THEN
    RAISE EXCEPTION 'migration 021 not kept: group_concat() does not answer as before';
  END IF;
  -- What the trigger of the books finds under its new path.
  PERFORM set_config('search_path', 'public, pg_temp', true);
  IF to_regclass('journal_lines') IS DISTINCT FROM 'public.journal_lines'::regclass THEN
    RAISE EXCEPTION 'migration 021 not kept: under its path, "journal_lines" is not public.journal_lines';
  END IF;
  PERFORM set_config('search_path', 'public', true);
  IF (SELECT count(*) FROM public.products) <> products THEN
    RAISE EXCEPTION 'migration 021 not kept: the number of products changed';
  END IF;

  -- 4. Row 21 of the ledger, in the form the runner writes it (scripts/migrate.mjs).
  INSERT INTO public.schema_migrations(version, name, checksum, applied_at, duration_ms)
  VALUES (21, '021_function_search_path.sql', 'c63b25d3aa3ed61b5a3fda2bd3ca269f43cc6365807b325ccc0e1963a941eec6',
          to_char(clock_timestamp() AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.MS"Z"'),
          round((extract(epoch from clock_timestamp()) - started) * 1000)::integer);
END
$apply$;

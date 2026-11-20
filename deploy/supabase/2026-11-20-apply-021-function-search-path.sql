-- Migration 021 (a fixed search path for every function) as it is sent to the shop's live database.
--
-- NOT SENT YET when this line was written. The header is completed with the time and the readings once it is.
--
-- WHAT THIS FILE IS: the exact text for Supabase's SQL endpoint. It is NOT a second migration. The migration
-- itself is scripts/migrations/021_function_search_path.sql; its block is in the middle of this file, word for
-- word. Around it: a look at the database first, a guard after, and row 21 of the migration ledger.
-- It is ONE statement, so it is one transaction: either all of it is kept or nothing.
-- scripts/test-search-path.mjs sends this text to a test database in the state the live one was read in.

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

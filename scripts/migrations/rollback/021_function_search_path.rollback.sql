-- 021 · a fixed search path for every function of this schema — ROLLBACK
--
--   node scripts/migrate.mjs --dsn <url> --rollback 021
--
-- Takes the path off the nine functions again, so that each looks names up
-- along the path of the session that calls it, as before. It holds no data and
-- changes no body: nothing is lost.
--
-- Only a function that carries THIS migration's value is changed. A function
-- to which somebody gave another path keeps it.
--
-- There is one reason to run this: a function of the list has been rewritten
-- to use something that is in neither this schema nor the system catalogue
-- (an extension's function, say) and fails with "does not exist". The better
-- repair is to name that schema in the function's own path.
--
-- Run twice, the second run changes nothing.

DO $$
DECLARE
  home    text := current_schema();
  ours    text := format('search_path=%I, pg_temp', current_schema());
  routine text;
BEGIN
  FOREACH routine IN ARRAY ARRAY[
    'datetime(text)',
    'wx_gc_trans(text[], text)',
    'wx_gc_final(text[])',
    'journal_lines_are_immutable()',
    'journal_entries_header_is_stable()',
    'journal_entry_must_balance()',
    'activities_are_append_only()',
    'dispatch_runs_keep_departure()',
    'tickets_freeze_sla()'
  ] LOOP
    IF EXISTS (
         SELECT 1 FROM pg_proc p
          WHERE p.oid = format('%I.%s', home, routine)::regprocedure
            AND p.proconfig @> ARRAY[ours]
       ) THEN
      EXECUTE format('ALTER FUNCTION %I.%s RESET search_path', home, routine);
    END IF;
  END LOOP;
END
$$;

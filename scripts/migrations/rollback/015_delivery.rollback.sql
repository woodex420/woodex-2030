-- 015 · delivery and installation — ROLLBACK
--
--   node scripts/migrate.mjs --dsn <url> --rollback 015
--
-- Refuses once a delivery or an installed asset exists.
--
-- An installed asset is a claim about the physical world: that object is in
-- that room, and its warranty runs until that date. Dropping the table does not
-- remove the furniture; it removes the only record that Woodex put it there and
-- owes anything on it. Deliveries are refused for the same kind of reason —
-- they are the signed evidence that goods arrived.

DO $$
DECLARE
  n_deliveries BIGINT;
  n_assets     BIGINT;
BEGIN
  SELECT count(*) INTO n_deliveries FROM deliveries;
  SELECT count(*) INTO n_assets     FROM installed_assets;

  IF n_deliveries > 0 OR n_assets > 0 THEN
    RAISE EXCEPTION
      'refusing to roll back 015: % delivery record(s) and % installed asset(s) exist. These are the evidence that goods arrived and that Woodex owes a warranty on them.',
      n_deliveries, n_assets;
  END IF;
END $$;

DROP TRIGGER IF EXISTS trg_dispatch_runs_departure ON dispatch_runs;
DROP FUNCTION IF EXISTS dispatch_runs_keep_departure();

DROP TABLE IF EXISTS installed_assets;
DROP TABLE IF EXISTS delivery_lines;
DROP TABLE IF EXISTS deliveries;
DROP TABLE IF EXISTS dispatch_runs;

-- 012 · inventory depth — ROLLBACK
--
--   node scripts/migrate.mjs --dsn <url> --rollback 012
--
-- Rolling this back deletes the only record of *where* stock is. That is
-- recoverable in principle (a physical count) and expensive in practice, which
-- is the test for whether a rollback should refuse.
--
-- It refuses on two conditions:
--
--   1. Any movement carries a location_id. Those rows were written when the
--      system could answer "where"; dropping the column would erase an answer
--      that was true at the time.
--   2. Any transfer exists. A dispatched transfer has ledger rows at both ends
--      summing to zero; after this rollback they become two unexplained
--      movements, one negative and one positive, with nothing saying they were
--      the same goods.

DO $$
DECLARE
  n_located   BIGINT;
  n_transfers BIGINT;
BEGIN
  SELECT count(*) INTO n_located   FROM stock_movements WHERE location_id IS NOT NULL;
  SELECT count(*) INTO n_transfers FROM stock_transfers;

  IF n_located > 0 OR n_transfers > 0 THEN
    RAISE EXCEPTION
      'refusing to roll back 012: % movement(s) record a location and % transfer(s) exist. Where stock is cannot be un-known by dropping a column.',
      n_located, n_transfers;
  END IF;
END $$;

/* A live reservation is the other thing that would vanish. It is checked
   separately because a reservation writes no movement and would otherwise slip
   past the counts above. */
DO $$
DECLARE
  n_held BIGINT;
BEGIN
  SELECT count(*) INTO n_held FROM stock_reservations WHERE status = 'held';
  IF n_held > 0 THEN
    RAISE EXCEPTION
      'refusing to roll back 012: % stock reservation(s) are still held and would be silently released by this change.',
      n_held;
  END IF;
END $$;

DROP INDEX IF EXISTS idx_stock_reservations_live;
DO $$
BEGIN
  ALTER TABLE stock_reservations DROP CONSTRAINT IF EXISTS stock_reservations_ref_required;
  ALTER TABLE stock_reservations DROP CONSTRAINT IF EXISTS stock_reservations_product_fkey;
END $$;
ALTER TABLE stock_reservations DROP COLUMN IF EXISTS released_at;

DROP TABLE IF EXISTS stock_transfer_lines;
DROP TABLE IF EXISTS stock_transfers;
DROP TABLE IF EXISTS stock_levels;

DROP INDEX IF EXISTS idx_stock_movements_location;
DO $$
BEGIN
  ALTER TABLE stock_movements DROP CONSTRAINT IF EXISTS stock_movements_location_fkey;
END $$;
ALTER TABLE stock_movements DROP COLUMN IF EXISTS location_id;

DROP TABLE IF EXISTS storage_locations;
DROP INDEX IF EXISTS uq_warehouses_single_default;
DROP TABLE IF EXISTS warehouses;

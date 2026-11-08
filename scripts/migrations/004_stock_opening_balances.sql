-- 004 · backfill opening balances for pre-existing stock
--
-- 003 created the ledger and recorded an opening balance for products that had
-- none. On a database that already held stock when the ledger arrived, the
-- order of events matters: the schema upgrade runs at boot, then the demo seed
-- imports the catalogue. That import uses INSERT OR IGNORE, so on an existing
-- database the products are already there and get no opening row — their stock
-- is real, their ledger is empty, and every one of them reconciles as a
-- discrepancy.
--
-- The reconciliation test caught exactly that: 148 products with stock 99 and a
-- ledger of 0.
--
-- This is safe to run repeatedly and safe on a live database. It only touches a
-- product that has NO movements at all — a product with any history already has
-- an opening row or a real sale, and inventing a second baseline for it would
-- corrupt the very invariant the ledger exists to protect.

INSERT INTO stock_movements(product_id, delta, reason, note, created_by, created_at)
SELECT p.id, p.stock_qty, 'receipt', 'opening balance backfilled at ledger introduction', 'migration:004', '2026-11-08T00:00:00.000Z'
  FROM products p
 WHERE p.stock_qty <> 0
   AND NOT EXISTS (SELECT 1 FROM stock_movements m WHERE m.product_id = p.id);

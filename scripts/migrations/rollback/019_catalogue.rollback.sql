-- 019 · catalogue — ROLLBACK
--
--   node scripts/migrate.mjs --dsn <url> --rollback 019
--
-- Refuses while the database holds anything that only 019 can express.
--
-- Rolling this back does not merely remove columns. It removes the difference
-- between a draft and a published product, so every draft and every archived
-- product would become public; it removes "price on request", so every such
-- product would be on sale at PKR 0; and it deletes every variant together
-- with its SKU. None of that is a schema change. It is a change to what the
-- shop sells and at what price, so it is refused while such rows exist.
--
-- To roll back deliberately: publish or delete the drafts and archived
-- products, give every "on request" product a price, delete the variants,
-- and run this again. Stock that is "made to order" is not checked: after the
-- rollback those products simply count stock again, starting from the number
-- they hold.

DO $$
DECLARE
  n_hidden BIGINT;
  n_request BIGINT;
  n_variants BIGINT;
BEGIN
  SELECT count(*) INTO n_hidden FROM products WHERE status <> 'published';
  SELECT count(*) INTO n_request FROM products WHERE price_on_request = 1;
  SELECT count(*) INTO n_variants FROM product_variants;

  IF n_hidden > 0 OR n_request > 0 OR n_variants > 0 THEN
    RAISE EXCEPTION
      'refusing to roll back 019: % product(s) are draft or archived and would become public, % are "price on request" and would be on sale at PKR 0, and % variant(s) would be deleted.',
      n_hidden, n_request, n_variants;
  END IF;
END $$;

DROP TABLE IF EXISTS product_variants;

DROP INDEX IF EXISTS uq_products_sku;
DROP INDEX IF EXISTS idx_products_status;

ALTER TABLE products DROP CONSTRAINT IF EXISTS products_status_check;
ALTER TABLE products DROP CONSTRAINT IF EXISTS products_price_on_request_check;
ALTER TABLE products DROP CONSTRAINT IF EXISTS products_track_stock_check;
ALTER TABLE products DROP CONSTRAINT IF EXISTS products_price_whole_rupees;
ALTER TABLE products DROP CONSTRAINT IF EXISTS products_price_or_request;
ALTER TABLE products DROP CONSTRAINT IF EXISTS products_sku_not_blank;

ALTER TABLE products DROP COLUMN IF EXISTS sku;
ALTER TABLE products DROP COLUMN IF EXISTS status;
ALTER TABLE products DROP COLUMN IF EXISTS price_on_request;
ALTER TABLE products DROP COLUMN IF EXISTS track_stock;

-- 019 · catalogue: a SKU, a state, a price that can be "on request",
--       stock that can be made to order, and variants
--
-- Until this migration a product was an id, a JSON document, a price and a
-- stock count. That was enough to show a demo catalogue and not enough to hold
-- a real one:
--
--   • No SKU. The live shop's list was measured on 2026-11-16: 171 products,
--     17 SKUs carried by 36 of them. A duplicate code is the kind of fact that
--     has to be impossible, not discouraged, so the uniqueness lives here and
--     not in a form validator.
--   • No state. Every row was public the moment it existed, so a catalogue
--     could not be loaded, checked and then shown. `status` separates "it is in
--     the system" from "it is on sale".
--   • A price had to be above zero. 34 live products are priced at PKR 0 —
--     which the old shop sells at PKR 0. "No price yet" is a state of its own.
--   • Stock was always a count, and the count was always 99. Furniture built to
--     order has no count; pretending it has one is the placeholder stock the
--     audit named (F-07).
--   • No variants. One table in three sizes was three products, or one product
--     that did not say which size was ordered.
--
-- ── WHAT STAYS COMPATIBLE ────────────────────────────────────────────────
--
-- Every new column has a default that means "as before": published, priced,
-- stock counted. The API that ran before this migration keeps working against
-- a database that has it — it never names the new columns — which is what lets
-- the migration be applied before OR after the code that uses it is deployed.
-- The API that uses it checks for these columns and serves the catalogue the
-- old way until they exist.
--
-- ── WHY THE PRICE STAYS DOUBLE PRECISION ─────────────────────────────────
--
-- Whole rupees are enforced by a CHECK, not by changing the type. Changing the
-- type would rewrite the column and change what every driver returns for it;
-- the CHECK gives the same guarantee and alters nothing else. The wider
-- question of how money is stored (F-08) is a later phase and covers thirty
-- columns, not this one.
--
-- ── WHAT IS NOT CONSTRAINED, AND WHY ─────────────────────────────────────
--
-- "A published product has a SKU" is NOT a CHECK. The rows already here are
-- published and have none; a CHECK would refuse the migration on the very
-- database it has to upgrade. The API enforces it on the way INTO published,
-- which is the moment that matters, and reports the rows that predate it.
--
-- A SKU must be unique across products AND variants. No constraint spans two
-- tables, so each table has its own unique index and the API holds an advisory
-- lock while it checks the other one. Two writers cannot both pass.
--
-- Idempotent: columns and indexes use IF NOT EXISTS, constraints are guarded
-- by name. Running it on a database that already has it changes nothing.

/* ───────────── 0. refuse rather than round ─────────────
   A price with a fraction would fail the CHECK below with a message that names
   the constraint and not the rows. Say which rows, and say it before anything
   is altered. Rounding them here would change money in a migration. */
DO $$
DECLARE
  offenders TEXT;
BEGIN
  SELECT string_agg(id || ' (' || price::text || ')', ', ' ORDER BY id) INTO offenders
    FROM (SELECT id, price FROM products WHERE price <> trunc(price) OR price <= 0 ORDER BY id LIMIT 20) AS bad;
  IF offenders IS NOT NULL THEN
    RAISE EXCEPTION
      'refusing 019: these products have a price that is not whole rupees above zero: %. Correct them in the dashboard, then run the migration again.',
      offenders;
  END IF;
END $$;

/* ───────────── 1. products ───────────── */
ALTER TABLE products ADD COLUMN IF NOT EXISTS sku TEXT;
/* 'published' is the default because that is what every existing row is. The
   API creates new rows as 'draft' explicitly; a default of 'draft' here would
   hide the whole catalogue the moment the migration ran. */
ALTER TABLE products ADD COLUMN IF NOT EXISTS status TEXT NOT NULL DEFAULT 'published';
ALTER TABLE products ADD COLUMN IF NOT EXISTS price_on_request INTEGER NOT NULL DEFAULT 0;
ALTER TABLE products ADD COLUMN IF NOT EXISTS track_stock INTEGER NOT NULL DEFAULT 1;

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'products_status_check') THEN
    ALTER TABLE products ADD CONSTRAINT products_status_check
      CHECK (status IN ('draft', 'published', 'archived'));
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'products_price_on_request_check') THEN
    ALTER TABLE products ADD CONSTRAINT products_price_on_request_check CHECK (price_on_request IN (0, 1));
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'products_track_stock_check') THEN
    ALTER TABLE products ADD CONSTRAINT products_track_stock_check CHECK (track_stock IN (0, 1));
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'products_price_whole_rupees') THEN
    ALTER TABLE products ADD CONSTRAINT products_price_whole_rupees CHECK (price = trunc(price));
  END IF;
  /* Either a price, or "on request" — never both, never neither. A row that is
     on request and still carries a number would show that number somewhere. */
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'products_price_or_request') THEN
    ALTER TABLE products ADD CONSTRAINT products_price_or_request
      CHECK ((price_on_request = 1 AND price = 0) OR (price_on_request = 0 AND price > 0));
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'products_sku_not_blank') THEN
    ALTER TABLE products ADD CONSTRAINT products_sku_not_blank CHECK (sku IS NULL OR btrim(sku) <> '');
  END IF;
END $$;

/* Compared without case: "mt-10001" and "MT-10001" are one code to a person
   reading a delivery note. Partial, because many rows have no SKU yet and NULL
   must not collide with NULL. */
CREATE UNIQUE INDEX IF NOT EXISTS uq_products_sku ON products (lower(sku)) WHERE sku IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_products_status ON products (status);

/* ───────────── 2. variants ─────────────
   A variant is one sellable form of a product: a size, a finish, a material.
   It has its own SKU and its own price. A product with variants is sold by
   variant; a product without them is sold as itself.

   Stock is not on this table. The ledger counts per product (003), and a
   made-to-order catalogue counts nothing; a variant-level count is a later
   change with its own migration, not a column that would sit here unused. */
CREATE TABLE IF NOT EXISTS product_variants (
  id               BIGSERIAL PRIMARY KEY,
  product_id       TEXT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
  sku              TEXT NOT NULL,
  /* What a customer chooses and what the order line prints: "1800 mm · Walnut". */
  label            TEXT NOT NULL,
  /* The same choice as data — {"size":"1800 mm","finish":"Walnut"} — so a
     filter or a production sheet does not have to parse the label. */
  attributes       TEXT NOT NULL DEFAULT '{}',
  price            DOUBLE PRECISION NOT NULL DEFAULT 0,
  price_on_request INTEGER NOT NULL DEFAULT 0,
  /* 0 withdraws the variant from sale without deleting it: an order placed
     last month still names it. */
  active           INTEGER NOT NULL DEFAULT 1,
  position         INTEGER NOT NULL DEFAULT 0,
  created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT product_variants_sku_not_blank CHECK (btrim(sku) <> ''),
  CONSTRAINT product_variants_label_not_blank CHECK (btrim(label) <> ''),
  CONSTRAINT product_variants_price_on_request_check CHECK (price_on_request IN (0, 1)),
  CONSTRAINT product_variants_active_check CHECK (active IN (0, 1)),
  CONSTRAINT product_variants_price_whole_rupees CHECK (price = trunc(price)),
  CONSTRAINT product_variants_price_or_request
    CHECK ((price_on_request = 1 AND price = 0) OR (price_on_request = 0 AND price > 0)),
  CONSTRAINT product_variants_position_non_negative CHECK (position >= 0)
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_product_variants_sku ON product_variants (lower(sku));
/* Two variants of one product that read the same cannot be told apart at the
   moment of choosing. */
CREATE UNIQUE INDEX IF NOT EXISTS uq_product_variants_label ON product_variants (product_id, lower(label));
CREATE INDEX IF NOT EXISTS idx_product_variants_product ON product_variants (product_id, position);

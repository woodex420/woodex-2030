-- 015 · delivery and installation: dispatch, deliveries, installed assets, proof
--
-- Orders already carry `stage` — production, qc, dispatch, delivery,
-- installation — but a stage is a single current word. It cannot answer the
-- questions a delivery actually raises:
--
--   Who took it out, on what vehicle, and what else was on the vehicle?
--   Which items went in which run, and which were short?
--   Did the customer sign, and who signed?
--   What was installed, where in the building, and when does its warranty start?
--
-- Today `stage='delivery'` answers none of those, and when an installation goes
-- wrong the only record is a message in a chat group. This migration gives the
-- three things a stage transition cannot: a **dispatch run** (goods left the
-- warehouse on a vehicle with a crew), a **delivery** (some of the order
-- arrived and somebody signed for it), and an **installed asset** (a physical
-- object at a location, with a warranty clock that starts when it was actually
-- installed rather than when the order was placed).
--
-- ── PARTIAL DELIVERY IS THE NORMAL CASE ─────────────────────────────────
--
-- A kitchen arrives in three runs: carcasses, worktop, appliances. Modelling
-- "the order was delivered" as a boolean forces the system to lie twice — once
-- when the first run arrives and the order is not delivered, and again when the
-- last run arrives and the earlier runs must be retconned into existence.
--
-- `delivery_lines` records what each run actually carried and how many units.
-- The order is delivered when the lines account for every unit; until then the
-- shortfall is a number, not an opinion.
--
-- ── PROOF, NOT A CHECKBOX ───────────────────────────────────────────────
--
-- `signed_by` is a name typed by a person standing in a kitchen, and `photo` is
-- a pointer to the picture of it. A delivery record with neither is refused:
-- "delivered" with no evidence is exactly the claim a dispute turns on, and a
-- system that accepts the claim without evidence is worse than one that has no
-- delivery record at all, because it looks authoritative.
--
-- ── WARRANTY STARTS AT INSTALLATION ─────────────────────────────────────
--
-- Not at order, not at delivery. A worktop that sat in a warehouse for six
-- weeks has not been in use for six weeks, and a customer told their warranty
-- began before they could use the product is a customer with a legitimate
-- complaint. `installed_assets.warranty_until` is computed from `installed_on`
-- at the moment of installation and stored, so a later change to the product's
-- warranty term cannot silently shorten a warranty already promised.
--
-- Installed assets are the join between a project and the objects in it: the
-- same table answers "what is in this flat" and "what have we got at this
-- address under warranty", which is the question support actually asks.

/* ───────────────────────────── dispatch runs ─────────────────────────────
   One trip. A vehicle, a crew, a date. Deliveries hang off it, so "what was on
   the truck" is answerable after the fact. */

CREATE TABLE IF NOT EXISTS dispatch_runs (
  id            BIGSERIAL PRIMARY KEY,
  ref           TEXT NOT NULL UNIQUE,
  vehicle       TEXT,
  driver        TEXT,
  crew          TEXT,
  /* The address is copied onto the run rather than read through the order:
     an order's address can be corrected later, and the run has to record where
     the driver was actually sent. */
  address       TEXT,
  city          TEXT,
  scheduled_for TIMESTAMPTZ,
  departed_at   TIMESTAMPTZ,
  returned_at   TIMESTAMPTZ,
  status        TEXT NOT NULL DEFAULT 'planned',
  notes         TEXT,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT dispatch_runs_status_check CHECK (status IN ('planned','dispatched','completed','cancelled')),
  CONSTRAINT dispatch_runs_return_after_departure
    CHECK (returned_at IS NULL OR departed_at IS NULL OR returned_at >= departed_at)
);

CREATE INDEX IF NOT EXISTS idx_dispatch_runs_status ON dispatch_runs (status, scheduled_for);

/* ────────────────────────────── deliveries ──────────────────────────────
   One arrival at one address. Several deliveries belong to one order when the
   order ships in runs; the lines say what each run carried. */

CREATE TABLE IF NOT EXISTS deliveries (
  id             BIGSERIAL PRIMARY KEY,
  ref            TEXT NOT NULL UNIQUE,
  order_id       BIGINT,
  project_id     BIGINT,
  dispatch_run_id BIGINT,
  customer       TEXT NOT NULL,
  address        TEXT,
  delivered_at   TIMESTAMPTZ,
  received_by    TEXT,
  /* The signature. A name, or the words "left with security" — but something. */
  signed_by      TEXT,
  photo          TEXT,
  condition      TEXT NOT NULL DEFAULT 'good',
  /* Damage found on arrival. Kept on the delivery because it is a fact about
     the arrival, not a later support ticket — the two get conflated and the
     support queue then cannot tell a transit break from a manufacturing one. */
  damage_notes   TEXT,
  status         TEXT NOT NULL DEFAULT 'pending',
  notes          TEXT,
  created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT deliveries_status_check CHECK (status IN ('pending','in_transit','delivered','failed','returned')),
  CONSTRAINT deliveries_condition_check CHECK (condition IN ('good','damaged','partial','rejected')),
  CONSTRAINT deliveries_customer_not_blank CHECK (btrim(customer) <> ''),
  CONSTRAINT deliveries_order_fkey    FOREIGN KEY (order_id) REFERENCES orders (id)     ON DELETE SET NULL,
  CONSTRAINT deliveries_project_fkey  FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL,
  CONSTRAINT deliveries_run_fkey      FOREIGN KEY (dispatch_run_id) REFERENCES dispatch_runs (id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_deliveries_order   ON deliveries (order_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_deliveries_project ON deliveries (project_id);
CREATE INDEX IF NOT EXISTS idx_deliveries_status  ON deliveries (status, delivered_at DESC);

/* ──────────────────────────── delivery lines ──────────────────────────── */

CREATE TABLE IF NOT EXISTS delivery_lines (
  id           BIGSERIAL PRIMARY KEY,
  delivery_id  BIGINT NOT NULL,
  product_id   TEXT,
  description  TEXT NOT NULL,
  qty          INTEGER NOT NULL,
  unit         TEXT,
  /* Serial or tag, when the item has one. A chair without one cannot be told
     apart from the other five chairs, so warranty claims on it are unanswerable. */
  serial       TEXT,
  CONSTRAINT delivery_lines_qty_positive CHECK (qty > 0),
  CONSTRAINT delivery_lines_desc_not_blank CHECK (btrim(description) <> ''),
  CONSTRAINT delivery_lines_delivery_fkey FOREIGN KEY (delivery_id) REFERENCES deliveries (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_delivery_lines_delivery ON delivery_lines (delivery_id);

/* ─────────────────────────── installed assets ───────────────────────────
   A physical object, at a place, from a date. This is what support asks about
   and what a warranty is attached to. */

CREATE TABLE IF NOT EXISTS installed_assets (
  id             BIGSERIAL PRIMARY KEY,
  tag            TEXT NOT NULL UNIQUE,
  order_id       BIGINT,
  project_id     BIGINT,
  delivery_id    BIGINT,
  product_id     TEXT,
  name           TEXT NOT NULL,
  location       TEXT,
  /* Where in the building. "Kitchen, left of the window" is what a technician
     needs; a flat number is not enough. */
  room           TEXT,
  serial         TEXT,
  installed_on   DATE,
  installed_by   TEXT,
  /* Computed at installation and stored. Recomputing it later from the product
     row would let a change to a warranty term silently shorten a warranty that
     was already promised to a customer. */
  warranty_months INTEGER,
  warranty_until DATE,
  status         TEXT NOT NULL DEFAULT 'installed',
  notes          TEXT,
  created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT installed_assets_name_not_blank CHECK (btrim(name) <> ''),
  CONSTRAINT installed_assets_tag_not_blank  CHECK (btrim(tag) <> ''),
  CONSTRAINT installed_assets_status_check   CHECK (status IN ('installed','removed','replaced','faulty')),
  CONSTRAINT installed_assets_warranty_months_non_negative CHECK (warranty_months IS NULL OR warranty_months >= 0),
  CONSTRAINT installed_assets_warranty_after_install
    CHECK (warranty_until IS NULL OR installed_on IS NULL OR warranty_until >= installed_on),
  CONSTRAINT installed_assets_order_fkey    FOREIGN KEY (order_id)    REFERENCES orders (id)     ON DELETE SET NULL,
  CONSTRAINT installed_assets_project_fkey  FOREIGN KEY (project_id)  REFERENCES projects (id)   ON DELETE SET NULL,
  CONSTRAINT installed_assets_delivery_fkey FOREIGN KEY (delivery_id) REFERENCES deliveries (id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_installed_assets_order   ON installed_assets (order_id);
CREATE INDEX IF NOT EXISTS idx_installed_assets_project ON installed_assets (project_id);
CREATE INDEX IF NOT EXISTS idx_installed_assets_warranty ON installed_assets (warranty_until) WHERE warranty_until IS NOT NULL;

/* One writer for "this shall not be edited after the fact": a dispatch run that
   has departed has a departure time, and rewriting it would let a late run look
   punctual. Only the fields a dispatcher may still correct are left open. */
CREATE OR REPLACE FUNCTION dispatch_runs_keep_departure() RETURNS trigger AS $$
BEGIN
  IF OLD.departed_at IS NOT NULL AND NEW.departed_at IS DISTINCT FROM OLD.departed_at THEN
    RAISE EXCEPTION 'a run that has departed keeps its departure time: rewriting it makes a late departure look punctual'
      USING ERRCODE = 'P0001';
  END IF;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_dispatch_runs_departure ON dispatch_runs;
CREATE TRIGGER trg_dispatch_runs_departure
  BEFORE UPDATE ON dispatch_runs
  FOR EACH ROW EXECUTE FUNCTION dispatch_runs_keep_departure();

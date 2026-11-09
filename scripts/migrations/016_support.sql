-- 016 · support and warranty: tickets, visits, warranty claims
--
-- 015 leaves the system knowing exactly which physical objects are in which
-- room and until when each is under warranty. It cannot answer the question
-- that follows: **something is wrong with one of them, and what happens now?**
--
-- Today that lives in a chat group. Which means the second complaint about the
-- same hinge is handled by whoever remembers the first, the warranty status is
-- argued rather than looked up, and the technician who fixed it last time is
-- whoever happens to be free.
--
-- ── A TICKET IS THE CUSTOMER'S CLAIM; A VISIT IS WOODEX'S RESPONSE ──────
--
-- These are separate tables because they have different lifespans and
-- different failure modes. A ticket is opened once and closed once; a visit is
-- scheduled, attended or missed, and there can be several before a ticket
-- closes. Collapsing them means a ticket can only ever have one attempt, or the
-- attempts have to be smuggled into a notes field where nothing can report on
-- them.
--
-- ── WARRANTY IS ANSWERED, NOT REMEMBERED ────────────────────────────────
--
-- `installed_assets.warranty_until` already stores the date that was promised
-- to the customer. A claim names the asset; the verdict (`covered`,
-- `not_covered`, `goodwill`) is recorded **with the date it was checked**, so a
-- year later nobody has to reconstruct whether the answer was right at the time.
-- The verdict is not derived from the current product warranty term, because a
-- term can change and the promise cannot.
--
-- ── SLA IS A DEADLINE, NOT A PRIORITY WORD ──────────────────────────────
--
-- "High priority" is an opinion. `respond_by` and `resolve_by` are timestamps,
-- computed once at intake from the priority, and `first_response_at` is stamped
-- the moment a human first writes back. `breached` is then arithmetic rather
-- than a colour judgement, and the report that says "we missed 6 of 40" is
-- possible at all.
--
-- The clock does not stop while a ticket waits on the customer: `waiting_on`
-- distinguishes 'us' from 'them', so a ticket blocked on a missing photograph
-- does not count against the team in the resolution number while still counting
-- in "how long has this been open".

/* ──────────────────────────────── tickets ──────────────────────────────── */

CREATE TABLE IF NOT EXISTS tickets (
  id            BIGSERIAL PRIMARY KEY,
  ref           TEXT NOT NULL UNIQUE,
  /* The customer, denormalised, exactly as on deliveries: a client record can
     be merged or corrected later, and the ticket has to record who complained
     on the day. Nullable pointer kept alongside for reporting. */
  customer      TEXT NOT NULL,
  client_id     BIGINT,
  company_id    BIGINT,
  order_id      BIGINT,
  project_id    BIGINT,
  /* What the complaint is about, when it is about a specific installed object.
     This is the link that makes the warranty answerable. */
  asset_id      BIGINT,
  subject       TEXT NOT NULL,
  description   TEXT,
  category      TEXT NOT NULL DEFAULT 'other',
  channel       TEXT NOT NULL DEFAULT 'phone',
  priority      TEXT NOT NULL DEFAULT 'normal',
  status        TEXT NOT NULL DEFAULT 'open',
  /* Whose move is it. A ticket waiting on the customer is not a ticket the
     team is ignoring, and conflating the two makes every ageing report wrong. */
  waiting_on    TEXT NOT NULL DEFAULT 'us',
  owner         TEXT,
  /* Stamped when a human first writes back, never when the ticket is created:
     an auto-acknowledgement is not a response. */
  first_response_at TIMESTAMPTZ,
  respond_by    TIMESTAMPTZ,
  resolve_by    TIMESTAMPTZ,
  resolved_at   TIMESTAMPTZ,
  resolution    TEXT,
  closed_at     TIMESTAMPTZ,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT tickets_customer_not_blank CHECK (btrim(customer) <> ''),
  CONSTRAINT tickets_subject_not_blank  CHECK (btrim(subject) <> ''),
  CONSTRAINT tickets_status_check
    CHECK (status IN ('open','in_progress','waiting','resolved','closed')),
  CONSTRAINT tickets_priority_check CHECK (priority IN ('low','normal','high','urgent')),
  CONSTRAINT tickets_channel_check  CHECK (channel IN ('phone','email','whatsapp','visit','web')),
  CONSTRAINT tickets_waiting_on_check CHECK (waiting_on IN ('us','them')),
  CONSTRAINT tickets_category_check CHECK (category IN
    ('damage','defect','missing_part','fitting','finish','delivery','other')),
  CONSTRAINT tickets_resolved_after_created CHECK (resolved_at IS NULL OR resolved_at >= created_at),
  CONSTRAINT tickets_client_fkey  FOREIGN KEY (client_id)  REFERENCES clients (id)  ON DELETE SET NULL,
  CONSTRAINT tickets_company_fkey FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL,
  CONSTRAINT tickets_order_fkey   FOREIGN KEY (order_id)   REFERENCES orders (id)   ON DELETE SET NULL,
  CONSTRAINT tickets_project_fkey FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL,
  /* CASCADE would silently delete the complaint when an asset is removed from
     the register — the exact moment its history matters most. */
  CONSTRAINT tickets_asset_fkey   FOREIGN KEY (asset_id)   REFERENCES installed_assets (id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_tickets_status   ON tickets (status, priority);
CREATE INDEX IF NOT EXISTS idx_tickets_asset    ON tickets (asset_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_tickets_customer ON tickets (customer);
CREATE INDEX IF NOT EXISTS idx_tickets_sla      ON tickets (resolve_by) WHERE resolved_at IS NULL;

/* The conversation. Internal notes are the same table with `internal = true`,
   because "what we told the customer" and "what we know" are the same thread
   read by different people — and splitting them means the second is never
   written down. */
CREATE TABLE IF NOT EXISTS ticket_messages (
  id         BIGSERIAL PRIMARY KEY,
  ticket_id  BIGINT NOT NULL,
  author     TEXT,
  body       TEXT NOT NULL,
  internal   BOOLEAN NOT NULL DEFAULT false,
  channel    TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT ticket_messages_body_not_blank CHECK (btrim(body) <> ''),
  CONSTRAINT ticket_messages_ticket_fkey FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_ticket_messages_ticket ON ticket_messages (ticket_id, created_at);

/* ───────────────────────────────── visits ─────────────────────────────────
   A technician going somewhere. Several per ticket when the first attempt does
   not fix it, which is the normal case for a mis-aligned door. */

CREATE TABLE IF NOT EXISTS service_visits (
  id           BIGSERIAL PRIMARY KEY,
  ref          TEXT NOT NULL UNIQUE,
  ticket_id    BIGINT NOT NULL,
  scheduled_for TIMESTAMPTZ,
  technician   TEXT,
  address      TEXT,
  status       TEXT NOT NULL DEFAULT 'scheduled',
  arrived_at   TIMESTAMPTZ,
  completed_at TIMESTAMPTZ,
  /* What was actually done, as opposed to what the ticket asked for. The gap
     between the two is the most useful number in a support operation. */
  work_done    TEXT,
  parts_used   TEXT,
  chargeable   BOOLEAN NOT NULL DEFAULT false,
  charge_amount NUMERIC(14,2),
  notes        TEXT,
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT service_visits_status_check
    CHECK (status IN ('scheduled','attended','completed','missed','cancelled')),
  CONSTRAINT service_visits_charge_non_negative CHECK (charge_amount IS NULL OR charge_amount >= 0),
  CONSTRAINT service_visits_completed_after_arrival
    CHECK (completed_at IS NULL OR arrived_at IS NULL OR completed_at >= arrived_at),
  CONSTRAINT service_visits_ticket_fkey FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_service_visits_ticket ON service_visits (ticket_id, scheduled_for DESC);
CREATE INDEX IF NOT EXISTS idx_service_visits_status ON service_visits (status, scheduled_for);

/* ──────────────────────────── warranty claims ────────────────────────────
   A claim is the question "is this covered", and the answer is recorded with
   the date it was decided. It is separate from the ticket because a ticket can
   carry a claim and a repair, and the claim is what finance and the customer
   argue about later. */

CREATE TABLE IF NOT EXISTS warranty_claims (
  id           BIGSERIAL PRIMARY KEY,
  ref          TEXT NOT NULL UNIQUE,
  ticket_id    BIGINT,
  asset_id     BIGINT,
  customer     TEXT NOT NULL,
  part         TEXT,
  fault        TEXT NOT NULL,
  /* The date the answer was decided. Stored, not derived: `installed_assets`
     can be edited and a product's warranty term can change, but the answer
     given to this customer on this day cannot. */
  claimed_on   DATE NOT NULL DEFAULT CURRENT_DATE,
  warranty_until_on_claim DATE,
  verdict      TEXT NOT NULL DEFAULT 'pending',
  decided_on   DATE,
  decided_by   TEXT,
  reason       TEXT,
  cost_to_woodex NUMERIC(14,2),
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT warranty_claims_customer_not_blank CHECK (btrim(customer) <> ''),
  CONSTRAINT warranty_claims_fault_not_blank    CHECK (btrim(fault) <> ''),
  CONSTRAINT warranty_claims_verdict_check
    CHECK (verdict IN ('pending','covered','not_covered','goodwill')),
  CONSTRAINT warranty_claims_decided_when_verdict
    CHECK (verdict = 'pending' OR decided_on IS NOT NULL),
  CONSTRAINT warranty_claims_cost_non_negative CHECK (cost_to_woodex IS NULL OR cost_to_woodex >= 0),
  CONSTRAINT warranty_claims_ticket_fkey FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE SET NULL,
  CONSTRAINT warranty_claims_asset_fkey  FOREIGN KEY (asset_id)  REFERENCES installed_assets (id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_warranty_claims_asset ON warranty_claims (asset_id);
CREATE INDEX IF NOT EXISTS idx_warranty_claims_verdict ON warranty_claims (verdict, claimed_on DESC);

/* SLA deadlines are computed from the priority at intake and frozen. Raising a
   priority later does not quietly extend the promise that was made when the
   ticket arrived — it would let a team escape an SLA by downgrading, waiting,
   and upgrading again. */
CREATE OR REPLACE FUNCTION tickets_freeze_sla() RETURNS trigger AS $$
BEGIN
  IF TG_OP = 'UPDATE' AND OLD.resolve_by IS NOT NULL AND NEW.resolve_by IS DISTINCT FROM OLD.resolve_by THEN
    RAISE EXCEPTION 'the SLA deadline is set once, from the priority at intake: moving it after the fact makes a breach disappear'
      USING ERRCODE = 'P0001';
  END IF;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_tickets_freeze_sla ON tickets;
CREATE TRIGGER trg_tickets_freeze_sla
  BEFORE UPDATE ON tickets
  FOR EACH ROW EXECUTE FUNCTION tickets_freeze_sla();

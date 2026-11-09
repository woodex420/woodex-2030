-- 014 · CRM depth: companies, contacts, meetings, attachments, activity history
--
-- Phase 5's CRM half. Before this migration a customer was a string: leads had
-- `company TEXT` and `contact TEXT`, clients had `company TEXT`, and the same
-- human arriving twice from two channels became two rows that nothing joined.
-- "Show me everything we have ever done with this company" was a LIKE across a
-- free-text column, so it missed the spellings the typist got wrong — which is
-- to say it missed most of them.
--
-- ── WHAT A COMPANY RECORD BUYS ──────────────────────────────────────────
--
-- One row per real-world company, with `name_norm` (the folded key) UNIQUE.
-- The fold lowercases, strips punctuation, and drops legal suffixes, so
-- "Clifton Villa (Pvt) Ltd.", "clifton villa private limited" and
-- "Clifton Villa" collide on purpose. That collision is the feature: the
-- second attempt is refused with a 409 naming the first, and the operator
-- opens it instead of inventing a third spelling.
--
-- Over-folding is the opposite failure and it is worse, because a duplicate is
-- visible and a bad merge is not. So the fold removes only suffixes that carry
-- no identifying information ("pvt", "ltd", "llc") and never touches the words
-- that distinguish one company from another. "Clifton Villa" and "Clifton
-- Heights" stay separate.
--
-- ── WHY ACTIVITIES IS A TABLE AND NOT A VIEW ────────────────────────────
--
-- The obvious cheap timeline is a UNION over audit_logs, tasks, meetings and
-- payments. It is a trap: a UNION silently omits every table nobody remembered
-- to add to it, and the failure mode is not an error — it is a timeline that
-- looks complete and is missing the deliveries. The missing rows are invisible
-- precisely because they are missing.
--
-- So the timeline is written, not derived: one narrow table that each route
-- appends to inside its own transaction. The cost is that a new event type must
-- remember to write here; the benefit is that what is present is a record of
-- what happened rather than a record of which tables someone joined.
--
-- ── THE TIMELINE IS APPEND-ONLY ─────────────────────────────────────────
--
-- Same reasoning as the journal in 013. A history that can be edited is not a
-- history; it is the current opinion of whoever last had write access. UPDATE
-- and DELETE on `activities` are refused by trigger, so a correction is another
-- event — "called again, earlier note was wrong" — and the wrong note stays
-- visible. That is what makes the timeline worth reading.
--
-- ── ATTACHMENTS ARE POINTERS ────────────────────────────────────────────
--
-- The table records path and checksum, never bytes. Storing files in Postgres
-- makes every backup of the database a backup of the file store too, and a
-- `bytea` column cannot be streamed to a CDN. The checksum is what makes two
-- revisions of the same drawing distinguishable when their filenames are
-- identical — which is the only question anyone asks about an attachment after
-- the fact.
--
-- Unique per (entity_type, entity_id, checksum): re-attaching the identical
-- file to the same entity is refused, because the second copy answers no
-- question the first did not. The same bytes under a DIFFERENT entity are
-- legitimate — one drawing can belong to a company and to the project it
-- describes.

/* ───────────────────────────── companies ───────────────────────────── */

CREATE TABLE IF NOT EXISTS companies (
  id            BIGSERIAL PRIMARY KEY,
  name          TEXT NOT NULL,
  /* The folded key. UNIQUE, and deliberately not exposed as an editable field:
     it is derived from `name` by the route, and a hand-edited key would let
     two companies share a fold while claiming not to. */
  name_norm     TEXT NOT NULL UNIQUE,
  industry      TEXT,
  website       TEXT,
  phone         TEXT,
  email         TEXT,
  tax_ref       TEXT,
  city          TEXT,
  address       TEXT,
  /* Free text, not a FK to users: the account owner is a person in the
     business, and users are logins. Conflating the two means a staff change
     breaks every ownership report. */
  account_owner TEXT,
  notes         TEXT,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT companies_name_not_blank CHECK (btrim(name) <> ''),
  CONSTRAINT companies_name_norm_not_blank CHECK (btrim(name_norm) <> '')
);

CREATE INDEX IF NOT EXISTS idx_companies_owner ON companies (account_owner);
CREATE INDEX IF NOT EXISTS idx_companies_city  ON companies (city);

/* ────────────────────────────── contacts ────────────────────────────── */

CREATE TABLE IF NOT EXISTS contacts (
  id          BIGSERIAL PRIMARY KEY,
  /* Both nullable and both ON DELETE SET NULL: a person outlives the company
     they used to work for, and deleting the company must not delete the
     person's number. */
  company_id  BIGINT,
  client_id   BIGINT,
  name        TEXT NOT NULL,
  email       TEXT,
  /* Folded email, unique where present. Partial, because a site foreman with
     no mailbox is normal and NULL must not collide with NULL. */
  email_norm  TEXT,
  phone       TEXT,
  phone_norm  TEXT,
  role        TEXT,
  is_primary  BOOLEAN NOT NULL DEFAULT false,
  notes       TEXT,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT contacts_name_not_blank CHECK (btrim(name) <> ''),
  CONSTRAINT contacts_company_fkey FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL,
  CONSTRAINT contacts_client_fkey  FOREIGN KEY (client_id)  REFERENCES clients (id)   ON DELETE SET NULL
);

/* Partial UNIQUE: only non-empty addresses participate. Two contacts with no
   email are two different people; two contacts with the same mailbox are the
   same person entered twice, and the second is refused. */
CREATE UNIQUE INDEX IF NOT EXISTS uq_contacts_email
  ON contacts (email_norm)
  WHERE email_norm IS NOT NULL AND email_norm <> '';

CREATE INDEX IF NOT EXISTS idx_contacts_company ON contacts (company_id, is_primary DESC);
CREATE INDEX IF NOT EXISTS idx_contacts_client  ON contacts (client_id);

/* ────────────────────────────── meetings ──────────────────────────────
   NOT a site visit. A site visit is a measurement taken at a property — it has
   an address, a surveyor and usually a report. A meeting is a conversation,
   and it can be a phone call with nobody at any property. Folding the two
   together would force every video call to claim a site, so site visits keep
   their own table and this one keeps `channel`. */

CREATE TABLE IF NOT EXISTS meetings (
  id         BIGSERIAL PRIMARY KEY,
  ref        TEXT NOT NULL UNIQUE,
  title      TEXT NOT NULL,
  company_id BIGINT,
  client_id  BIGINT,
  lead_id    BIGINT,
  starts_at  TIMESTAMPTZ NOT NULL,
  minutes    INTEGER NOT NULL DEFAULT 30,
  location   TEXT,
  channel    TEXT NOT NULL DEFAULT 'in-person',
  owner      TEXT,
  status     TEXT NOT NULL DEFAULT 'scheduled',
  attendees  TEXT,
  agenda     TEXT,
  /* Written after the fact. The route refuses to move a meeting to 'held'
     without one: a calendar that records attendance and not results is a
     diary, and the outcome is the only part anyone rereads. */
  outcome    TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT meetings_status_check  CHECK (status  IN ('scheduled','held','cancelled','no_show')),
  CONSTRAINT meetings_channel_check CHECK (channel IN ('in-person','phone','video')),
  CONSTRAINT meetings_minutes_positive CHECK (minutes > 0),
  CONSTRAINT meetings_title_not_blank CHECK (btrim(title) <> ''),
  CONSTRAINT meetings_company_fkey FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL,
  CONSTRAINT meetings_client_fkey  FOREIGN KEY (client_id)  REFERENCES clients (id)   ON DELETE SET NULL,
  CONSTRAINT meetings_lead_fkey    FOREIGN KEY (lead_id)    REFERENCES leads (id)     ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_meetings_starts ON meetings (starts_at, status);
CREATE INDEX IF NOT EXISTS idx_meetings_owner  ON meetings (owner, starts_at DESC);
CREATE INDEX IF NOT EXISTS idx_meetings_company ON meetings (company_id);

/* ──────────────────────────── attachments ──────────────────────────── */

CREATE TABLE IF NOT EXISTS attachments (
  id          BIGSERIAL PRIMARY KEY,
  entity_type TEXT NOT NULL,
  entity_id   BIGINT NOT NULL,
  filename    TEXT NOT NULL,
  mime_type   TEXT,
  size_bytes  BIGINT,
  /* Where the object store keeps it. Never the bytes; see the header. */
  path        TEXT,
  /* What distinguishes revision 2 from revision 1 when the name is identical. */
  checksum    TEXT,
  uploaded_by TEXT,
  notes       TEXT,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT attachments_filename_not_blank CHECK (btrim(filename) <> ''),
  CONSTRAINT attachments_size_non_negative CHECK (size_bytes IS NULL OR size_bytes >= 0)
);

CREATE INDEX IF NOT EXISTS idx_attachments_entity ON attachments (entity_type, entity_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_attachments_checksum_per_entity
  ON attachments (entity_type, entity_id, checksum)
  WHERE checksum IS NOT NULL;

/* ──────────────────────────── activities ──────────────────────────── */

CREATE TABLE IF NOT EXISTS activities (
  id          BIGSERIAL PRIMARY KEY,
  entity_type TEXT NOT NULL,
  entity_id   BIGINT NOT NULL,
  kind        TEXT NOT NULL,
  summary     TEXT NOT NULL,
  detail      TEXT,
  actor       TEXT,
  /* When it happened, which is not when it was recorded: a Monday call logged
     on Friday keeps Monday's date, because the timeline is ordered by when
     things happened. */
  occurred_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT activities_summary_not_blank CHECK (btrim(summary) <> ''),
  CONSTRAINT activities_kind_check CHECK (kind IN
    ('call','meeting','note','email','status_change','document','payment','system'))
);

CREATE INDEX IF NOT EXISTS idx_activities_entity   ON activities (entity_type, entity_id, occurred_at DESC);
CREATE INDEX IF NOT EXISTS idx_activities_occurred ON activities (occurred_at DESC);

/* Append-only, enforced here rather than in the route. A route-level guard is
   a promise; this is a mechanism — the same reasoning, and the same shape, as
   journal_lines in 013. */
CREATE OR REPLACE FUNCTION activities_are_append_only() RETURNS trigger AS $$
BEGIN
  RAISE EXCEPTION 'activities are append-only: a history that can be edited is not a history'
    USING ERRCODE = 'P0001';
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_activities_no_change ON activities;
CREATE TRIGGER trg_activities_no_change
  BEFORE UPDATE OR DELETE ON activities
  FOR EACH ROW EXECUTE FUNCTION activities_are_append_only();

/* ──────────────── links from the existing free-text records ────────────────
   leads.company and clients.company stay. They are what intake writes, and
   removing them would break every caller and every backfill script on the day
   this migration lands. `company_id` is added as a nullable pointer, filled by
   the route that links records, and never guessed from the text: a wrong guess
   attaches a lead to the wrong customer and nobody ever notices. */

ALTER TABLE leads   ADD COLUMN IF NOT EXISTS company_id BIGINT;
ALTER TABLE clients ADD COLUMN IF NOT EXISTS company_id BIGINT;

/* Named, not inline. Two reasons: an inline `REFERENCES` gets an
   automatically generated constraint name, so the drift audit cannot look for
   it by name; and ALTER TABLE ... ADD CONSTRAINT IF NOT EXISTS does not exist,
   so the idempotent form is DO-block guarded. */
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'leads_company_fkey') THEN
    ALTER TABLE leads ADD CONSTRAINT leads_company_fkey
      FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'clients_company_fkey') THEN
    ALTER TABLE clients ADD CONSTRAINT clients_company_fkey
      FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL;
  END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_leads_company   ON leads (company_id);
CREATE INDEX IF NOT EXISTS idx_clients_company ON clients (company_id);

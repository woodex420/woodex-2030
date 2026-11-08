-- 001 · give client_links a primary key
--
-- client_links is the CRM's join table (client ↔ lead/quote/order/invoice).
-- It carried a UNIQUE(entity, entity_id) constraint but no primary key, so it
-- was the only one of the 29 tables without one. A table with no primary key
-- has no row identity: it cannot be referenced by a foreign key, cannot be
-- de-duplicated by row, and cannot be updated or deleted by handle — only by
-- matching on values.
--
-- The key is (entity, entity_id), not the surrogate pair, because a given
-- record belongs to exactly one client. That is already true in the data: the
-- UNIQUE(entity, entity_id) this replaces enforced it. Making it the PK states
-- the invariant instead of merely constraining it, and lets 002 point a real
-- foreign key at it later.
--
-- client_id stays NOT NULL: a link with no client is not a link.
--
-- Idempotent: runs only when the table still lacks a primary key, so a
-- database that already has it is untouched.

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint c
      JOIN pg_class t ON t.oid = c.conrelid
      JOIN pg_namespace n ON n.oid = t.relnamespace
     WHERE c.contype = 'p' AND t.relname = 'client_links' AND n.nspname = current_schema()
  ) THEN
    -- Pre-flight: a PK cannot be added over duplicate (entity, entity_id) pairs.
    -- The old UNIQUE made them impossible, but assert rather than assume.
    IF EXISTS (
      SELECT 1 FROM client_links
       GROUP BY entity, entity_id HAVING count(*) > 1
    ) THEN
      RAISE EXCEPTION 'client_links: duplicate (entity, entity_id) rows block the primary key; resolve them first';
    END IF;

    ALTER TABLE client_links ADD CONSTRAINT client_links_pkey PRIMARY KEY (entity, entity_id);
  END IF;
END $$;

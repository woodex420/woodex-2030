-- 016 · support and warranty — ROLLBACK
--
--   node scripts/migrate.mjs --dsn <url> --rollback 016
--
-- Refuses once any ticket, visit or claim exists.
--
-- A ticket is what a customer told us was wrong; a visit is what we did about
-- it; a claim is an answer we gave about whether it was covered. Dropping them
-- does not resolve the complaints — it destroys the record that they were ever
-- made, which is the record that protects both the customer and Woodex when the
-- same fault reappears.

DO $$
DECLARE
  n_tickets BIGINT;
  n_visits  BIGINT;
  n_claims  BIGINT;
BEGIN
  SELECT count(*) INTO n_tickets FROM tickets;
  SELECT count(*) INTO n_visits  FROM service_visits;
  SELECT count(*) INTO n_claims  FROM warranty_claims;

  IF n_tickets > 0 OR n_visits > 0 OR n_claims > 0 THEN
    RAISE EXCEPTION
      'refusing to roll back 016: % ticket(s), % visit(s) and % claim(s) exist. These are the record of what customers reported and what was promised.',
      n_tickets, n_visits, n_claims;
  END IF;
END $$;

DROP TRIGGER IF EXISTS trg_tickets_freeze_sla ON tickets;
DROP FUNCTION IF EXISTS tickets_freeze_sla();

DROP TABLE IF EXISTS warranty_claims;
DROP TABLE IF EXISTS service_visits;
DROP TABLE IF EXISTS ticket_messages;
DROP TABLE IF EXISTS tickets;

-- 018 · shared throttling — ROLLBACK
--
--   node scripts/migrate.mjs --dsn <url> --rollback 018
--
-- Refuses while any bucket is locked.
--
-- Dropping this table returns login throttling to the per-process Map, which
-- means an attacker holding a locked account gets their attempts back the
-- moment a new function instance starts. That is a security regression, not a
-- schema change, so it is refused while a lock is actually in force.
--
-- Locked buckets expire by themselves. Wait for them to lapse, or clear them
-- deliberately first if you are certain — deleting the table while somebody is
-- locked out would be the one case where removing it silently reopens an
-- attack that was already being defended against.

DO $$
DECLARE
  n_locked BIGINT;
BEGIN
  SELECT count(*) INTO n_locked
    FROM throttle_buckets
   WHERE locked_until IS NOT NULL AND locked_until > now();

  IF n_locked > 0 THEN
    RAISE EXCEPTION
      'refusing to roll back 018: % bucket(s) are locked right now. Dropping this table hands those attempts back to the next cold start.',
      n_locked;
  END IF;
END $$;

DROP TABLE IF EXISTS throttle_buckets;

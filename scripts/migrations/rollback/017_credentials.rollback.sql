-- 017 · credentials — ROLLBACK
--
--   node scripts/migrate.mjs --dsn <url> --rollback 017
--
-- Refuses while any password reset is still live.
--
-- A live token is an outstanding key to an account. Dropping the table would
-- silently invalidate it — which is safe — but it would also erase the record
-- that somebody asked to regain access and was never answered. Finish the
-- resets (use them or revoke them) before taking the table away; the history
-- stays, and the code that issued them stops trusting the table first.

DO $$
DECLARE
  n_live BIGINT;
BEGIN
  SELECT count(*) INTO n_live
    FROM password_resets
   WHERE used_at IS NULL AND revoked_at IS NULL AND expires_at > now();

  IF n_live > 0 THEN
    RAISE EXCEPTION
      'refusing to roll back 017: % password reset token(s) are still live. A live token is an outstanding key to an account — use or revoke it first.',
      n_live;
  END IF;
END $$;

DROP TABLE IF EXISTS password_resets;

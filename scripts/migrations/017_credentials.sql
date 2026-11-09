-- 017 · credentials: self-service password change and audited recovery
--
-- Today a password can only be changed by somebody holding `users.manage`.
-- Every other person in the building is locked to whatever they were given on
-- their first day, cannot improve it, and — because there is no reset flow at
-- all — cannot recover it. Production currently runs a single owner account:
-- lose that password and the system is unreachable, with no way back in but a
-- hand-written SQL update.
--
-- That is not a missing feature. It is the difference between a system that can
-- be run and one that can be lost.
--
-- ── TWO WAYS BACK IN, AND WHY THEY MUST NOT BE THE SAME THING ─────────────
--
--   1. A CHANGE — you know your current password and you choose a new one.
--      Self-service, immediate, no token, no email. This is the common case and
--      it must never depend on anybody else being awake.
--
--   2. A RESET — you have forgotten it. This is the dangerous case, because the
--      person asking may not be the person who owns the account. It requires a
--      single-use token that expires, is stored only as a hash, and is minted
--      only by somebody already holding `users.manage`.
--
-- Conflating them is how password reset becomes an account-takeover vector. A
-- "reset" that anybody can trigger and that never expires is not recovery, it
-- is a back door.
--
-- ── WHAT THIS MIGRATION DOES NOT CLAIM ────────────────────────────────────
--
-- It does not send email. There is no mail infrastructure in this system and
-- inventing one inside a credentials migration would produce something that
-- looks finished and silently delivers nothing. The token is returned to the
-- caller — the administrator hands it to the person by a channel they choose —
-- and the `email` delivery channel is left explicitly unsupported (`501`) with
-- a message that says which decision is outstanding. When SMTP credentials
-- exist, only the delivery step changes; the token rules already hold.

CREATE TABLE IF NOT EXISTS password_resets (
  id          BIGSERIAL PRIMARY KEY,
  user_id     BIGINT NOT NULL,
  /* Only the hash is stored. Whoever reads this table — a backup, a support
     engineer, an attacker — cannot use it to reset anybody's password. */
  token_hash  TEXT NOT NULL UNIQUE,
  /* Why it was raised. A reset issued because somebody left the company and
     one issued because an owner mistyped their password are different events,
     and only one of them should make you check whether other sessions died. */
  reason      TEXT NOT NULL DEFAULT 'forgotten',
  requested_by BIGINT,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  expires_at  TIMESTAMPTZ NOT NULL,
  used_at     TIMESTAMPTZ,
  /* Set when the reset is retired without being used — superseded by a newer
     one, or withdrawn. A token that is simply deleted leaves no trace that it
     ever existed, so nobody can tell a password was almost recovered. */
  revoked_at  TIMESTAMPTZ,
  CONSTRAINT password_resets_reason_check
    CHECK (reason IN ('forgotten','compromised','offboarding','initial')),
  CONSTRAINT password_resets_expiry_after_creation CHECK (expires_at > created_at),
  /* A single value cannot be both spent and withdrawn. If it were, the audit
     trail would say two contradictory things about the same token. */
  CONSTRAINT password_resets_one_terminal_state
    CHECK (used_at IS NULL OR revoked_at IS NULL),
  CONSTRAINT password_resets_user_fkey FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT password_resets_requester_fkey FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_password_resets_user ON password_resets (user_id, created_at DESC);
/* The lookup that matters: "is there a live token for this hash". Partial on
   the open rows only, because spent tokens are history and never queried. */
CREATE INDEX IF NOT EXISTS idx_password_resets_live
  ON password_resets (token_hash) WHERE used_at IS NULL AND revoked_at IS NULL;

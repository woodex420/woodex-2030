-- 018 · shared throttling: a lockout that survives a cold start
--
-- The login throttle lives in a JavaScript Map inside the process. That is
-- correct for a single long-running server and wrong for everything this
-- system actually deploys as:
--
--   • Vercel runs each request in a function instance that may be brand new.
--     A cold start gets an empty Map, so the 5th wrong password is the 1st
--     again. Measured: two processes on the SAME database — one throttled, the
--     other wide open — so the counter protected nothing but its own process.
--   • Several instances run in parallel. An attacker reaching N instances gets
--     N × 5 attempts per minute, and nothing coordinates them.
--
-- A lockout is a fact about the *account*, not about the process that happened
-- to serve the request. It belongs where the accounts are: the database.
--
-- ── WHY A ROW AND NOT A COUNTER ON THE USER ──────────────────────────────
--
-- Counting failed attempts on the `users` row looks simpler and is wrong twice:
--   1. An unknown email has no row, so attempts against a typo'd or invented
--      address would be uncounted — which is exactly the enumeration case.
--   2. The count would be reset by any successful login, so an attacker who
--      guessed one password could wipe the evidence of their failures.
--
-- The key is the *thing being throttled* — an email or an IP — which may have
-- no users row at all.
--
-- ── FAILURES ARE FORGIVEN, NOT ACCUMULATED ───────────────────────────────
--
-- `failures` is a count within `window_started_at`, not a lifetime total. A
-- person who mistypes twice on Monday and twice on Friday is not an attacker,
-- and a system that treats them as one eventually locks out the whole office.
-- The window slides: once it is older than the window length, the count starts
-- again from zero on the next failure.
--
-- The lock itself is a timestamp, not a boolean, so "how long is left" is
-- arithmetic rather than a second column that can drift out of agreement.

CREATE TABLE IF NOT EXISTS throttle_buckets (
  id               BIGSERIAL PRIMARY KEY,
  /* 'login:<email>' or 'ip:<address>'. The prefix keeps the two namespaces
     from colliding: an email that looks like an IP is still a different key,
     and one policy cannot overwrite the other. */
  bucket_key       TEXT NOT NULL UNIQUE,
  kind             TEXT NOT NULL,
  failures         INTEGER NOT NULL DEFAULT 0,
  window_started_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  /* Set when the bucket trips. NULL means "counting", not "locked". */
  locked_until     TIMESTAMPTZ,
  last_seen_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
  created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT throttle_buckets_kind_check CHECK (kind IN ('login', 'ip')),
  CONSTRAINT throttle_buckets_key_not_blank CHECK (btrim(bucket_key) <> ''),
  /* A negative count is not a state this table can be in; allowing it would
     let an off-by-one bug quietly create free attempts forever. */
  CONSTRAINT throttle_buckets_failures_non_negative CHECK (failures >= 0),
  CONSTRAINT throttle_buckets_window_before_seen CHECK (window_started_at <= last_seen_at)
);

/* The only lookup that happens on a hot path: "is this key locked right now".
   Partial on the locked rows, because counting buckets are the many and the
   locked ones are the few. */
CREATE INDEX IF NOT EXISTS idx_throttle_buckets_locked
  ON throttle_buckets (bucket_key) WHERE locked_until IS NOT NULL;

/* Sweeping. Expired counting buckets are noise that grows forever if nothing
   removes them; this index makes the sweep a range scan rather than a table
   scan on a table that will become the largest in the system. */
CREATE INDEX IF NOT EXISTS idx_throttle_buckets_last_seen ON throttle_buckets (last_seen_at);

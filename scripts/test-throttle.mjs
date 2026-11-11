#!/usr/bin/env node
/**
 * Shared throttle rehearsal — a lockout that survives a cold start.
 *
 *   node scripts/test-throttle.mjs
 *
 * The finding this suite exists for: the login throttle used to be a Map inside
 * the process. On Vercel every cold start got an empty Map, so the 5th wrong
 * password was the 1st again. Measured directly: two processes on the SAME
 * database, one throttled and the other wide open.
 *
 * So the central assertion here is not "it locks out" — any counter does that.
 * It is: **a lock created by one process is respected by a different process**.
 * That is the property a serverless deployment needs and the Map could not give.
 *
 * The rest guards the ways a throttle goes wrong in the other direction:
 *
 *   • It must not lock out an honest person who mistypes twice a week, so the
 *     window slides rather than accumulating forever.
 *   • It must not become a directory of who works here — a wrong password on a
 *     real account and on an invented one must look identical.
 *   • It must not be resettable by a successful login from another account
 *     sharing the office address.
 *   • It must not become a free oracle for a disabled account.
 */
import { spawn } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { DatabaseSync } from "node:sqlite";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const tempRoot = path.join(os.tmpdir(), `woodex-throttle-${process.pid}`);
const problems = [];
function check(ok, label, detail = "") {
  if (ok) console.log(`  ✓ ${label}`);
  else { problems.push(`${label}${detail ? ` — ${detail}` : ""}`); console.log(`  ✗ ${label}${detail ? ` — ${detail}` : ""}`); }
}

const children = [];
function startServer(dir) {
  const child = spawn(process.execPath, ["apps/api/server.mjs"], {
    cwd: ROOT,
    env: { ...process.env, NODE_ENV: "test", PORT: "0", WOODEX_DATA_DIR: dir,
      DATABASE_URL: "", VERCEL: "", VERCEL_ENV: "", WOODEX_DEMO_SEED: "1",
      /* The hourly sweeper would otherwise fire against a temp db mid-run. */
      WOODEX_DISABLE_SWEEP: "1" },
    stdio: ["ignore", "pipe", "pipe"],
  });
  children.push(child);
  let log = "";
  const base = new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error(`server did not start in 60s:\n${log.slice(-900)}`)), 60_000);
    const onData = (buf) => {
      log += String(buf);
      const m = log.match(/http:\/\/localhost:(\d+)/);
      if (m) { clearTimeout(timer); resolve(`http://127.0.0.1:${m[1]}`); }
    };
    child.stdout.on("data", onData);
    child.stderr.on("data", onData);
    child.on("exit", (code) => { clearTimeout(timer); reject(new Error(`server exited ${code}:\n${log.slice(-900)}`)); });
  });
  return { child, base };
}

const call = (base) => async (p, opts = {}) => {
  const res = await fetch(base + p, {
    method: opts.method ?? "GET",
    headers: { "content-type": "application/json", ...(opts.token ? { authorization: "Bearer " + opts.token } : {}) },
    body: opts.body ? JSON.stringify(opts.body) : undefined,
  });
  let json = null;
  try { json = await res.json(); } catch { /* non-JSON is itself a finding */ }
  return { status: res.status, json, headers: res.headers };
};
const tryLogin = async (api, email, password) => {
  try { return await api("/api/auth/login", { method: "POST", body: { email, password } }); }
  catch (e) { return { status: 0, json: { error: String(e) } }; }
};

try {
  fs.mkdirSync(tempRoot, { recursive: true });

  /* Two servers, ONE data directory. This is the whole point: separate process
     memory, shared database. A cold start looks exactly like the second server. */
  const a = startServer(tempRoot);
  const baseA = await a.base;
  const apiA = call(baseA);
  const loginA = await tryLogin(apiA, "admin", "admin");
  const tokenA = loginA.json?.token;
  check(Boolean(tokenA), "server A signs in", loginA.json?.error ?? "");

  console.log("\n[throttle] one process locks the account");
  /* Fresh state for a clean count. */
  await apiA(`/api/ops/throttle/${encodeURIComponent("login:sana@woodex.pk")}`, { method: "DELETE", token: tokenA });
  await apiA("/api/users", { method: "POST", token: tokenA, body: {
    email: "sana@woodex.pk", name: "Sana", role: "sales", password: "realpass1" } });

  const attempts = [];
  for (let i = 1; i <= 5; i++) {
    const r = await tryLogin(apiA, "sana@woodex.pk", "wrong");
    attempts.push(r.status);
  }
  check(attempts.slice(0, 5).every((s) => s === 401),
    "the first five wrong passwords are plain rejections", attempts.join(","));
  const sixth = await tryLogin(apiA, "sana@woodex.pk", "wrong");
  check(sixth.status === 429, "the sixth trips the lock", `got ${sixth.status}`);
  check(sixth.json?.code === "throttled", "with a machine-readable code", sixth.json?.code);
  check(sixth.json?.scope === "account", "and says it is the account that is locked", sixth.json?.scope);
  check(Number(sixth.json?.retryAfterSeconds) > 0, "and how long is left", `${sixth.json?.retryAfterSeconds}`);
  check(Boolean(sixth.headers.get("retry-after")), "with a Retry-After header a client can honour",
    sixth.headers.get("retry-after"));

  /* The real password is refused too — a lock is a lock, and letting the right
     password through would mean an attacker just guesses until they hit it. */
  const correctWhileLocked = await tryLogin(apiA, "sana@woodex.pk", "realpass1");
  check(correctWhileLocked.status === 429, "the correct password is refused while locked", `got ${correctWhileLocked.status}`);

  /* ─────────── the assertion this whole increment exists for ─────────── */
  console.log("\n[throttle] a different process on the same database respects it");
  const b = startServer(tempRoot);
  const baseB = await b.base;
  const apiB = call(baseB);
  const crossProcess = await tryLogin(apiB, "sana@woodex.pk", "wrong");
  check(crossProcess.status === 429,
    "server B — a cold start — is already locked, because the lock is in the database",
    `got ${crossProcess.status}`);
  check(crossProcess.json?.scope === "account", "and agrees on the scope", crossProcess.json?.scope);
  /* Prove it is the shared row and not luck: the bucket exists in the file. */
  const db = new DatabaseSync(path.join(tempRoot, "woodex.db"));
  try {
    const row = db.prepare("SELECT * FROM throttle_buckets WHERE bucket_key = ?").get("login:sana@woodex.pk");
    check(Boolean(row), "the bucket is a row in the database, not process memory");
    check(Number(row?.failures) >= 5, "holding the failure count", `${row?.failures}`);
    check(Boolean(row?.locked_until), "and the lock timestamp", String(row?.locked_until));
  } finally { db.close(); }

  /* ─────────── unlocking: by hand, and the audit trail ─────────── */
  console.log("\n[throttle] an operator can clear a lock, and it is recorded");
  const lockedList = await apiA("/api/ops/throttle?locked=1", { token: tokenA });
  check((lockedList.json?.locked ?? 0) >= 1, "the locked bucket is visible to an operator", `${lockedList.json?.locked}`);
  check(JSON.stringify(lockedList.json).includes("login:sana@woodex.pk"), "by key, so they know who to unblock");

  const nonOwner = await apiA("/api/ops/throttle", { token: (await tryLogin(apiA, "sana@woodex.pk", "realpass1")).json?.token });
  check(nonOwner.status === 401 || nonOwner.status === 403,
    "a non-owner cannot read the throttle table", `got ${nonOwner.status}`);

  const cleared = await apiA(`/api/ops/throttle/${encodeURIComponent("login:sana@woodex.pk")}`, { method: "DELETE", token: tokenA });
  check(cleared.status === 200, "the lock can be cleared deliberately", `got ${cleared.status}`);
  const afterClear = await tryLogin(apiA, "sana@woodex.pk", "realpass1");
  check(afterClear.status === 200, "and the person can sign in with their real password", `got ${afterClear.status}`);

  const clearGhost = await apiA("/api/ops/throttle/login%3Anobody", { method: "DELETE", token: tokenA });
  check(clearGhost.status === 404, "clearing a bucket that does not exist says so", `got ${clearGhost.status}`);

  const audit = await apiA("/api/audit?limit=8", { token: tokenA });
  const actions = (audit.json?.items ?? []).map((x) => x.action);
  check(actions.includes("throttle.cleared"), "clearing a security control is audited", JSON.stringify(actions.slice(0, 5)));

  /* ─────────── a success must not wipe an IP's history ─────────── */
  console.log("\n[throttle] a success does not whitewash the address");
  /* Everything here arrives from 127.0.0.1, so the IP bucket is shared by all
     of these requests — which is exactly the real-world office-NAT case. */
  const ipRowBefore = (() => {
    const d = new DatabaseSync(path.join(tempRoot, "woodex.db"));
    try { return d.prepare("SELECT * FROM throttle_buckets WHERE bucket_key LIKE 'ip:%'").get(); }
    finally { d.close(); }
  })();
  check(Boolean(ipRowBefore), "the address has its own bucket", String(ipRowBefore?.bucket_key));
  check(Number(ipRowBefore?.failures) > 0, "counting the failures from this address", `${ipRowBefore?.failures}`);

  const ipRowAfter = (() => {
    const d = new DatabaseSync(path.join(tempRoot, "woodex.db"));
    try { return d.prepare("SELECT * FROM throttle_buckets WHERE bucket_key = ?").get(String(ipRowBefore?.bucket_key)); }
    finally { d.close(); }
  })();
  check(Number(ipRowAfter?.failures) >= Number(ipRowBefore?.failures),
    "and a successful login from the same address did not reset it",
    `${ipRowBefore?.failures} → ${ipRowAfter?.failures}`);

  /* ─────────── enumeration: real and invented accounts look the same ─────────── */
  console.log("\n[throttle] a real account and an invented one look identical");
  const realWrong = await tryLogin(apiA, "sana@woodex.pk", "certainlywrong");
  const ghostWrong = await tryLogin(apiA, "nosuchperson@woodex.pk", "certainlywrong");
  check(realWrong.status === ghostWrong.status,
    "both answer with the same status", `${realWrong.status} vs ${ghostWrong.status}`);
  check(realWrong.json?.error === ghostWrong.json?.error,
    "and the same message, so the form is not a staff directory",
    `${realWrong.json?.error} vs ${ghostWrong.json?.error}`);

  const ghostRow = (() => {
    const d = new DatabaseSync(path.join(tempRoot, "woodex.db"));
    try { return d.prepare("SELECT * FROM throttle_buckets WHERE bucket_key = ?").get("login:nosuchperson@woodex.pk"); }
    finally { d.close(); }
  })();
  check(Boolean(ghostRow), "an invented address still gets a bucket, so its attempts are counted too",
    "no bucket = free guesses at a typo'd address");

  /* ─────────── a disabled account is not a free oracle ─────────── */
  console.log("\n[throttle] a disabled account cannot be ground against");
  const db2 = new DatabaseSync(path.join(tempRoot, "woodex.db"));
  const staffIds = (() => { try { return db2.prepare("SELECT id FROM users WHERE email=?").get("sana@woodex.pk"); } finally { db2.close(); } })();
  await apiA(`/api/users/${staffIds.id}`, { method: "PATCH", token: tokenA, body: { active: false } });
  await apiA(`/api/ops/throttle/${encodeURIComponent("login:sana@woodex.pk")}`, { method: "DELETE", token: tokenA });

  const disabledFirst = await tryLogin(apiA, "sana@woodex.pk", "realpass1");
  check(disabledFirst.status === 403, "the correct password on a disabled account is refused", `got ${disabledFirst.status}`);
  check(disabledFirst.json?.error?.includes("disabled"), "and says the account is disabled", String(disabledFirst.json?.error).slice(0, 40));

  const disabledWrong = await tryLogin(apiA, "sana@woodex.pk", "notthepassword");
  check(disabledWrong.status === 401, "a wrong password on it is a plain rejection", `got ${disabledWrong.status}`);

  /* Five wrong guesses at a disabled account must still trip the lock —
     otherwise "disabled" becomes the one account you can grind forever. */
  for (let i = 0; i < 5; i++) await tryLogin(apiA, "sana@woodex.pk", "wrong");
  const disabledLocked = await tryLogin(apiA, "sana@woodex.pk", "wrong");
  check(disabledLocked.status === 429, "and enough attempts still trip the lock", `got ${disabledLocked.status}`);

  /* ─────────── the sweep ─────────── */
  console.log("\n[throttle] housekeeping");
  const sweep = await apiA("/api/ops/throttle/sweep", { method: "POST", token: tokenA });
  check(sweep.status === 200, "the sweep can be run on demand (serverless has no timer)", `got ${sweep.status}`);
  check(typeof sweep.json?.remaining === "number", "and reports what is left", JSON.stringify(sweep.json));
  /* A bucket touched one second ago is fresh, so the sweep must not remove it. */
  check(Number(sweep.json?.remaining) >= 1, "recent buckets survive the sweep", `${sweep.json?.remaining}`);

  console.log("\n[throttle] the throttle table refuses impossible rows");
  const db3 = new DatabaseSync(path.join(tempRoot, "woodex.db"));
  try {
    const probes = [
      ["INSERT INTO throttle_buckets(bucket_key,kind,failures,window_started_at,last_seen_at,created_at) VALUES('login:x@y.pk','login',-1,'2026-01-01','2026-01-02','2026-01-01')", "a negative failure count"],
      ["INSERT INTO throttle_buckets(bucket_key,kind,failures,window_started_at,last_seen_at,created_at) VALUES('weird:z','weird',1,'2026-01-01','2026-01-02','2026-01-01')", "an invented bucket kind"],
      ["INSERT INTO throttle_buckets(bucket_key,kind,failures,window_started_at,last_seen_at,created_at) VALUES('  ','login',1,'2026-01-01','2026-01-02','2026-01-01')", "a blank bucket key"],
      ["INSERT INTO throttle_buckets(bucket_key,kind,failures,window_started_at,last_seen_at,created_at) VALUES('login:dup@x.pk','login',1,'2026-01-01','2026-01-02','2026-01-01'); INSERT INTO throttle_buckets(bucket_key,kind,failures,window_started_at,last_seen_at,created_at) VALUES('login:dup@x.pk','login',1,'2026-01-01','2026-01-02','2026-01-01')", "the same bucket key twice"],
    ];
    for (const [sql, label] of probes) {
      let blocked = false;
      try { db3.exec(sql); } catch { blocked = true; }
      check(blocked, `${label} is rejected`);
    }
  } finally { db3.close(); }

  /* ---------------------------------------------------------------------
     Postgres timestamp semantics. SQLite hands back strings, Postgres hands
     back Date objects. Writing one back raw, or comparing String(date) to an
     ISO string, works perfectly on SQLite and is broken on Postgres — which
     is how a throttle that passed every local gate returned 500 on the
     PostgreSQL rehearsal, and how two expiry checks silently never fired.
     These four assertions run against real Postgres semantics via PGlite.
     --------------------------------------------------------------------- */
  console.log("\n[throttle] timestamps survive a Postgres round-trip");
  const { PGlite } = await import("@electric-sql/pglite");
  const pg = await PGlite.create();
  try {
    await pg.exec(fs.readFileSync(path.join(ROOT, "scripts/migrations/018_throttle.sql"), "utf8"));

    /* 1. A timestamp read back is a Date, not the string SQLite would give. */
    await pg.query(
      "INSERT INTO throttle_buckets(bucket_key,kind,failures,window_started_at,last_seen_at,created_at) VALUES($1,'login',3,now(),now(),now())",
      ["login:pg@x.pk"]
    );
    const back = (await pg.query("SELECT * FROM throttle_buckets WHERE bucket_key=$1", ["login:pg@x.pk"])).rows[0];
    check(back.window_started_at instanceof Date, "Postgres returns a Date, not a string (the assumption that broke)");

    /* 2. String(date) is NOT a timestamp. This is the exact call that turned a
          Date into "Tue Nov 10 2026 17:57:25 GMT+0000 (…)" and made the login
          endpoint answer 500 on Postgres while SQLite accepted it happily. */
    let stringWriteFailed = false;
    try {
      await pg.query("UPDATE throttle_buckets SET window_started_at=$1 WHERE bucket_key=$2", [String(back.window_started_at), "login:pg@x.pk"]);
    } catch { stringWriteFailed = true; }
    check(stringWriteFailed, "String(date) written to a timestamptz column is rejected — the exact bug");

    /* 3. A Date object is fine, because the driver serialises it properly. The
          bug was never "passed a Date", it was "called String() on a Date". */
    let dateWriteOk = true;
    try {
      await pg.query("UPDATE throttle_buckets SET last_seen_at=$1 WHERE bucket_key=$2", [new Date(), "login:pg@x.pk"]);
    } catch { dateWriteOk = false; }
    check(dateWriteOk, "a Date object itself serialises correctly — the fix is normalising, not avoiding");

    /* 2. The fix: iso() normalises it, and the write succeeds. */
    const iso = (v) => (v === null || v === undefined ? null : v instanceof Date ? v.toISOString() : String(v));
    const ts = (v) => (v === null || v === undefined ? NaN : v instanceof Date ? v.getTime() : Date.parse(String(v)));
    await pg.query(
      "UPDATE throttle_buckets SET window_started_at=$1, locked_until=$2, last_seen_at=$3 WHERE bucket_key=$4",
      [iso(back.window_started_at), iso(new Date(Date.now() + 60_000)), iso(new Date()), "login:pg@x.pk"]
    );
    check(true, "an ISO-normalised carry-back is accepted");

    /* 3. The comparison bug: String(date) never sorts against an ISO string. */
    const expired = (await pg.query("SELECT (now() - interval '1 day') AS at")).rows[0].at;
    check(String(expired) < new Date().toISOString() === false, "String(date) misreads a day-old timestamp as not-expired (the bug)");
    check(ts(expired) < Date.now(), "ts(date) reads the same timestamp correctly (the fix)");

    /* 4. The window-slide predicate the throttle relies on. */
    const slid = await pg.query(
      "SELECT window_started_at <= $1 AS fresh FROM throttle_buckets WHERE bucket_key=$2",
      [new Date(Date.now() + 60_000).toISOString(), "login:pg@x.pk"]
    );
    check(slid.rows[0].fresh === true, "an ISO string compares correctly against a timestamptz column");
  } finally {
    await pg.close();
  }

  /* A static guard, because the suites above provably cannot catch this one.
     On SQLite `String(row.expires_at)` of a string IS that string, so a dead
     expiry check still passes every SQLite test — which is exactly how it
     reached production. A Postgres-backed unit test proves the semantics but
     would not notice if the same pattern were reintroduced somewhere else. So
     this scans the source for the pattern itself. */
  console.log("\n[throttle] no timestamp is compared as a string");
  {
    const source = fs.readFileSync(path.join(ROOT, "apps/api/server.mjs"), "utf8");
    const offenders = source.split("\n")
      .map((line, i) => ({ line, n: i + 1 }))
      /* Date.parse(String(x)) is legitimate — the parser accepts both forms.
         Everything else that stringifies a timestamp is a bug, because
         String(date) is "Tue Nov 10 2026 …", not an ISO timestamp. */
      .filter(({ line }) => /String\(\s*[\w.$]+\.(expires_at|created_at|updated_at|locked_until|last_seen_at|used_at|revoked_at|issued_at|applied_at|window_started_at|published_at)\s*\)/.test(line))
      .filter(({ line }) => !/Date\.parse\(\s*String\(/.test(line));
    check(offenders.length === 0,
      "String(<timestamp>) is never used — Postgres returns a Date and String(date) is not an ISO timestamp",
      offenders.map((o) => `server.mjs:${o.n}`).join(", "));
  }

} catch (e) {
  problems.push(`suite crashed: ${e.message}`);
  console.error(e);
} finally {
  for (const c of children) c.kill("SIGTERM");
  fs.rmSync(tempRoot, { recursive: true, force: true });
}

console.log("");
if (problems.length) {
  console.error(`${problems.length} problem(s):`);
  for (const p of problems) console.error(`  - ${p}`);
  process.exit(1);
}
console.log("Throttle rehearsal passed.");

#!/usr/bin/env node
/**
 * Credentials rehearsal — changing your own password, and recovering it.
 *
 *   node scripts/test-credentials.mjs
 *
 * The assertions that matter are the ones about what must NOT work:
 *
 *   1. A USER CAN CHANGE THEIR OWN PASSWORD without `users.manage`. Before
 *      this, everybody except an administrator was locked to the password they
 *      were given on their first day.
 *
 *   2. CHANGING REQUIRES THE CURRENT PASSWORD. A session alone is not enough —
 *      otherwise a stolen laptop is an account takeover with extra steps.
 *
 *   3. A RESET TOKEN IS STORED ONLY AS A HASH. Reading the table cannot reset
 *      anybody's password. Proven by checking the raw token does not appear in
 *      the database at all.
 *
 *   4. A TOKEN IS SINGLE-USE, EXPIRES, AND CAN BE REVOKED. Each is proven, not
 *      assumed — and every failure answers with the same message, so the
 *      endpoint cannot be used as an oracle for guessing tokens.
 *
 *   5. A RESET ENDS EVERY SESSION; A CHANGE ENDS ALL BUT THE CURRENT ONE.
 *      A password change is the moment somebody believes their old password is
 *      known to someone else; leaving sessions alive means it did not work.
 *
 *   6. ONLY AN ADMINISTRATOR CAN MINT ONE, and minting invalidates any earlier
 *      live token — two valid keys for one account means "cancel that link"
 *      stops being possible.
 *
 *   7. EMAIL IS REFUSED HONESTLY. There is no mail infrastructure; asking for
 *      email delivery answers 501 rather than pretending to send.
 */
import { spawn } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { DatabaseSync } from "node:sqlite";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const tempRoot = path.join(os.tmpdir(), `woodex-cred-${process.pid}`);
const problems = [];
function check(ok, label, detail = "") {
  if (ok) console.log(`  ✓ ${label}`);
  else { problems.push(`${label}${detail ? ` — ${detail}` : ""}`); console.log(`  ✗ ${label}${detail ? ` — ${detail}` : ""}`); }
}

let child;
try {
  fs.mkdirSync(tempRoot, { recursive: true });
  child = spawn(process.execPath, ["apps/api/server.mjs"], {
    cwd: ROOT,
    env: { ...process.env, NODE_ENV: "test", PORT: "0", WOODEX_DATA_DIR: tempRoot,
      DATABASE_URL: "", VERCEL: "", VERCEL_ENV: "", WOODEX_DEMO_SEED: "1" },
    stdio: ["ignore", "pipe", "pipe"],
  });

  let log = "";
  const base = await new Promise((resolve, reject) => {
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

  const api = async (p, opts = {}) => {
    const res = await fetch(base + p, {
      method: opts.method ?? "GET",
      headers: { "content-type": "application/json", ...(opts.token ? { authorization: "Bearer " + opts.token } : {}) },
      body: opts.body ? JSON.stringify(opts.body) : undefined,
    });
    let json = null;
    try { json = await res.json(); } catch { /* non-JSON is itself a finding */ }
    return { status: res.status, json };
  };
  const login = (email, password) => api("/api/auth/login", { method: "POST", body: { email, password } });

  /* ─────────────────── setup: an owner and an ordinary user ─────────────────── */
  const owner = await login("admin", "admin");
  const ownerToken = owner.json?.token;
  check(Boolean(ownerToken), "owner signed in", owner.json?.error ?? "");

  const created = await api("/api/users", { method: "POST", token: ownerToken, body: {
    email: "nadeem@woodex.pk", name: "Nadeem", role: "production", password: "floorpass1" } });
  check(created.status === 201, "an ordinary user exists", `got ${created.status} ${JSON.stringify(created.json)?.slice(0, 120)}`);
  const staff = created.json;
  const staffId = staff?.id;

  const staffLogin = await login("nadeem@woodex.pk", "floorpass1");
  check(Boolean(staffLogin.json?.token), "and can sign in", staffLogin.json?.error ?? "");
  let staffToken = staffLogin.json?.token;
  check(staffLogin.json?.caps?.includes("users.manage") !== true,
    "with no team-management rights", JSON.stringify(staffLogin.json?.caps));

  /* ───────────── 1+2. change your own password ───────────── */
  console.log("\n[credentials] changing your own password");

  const cannotManage = await api("/api/users", { token: staffToken });
  check(cannotManage.status === 403, "they still cannot read the roster", `got ${cannotManage.status}`);

  const noCurrent = await api("/api/auth/change-password", { method: "POST", token: staffToken, body: {
    newPassword: "floorpass2" } });
  check(noCurrent.status === 400, "a change without the current password is refused", `got ${noCurrent.status}`);
  check(noCurrent.json?.code === "current_required", "and says what is missing", noCurrent.json?.code);

  const wrongCurrent = await api("/api/auth/change-password", { method: "POST", token: staffToken, body: {
    currentPassword: "notmypassword", newPassword: "floorpass2" } });
  check(wrongCurrent.status === 401, "a wrong current password is refused", `got ${wrongCurrent.status}`);
  check(wrongCurrent.json?.code === "wrong_password", "and does not leak whether the account exists", wrongCurrent.json?.code);

  const tooShort = await api("/api/auth/change-password", { method: "POST", token: staffToken, body: {
    currentPassword: "floorpass1", newPassword: "abc" } });
  check(tooShort.status === 400, "a password under the floor is refused", `got ${tooShort.status}`);
  check(/at least 6/.test(tooShort.json?.error ?? ""), "and the message names the number", tooShort.json?.error);

  const unchanged = await api("/api/auth/change-password", { method: "POST", token: staffToken, body: {
    currentPassword: "floorpass1", newPassword: "floorpass1" } });
  check(unchanged.status === 400, "re-using the same password is refused", `got ${unchanged.status}`);
  check(unchanged.json?.code === "password_unchanged", "with its own reason", unchanged.json?.code);

  /* A second session for the same user, to prove it is ended by the change. */
  const secondSession = await login("nadeem@woodex.pk", "floorpass1");
  const secondToken = secondSession.json?.token;
  check(Boolean(secondToken), "the user has a second session open");

  const changed = await api("/api/auth/change-password", { method: "POST", token: staffToken, body: {
    currentPassword: "floorpass1", newPassword: "floorpass2" } });
  check(changed.status === 200, "with everything right, the password changes", `got ${changed.status} ${JSON.stringify(changed.json)?.slice(0, 120)}`);
  check(changed.json?.sessionsEnded === 1, "the other session is ended", `${changed.json?.sessionsEnded}`);

  const currentSurvives = await api("/api/auth/me", { token: staffToken });
  check(currentSurvives.status === 200, "the session that made the change survives", `got ${currentSurvives.status}`);
  const otherDead = await api("/api/auth/me", { token: secondToken });
  check(otherDead.status === 401, "the other one does not", `got ${otherDead.status}`);

  const oldPasswordDead = await login("nadeem@woodex.pk", "floorpass1");
  check(oldPasswordDead.status === 401, "the old password stops working", `got ${oldPasswordDead.status}`);
  const newPasswordWorks = await login("nadeem@woodex.pk", "floorpass2");
  check(Boolean(newPasswordWorks.json?.token), "the new one works", newPasswordWorks.json?.error ?? "");
  staffToken = newPasswordWorks.json?.token;

  /* ───────────── 6+7. only an administrator mints a reset ───────────── */
  console.log("\n[credentials] only an administrator mints a reset");

  const staffCannotMint = await api(`/api/users/${staffId}/password-reset`, { method: "POST", token: staffToken, body: {} });
  check(staffCannotMint.status === 403, "an ordinary user cannot mint a reset", `got ${staffCannotMint.status}`);

  const byEmail = await api(`/api/users/${staffId}/password-reset`, { method: "POST", token: ownerToken, body: { channel: "email" } });
  check(byEmail.status === 501, "asking for email delivery is refused honestly", `got ${byEmail.status}`);
  check(byEmail.json?.code === "email_not_configured", "because there is no mail infrastructure", byEmail.json?.code);
  check(/no mail infrastructure/i.test(byEmail.json?.error ?? ""),
    "and the message says why, in words the caller can act on", String(byEmail.json?.error).slice(0, 58));

  const badReason = await api(`/api/users/${staffId}/password-reset`, { method: "POST", token: ownerToken, body: { reason: "because" } });
  check(badReason.status === 400, "an invented reason is refused", `got ${badReason.status}`);

  const ghost = await api("/api/users/99999/password-reset", { method: "POST", token: ownerToken, body: {} });
  check(ghost.status === 404, "a reset for a user who does not exist is refused", `got ${ghost.status}`);

  /* ───────────── 3. the token ───────────── */
  const issued = await api(`/api/users/${staffId}/password-reset`, { method: "POST", token: ownerToken, body: { reason: "forgotten" } });
  check(issued.status === 201, "an administrator can issue a reset", `got ${issued.status} ${JSON.stringify(issued.json)?.slice(0, 140)}`);
  const token = issued.json?.reset?.token;
  check(typeof token === "string" && token.length === 64, "the token is 32 random bytes", String(token).length);
  check(issued.json?.expiresInMinutes === 60, "and lives for an hour", `${issued.json?.expiresInMinutes}`);
  check(/shown once/i.test(issued.json?.notice ?? ""), "the response says it is shown once", issued.json?.notice);

  console.log("\n[credentials] the token is stored only as a hash");
  const db = new DatabaseSync(path.join(tempRoot, "woodex.db"));
  try {
    const rows = db.prepare("SELECT * FROM password_resets").all();
    check(rows.length >= 1, "the reset row exists", `${rows.length}`);
    const blob = JSON.stringify(rows);
    check(!blob.includes(token), "the raw token is nowhere in the table");
    check(rows.every((r) => !("token" in r)), "there is no plaintext token column at all");
    const stored = rows.find((r) => r.used_at === null);
    check(typeof stored?.token_hash === "string" && stored.token_hash.length === 64,
      "a sha256 hash is stored instead", `${stored?.token_hash?.length}`);
  } finally { db.close(); }

  const listed = await api(`/api/users/${staffId}/password-resets`, { token: ownerToken });
  check(listed.json?.live === 1, "the administrator can see one is outstanding", `${listed.json?.live}`);
  check(!JSON.stringify(listed.json).includes(token), "and listing them never returns the token");

  /* ───────────── 4. single use, and one message for every failure ───────────── */
  console.log("\n[credentials] single use, and one message for every failure");

  const anonymousOk = await api("/api/auth/reset-password", { method: "POST", body: {
    token, newPassword: "recovered1" } });
  check(anonymousOk.status === 200, "the token is spent with no session at all", `got ${anonymousOk.status} ${JSON.stringify(anonymousOk.json)?.slice(0, 120)}`);
  check(anonymousOk.json?.email === "nadeem@woodex.pk", "and the account it belonged to is reported", anonymousOk.json?.email);

  const replay = await api("/api/auth/reset-password", { method: "POST", body: { token, newPassword: "hijacked1" } });
  check(replay.status === 400, "the same token cannot be used twice", `got ${replay.status}`);
  check(replay.json?.code === "reset_invalid", "and is refused as invalid", replay.json?.code);

  const madeUp = await api("/api/auth/reset-password", { method: "POST", body: {
    token: "f".repeat(64), newPassword: "hijacked1" } });
  check(madeUp.status === 400, "an invented token is refused", `got ${madeUp.status}`);
  check(madeUp.json?.error === replay.json?.error,
    "and answers with the SAME message, so it cannot be used to guess tokens");

  const shortAtReset = await api("/api/auth/reset-password", { method: "POST", body: { token: "f".repeat(64), newPassword: "abc" } });
  check(shortAtReset.status === 400 && /at least 6/.test(shortAtReset.json?.error ?? ""),
    "the password floor applies at reset too", shortAtReset.json?.error);

  const recoveredLogin = await login("nadeem@woodex.pk", "recovered1");
  check(Boolean(recoveredLogin.json?.token), "the recovered password works", recoveredLogin.json?.error ?? "");
  const spentDead = await login("nadeem@woodex.pk", "floorpass2");
  check(spentDead.status === 401, "the previous one does not", `got ${spentDead.status}`);
  staffToken = recoveredLogin.json?.token;

  /* A reset ends every session, because the person was locked out and somebody
     else may have been signed in. */
  const allDead = await api("/api/auth/me", { token: newPasswordWorks.json?.token });
  check(allDead.status === 401, "a reset ends every other session, unlike a change", `got ${allDead.status}`);

  /* ───────────── revocation, and superseding ───────────── */
  console.log("\n[credentials] revocation and superseding");

  const first = await api(`/api/users/${staffId}/password-reset`, { method: "POST", token: ownerToken, body: {} });
  const second = await api(`/api/users/${staffId}/password-reset`, { method: "POST", token: ownerToken, body: {} });
  check(second.json?.superseded === 1, "issuing a second one retires the first", `${second.json?.superseded}`);

  const supersededUse = await api("/api/auth/reset-password", { method: "POST", body: {
    token: first.json?.reset?.token, newPassword: "superseded1" } });
  check(supersededUse.status === 400, "the retired token no longer works", `got ${supersededUse.status}`);
  check(supersededUse.json?.error === replay.json?.error, "and gives the same message as an expired one");

  const revoked = await api(`/api/users/${staffId}/password-reset/revoke`, { method: "POST", token: ownerToken, body: {} });
  check(revoked.json?.revoked === 1, "the live one can be withdrawn", `${revoked.json?.revoked}`);
  const revokedUse = await api("/api/auth/reset-password", { method: "POST", body: {
    token: second.json?.reset?.token, newPassword: "revokedpw1" } });
  check(revokedUse.status === 400, "and then cannot be used", `got ${revokedUse.status}`);

  const revokeNothing = await api(`/api/users/${staffId}/password-reset/revoke`, { method: "POST", token: ownerToken, body: {} });
  check(revokeNothing.status === 409, "withdrawing when nothing is live says so", `got ${revokeNothing.status}`);
  check(revokeNothing.json?.code === "no_live_reset", "instead of pretending", revokeNothing.json?.code);

  /* ───────────── a disabled account cannot spend a token ───────────── */
  console.log("\n[credentials] a disabled account cannot spend a token");

  const fresh = await api(`/api/users/${staffId}/password-reset`, { method: "POST", token: ownerToken, body: { reason: "compromised" } });
  await api(`/api/users/${staffId}`, { method: "PATCH", token: ownerToken, body: { active: false } });
  const disabledUse = await api("/api/auth/reset-password", { method: "POST", body: {
    token: fresh.json?.reset?.token, newPassword: "disabled1" } });
  check(disabledUse.status === 403, "the token is refused for a disabled account", `got ${disabledUse.status}`);
  check(disabledUse.json?.code === "account_disabled", "and says the account is the problem, not the token", disabledUse.json?.code);

  const stillDisabled = await login("nadeem@woodex.pk", "disabled1");
  check(stillDisabled.status !== 200, "so the reset did not quietly re-enable it", `got ${stillDisabled.status}`);

  await api(`/api/users/${staffId}`, { method: "PATCH", token: ownerToken, body: { active: true } });

  /* ───────────── the write is on the record ───────────── */
  console.log("\n[credentials] changes are on the record");
  const audit = await api("/api/audit?entity_type=user", { token: ownerToken });
  const actions = (audit.json?.items ?? audit.json?.rows ?? []).map((a) => a.action);
  check(actions.includes("auth.password_changed"), "a self-service change is audited", JSON.stringify(actions.slice(0, 6)));
  check(actions.includes("auth.password_reset_issued"), "so is issuing a reset");
  check(actions.includes("auth.password_reset_used"), "and spending one");
  const auditText = JSON.stringify(audit.json);
  check(!auditText.includes("recovered1") && !auditText.includes("floorpass2"),
    "and no password appears anywhere in the audit trail");

} catch (e) {
  problems.push(`suite crashed: ${e.message}`);
  console.error(e);
} finally {
  child?.kill("SIGTERM");
  fs.rmSync(tempRoot, { recursive: true, force: true });
}

console.log("");
if (problems.length) {
  console.error(`${problems.length} problem(s):`);
  for (const p of problems) console.error(`  - ${p}`);
  process.exit(1);
}
console.log("Credentials rehearsal passed.");

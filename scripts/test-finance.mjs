#!/usr/bin/env node
/**
 * Finance rehearsal — chart of accounts, double-entry journal, expenses,
 * credit notes, payment schedules.
 *
 *   node scripts/test-finance.mjs
 *
 * The assertions that matter are not "it saved the row". They are:
 *
 *   1. AN UNBALANCED ENTRY CANNOT BE POSTED. Debits must equal credits, and the
 *      refusal names both numbers. A journal that accepts an unbalanced entry
 *      is a list of amounts, not a ledger.
 *
 *   2. THE LEDGER CANNOT BE REWRITTEN. Not "the API offers no UPDATE" — the
 *      database itself refuses UPDATE and DELETE on journal_lines, so a repair
 *      script or a psql session is refused by the same mechanism as the API.
 *      The spec's "immutable transaction history" is either this or a promise.
 *
 *   3. CORRECTION IS A REVERSAL, AND NOTHING IS ERASED. Reversing an entry must
 *      (a) net every account back to zero, (b) leave BOTH entries present, and
 *      (c) refuse to reverse the same entry twice. Deleting the original would
 *      satisfy (a) while destroying the reason the books needed correcting.
 *
 *   4. DOCUMENTS POST ATOMICALLY. An expense that exists and is not in the
 *      books is the exact state this module exists to make impossible, so the
 *      row and its entry are written in one transaction.
 *
 *   5. A DRAFT IS NOT A TRANSACTION. A draft credit note is a document; nothing
 *      reaches the journal until it is issued, and issuing twice is refused.
 *
 *   6. THE TRIAL BALANCE ADDS UP. `balanced` is computed from the totals, not
 *      asserted, and an account's normal side is stated rather than assumed.
 */
import { spawn } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const tempRoot = path.join(os.tmpdir(), `woodex-fin-${process.pid}`);
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

  const login = await api("/api/auth/login", { method: "POST", body: { email: "admin@woodex.pk", password: "admin" } });
  const token = login.json?.token;
  check(Boolean(token), "operator signed in", login.json?.error ?? "");

  console.log("\n[finance] chart of accounts");
  const accounts = await api("/api/accounts", { token });
  check((accounts.json?.total ?? 0) >= 16, "the opening chart is seeded on a fresh database", `${accounts.json?.total} account(s)`);
  const cash = accounts.json?.items?.find((a) => a.code === "1000");
  check(cash?.normalSide === "debit", "an asset account reports a debit-normal side");
  const sales = accounts.json?.items?.find((a) => a.code === "4000");
  check(sales?.normalSide === "credit", "a revenue account reports a credit-normal side");
  check(accounts.json?.items?.every((a) => ["debit", "credit"].includes(a.normalSide)),
    "every account states which side its balance sits on — a reader need not remember accounting conventions");

  const dupe = await api("/api/accounts", { method: "POST", token, body: { code: "1000", name: "Dup", kind: "asset" } });
  check(dupe.status === 409, "a duplicate account code is refused", `got ${dupe.status}`);
  const badKind = await api("/api/accounts", { method: "POST", token, body: { code: "7777", name: "Weird", kind: "nonsense" } });
  check(badKind.status === 400, "an unknown account kind is refused", `got ${badKind.status}`);

  console.log("\n[finance] the posting engine");
  const unbalanced = await api("/api/journal", { method: "POST", token, body: {
    entryDate: "2026-11-01", memo: "bad", lines: [
      { account: "1000", debit: 100 }, { account: "4000", credit: 90 },
    ] } });
  check(unbalanced.status === 422, "an unbalanced entry is refused with 422", `got ${unbalanced.status}`);
  check(/100/.test(unbalanced.json?.error ?? "") && /90/.test(unbalanced.json?.error ?? ""),
    "the refusal names both totals, not just 'invalid'", unbalanced.json?.error);

  const oneSide = await api("/api/journal", { method: "POST", token, body: {
    entryDate: "2026-11-01", lines: [{ account: "1000", debit: 50, credit: 50 }, { account: "4000", debit: 0, credit: 0 }] } });
  check(oneSide.status === 400, "a line with both debit and credit is refused", `got ${oneSide.status}`);

  const unknownAcct = await api("/api/journal", { method: "POST", token, body: {
    entryDate: "2026-11-01", lines: [{ account: "9999", debit: 10 }, { account: "4000", credit: 10 }] } });
  check(unknownAcct.status === 400, "an unknown account code is refused by name", `got ${unknownAcct.status}`);
  check(/9999/.test(unknownAcct.json?.error ?? ""), "the refusal names the account", unknownAcct.json?.error);

  const oneLine = await api("/api/journal", { method: "POST", token, body: {
    entryDate: "2026-11-01", lines: [{ account: "1000", debit: 10 }] } });
  check(oneLine.status === 400, "a one-line entry is refused — it cannot balance", `got ${oneLine.status}`);

  const good = await api("/api/journal", { method: "POST", token, body: {
    entryDate: "2026-11-01", memo: "Cash desk sale", lines: [
      { account: "1000", debit: 118000, description: "cash" },
      { account: "4000", credit: 100000 },
      { account: "2100", credit: 18000 },
    ] } });
  check(good.status === 200 || good.status === 201, "a balanced entry posts", `got ${good.status} ${JSON.stringify(good.json)?.slice(0, 160)}`);
  const entryId = good.json?.entry?.id;
  check(good.json?.entry?.balanced === true, "the stored entry reports itself balanced");
  check(good.json?.entry?.lines?.length === 3, "all three lines are stored");
  check(good.json?.entry?.totalDebit === good.json?.entry?.totalCredit, "debits equal credits on the stored entry");

  const tb1 = await api("/api/finance/trial-balance", { token });
  check(tb1.json?.balanced === true, "the trial balance balances", `diff=${tb1.json?.difference}`);
  check(tb1.json?.difference === 0, "the trial balance reports a zero difference");
  const cashRow = tb1.json?.items?.find((a) => a.code === "1000");
  check(cashRow?.balance === 118000, "cash carries the debit", `${cashRow?.balance}`);
  const revRow = tb1.json?.items?.find((a) => a.code === "4000");
  check(revRow?.balance === 100000, "revenue carries the credit", `${revRow?.balance}`);

  console.log("\n[finance] immutability is a mechanism");
  /* The API has no UPDATE for journal_lines at all, which proves nothing on its
     own — the question is whether the data CAN be changed. This opens the SQLite
     file directly, as a repair script or a person at a shell would. */
  const { DatabaseSync } = await import("node:sqlite");
  const direct = new DatabaseSync(path.join(tempRoot, "woodex.db"));
  let updateBlocked = false;
  let deleteBlocked = false;
  let dateBlocked = false;
  let memoAllowed = false;
  try { direct.prepare("UPDATE journal_lines SET debit = 1 WHERE id = 1").run(); }
  catch { updateBlocked = true; }
  try { direct.prepare("DELETE FROM journal_lines WHERE id = 1").run(); }
  catch { deleteBlocked = true; }
  try { direct.prepare("UPDATE journal_entries SET entry_date = '2020-01-01' WHERE id = ?").run(entryId); }
  catch { dateBlocked = true; }
  try { direct.prepare("UPDATE journal_entries SET memo = 'corrected prose' WHERE id = ?").run(entryId); memoAllowed = true; }
  catch { memoAllowed = false; }
  direct.close();

  check(updateBlocked, "a journal line cannot be UPDATEd even from a direct database connection");
  check(deleteBlocked, "a journal line cannot be DELETEd even from a direct database connection");
  check(dateBlocked, "an entry's date cannot be moved to another period");
  check(memoAllowed, "a memo typo CAN be corrected — prose is not a financial fact, and forbidding it would push people to delete and repost");

  console.log("\n[finance] correction is a reversal");
  const rev = await api(`/api/journal/${entryId}/reverse`, { method: "POST", token, body: {} });
  check(rev.status === 200 || rev.status === 201, "an entry can be reversed", `got ${rev.status} ${JSON.stringify(rev.json)?.slice(0, 140)}`);
  check(rev.json?.reverses === entryId, "the reversal points at the entry it reverses");
  check(rev.json?.entry?.lines?.every((l) => l.debit === 0 || l.credit === 0), "reversal lines keep one side each");

  const twice = await api(`/api/journal/${entryId}/reverse`, { method: "POST", token, body: {} });
  check(twice.status === 409, "reversing the same entry twice is refused", `got ${twice.status}`);

  const tb2 = await api("/api/finance/trial-balance", { token });
  check(tb2.json?.balanced === true, "the books still balance after a reversal");
  check(tb2.json?.items?.every((a) => a.balance === 0), "every account nets back to zero",
    `non-zero: ${tb2.json?.items?.filter((a) => a.balance !== 0).map((a) => a.code).join(", ")}`);

  const all = await api("/api/journal", { token });
  check(all.json?.total === 2, "BOTH the original and the reversal remain — nothing was erased", `${all.json?.total} entries`);
  check(all.json?.items?.some((e) => e.id === entryId), "the original entry is still readable by id");

  console.log("\n[finance] expenses post atomically");
  const exp = await api("/api/expenses", { method: "POST", token, body: {
    category: "Rent", amount: 50000, taxAmount: 0, spentAt: "2026-11-01",
    payee: "Gulberg Plaza", paidFrom: "1010", expenseAccount: "6000" } });
  check(exp.status === 200 || exp.status === 201, "an expense can be recorded", `got ${exp.status} ${JSON.stringify(exp.json)?.slice(0, 160)}`);
  check(Boolean(exp.json?.journal?.ref), "the expense posted a journal entry in the same transaction", JSON.stringify(exp.json?.journal));
  check(exp.json?.expense?.journalEntryId === exp.json?.journal?.id, "the expense row points at its entry");

  const expList = await api("/api/expenses", { token });
  check(expList.json?.total === 1, "the expense is listed");
  check(expList.json?.totalAmount === 50000, "the expense total is summed", `${expList.json?.totalAmount}`);
  check(expList.json?.byCategory?.[0]?.category === "Rent", "expenses roll up by category");

  const zeroExp = await api("/api/expenses", { method: "POST", token, body: { category: "Rent", amount: 0 } });
  check(zeroExp.status === 400, "a zero expense is refused", `got ${zeroExp.status}`);
  const noCat = await api("/api/expenses", { method: "POST", token, body: { amount: 100 } });
  check(noCat.status === 400, "an expense with no category is refused", `got ${noCat.status}`);
  const badAcct = await api("/api/expenses", { method: "POST", token, body: { category: "Rent", amount: 100, paidFrom: "8888" } });
  check(badAcct.status === 400, "an expense paid from an unknown account is refused", `got ${badAcct.status}`);

  /* The journal must reflect the expense exactly once. A route that wrote the
     row and the entry separately could leave one without the other. */
  const expJournal = await api("/api/journal?source_type=expense", { token });
  check(expJournal.json?.total === 1, "exactly one journal entry exists for the expense", `${expJournal.json?.total}`);
  check(expJournal.json?.items?.[0]?.sourceId === exp.json?.expense?.id, "the entry names its source document");

  console.log("\n[finance] credit notes");
  const invoices = await api("/api/invoices", { token });
  const invoiceId = invoices.json?.items?.[0]?.id;
  check(Boolean(invoiceId), "an invoice is available to credit");

  const beforeEntries = (await api("/api/finance/trial-balance", { token })).json?.entries;
  const cn = await api("/api/credit-notes", { method: "POST", token, body: {
    customer: "Clifton Villa", subtotal: 10000, tax: 1800, invoiceId, reason: "damaged panel" } });
  check(Boolean(cn.json?.creditNote?.id), "a credit note can be drafted", JSON.stringify(cn.json)?.slice(0, 160));
  check(cn.json?.creditNote?.status === "draft", "a new credit note is a draft");
  check(cn.json?.creditNote?.total === 11800, "the credit note computes its total", `${cn.json?.creditNote?.total}`);

  const afterDraft = (await api("/api/finance/trial-balance", { token })).json?.entries;
  check(afterDraft === beforeEntries, "a DRAFT posts nothing to the journal — it is a document, not a transaction",
    `${beforeEntries} -> ${afterDraft}`);

  const cnId = cn.json?.creditNote?.id;
  const issued = await api(`/api/credit-notes/${cnId}`, { method: "PATCH", token, body: { status: "issued" } });
  check(issued.json?.creditNote?.status === "issued", "the credit note can be issued");
  check(Boolean(issued.json?.creditNote?.journalEntryId), "issuing posts a journal entry");
  const afterIssue = (await api("/api/finance/trial-balance", { token })).json?.entries;
  check(afterIssue === beforeEntries + 1, "issuing adds exactly one entry", `${beforeEntries} -> ${afterIssue}`);

  const issueTwice = await api(`/api/credit-notes/${cnId}`, { method: "PATCH", token, body: { status: "issued" } });
  check(issueTwice.status === 409, "issuing the same credit note twice is refused", `got ${issueTwice.status}`);

  const badStatus = await api("/api/credit-notes", { method: "PATCH", token, body: { status: "issued" } })
    .catch(() => ({ status: 0 }));
  void badStatus;

  console.log("\n[finance] payment schedules");
  const sched = await api("/api/payment-schedules", { method: "POST", token, body: {
    invoiceId, instalments: [{ amount: 20000, dueDate: "2026-11-15" }, { amount: 30000, dueDate: "2026-12-15" }] } });
  check(sched.json?.created === 2, "two instalments can be scheduled", JSON.stringify(sched.json)?.slice(0, 120));

  const schedList = await api("/api/payment-schedules", { token });
  check(schedList.json?.total === 2, "the schedule is listed");
  check(schedList.json?.outstanding === 50000, "outstanding is the sum of pending instalments", `${schedList.json?.outstanding}`);
  check(schedList.json?.items?.[0]?.outstanding === 20000, "each instalment reports its own outstanding amount");
  check(typeof schedList.json?.overdue === "number", "overdue is derived from the due date, never stored");

  const badSched = await api("/api/payment-schedules", { method: "POST", token, body: { invoiceId, instalments: [{ amount: 0, dueDate: "2026-11-15" }] } });
  check(badSched.status === 400, "a zero instalment is refused", `got ${badSched.status}`);
  const noDue = await api("/api/payment-schedules", { method: "POST", token, body: { invoiceId, instalments: [{ amount: 100 }] } });
  check(noDue.status === 400, "an instalment with no due date is refused", `got ${noDue.status}`);

  console.log("\n[finance] the books still balance");
  const finalTb = await api("/api/finance/trial-balance", { token });
  check(finalTb.json?.balanced === true, "the trial balance balances after every operation in this suite",
    `dr=${finalTb.json?.totalDebit} cr=${finalTb.json?.totalCredit}`);
  check(finalTb.json?.difference === 0, "there is no residual difference");

  console.log("\n[finance] authorization");
  const anonTb = await fetch(base + "/api/finance/trial-balance");
  check(anonTb.status === 401, "the trial balance is not readable without a session", `got ${anonTb.status}`);
  const anonPost = await fetch(base + "/api/journal", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ entryDate: "2026-11-01", lines: [] }) });
  check(anonPost.status === 401, "entries cannot be posted without a session", `got ${anonPost.status}`);

  console.log("\n[finance] source guards");
  {
    const src = fs.readFileSync(path.join(ROOT, "apps/api/server.mjs"), "utf8");
    /* One posting path, as with the stock ledger. A second way to write
       journal_lines is a second way to write an unbalanced entry. */
    const helperStart = src.indexOf("const postJournal = async");
    const helperEnd = src.indexOf("const journalEntryFull");
    const linesWrites = [...src.matchAll(/INSERT\s+INTO\s+journal_lines/gi)]
      .map((m) => m.index).filter((i) => i < helperStart || i > helperEnd).length;
    check(helperStart > 0 && helperEnd > helperStart, "the posting helper is a single identifiable block");
    check(linesWrites === 0, "journal_lines is written ONLY inside postJournal", `${linesWrites} write(s) outside`);
    check(!/UPDATE\s+journal_lines/i.test(src), "the API never issues an UPDATE against journal_lines");
  }
} catch (error) {
  problems.push(`rehearsal crashed: ${error.message}`);
  console.error(`\n✗ ${error.stack ?? error.message}`);
} finally {
  child?.kill("SIGKILL");
  fs.rmSync(tempRoot, { recursive: true, force: true });
}

if (problems.length) {
  console.error(`\n✗ ${problems.length} finance problem(s):\n`);
  for (const p of problems) console.error(`  • ${p}`);
  process.exit(1);
}
console.log("\n✓ finance behaves: entries must balance, the ledger cannot be rewritten, corrections are reversals, documents post atomically.\n");

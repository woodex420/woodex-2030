#!/usr/bin/env node
/* Cross-app browser acceptance: no-basket enquiry and basket-backed quote →
   consented/logged manual WhatsApp handoff → CRM/quotation visibility, plus a
   site visit and internal staff reminder task. Needs API, dashboard and storefront
   dev servers plus Playwright Chromium. Env: DASHBOARD_BASE (or BASE),
   STOREFRONT_BASE, E2E_SQLITE_DB. */
import { existsSync } from "node:fs";
import { DatabaseSync } from "node:sqlite";

const DASHBOARD_BASE = process.env.DASHBOARD_BASE ?? process.env.BASE ?? "http://127.0.0.1:5173";
const STOREFRONT_BASE = process.env.STOREFRONT_BASE ?? "http://127.0.0.1:5174";
const browserRequired = process.env.E2E_REQUIRED === "1";
const unavailable = (message) => {
  console.log(`${browserRequired ? "ERROR" : "SKIP"}: ${message}`);
  process.exit(browserRequired ? 1 : 0);
};
let chromium;
try { ({ chromium } = await import("playwright")); }
catch { unavailable("Playwright is unavailable. Install dependencies before browser E2E."); }

let browser;
try { browser = await chromium.launch(); }
catch (error) {
  unavailable("no Chromium binary — run `npx playwright install chromium` in a network-enabled environment. (" + String(error.message).split("\n")[0] + ")");
}

const marker = `E2E-${Date.now()}`;
const leadName = `E2E QA ${marker}`;
const email = `${marker.toLowerCase()}@example.test`;
const phone = `+92 300 ${String(Date.now()).slice(-7)}`;
let context;
let leadId = null;
let clientId = null;
let basketLeadId = null;
let basketClientId = null;
let basketQuoteId = null;
const cleanupTargets = [];
let failures = [];

const requireCondition = (condition, message) => { if (!condition) throw new Error(message); };
const isoDateTimeFor = (date, timeZone) => {
  const parts = new Intl.DateTimeFormat("en-CA", {
    timeZone,
    year: "numeric", month: "2-digit", day: "2-digit",
    hour: "2-digit", minute: "2-digit", hourCycle: "h23",
  }).formatToParts(date).reduce((out, part) => {
    if (part.type !== "literal") out[part.type] = part.value;
    return out;
  }, {});
  return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
};

async function cleanup() {
  const dbPath = process.env.E2E_SQLITE_DB ?? new URL("../data/woodex.db", import.meta.url).pathname;
  if (!existsSync(dbPath)) return;
  const db = new DatabaseSync(dbPath);
  const targets = cleanupTargets.slice();
  if (leadId && !targets.some((target) => target.leadId === leadId))
    targets.push({ leadId, clientId, clientName: leadName, quoteId: null });
  try {
    db.exec("BEGIN IMMEDIATE");
    try {
      for (const target of targets) {
        if (target.quoteId != null) {
          db.prepare("DELETE FROM tasks WHERE quote_id=?").run(target.quoteId);
          db.prepare("DELETE FROM quote_versions WHERE quote_id=?").run(target.quoteId);
          db.prepare("DELETE FROM client_links WHERE entity='quote' AND entity_id=?").run(target.quoteId);
          db.prepare("DELETE FROM quotes WHERE id=?").run(target.quoteId);
        }
        const lead = target.leadId != null
          ? db.prepare("SELECT id FROM leads WHERE id=?").get(target.leadId)
          : null;
        const linked = lead
          ? db.prepare("SELECT client_id FROM client_links WHERE entity='lead' AND entity_id=?").get(lead.id)
          : null;
        const linkedClientId = target.clientId ?? linked?.client_id ?? null;
        if (lead) {
          db.prepare("DELETE FROM tasks WHERE lead_id=? OR site_visit_id IN (SELECT id FROM site_visits WHERE lead_id=?)").run(lead.id, lead.id);
          db.prepare("DELETE FROM site_visits WHERE lead_id=?").run(lead.id);
          db.prepare("DELETE FROM client_links WHERE entity='lead' AND entity_id=?").run(lead.id);
          db.prepare("DELETE FROM leads WHERE id=?").run(lead.id);
        }
        if (linkedClientId != null && target.clientName) {
          db.prepare("DELETE FROM messages WHERE conversation_id IN (SELECT id FROM conversations WHERE client_id=?)").run(linkedClientId);
          db.prepare("DELETE FROM conversations WHERE client_id=?").run(linkedClientId);
          db.prepare("DELETE FROM tasks WHERE client_id=?").run(linkedClientId);
          db.prepare("DELETE FROM client_links WHERE client_id=?").run(linkedClientId);
          db.prepare("DELETE FROM clients WHERE id=? AND name=?").run(linkedClientId, target.clientName);
        }
      }
      db.exec("COMMIT");
    } catch (error) {
      db.exec("ROLLBACK");
      throw error;
    }
  } finally {
    db.close();
  }
}

try {
  context = await browser.newContext({ timezoneId: "Asia/Karachi" });
  context.setDefaultTimeout(12_000);
  // Keep the manual handoff assertion local: the browser opens the real wa.me URL,
  // while this route prevents the test from navigating to an external service.
  await context.route("https://wa.me/**", (route) => route.fulfill({
    status: 200,
    contentType: "text/html",
    body: "<!doctype html><title>Manual WhatsApp handoff preview</title><p>Manual handoff preview only.</p>",
  }));

  const dashboard = await context.newPage();
  dashboard.on("pageerror", (error) => failures.push(`dashboard page error: ${error.message}`));
  await dashboard.goto(`${DASHBOARD_BASE}/dashboard`, { waitUntil: "domcontentloaded" });
  await dashboard.getByPlaceholder("admin").first().fill("admin");
  await dashboard.locator('input[type="password"]').fill("admin");
  await dashboard.getByRole("button", { name: /sign in/i }).click();
  await dashboard.getByRole("link", { name: "Overview", exact: true }).waitFor();
  await dashboard.goto(`${DASHBOARD_BASE}/crm`, { waitUntil: "domcontentloaded" });
  await dashboard.getByRole("heading", { name: "CRM — Lead Pipeline" }).waitFor();

  const storefront = await context.newPage();
  storefront.on("pageerror", (error) => failures.push(`storefront page error: ${error.message}`));
  await storefront.goto(`${STOREFRONT_BASE}/quotation?utm_source=e2e&utm_campaign=${encodeURIComponent(marker)}`, { waitUntil: "domcontentloaded" });
  await storefront.getByRole("heading", { name: "Get an E-Quotation" }).waitFor();
  /* The intent is "the basket section with items is not rendered". Assert it on
     the real heading, not on a substring.

     `getByText("Your Quote Basket")` is CASE-INSENSITIVE substring matching, so it
     also matches the empty-state copy "Your quote basket is empty" — the journey
     failed for weeks on a page that was behaving correctly. Match the `<h2>`
     (which only exists when items.length > 0) by role and by an anchored,
     case-sensitive pattern. */
  const basketHeading = storefront.getByRole("heading", { name: /^Your Quote Basket \(\d+ item/ });
  const basketSections = await basketHeading.count();
  requireCondition(basketSections === 0, `Expected the no-basket quotation path but found ${basketSections} basket heading(s).`);
  // Sanity check the opposite: the empty state IS the thing on screen.
  const emptyState = await storefront.getByText("Your quote basket is empty").count();
  requireCondition(emptyState === 1, `Expected the empty-basket state on /quotation, found ${emptyState}.`);

  await storefront.locator("#name").fill(leadName);
  await storefront.locator("#company").fill(`E2E Company ${marker}`);
  await storefront.locator("#email").fill(email);
  await storefront.locator("#phone").fill(phone);
  await storefront.locator("#project-type").fill("No-basket browser acceptance");
  await storefront.locator("#preferred-channel").selectOption("whatsapp");
  await storefront.locator('input[name="contactConsent"]').check();

  const leadResponsePromise = storefront.waitForResponse((response) => {
    const url = new URL(response.url());
    return url.pathname === "/api/leads" && response.request().method() === "POST";
  });
  await storefront.getByRole("button", { name: "Submit Quote Request" }).click();
  const leadResponse = await leadResponsePromise;
  requireCondition(leadResponse.status() === 201, `No-basket enquiry returned HTTP ${leadResponse.status()}.`);
  const lead = await leadResponse.json();
  leadId = Number(lead.id);
  clientId = Number(lead.clientId);
  requireCondition(Number.isSafeInteger(leadId) && leadId > 0, "No-basket enquiry did not return a lead id.");
  requireCondition(Number.isSafeInteger(clientId) && clientId > 0, "No-basket enquiry did not return a linked client id.");
  cleanupTargets.push({ leadId, clientId, clientName: leadName, quoteId: null });
  requireCondition(lead.contactConsent === true && lead.preferredChannel === "whatsapp", "Lead consent/preferred channel was not persisted.");
  await storefront.getByText(/Thank you! Our team will review your requirements/).waitFor();
  await storefront.getByText(/manual handoff/i).waitFor();

  const handoffResponsePromise = storefront.waitForResponse((response) => {
    const url = new URL(response.url());
    return url.pathname === `/api/leads/${leadId}/whatsapp-handoff` && response.request().method() === "POST";
  });
  const popupPromise = storefront.waitForEvent("popup");
  await storefront.getByRole("button", { name: "Log & open WhatsApp" }).click();
  const [popup, handoffResponse] = await Promise.all([popupPromise, handoffResponsePromise]);
  requireCondition(handoffResponse.status() === 201, `Manual handoff returned HTTP ${handoffResponse.status()}.`);
  const handoff = await handoffResponse.json();
  requireCondition(handoff.logged === true && handoff.delivery === "manual_handoff_not_sent", "Handoff response must state manual/not-sent delivery.");
  await popup.waitForURL(/^https:\/\/wa\.me\//);
  requireCondition(new URL(popup.url()).hostname === "wa.me", "Handoff did not open the manual WhatsApp destination.");
  await storefront.getByRole("link", { name: "Open WhatsApp manually" }).waitFor();

  await dashboard.goto(`${DASHBOARD_BASE}/crm`, { waitUntil: "domcontentloaded" });
  await dashboard.getByText(leadName, { exact: true }).waitFor();
  await dashboard.getByText(/Contact permission captured · whatsapp/).waitFor();
  await dashboard.getByText(/Site visits/).waitFor();

  await dashboard.getByRole("button", { name: "Book site visit", exact: true }).click();
  const dialog = dashboard.locator('[role="dialog"]').filter({ hasText: "Book a site visit" });
  await dialog.getByLabel("Lead").selectOption(String(leadId));
  const visitTimeZone = await dialog.getByLabel("Timezone").inputValue();
  const scheduledAt = isoDateTimeFor(new Date(Date.now() + 36 * 60 * 60 * 1000), visitTimeZone);
  const reminderAt = isoDateTimeFor(new Date(Date.now() + 35 * 60 * 60 * 1000), visitTimeZone);
  await dialog.getByLabel("Visit date and time").fill(scheduledAt);
  await dialog.getByLabel("Visit address or location").fill("E2E Lahore project site");
  await dialog.getByLabel("Assigned owner").fill("E2E CRM owner");
  await dialog.getByLabel("Staff reminder time").fill(reminderAt);

  const visitResponsePromise = dashboard.waitForResponse((response) => {
    const url = new URL(response.url());
    return url.pathname === "/api/site-visits" && response.request().method() === "POST";
  });
  await dialog.getByRole("button", { name: "Book site visit", exact: true }).click();
  const visitResponse = await visitResponsePromise;
  requireCondition(visitResponse.status() === 201, `Site visit booking returned HTTP ${visitResponse.status()}.`);
  const visit = await visitResponse.json();
  requireCondition(visit.leadId === leadId && visit.status === "Scheduled" && visit.reminderTaskId, "Site visit or internal reminder task was not linked to the lead.");
  await dashboard.getByText(visit.ref, { exact: true }).waitFor();
  await dashboard.getByText(/Staff task reminder:.*no automatic customer reminder/i).waitFor();

  const statusSelect = dashboard.getByRole("combobox", { name: `Visit status for ${visit.ref}` });
  const statusResponsePromise = dashboard.waitForResponse((response) => {
    const url = new URL(response.url());
    return url.pathname === `/api/site-visits/${visit.id}` && response.request().method() === "PATCH";
  });
  await statusSelect.selectOption("Confirmed");
  const statusResponse = await statusResponsePromise;
  requireCondition(statusResponse.status() === 200, `Site visit status update returned HTTP ${statusResponse.status()}.`);
  requireCondition((await statusResponse.json()).status === "Confirmed", "Confirmed site-visit status was not persisted.");

  const basketName = `E2E Basket ${marker}`;
  const basketPhone = `+92 322 ${String(Date.now()).slice(-7)}`;
  const basketPage = await context.newPage();
  basketPage.on("pageerror", (error) => failures.push(`basket page error: ${error.message}`));
  await basketPage.goto(`${STOREFRONT_BASE}/quotation?utm_source=e2e&utm_campaign=${encodeURIComponent(marker)}-basket`, { waitUntil: "domcontentloaded" });
  const product = await basketPage.evaluate(async () => {
    const response = await fetch("/api/products", { headers: { accept: "application/json" } });
    if (!response.ok) return null;
    const payload = await response.json();
    const items = Array.isArray(payload.items) ? payload.items : payload;
    const first = Array.isArray(items) ? items[0] : null;
    return first ? { id: String(first.id), name: first.name, price: Number(first.price) } : null;
  });
  requireCondition(product && product.name && Number.isFinite(product.price), "Could not load a catalog product for the basket scenario.");
  await basketPage.getByPlaceholder(/Search products by name/).fill(product.name);
  await basketPage.getByRole("button").filter({ hasText: product.name }).first().click();
  await basketPage.getByRole("button", { name: "Close quote basket" }).click();
  await basketPage.getByRole("heading", { name: /Your Quote Basket/ }).waitFor();
  /* Scope to <main>. The quote-basket drawer (QuoteBasket.tsx) is mounted at all
     times and renders its own "Estimated Total" label, so an unscoped getByText
     resolves to two elements and throws a strict-mode violation. This assertion is
     about the quotation page's summary, not the hidden drawer's. */
  const basketMain = basketPage.getByRole("main");
  await basketMain.getByText("Estimated Total", { exact: true }).waitFor();
  await basketMain.getByText("Final payment terms will be included in formal quotation.", { exact: true }).waitFor();

  await basketPage.getByRole("button", { name: "Log & open WhatsApp", exact: true }).click();
  const builderDialog = basketPage.locator('[role="dialog"]');
  await builderDialog.getByLabel("Client / Company Name *").fill(basketName);
  await builderDialog.getByLabel("Location / City *").fill("Lahore, Pakistan");
  await builderDialog.getByLabel("Contact Number *").fill(basketPhone);
  await builderDialog.getByLabel("WhatsApp Number *").fill(basketPhone);
  await builderDialog.locator('input[type="checkbox"]').check();

  const quoteResponsePromise = basketPage.waitForResponse((response) => {
    const url = new URL(response.url());
    return url.pathname === "/api/quotes" && response.request().method() === "POST";
  });
  const builderHandoffResponsePromise = basketPage.waitForResponse((response) => {
    const url = new URL(response.url());
    return url.pathname.startsWith("/api/leads/") && url.pathname.endsWith("/whatsapp-handoff") && response.request().method() === "POST";
  });
  const builderPopupPromise = basketPage.waitForEvent("popup");
  await builderDialog.getByRole("button", { name: "Continue" }).click();
  const [quoteResponse, builderHandoffResponse, builderPopup] = await Promise.all([
    quoteResponsePromise, builderHandoffResponsePromise, builderPopupPromise,
  ]);
  requireCondition(quoteResponse.status() === 201, `Basket quote request returned HTTP ${quoteResponse.status()}.`);
  const quote = await quoteResponse.json();
  basketQuoteId = Number(quote.id);
  basketLeadId = Number(quote.leadId);
  basketClientId = Number(quote.clientId);
  requireCondition(Number.isSafeInteger(basketQuoteId) && basketQuoteId > 0, "Basket request did not return a quote id.");
  requireCondition(Number.isSafeInteger(basketLeadId) && basketLeadId > 0, "Basket request did not return a linked lead id.");
  requireCondition(Number.isSafeInteger(basketClientId) && basketClientId > 0, "Basket request did not return a linked client id.");
  cleanupTargets.push({ leadId: basketLeadId, clientId: basketClientId, clientName: basketName, quoteId: basketQuoteId });
  requireCondition(quote.status === "Draft" && quote.source === "storefront", "Basket request was not persisted as a storefront draft quote.");
  requireCondition(quote.items?.length === 1 && quote.items[0].name === product.name && Math.abs(Number(quote.total) - product.price) < 0.01, "Basket line item or server total did not match the selected catalog item.");
  requireCondition(basketLeadId > 0 && builderHandoffResponse.status() === 201, "Basket quote was not linked to a lead or its handoff was not logged.");
  const builderHandoff = await builderHandoffResponse.json();
  requireCondition(builderHandoff.leadId === basketLeadId && builderHandoff.delivery === "manual_handoff_not_sent", "Basket handoff was not logged as manual/not-sent.");
  await builderPopup.waitForURL((url) => url.protocol === "https:" && url.hostname === "wa.me");
  requireCondition(new URL(builderPopup.url()).hostname === "wa.me", "Basket handoff did not open the manual WhatsApp destination.");

  await dashboard.goto(`${DASHBOARD_BASE}/crm`, { waitUntil: "domcontentloaded" });
  await dashboard.getByText(basketName, { exact: true }).waitFor();
  await dashboard.goto(`${DASHBOARD_BASE}/quotations`, { waitUntil: "domcontentloaded" });
  await dashboard.getByRole("heading", { name: "Sales & Quotations" }).waitFor();
  await dashboard.getByText(quote.ref, { exact: true }).waitFor();
  await dashboard.getByText(basketName, { exact: true }).waitFor();

  requireCondition(failures.length === 0, failures.join(" | "));
  console.log(`E2E PASS: no-basket lead#${leadId} → manual handoff → CRM/site visit; basket quote ${quote.ref} → linked lead#${basketLeadId} → manual handoff → CRM/quotations.`);
} catch (error) {
  console.error("E2E FAIL:", error instanceof Error ? error.stack?.split("\n").slice(0, 4).join(" | ") : String(error));
  process.exitCode = 1;
} finally {
  try { await context?.close(); } catch { /* best effort */ }
  try { await cleanup(); } catch (error) {
    console.error("E2E cleanup failed:", error instanceof Error ? error.message : String(error));
    process.exitCode = 1;
  }
  try { await browser.close(); } catch { /* best effort */ }
}

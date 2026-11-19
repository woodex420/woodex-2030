import { ApiV1Error, firstRefusal, isUuid, keyFor, readV1, settled, stillOpen, withRequestNumber } from "./apiV1";

type Fields = Record<string, unknown>;

/** Explicit adapter for lead-only forms. Never routes a basket quotation or order. */
export function furnitureLeadPayload(input: Fields) {
  let email = String(input.email ?? "").trim();
  let phone = String(input.phone ?? "").trim();
  const contact = String(input.contact ?? "").trim();
  // PublishedPage has one contact field. Refuse ambiguous combined text rather than guessing identity.
  if (!email && !phone && contact) {
    if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(contact)) email = contact;
    else if (/^[+\d\s()-]+$/.test(contact)) phone = contact;
    else throw new Error("Please enter a phone number or email address, not a combined contact description.");
  }
  if (!email && !phone) throw new Error("Please enter a phone number or email address.");
  return {
    name: String(input.name ?? "").trim(), email: email || null, phone: phone || null,
    company: String(input.company ?? "").trim(),
    preferred_channel: input.preferredChannel ?? "email",
    business_line: "furniture", source: "website_form", form: String(input.source ?? "Storefront").slice(0, 80),
    service: String(input.interest ?? "").length <= 60 ? input.interest ?? null : null,
    message: [input.interest, input.note].filter(Boolean).join("\n"),
    consent: input.contactConsent === true,
    page_url: input.landingPath ?? null, referrer: input.referrer ? String(input.referrer).slice(0, 500) : null,
    utm_source: input.utmSource ? String(input.utmSource).slice(0, 120) : null,
    utm_medium: input.utmMedium ? String(input.utmMedium).slice(0, 120) : null,
    utm_campaign: input.utmCampaign ? String(input.utmCampaign).slice(0, 120) : null,
    turnstile_token: input.turnstile_token ?? null,
    form_started_at: input.form_started_at ?? null,
    company_website: input.company_website ?? "",
  };
}

const JSON_HEADERS = { "Content-Type": "application/json", Accept: "application/json" };
const TOO_MANY = "Too many requests. Please wait a minute before trying again.";
const NOT_SAVED = "The enquiry could not be saved. Check your details and try again.";
const NOT_CONFIRMED = "The enquiry was not confirmed. Please refresh and try again.";

export async function captureStorefrontLead(input: Fields, mode: string = "legacy", request: typeof fetch = fetch, requireChallenge = false) {
  if (!["legacy", "v1"].includes(mode)) throw new Error("Unsupported lead API configuration.");
  if (mode === "legacy") {
    const response = await request("/api/leads", { method: "POST", headers: JSON_HEADERS, body: JSON.stringify(input) });
    if (!response.ok) throw new Error(response.status === 429 ? TOO_MANY : NOT_SAVED);
    return response.json();
  }
  if (requireChallenge && !String(input.turnstile_token ?? "").trim()) {
    throw new Error("Please complete enquiry verification before submitting.");
  }
  const payload = furnitureLeadPayload(input);
  /* One key for one enquiry: a second try of the same form carries the key of
     the first, and the admin answers what it answered then (apiV1.ts). */
  const key = isUuid(input.requestKey) ? input.requestKey : keyFor("lead", payload);
  let data: { reference?: unknown; handoff?: { token?: unknown } | null } | null;
  try {
    ({ data } = await readV1<typeof data>(await request("/api/v1/leads", {
      method: "POST", headers: { ...JSON_HEADERS, "Idempotency-Key": key }, body: JSON.stringify(payload),
    })));
  } catch (error) {
    if (!stillOpen(error)) settled("lead", payload);
    if (!(error instanceof ApiV1Error)) throw error;
    const refusal = firstRefusal(error);
    throw new Error(withRequestNumber(
      error.status === 429 ? TOO_MANY
        : error.status < 400 ? NOT_CONFIRMED
        : error.code === "CONFLICT" ? "The enquiry could not be saved just now. Please send it again."
        : refusal ? `The enquiry could not be saved. ${refusal}`
        : NOT_SAVED,
      error));
  }
  settled("lead", payload);
  if (!data || typeof data.reference !== "string" || !data.reference.trim()) throw new Error(NOT_CONFIRMED);
  // Never invent a Node numeric lead ID, or invoke its handoff endpoint for a Laravel record.
  const token = data.handoff?.token;
  const handoffToken = typeof token === "string" && /^[a-f0-9]{64}$/.test(token) ? token : undefined;
  return { ok: true, ref: data.reference, reference: data.reference, handoffToken, handoffUnavailable: !handoffToken };
}

/** POST body only: never put bearer capabilities into paths, query strings or persistent storage. */
export async function redeemLeadHandoff(token: string, request: typeof fetch = fetch) {
  if (!/^[a-f0-9]{64}$/.test(token)) throw new Error("Invalid handoff. Please submit a new enquiry.");
  let data: { delivery?: unknown; whatsapp_url?: unknown } | null;
  try {
    // The token is the key of this request: sent twice, it is written down once and answered the same.
    ({ data } = await readV1<typeof data>(await request("/api/v1/leads/whatsapp-handoff", {
      method: "POST", headers: JSON_HEADERS, body: JSON.stringify({ token }),
    })));
  } catch (error) {
    if (!(error instanceof ApiV1Error)) throw error;
    throw new Error(withRequestNumber(
      error.status === 404 ? "This handoff has expired or is unavailable. Your enquiry remains saved; our team will follow up."
        : error.status < 400 ? "The handoff was not confirmed. Please retry."
        : "The handoff could not be logged. Please retry shortly.",
      error));
  }
  if (!data || data.delivery !== "manual_handoff_not_sent"
    || typeof data.whatsapp_url !== "string" || !/^https:\/\/wa\.me\/\d+\?text=/.test(data.whatsapp_url)) {
    throw new Error("The handoff was not confirmed. Please retry.");
  }
  return data.whatsapp_url;
}

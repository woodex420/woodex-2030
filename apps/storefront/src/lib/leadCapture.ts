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

export async function captureStorefrontLead(input: Fields, mode: string = "legacy", request: typeof fetch = fetch, requireChallenge = false) {
  if (!["legacy", "v1"].includes(mode)) throw new Error("Unsupported lead API configuration.");
  const versioned = mode === "v1";
  if (versioned && requireChallenge && !String(input.turnstile_token ?? "").trim()) {
    throw new Error("Please complete enquiry verification before submitting.");
  }
  const response = await request(versioned ? "/api/v1/leads" : "/api/leads", {
    method: "POST", headers: { "Content-Type": "application/json", Accept: "application/json" },
    body: JSON.stringify(versioned ? furnitureLeadPayload(input) : input),
  });
  if (!response.ok) throw new Error(response.status === 429
    ? "Too many requests. Please wait a minute before trying again."
    : "The enquiry could not be saved. Check your details and try again.");
  const reply = await response.json();
  if (!versioned) return reply;
  if (reply.ok !== true || typeof reply.reference !== "string" || !reply.reference.trim()) {
    throw new Error("The enquiry was not confirmed. Please refresh and try again.");
  }
  // Never invent a Node numeric lead ID, or invoke its handoff endpoint for a Laravel record.
  const handoffToken = typeof reply.handoff?.token === "string" && /^[a-f0-9]{64}$/.test(reply.handoff.token)
    ? reply.handoff.token : undefined;
  return { ok: true, ref: reply.reference, reference: reply.reference, handoffToken, handoffUnavailable: !handoffToken };
}

/** POST body only: never put bearer capabilities into paths, query strings or persistent storage. */
export async function redeemLeadHandoff(token: string, request: typeof fetch = fetch) {
  if (!/^[a-f0-9]{64}$/.test(token)) throw new Error("Invalid handoff. Please submit a new enquiry.");
  const response = await request("/api/v1/leads/whatsapp-handoff", {
    method: "POST", headers: { "Content-Type": "application/json", Accept: "application/json" },
    body: JSON.stringify({ token }),
  });
  if (!response.ok) throw new Error(response.status === 404
    ? "This handoff has expired or is unavailable. Your enquiry remains saved; our team will follow up."
    : "The handoff could not be logged. Please retry shortly.");
  const reply = await response.json();
  if (reply.logged !== true || reply.delivery !== "manual_handoff_not_sent"
    || typeof reply.whatsapp_url !== "string" || !/^https:\/\/wa\.me\/\d+\?text=/.test(reply.whatsapp_url)) {
    throw new Error("The handoff was not confirmed. Please retry.");
  }
  return reply.whatsapp_url as string;
}

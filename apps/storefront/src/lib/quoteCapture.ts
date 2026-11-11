import { furnitureLeadPayload } from "./leadCapture";

/** Separate opt-in. Node IDs are external keys, never Laravel catalog primary keys. */
export async function captureBasketRequest(input: Record<string, unknown>, mode = "legacy", request: typeof fetch = fetch, requireChallenge = false) {
  if (mode === "legacy") {
    const response = await request("/api/quotes", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(input) });
    if (!response.ok) throw new Error("The basket request could not be saved. Please try again.");
    return response.json();
  }
  if (mode !== "v1") throw new Error("Unsupported basket API configuration.");
  if (requireChallenge && !String(input.turnstile_token ?? "").trim()) throw new Error("Please complete enquiry verification before submitting.");
  const items = input.items as { productId: unknown; qty: unknown; material?: unknown }[];
  if (!Array.isArray(items) || !items.length) throw new Error("Please add products to your basket.");
  // Existing free-text colours are not authoritative variants; refuse rather than discard a choice.
  if (items.some(item => item.material)) throw new Error("Colour/material selections need team review. Please contact the team with your basket before submitting.");
  const body = { ...furnitureLeadPayload({ ...input, name: input.customer }), request_key: input.requestKey,
    items: items.map(item => ({ product_id: String(item.productId), qty: item.qty })) };
  const response = await request("/api/v1/quote-requests", { method: "POST", headers: { "Content-Type": "application/json", Accept: "application/json" }, body: JSON.stringify(body) });
  if (!response.ok) throw new Error(response.status === 429 ? "Too many requests. Please wait a minute before retrying."
    : response.status === 409 ? "Your basket details changed. Please reload to start a new request."
    : "The basket could not be confirmed. A product may be unavailable or unmapped. Check your details or contact the team.");
  const reply = await response.json();
  if (reply.ok !== true || typeof reply.reference !== "string" || !reply.reference || reply.status !== "pending_review") {
    throw new Error("The basket request was not confirmed. Please try again.");
  }
  return { ok: true, ref: reply.reference, handoffUnavailable: true };
}

import { ApiV1Error, isUuid, keyFor, readV1, settled, stillOpen, withRequestNumber } from "./apiV1";
import { furnitureLeadPayload } from "./leadCapture";

const NOT_CONFIRMED = "The basket request was not confirmed. Please try again.";

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
  const body = { ...furnitureLeadPayload({ ...input, name: input.customer }),
    items: items.map(item => ({ product_id: String(item.productId), qty: item.qty })) };
  /* The key of the request travels in the Idempotency-Key header, as for every
     create under /api/v1. The page makes one when it opens (requestKey), so that
     a basket that changed after it was sent is told so and not saved a second
     time under the first one's name; a page that gives none gets one here. */
  const key = isUuid(input.requestKey) ? input.requestKey : keyFor("basket", body);
  let data: { reference?: unknown; status?: unknown } | null;
  try {
    ({ data } = await readV1<typeof data>(await request("/api/v1/quote-requests", {
      method: "POST", headers: { "Content-Type": "application/json", Accept: "application/json", "Idempotency-Key": key }, body: JSON.stringify(body),
    })));
  } catch (error) {
    if (!stillOpen(error)) settled("basket", body);
    if (!(error instanceof ApiV1Error)) throw error;
    throw new Error(withRequestNumber(
      error.status === 429 ? "Too many requests. Please wait a minute before retrying."
        : error.status < 400 ? NOT_CONFIRMED
        : error.code === "IDEMPOTENCY_KEY_REUSED" ? "Your basket details changed. Please reload to start a new request."
        : error.code === "CONFLICT" ? "The basket could not be saved just now. Please send it again."
        : "The basket could not be confirmed. A product may be unavailable or unmapped. Check your details or contact the team.",
      error));
  }
  settled("basket", body);
  if (!data || typeof data.reference !== "string" || !data.reference || data.status !== "pending_review") throw new Error(NOT_CONFIRMED);
  return { ok: true, ref: data.reference, handoffUnavailable: true };
}

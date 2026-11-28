import { ApiV1Error, firstRefusal, isUuid, keyFor, readV1, settled, stillOpen, withRequestNumber } from "./apiV1";
import { furnitureLeadPayload } from "./leadCapture";

const NOT_CONFIRMED = "The basket request was not confirmed. Please try again.";

/**
 * A basket, sent where `mode` says (lib/runtime.ts, `basketDestination`).
 *
 *   "legacy"  this repository's API, unchanged.
 *   "v1"      the Laravel API, each line by the shop's own key (`product_id`). A separate opt-in: Node IDs are
 *             external keys, never Laravel catalog primary keys.
 *   "admin"   the Laravel API, for a catalogue that was read from the admin (master list T-191): each line names
 *             the product by its address (`slug`), which is this shop's id of such a product, and the option the
 *             visitor chose by its code (`sku`). Nothing is mapped, and nothing the page shows of a name or a
 *             price is sent: the admin reads its own published copy.
 */
export async function captureBasketRequest(input: Record<string, unknown>, mode = "legacy", request: typeof fetch = fetch, requireChallenge = false) {
  if (mode === "legacy") {
    const response = await request("/api/quotes", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(input) });
    if (!response.ok) throw new Error("The basket request could not be saved. Please try again.");
    return response.json();
  }
  if (mode !== "v1" && mode !== "admin") throw new Error("Unsupported basket API configuration.");
  if (requireChallenge && !String(input.turnstile_token ?? "").trim()) throw new Error("Please complete enquiry verification before submitting.");
  const items = input.items as { productId: unknown; qty: unknown; material?: unknown; variantId?: unknown; sku?: unknown }[];
  if (!Array.isArray(items) || !items.length) throw new Error("Please add products to your basket.");
  // Existing free-text colours are not authoritative variants; refuse rather than discard a choice.
  if (items.some(item => item.material)) throw new Error("Colour/material selections need team review. Please contact the team with your basket before submitting.");
  const lines = mode === "admin"
    // The code is sent for a line that carries an option, and only then: a product sold in one form is named by
    // its address alone, so that a change of its own code in the admin does not turn a good basket away.
    ? items.map(item => ({ slug: String(item.productId), ...(item.variantId != null && typeof item.sku === "string" && item.sku ? { sku: item.sku } : {}), qty: item.qty }))
    : items.map(item => ({ product_id: String(item.productId), qty: item.qty }));
  const body = { ...furnitureLeadPayload({ ...input, name: input.customer }), items: lines };
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
    // What the admin said about the first thing it refused, in its own sentence: which product, or which field.
    const refusal = firstRefusal(error);
    throw new Error(withRequestNumber(
      error.status === 429 ? "Too many requests. Please wait a minute before retrying."
        : error.status < 400 ? NOT_CONFIRMED
        : error.code === "IDEMPOTENCY_KEY_REUSED" ? "Your basket details changed. Please reload to start a new request."
        : error.code === "CONFLICT" ? "The basket could not be saved just now. Please send it again."
        : error.status >= 500 ? "The basket could not be saved just now. Please send it again in a moment."
        : refusal ? `The basket could not be saved. ${refusal}`
        : "The basket could not be confirmed. A product may be unavailable or unmapped. Check your details or contact the team.",
      error));
  }
  settled("basket", body);
  if (!data || typeof data.reference !== "string" || !data.reference || data.status !== "pending_review") throw new Error(NOT_CONFIRMED);
  return { ok: true, ref: data.reference, handoffUnavailable: true };
}

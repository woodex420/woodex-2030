/* The one place that reads an answer of /api/v1, the API of the Laravel admin
   (WOODEX OS) for the shop. Its rules are docs/woodex-os/29-API-V1-RULES.md and
   its contract is platform/resources/api/v1.openapi.json.

   Every answer is one of two shapes:
     a success   {"data": …, "meta": {"request_id": "4F7K-9Q2M-T8RB"}}
     an error    {"error": {"code": "NOT_FOUND", "message": "…", "details": {…}}, "meta": {…}}

   A caller decides by the status and by error.code, which comes from a closed
   list, never by the sentence. meta.request_id is the number that finds the
   request in the admin's log: it is shown to the visitor with an error, so
   that somebody who asks for help can name the request. */

export class ApiV1Error extends Error {
  constructor(
    readonly status: number,
    /** One of the API's codes; "" when the answer was not in the API's shape. */
    readonly code: string,
    message: string,
    /** By name: the messages of each refused field. */
    readonly details: Record<string, string[]>,
    /** The number of the request, when the answer carried one. */
    readonly requestId: string | null,
  ) {
    super(message);
    this.name = "ApiV1Error";
  }
}

/* Twelve characters in three groups, without I, L, O and U. */
const REQUEST_NUMBER = /^[0-9A-HJKMNP-TV-Z]{4}(?:-[0-9A-HJKMNP-TV-Z]{4}){2}$/;

function requestNumber(body: unknown, response: Response): string | null {
  const inBody = (body as { meta?: { request_id?: unknown } } | null)?.meta?.request_id;
  const inHeader = response.headers?.get?.("x-request-id");
  for (const value of [inBody, inHeader]) {
    if (typeof value === "string" && REQUEST_NUMBER.test(value)) return value;
  }
  return null;
}

function refusedFields(details: unknown): Record<string, string[]> {
  const fields: Record<string, string[]> = {};
  if (!details || typeof details !== "object" || Array.isArray(details)) return fields;
  for (const [name, messages] of Object.entries(details as Record<string, unknown>)) {
    if (Array.isArray(messages)) fields[name] = messages.filter((m): m is string => typeof m === "string");
  }
  return fields;
}

/** `data` of a success. Everything else is an ApiV1Error: an error of the API, and an answer that is not in its shape. */
export async function readV1<T = unknown>(response: Response): Promise<{ data: T; requestId: string | null; replayed: boolean }> {
  let body: unknown = null;
  try { body = await response.json(); } catch { /* not JSON: an error below */ }
  const requestId = requestNumber(body, response);
  const record = body && typeof body === "object" ? (body as Record<string, unknown>) : null;
  if (response.ok && record && "data" in record && record.meta && typeof record.meta === "object") {
    return { data: record.data as T, requestId, replayed: response.headers?.get?.("idempotent-replayed") === "true" };
  }
  const error = record?.error as { code?: unknown; message?: unknown; details?: unknown } | undefined;
  throw new ApiV1Error(
    response.status,
    typeof error?.code === "string" ? error.code : "",
    typeof error?.message === "string" ? error.message : "",
    refusedFields(error?.details),
    requestId,
  );
}

/** What the API said about the first field it refused, or "". */
export function firstRefusal(error: unknown): string {
  if (!(error instanceof ApiV1Error) || error.code !== "VALIDATION_FAILED") return "";
  for (const messages of Object.values(error.details)) if (messages[0]) return messages[0];
  return "";
}

/** A sentence for the visitor, with the number of the request behind it when there is one. */
export function withRequestNumber(sentence: string, error: unknown): string {
  return error instanceof ApiV1Error && error.requestId
    ? `${sentence} If you ask us about it, give this number: ${error.requestId}.`
    : sentence;
}

/* The key of a create (the header Idempotency-Key). One key names one request:
   the API writes a request once however often it is sent with its key, and
   answers the same each time. So a second try of the same form must carry the
   key of the first, and a new form a new key.

   keyFor() gives the same key for the same content until settled() says the
   request is over: it was answered, or it was refused for good. A try that got
   no answer (the network), a fault of the server, "too many requests" or "another
   writer, send again" leaves the key in place, and the next try is the same
   request. */
const waiting = new Map<string, string>();
const NOT_PART_OF_A_REQUEST = new Set(["turnstile_token", "form_started_at"]);

function contentOf(kind: string, payload: Record<string, unknown>): string {
  const fields = Object.keys(payload).filter(name => !NOT_PART_OF_A_REQUEST.has(name)).sort();
  return kind + "\n" + JSON.stringify(fields.map(name => [name, payload[name]]));
}

export function keyFor(kind: string, payload: Record<string, unknown>): string {
  const content = contentOf(kind, payload);
  let key = waiting.get(content);
  if (!key) {
    key = crypto.randomUUID();
    waiting.set(content, key);
  }
  return key;
}

export function settled(kind: string, payload: Record<string, unknown>): void {
  waiting.delete(contentOf(kind, payload));
}

/** Is the request still open after this error, so that the next try must carry the same key? */
export function stillOpen(error: unknown): boolean {
  if (!(error instanceof ApiV1Error)) return true; // no answer at all
  return error.status >= 500 || error.status === 429 || error.code === "CONFLICT";
}

export const isUuid = (value: unknown): value is string =>
  typeof value === "string" && /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);

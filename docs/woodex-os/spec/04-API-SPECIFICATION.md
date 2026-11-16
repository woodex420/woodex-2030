# 04 — API Specification

## 1. Status

This is the target API contract standard. Existing Express routes and Laravel routes must be inventoried and mapped before endpoints are implemented or retired. Examples below are proposed contracts, not claims that those endpoints currently exist.

## 2. API conventions

- Base path: `/api/v1`.
- JSON request and response bodies.
- UTF-8.
- ISO-8601 timestamps in UTC.
- Money values returned as decimal strings or integer minor units consistently; choose one convention before implementation.
- Pagination for collection endpoints.
- Explicit filter/sort allowlists.
- Stable resource identifiers.
- Server-side validation and authorization.
- Idempotency keys for retryable create/financial actions.
- Request correlation ID in responses and logs.
- No secrets in response payloads.

## 3. Response envelope

Success:
```json
{
  "data": {
    "id": "01J...",
    "type": "quotation"
  },
  "meta": {
    "request_id": "req_..."
  }
}
```

Collection:
```json
{
  "data": [],
  "meta": {
    "page": 1,
    "per_page": 25,
    "total": 0,
    "request_id": "req_..."
  },
  "links": {
    "self": "/api/v1/quotations?page=1"
  }
}
```

Error:
```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The submitted data is invalid.",
    "details": {
      "reference": ["The reference field is required."]
    }
  },
  "meta": {
    "request_id": "req_..."
  }
}
```

Do not expose stack traces, SQL errors, filesystem paths or secrets.

## 4. HTTP behavior

| Status | Meaning |
|---|---|
| 200 | Successful read/update/action |
| 201 | Resource created |
| 202 | Accepted for asynchronous processing |
| 204 | Successful operation with no body |
| 400 | Invalid request structure |
| 401 | Not authenticated |
| 403 | Authenticated but not authorized |
| 404 | Resource unavailable or not found |
| 409 | State conflict / duplicate / version conflict |
| 422 | Validation failure |
| 429 | Rate limited |
| 500 | Unexpected server error |
| 503 | Temporarily unavailable |

## 5. Resource groups

### Identity
- `GET /me`
- `GET /me/permissions`
- `GET /users`
- `POST /users`
- `PATCH /users/{id}`
- `GET /roles`
- `POST /roles`
- `PUT /roles/{id}/permissions`

### CRM
- `GET|POST /leads`
- `GET|PATCH /leads/{id}`
- `POST /leads/{id}/assign`
- `POST /leads/{id}/convert`
- `GET|POST /contacts`
- `GET|POST /activities`
- `GET|POST /tasks`
- `GET|POST /visits`

### Sales
- `GET|POST /estimates`
- `GET|POST /quotations`
- `GET /quotations/{id}`
- `POST /quotations/{id}/submit-approval`
- `POST /quotations/{id}/approve`
- `POST /quotations/{id}/reject`
- `POST /quotations/{id}/revise`
- `POST /quotations/{id}/convert`
- `GET|POST /invoices`
- `POST /invoices/{id}/issue`
- `GET|POST /payment-records`
- `POST /payment-records/{id}/allocations`

### Commerce
- `GET|POST /products`
- `GET|PATCH /products/{id}`
- `GET /categories`
- `GET /series`
- `GET|POST /orders`
- `GET /orders/{id}`
- `POST /orders/{id}/transition`
- `GET|POST /delivery-jobs`
- `GET|POST /warranty-cases`

### Operations / procurement / inventory
- `GET|POST /suppliers`
- `GET|POST /purchase-orders`
- `POST /purchase-orders/{id}/submit`
- `POST /purchase-orders/{id}/approve`
- `GET|POST /goods-receipts`
- `GET /warehouses`
- `GET /stock-ledger`
- `POST /stock-transfers`
- `POST /stock-adjustments`
- `GET|POST /production-jobs`
- `POST /production-jobs/{id}/stage-events`

### Projects and finance
- `GET|POST /projects`
- `GET|POST /projects/{id}/tasks`
- `GET|POST /projects/{id}/milestones`
- `GET|POST /projects/{id}/costs`
- `GET /accounts/ledger`
- `GET|POST /expenses`
- `GET /payables`
- `GET /reports/{report_key}`

### Website Studio
- `GET|POST /website/pages`
- `GET|PATCH /website/pages/{id}`
- `GET|POST /website/pages/{id}/revisions`
- `POST /website/pages/{id}/preview-token`
- `POST /website/pages/{id}/submit-approval`
- `POST /website/pages/{id}/publish`
- `POST /website/pages/{id}/rollback`
- `GET|POST /website/templates`
- `GET|POST /website/sections`
- `GET|PUT /website/navigation/{menu_key}`
- `GET|PUT /website/theme`
- `GET|POST /media`
- `GET|POST /website/redirects`

## 6. State transitions

Do not permit arbitrary status patching. Use explicit transition actions with policy checks and validation.

Example quotation lifecycle:
`draft -> pending_approval -> approved -> sent -> accepted`
Alternative terminal/exception states: `rejected`, `expired`, `cancelled`, `superseded`.

Example order lifecycle (final states to be confirmed):
`draft -> confirmed -> processing -> in_production -> ready -> dispatched -> delivered -> completed`
Exception states: `on_hold`, `cancelled`, `returned`.

The backend must reject invalid transitions and record actor, time, previous state, new state and reason.

## 7. Example quotation creation request

```json
{
  "contact_id": "contact_123",
  "currency": "PKR",
  "valid_until": "2026-12-15",
  "items": [
    {
      "description": "Configured furniture item",
      "product_id": "product_123",
      "quantity": "1.000",
      "unit": "each",
      "unit_price": "125000.00",
      "discount_amount": "0.00",
      "tax_rule_id": "tax_standard"
    }
  ],
  "notes": "Customer-facing note"
}
```

The server must resolve product, pricing and tax policy, validate the user’s permissions and calculate totals. Do not trust client-supplied totals.

## 8. Puck page save/publish contract

A draft save request should include:
- `page_id`.
- `base_revision_id` or version token.
- `schema_version`.
- `puck_data`.
- `change_summary`.

The server must:
1. Authorize editor access.
2. Validate component types and props against the allowlisted schema.
3. Validate referenced media and data bindings.
4. Check revision conflict.
5. Save a new immutable revision.
6. Return the revision ID and validation result.

Publish is a separate action. It requires publish permission, required approval, successful validation and a publication audit event.

## 9. API client requirements

- Central typed API client.
- One error normalization layer.
- Request cancellation for stale search/list requests.
- Retry only safe/idempotent operations.
- Refresh or reauthenticate on session expiry.
- Do not silently retry financial writes.
- Surface field-level validation errors.
- Log correlation IDs for support.

## 10. Open API decisions

- Cookie/session versus token topology.
- Exact route naming compatibility with current endpoints.
- Money serialization format.
- Public checkout/payment gateway scope.
- API rate limits and public form throttling.
- Webhook authentication and idempotency policy.

# Forms `[current]`

## Stack
- `react-hook-form` for state + validation lifecycle.
- `zod` for schema, resolved via `@hookform/resolvers/zod`.
- shadcn `Form` primitive for accessible field markup.

## Form Inventory
| Form | Fields | Submit action |
|---|---|---|
| Contact | name, email, phone, message | `[current]` toast; `[planned]` POST to `/api/leads` |
| Quote form (Quotation page) | company, contact, project type, size, budget, timeline, message | `[current]` builds mailto + WhatsApp; `[planned]` insert into `quotes` |
| Quote basket submit | derived from cart + company block | `[current]` PDF gen client-side + WhatsApp share |
| Cart checkout | name, phone, address, city, payment method | `[current]` WhatsApp confirmation; `[planned]` order row |
| Newsletter (footer) | email | `[planned]` |

## Validation Rules
- Email: `z.string().email()`.
- Phone (PK): regex `^\+?92[0-9]{10}$` or `^0[0-9]{10}$`.
- Required strings: `.min(2)`.
- Message: `.min(10).max(1000)`.

## UX
- Inline errors under fields.
- Disable submit while `isSubmitting`.
- Success = toast + optional redirect.
- Never lose typed data on validation failure.

## Accessibility
- Every input has `<label>` associated by `htmlFor`.
- Errors linked via `aria-describedby`.
- Required marked `aria-required="true"`.

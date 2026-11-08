# Quotes `[planned]`

## Purpose
Manage the B2B quote lifecycle from submission → issued → accepted → converted to order.

## Quote Record
Fields: quote_no (auto WDX-YYYY-####), lead_id, items[], subtotal, tax, total, advance_amount (75%), balance, valid_until, status, pdf_url, sent_at, accepted_at, notes.

## Statuses
`draft → sent → viewed → accepted → declined | expired`.

## Actions
- View / edit line items.
- Regenerate PDF (server-side via edge function).
- Resend via WhatsApp / email.
- Duplicate quote.
- Convert to order.

## PDF Layout
- Header: WOODEX logo, company address.
- Client block + quote no + date + validity.
- Item table (image, description, qty, unit price, total).
- Totals block with 75% advance callout.
- Terms & conditions footer.

## Metrics
- Quote-to-order conversion rate.
- Avg quote value.
- Avg time from sent → accepted.

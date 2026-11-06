# Orders `[planned]`

## Purpose
Manage B2C retail orders end-to-end.

## Order Record
order_no, customer (name, phone, email, address, city), items[], subtotal, shipping, total, payment_method (COD | Bank Transfer), payment_status, fulfilment_status, tracking, notes, created_at.

## Statuses
- **Payment:** pending, paid, refunded.
- **Fulfilment:** pending → confirmed → in_production → dispatched → delivered → completed.

## Actions
- Confirm order (auto WhatsApp to customer).
- Mark payment received (bank transfer flow).
- Assign dispatch + tracking number.
- Cancel / refund with reason.

## Views
- List with quick filters (status, city, date, value).
- Detail drawer with timeline + WhatsApp thread quick link.

## Metrics
- Daily order volume.
- AOV.
- COD vs bank transfer split.
- Cancellation rate.
- Delivery SLA adherence.

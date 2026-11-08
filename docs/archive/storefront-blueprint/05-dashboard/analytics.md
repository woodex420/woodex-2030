# Analytics `[planned]`

## Purpose
Single-pane view of business + web KPIs mapped to `docs/01-discovery/website-goals.md`.

## KPI Cards (top row)
- Qualified B2B leads (MTD vs target 50)
- B2C conversion rate (MTD vs 3%)
- WhatsApp CTR on PDPs (MTD vs 30%)
- AOV / Avg quote value
- Organic sessions (MTD vs prev)

## Charts
- Leads by source (stacked bar, 30d).
- Quote pipeline value (funnel).
- Order volume + revenue (line, 90d).
- Top pages by conversion.
- SEO rank tracker (Semrush integration `[planned]`).

## Data Sources
- Supabase tables: leads, quotes, orders.
- GA4 via BigQuery export or Data API.
- Semrush API for keyword ranks.

## Alerts
- Leads < 10 in last 7 days.
- Orders drop > 30% WoW.
- Any keyword drops out of top 10.

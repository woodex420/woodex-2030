# CRM `[planned]`

## Purpose
Track every inbound lead from web forms + WhatsApp handoffs through to won/lost.

## Pipeline Stages
```text
New → Contacted → Qualified → Quoted → Negotiation → Won | Lost
```

## Lead Record
Fields: name, email, phone, company, source (page URL + UTM), message, assigned_to, stage, notes[], created_at, last_activity_at.

## Views
- **Board:** Kanban by stage, drag-to-move.
- **List:** filterable table (source, stage, owner, date range).
- **Detail drawer:** lead info + activity timeline + quick actions (WhatsApp, email, add note, create quote).

## Automations
- New lead → Slack / WhatsApp notification to on-duty sales.
- No activity 3 days → auto-flag "at risk".
- Lost → require reason (price, timing, competitor, no-response).

## Metrics
- Lead volume by source.
- Stage conversion rates.
- Time-to-first-response.
- Win rate by sector.

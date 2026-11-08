# API `[planned]`

Frontend today is fully client-side. Phase 2 introduces Lovable Cloud (Supabase) edge functions.

## Auth
- Anon key for public reads.
- `has_role(auth.uid(), 'admin')` for admin writes.
- No B2C customer accounts in v1.

## REST Contract (via Supabase auto-generated + edge functions)

### Public Reads (PostgREST)
```text
GET /rest/v1/products?is_published=eq.true
GET /rest/v1/series?is_published=eq.true
GET /rest/v1/projects?is_published=eq.true
GET /rest/v1/blog_posts?is_published=eq.true&order=published_at.desc
```

### Public Writes (Edge Functions — no auth required)
| Function | Purpose |
|---|---|
| `POST /functions/v1/submit-lead` | Insert into `leads`, notify sales via WhatsApp + email |
| `POST /functions/v1/submit-quote` | Build quote row, generate PDF, return signed URL |
| `POST /functions/v1/submit-order` | Insert B2C order, send WhatsApp confirmation |
| `POST /functions/v1/newsletter-subscribe` | Add email to list |

### Integrations
| Service | Purpose |
|---|---|
| WhatsApp Cloud API | Outbound quote / order messages |
| SendGrid / Resend | Transactional email |
| PDF generation | `@react-pdf/renderer` inside edge function |

## Error Handling
- Standard `{ error: { code, message } }` envelope.
- Client shows toast + logs to console; retries idempotent GETs only.

## Rate Limits `[planned]`
- Lead / quote submit: 5 req / min per IP (edge middleware).

# Deployment

## Environment
- Preview: `https://id-preview--<uuid>.lovable.app`
- Production: `https://woodex-reimagined.lovable.app` (or connected custom domain).

## Publish Flow
1. Merge changes via Lovable editor.
2. Verify preview URL renders and passes smoke QA.
3. Click **Publish** in Lovable.
4. Verify production URL.
5. Warm-crawl top 10 pages.

## Custom Domain `[planned]`
- Connect `woodex.com.pk` (or brand-owned domain) in Lovable settings.
- Update DNS: A / CNAME as instructed.
- Wait for SSL provisioning.
- Update canonical + OG URLs.

## Secrets & Env
Managed via Lovable Cloud secrets (never committed):
- `WHATSAPP_TOKEN`
- `WHATSAPP_PHONE_ID`
- `RESEND_API_KEY` (or SendGrid)
- `SUPABASE_SERVICE_ROLE_KEY` (edge functions only)

Publishable Supabase anon key is safe in client code.

## Post-Deployment
- [ ] Sitemap accessible at `/sitemap.xml`
- [ ] robots.txt accessible at `/robots.txt`
- [ ] GA4 live data confirmed within 30 min
- [ ] GSC re-verify on production domain
- [ ] Test WhatsApp CTA on real device
- [ ] Test quote PDF download on real device
- [ ] Monitor Sentry / console errors for 24h `[planned]`

## Rollback
- Lovable maintains version history; revert via chat "revert to previous version".
- Data (once Cloud added) restored from Supabase point-in-time backup.

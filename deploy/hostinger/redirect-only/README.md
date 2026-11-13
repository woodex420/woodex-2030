# Temporary staging homepage redirect

This is an optional manual-upload artifact, NOT a completed deployment. The user requested Vercel frontend plus Hostinger administration; actual split-host API/session/ownership design and Hostinger execution access remain unresolved. Do not treat a redirect as that integration.

The accompanying ZIP contains only `.htaccess`, not this README or repository source. Do not redeploy the repository root as the site build.

## Intended behaviour

- Staging `/`: temporary302 to `https://woodex-2030.vercel.app/`, discarding the query string.
- `/admin`, `/dashboard`, `/api` and descendants:503, backend not enabled.
- Every other staging path:404, rather than serving copied repository files.
- Directory listings disabled. No proxy, database access, credential or application runtime is included.

The Vercel destination continues using its existing backend/configuration. No Vercel project, alias, environment variable or deployment was changed. Its READY state is connector metadata, not a fresh public browser/API acceptance test.

## Before manual installation

1. Confirm the selected File Manager root is exactly the document root of staging.blackibex.net. An account-wide file list is not sufficient evidence.
2. Download/back up any existing staging `.htaccess` and take a hosting backup. Do not delete or edit repository/database/media files.
3. Upload/extract only the ZIP's `.htaccess` into that confirmed staging root. File Manager may need Show hidden files enabled. Do not install this in blackibex.net's production folder.
4. Check staging `/` redirects to the exact Vercel origin, `/admin` shows backend unavailable, and `/README.md` plus `/.git/HEAD` do not disclose files. Do not open or share `.env` contents for testing. If configuration errors occur, restore the backed-up file and ask for review.
5. Prevent an automatic GitHub redeployment from silently replacing this temporary file; it is not a durable release mechanism.

The directives have been inspected locally for the intended restrictive rules; Apache/LiteSpeed syntax and behaviour have NOT been executed against Hostinger. The configuration assumes standard mod_rewrite/QSD and Options support. A proper server configuration with source outside the public document root is still required; this temporary rule file is not a full security assessment. No actual hosted upload or verification has occurred.

## Real admin/backend deployment is still gated

Need verified staging document root, PHP/extensions/dependency compatibility, secure execution or an approved artifact workflow, separate database inspection/backup, disposable MySQL gates, production-grade auth/HTTPS/cookie/session routing and a bounded admin scope. The DEV-only React staff workspace/local staff API must not simply be enabled on a hosted environment. The intended full React-dashboard/Laravel API product remains incomplete. Existing native Laravel screens are not proof that it is the accepted target admin.

Do not use shared-in-chat passwords. Rotate the previously exposed password and provision secrets through an approved secure mechanism, never chat or repository files.

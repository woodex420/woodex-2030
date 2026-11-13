# Native Laravel/Livewire Vercel deployment profile

Owner selected Livewire admin on Vercel. This opt-in package does not change root vercel.json or the existing React deployment.

Package committed platform source with:

    node scripts/package-vercel-livewire.mjs /absolute/empty/directory

This deliberately excludes dirty Laravel work, .env, local databases, vendor and generated caches. Composer lifecycle scripts are disabled in the package to prevent setup/migration/seed actions; the committed lockfile is retained. No dependency-resolution update is authorized by packaging.

The community vercel-php@0.9.0 runtime advertises PHP8.5 (Laravel requires >=8.4.1); source inspected at https://github.com/vercel-community/php . This is a community runtime, not official Vercel PHP support. Actual remote PHP/extension/dependency compatibility still needs validation. Existing public/build assets are included; a future release must rebuild them if native frontend source changes.

Required runtime configuration: stable APP_KEY, explicit APP_URL, isolated compatible MySQL DB_* credentials, schema/session/cache tables and installation state. Do not reuse the Node DATABASE_URL or map incompatible Postgres tables into Laravel. Entry fails closed before Laravel boot without required configuration; no generated key, SQLite fallback, installer bypass or migration-on-request. Session/cache/queue use the database, never ephemeral /tmp. /tmp is used only for views, logs and caches. Persistent uploads/object storage and worker/cron strategy remain prerequisites for modules that need them; this profile does not make ephemeral local uploads durable.

Only /build static assets are exposed; other requests route through Laravel including Livewire updates and auth/TOTP routes. No source/vendor public filesystem route. Installer/security middleware remains intact. Deployment protection must stay enabled for initial preview. Do not change the existing project's runtime/settings/aliases to this package until its dependencies and isolated database are ready.

Current evidence: package extraction/config assertions and no-overwrite guard passed. No local PHP executable is available in this session; PHP lint/runtime tests not run. Native deployment, login/TOTP and Livewire interactions are not yet accepted. Packaging is the first implemented step, not complete admin delivery.

## Runtime validation follow-up

A cached PHP8.4.21 executable was discovered at /home/user/.cache/woodex-os/php/php; the earlier statement that PHP was unavailable was incorrect. Entry syntax and missing-configuration response were exercised successfully. Locked production dependency check exposed missing ext-exif in that local binary; PHP version itself passed. This is not evidence that the Vercel runtime lacks exif. No ignore-platform-reqs workaround is used.

The Vercel Livewire runtime validation workflow installs PHP8.5 with explicit required extensions, packages the committed source, installs locked production dependencies with scripts disabled, and asserts the entry returns503 without credentials. This tests dependency installability and fail-closed behavior, not actual Vercel runtime parity or authenticated Livewire acceptance. It never connects to a hosted DB or deploys a partially configured application.

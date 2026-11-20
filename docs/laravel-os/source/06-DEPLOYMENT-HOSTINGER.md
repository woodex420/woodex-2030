> **This is not a document of this product.** It is the instructions for installing on Hostinger of another system: the Interior studio's
> Laravel application, [woodex420/woodexinterior](https://github.com/woodex420/woodexinterior), from which the code
> of this admin was taken. The file is `docs/06-DEPLOYMENT-HOSTINGER.md` of that repository at commit
> `603428cca1e45f9da2ffd9c4a0d88eb741285339` (3 November 2026), kept here unchanged so that a section the code names, such as "docs/06 §4",
> can be read where the code is. Checksum (sha256) of the file there, and of everything below this note:
> `572c0102eb234718e217ea79b3248d05f490b70dc5dfec2f563b6005cf0cf8bc`.
>
> **Where this admin differs.** This admin's own steps for Hostinger are
> [document 24](../../woodex-os/24-HOSTINGER-STEP-BY-STEP.md); the demonstration on Vercel is
> [document 13](../../woodex-os/13-VERCEL-DEPLOYMENT.md). The domain, the mailboxes, the folders and the
> repository named below are the studio's, not this product's: follow document 24, not this page. Every decision
> taken for this admin is a row of [docs/woodex-os/12-DECISION-LOG.md](../../woodex-os/12-DECISION-LOG.md), and
> where the two differ, that log holds.
>
> The people, telephone numbers, addresses and web addresses in it are the studio's, as the studio published
> them; no password or key is in it. `scripts/verify-code-references.mjs` fails when anything below is changed.

<!-- The studio's document follows, unchanged. -->
# Woodex OS: Deployment on Hostinger (shared / cloud hosting)

**Version:** 1.1 · **Date:** 16 October 2026 · **Target:** Hostinger hPanel: "Deploy from GitHub" without SSH
(recommended, §2) or an SSH install (§3). LiteSpeed, MySQL, cron.
**Rule:** no Node.js on the server, no daemons, no secrets in Git (D-10, D-17, D-22, D-56…D-58)

---

## 1. What you need (one time)

| Item | Where | Notes |
|---|---|---|
| Plan with **Git deploy + cron + MySQL** | Hostinger web or cloud hosting | SSH is optional (§3). Confirm P-05 |
| **PHP 8.4** for the site | hPanel → Websites → site → Advanced → **PHP Configuration** | Hostinger offers PHP 8.2–8.5 (8.3 is the default). Select 8.4 **before** the first deploy. |
| PHP extensions | hPanel → PHP Configuration → PHP extensions | `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `gd` (or `imagick`), `intl`, `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, `zip`, `exif`. The installer checks them. |
| Test site | hPanel → Domains → Subdomains | **`interior.woodex.com.pk`** until the owner approves; then the main domain (§2.6) |
| SSL | hPanel → Security → SSL | Free Let's Encrypt for apex + subdomains |
| MySQL databases | hPanel → Databases → Management | one **new, empty** database per site (`u…_woodex_test`, later `u…_woodex`) · read-only user on the **old v2.1** DB for the importer |
| Email DNS | hPanel → DNS | SPF, DKIM, DMARC for `woodex.com.pk` |

> **Before go-live:** rotate every credential that appeared in the old public repository (DB passwords, SMTP,
> WhatsApp token, Telegram bot token, AI keys, Meta app secret). See risk R5 / P-06.

---

## 2. Recommended: Hostinger Git deploy (no SSH)

### 2.1 How it works

hPanel's **Advanced → Git** copies the repository branch into the site folder on every push and replaces the files
there. It runs no build step except Composer. The repository is prepared for exactly that (D-56…D-58):

| Piece | What it does |
|---|---|
| `/.htaccess` (repository root) | Sends every request to `platform/public/`. Source code, docs, `vendor/`, `storage/` and any `.env` are never reachable from the web. |
| `/composer.json` (repository root) | Hostinger's "Installing Composer dependencies" step installs the app's PHP packages into `platform/vendor` (same `require` as `platform/composer.json`; a test keeps them equal). |
| `platform/public/build/` | The front-end build is **committed** (the server has no Node.js). Rebuild with `npm run build` before each commit that changes CSS/JS. |
| `~/woodex-os/<site>/` | The private **data folder** in the home folder, outside every `public_html`: `.env`, `storage/` (uploads, logs, caches) and, for a quick test, `database.sqlite`. Deploys never touch it. `<site>` is the site folder name, e.g. `interior.woodex.com.pk`. |
| `/install` | The **web installer**: writes `.env` (new `APP_KEY`), creates the tables, reference data and the owner account. Protected by a setup code that only the hosting account can read; closed for good afterwards. |
| System → **Database update** | After a deploy with new migrations, the owner's next admin page offers a one-click update (migrations, permissions, cache clear). Staff see a short "updating" notice meanwhile. SSH alternative: `php artisan woodex:update`. |

```
/home/u123456789/
├── woodex-os/
│   └── interior.woodex.com.pk/          ← data folder (created by the installer, never deployed over)
│       ├── .env                         ← settings and secrets (chmod 600)
│       └── storage/                     ← uploads, logs, sessions, compiled views
└── domains/…/public_html/…               ← the site folder = this repository (replaced on every deploy)
    ├── .htaccess  composer.json          ← routes everything to platform/public · installs platform/vendor
    ├── docs/  tools/                     ← not web-reachable
    └── platform/
        ├── public/                       ← the only web-visible folder (incl. build/)
        └── vendor/                       ← installed by Hostinger's Composer step
```

### 2.1a Woodex OS 1: `platform/public/` is the live site (read before deploying)

Since Woodex OS 1 the website **is** the files in `platform/public/`. The v1 pages ship in the repository, and
every page published from the admin — an article, a study, a page made in Page builder — is a **new file on
that server that is not in git**. hPanel's Git step updates tracked files and leaves untracked ones alone, so
an ordinary deploy is safe; these are the three things that are not:

| Never | Why |
|---|---|
| `git clean -fd` (or any deploy that deletes untracked files) inside the site folder | It deletes every page the owner published from the admin. |
| Replacing the folder with a fresh clone/upload "to be safe" | Same effect: the pages only exist on that server. |
| Restoring an old `public/` over the current one | Older pages come back and newer ones vanish. |

What protects the pages:

- **`woodex:site-backup`** — zips every page file under `public/` into
  `storage/app/private/site-backups/site-<date>.zip` in the data folder (outside every `public_html`), keeps the
  last **7**. It runs on the scheduler daily at **02:45** (`routes/console.php`), on top of the weekly full
  `spatie/laravel-backup` run that already includes the application folder. 142 pages ≈ 2.5 MB per copy.
- **Builder versions** — every save in Page builder keeps up to 40 previous HTML versions per page in
  `storage/app/private/builder/<hash>/`, and a retired page moves to `storage/app/private/site-retired/…`.
  Neither is in the deploy's way.

To restore a page, unzip the dated archive over `platform/public/`:

```bash
cd ~/apps/woodexinterior/platform/public
unzip -o ~/woodex-os/<site>/storage/app/private/site-backups/site-20261021-061746.zip insights/a-post/index.html
```

### 2.2 First setup on `interior.woodex.com.pk`

1. **Find the subdomain folder.** hPanel → Domains → Subdomains shows it, for example
   `domains/woodex.com.pk/public_html/interior` or `domains/interior.woodex.com.pk/public_html` (when the subdomain
   is its own website). In File Manager **empty it** (delete Hostinger's `default.php`).
2. **PHP 8.4** for that site (hPanel → Advanced → PHP Configuration) and the extensions from §1.
3. **Database:** hPanel → Databases → Management → new database + user + password. Note the full names
   (`u123456789_…`). Use a new, empty database: the installer refuses databases of other applications (e.g. v2.1).
4. **Connect Git** on the website that owns the folder: Advanced → Git → Connect with GitHub → repository
   `woodex420/woodexinterior` → branch (today `arena/8be318a2-woodexinterior`; after the pull request is merged:
   `master`) → **Root directory = the subdomain folder from step 1** (never the main `public_html` while the
   main domain serves another site) → Deploy.
5. **Read the deployment log.** "Installing Composer dependencies" must list packages and end with
   `Woodex OS: PHP packages installed in platform/vendor`. A failure there is usually the PHP version (step 2).
6. **Open `https://interior.woodex.com.pk`.** A fresh site opens the installer (§2.3).
7. **Cron** (§2.4) and **SSL** (hPanel → Security → SSL) for the subdomain.

### 2.3 The installer

- **Setup code:** the installer writes the code twice: `~/woodex-os/<site>/setup-code.txt` (private, the source of
  truth) and a copy **`WOODEX-SETUP-CODE.txt` in the site folder** — the folder hPanel → Websites → the site → File
  Manager opens first (`public_html`, or `public_html/<sub>` for a subdomain folder). Open the copy and paste the code.
  The private folder only shows under File Manager → **Access all files of hosting** (owners did not find it there,
  2026-10-15, hence the copy). The copy is never served: the root `.htaccess` sends every request to `platform/public`
  and also denies the name. A deploy removes the copy; reloading `/install` writes it again with the same code.
  Reloading never changes the code. Both files are deleted when the installation finishes. This proves the person
  installing owns the hosting account; nobody else can finish the installation, even while `/install` is public.
- **No file needed (Hostinger/cPanel):** leave the setup code empty and enter a MySQL database of this hosting account
  (host `localhost`, user `u123456789_…` with a password). Only the account owner can create such a user in hPanel, so
  logging in with it proves ownership as well. Never accepted: remote hosts, other accounts' users, users without a
  password, an anonymous MySQL login (`CURRENT_USER()` must be the account user), SQLite. After 10 failed proofs from
  one address the installer waits 10 minutes (`install-failures.json` in the data folder; deleted after installing).
- **Form:** site address (`https://interior.woodex.com.pk`), database (MySQL: host `localhost`, port 3306, name,
  user, password; SQLite only for a quick look), owner name, email and password (10+ characters, letters and
  numbers), and "require two-factor sign-in" (on by default).
- **Step 1** checks the database connection and writes `.env` (production, `APP_DEBUG=false`, new `APP_KEY`,
  database sessions/cache/queue, admin at `/admin`). **Step 2** continues by itself: migrations, reference data
  (roles, pipeline, numbering, rate book, 16 templates) and the owner account. Then the setup code is deleted and
  `/install` answers 404. Sign in at `https://interior.woodex.com.pk/admin`.
- **Start over** (wrong database details): delete `~/woodex-os/<site>/.env` in File Manager and open `/install`.
- **No home folder found** (unusual hosts): settings stay in `platform/` like the SSH install and the Health page
  warns, because a deploy may replace them. A server variable `WOODEX_HOME` can point to the home folder.

### 2.4 Cron

hPanel → Advanced → **Cron Jobs** → Custom, every minute. The installer's last page shows the exact line, e.g.:

```
/usr/bin/php /home/u123456789/domains/woodex.com.pk/public_html/interior/platform/artisan schedule:run
```

Use `/opt/alt/php84/usr/bin/php` if `/usr/bin/php` is another version. The scheduler is described in §5.

### 2.5 After each deploy

- Pushing to the connected branch deploys automatically (auto-deployment needs Business or higher; otherwise
  Advanced → Git → **Redeploy**). The data folder is untouched.
- Sign in. If the new code changed the database, the admin opens **System → Database update**: review the list,
  click **Apply update**. Staff get a short "updating" notice until then.
- Reference data never overwrites the owner's edits: re-seeding only adds missing rates, templates and stages.

### 2.6 Moving to the main domain (after approval)

When the owner approves (end of a phase or the P6 website cut-over):

1. Back up the current `public_html` of `woodex.com.pk` (hPanel → Files → Backups) if a site still runs there.
2. Copy the data folder: `~/woodex-os/interior.woodex.com.pk` → `~/woodex-os/woodex.com.pk` (or run the installer
   again for a fresh production database), then edit `APP_URL` in the copied `.env`.
3. Connect Git on `woodex.com.pk` with Root directory `public_html`, deploy, add the cron line for the new path.
4. Keep the subdomain as staging, with its own database.

### 2.7 What went wrong on the first try (15 Oct 2026)

The repository was deployed into the **main domain's** `public_html` (Hostinger's default root directory), before
the root `.htaccess` and `composer.json` existed: `woodex.com.pk` answered **403** (no index file at the repository
root), Composer was skipped (no `composer.json` at the root), and the subdomain still showed Hostinger's default
page. Fix: disconnect Git there, delete the deployed files (`docs/`, `platform/`, `tools/`, `woodex/`,
`.gitignore`, and later `.htaccess`, `composer.json`) from that `public_html`, restore the old site from a Hostinger
backup if it lived there, and connect Git on the subdomain folder as in §2.2.

---

## 3. SSH installation (alternative)

### 3.1 Folder layout

```
/home/uXXXXXXX/
├── apps/
│   └── woodexinterior/                 ← git clone of this repository (outside public_html)
│       └── platform/                   ← Laravel application root
│           ├── .env                    ← created on the server only (chmod 600)
│           ├── public/                 ← the ONLY web-visible folder
│           ├── storage/  bootstrap/cache/   ← writable (775)
│           └── vendor/                 ← composer install (on server) or uploaded
└── domains/woodex.com.pk/public_html/
    ├── (current live site stays here until the P6 website cut-over)
    └── admin  →  symlink to /home/uXXXXXXX/apps/woodexinterior/platform/public
```

If hPanel created the subdomain in its own folder (for example `domains/admin.woodex.com.pk/public_html`),
replace **that** folder with the symlink instead.

**If symlinks are not allowed:** copy the contents of `platform/public/` into the subdomain folder, then edit
the copied `index.php` so its two `require` paths point to the app:

```php
require __DIR__.'/../../../apps/woodexinterior/platform/vendor/autoload.php';
$app = require_once __DIR__.'/../../../apps/woodexinterior/platform/bootstrap/app.php';
```

Then set `APP_PUBLIC_PATH` in `.env` so asset paths resolve (see `bootstrap/app.php`). Adjust the number of
`../` segments to match your folder depth.

### 3.2 First installation

```bash
# 1) Get the code
mkdir -p ~/apps && cd ~/apps
git clone https://github.com/woodex420/woodexinterior.git
cd woodexinterior/platform
git checkout <release-tag-or-branch>

# 2) PHP version check (CLI can differ from the website version)
php -v                       # must say 8.4.x; otherwise use: /opt/alt/php84/usr/bin/php
composer -V                  # Hostinger ships Composer 2

# 3) Dependencies (production only)
COMPOSER_MEMORY_LIMIT=-1 composer install --no-dev --optimize-autoloader --no-interaction

# 4) Environment
cp .env.example .env && chmod 600 .env
nano .env                    # fill in the values from section 4
php artisan key:generate

# 5) Front-end assets: already in the repository (platform/public/build, D-56)

# 6) Database + storage
php artisan migrate --force
php artisan woodex:install    # reference data + the owner account (asks for name, email, password)

# 7) Optimise
php artisan optimize          # config, routes, views, events cache
```

Permissions: `chmod -R 775 storage bootstrap/cache`. No file in the repository needs 777. The web installer
(§2.3) also works on an SSH install when `.env` does not exist yet.

---

## 4. Production `.env` (values are examples; never commit this file)

```dotenv
APP_NAME="Woodex OS"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://admin.woodex.com.pk
APP_TIMEZONE=Asia/Karachi
ADMIN_DOMAIN=admin.woodex.com.pk
ADMIN_PATH=
PUBLIC_SITE_URL=https://woodex.com.pk

LOG_CHANNEL=daily
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=uXXXX_woodex_os
DB_USERNAME=uXXXX_woodex
DB_PASSWORD=********

# Read-only connection to the old v2.1 database (importer only; remove after cut-over)
LEGACY_DB_HOST=localhost
LEGACY_DB_DATABASE=uXXXX_woodex_v21
LEGACY_DB_USERNAME=uXXXX_v21_ro
LEGACY_DB_PASSWORD=********

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=admin.woodex.com.pk
QUEUE_CONNECTION=database
CACHE_STORE=database
BROADCAST_CONNECTION=log        # switch to "pusher" when the Pusher/Ably keys are added (P4)

MAIL_MAILER=smtp
MAIL_HOST=smtp.hostinger.com
MAIL_PORT=465
MAIL_SCHEME=smtps
MAIL_USERNAME=noreply@woodex.com.pk
MAIL_PASSWORD=********
MAIL_FROM_ADDRESS=noreply@woodex.com.pk
MAIL_FROM_NAME="Woodex Interior"

PUBLIC_LEADS_ALLOWED_ORIGINS=https://woodex.com.pk,https://www.woodex.com.pk
TURNSTILE_SITE_KEY=
TURNSTILE_SECRET_KEY=
TELEGRAM_BOT_TOKEN=
TELEGRAM_WEBHOOK_SECRET=       # long random string; also used in the webhook URL path
```

Integration keys (WhatsApp, Meta, LinkedIn, AI, Pusher) can also be entered later in
**System → Integrations**, where they are stored encrypted. Either place works; `.env` wins if both are set.

---

## 5. Cron (the heartbeat of the system)

hPanel → Advanced → **Cron Jobs** → "Custom", every minute (`* * * * *`):

```
/usr/bin/php /home/uXXXXXXX/apps/woodexinterior/platform/artisan schedule:run >> /dev/null 2>&1
```

(Git deploy: the path is `<site folder>/platform/artisan`, shown by the installer, §2.4.) Use the PHP 8.4 binary path if `/usr/bin/php` is a different version (for example `/opt/alt/php84/usr/bin/php`).

The scheduler (`platform/routes/console.php`) then runs:

| Task | Frequency | Purpose |
|---|---|---|
| `queue:work --stop-when-empty --max-time=50 --tries=3` | every minute (no overlap) | Sends WhatsApp/Telegram/email, builds PDFs, syncs |
| `woodex:heartbeat` | every minute | Writes the cron heartbeat. The health screen and Telegram alert if it stops. |
| `woodex:sla-check` | every minute | SLA breaches → escalations (P1) |
| `automations:tick` | every minute | Sequences, reminders (P4) |
| `backup:run --only-db` | daily 02:30 | DB backup (P0) |
| `backup:run` | weekly Sun 03:00 | DB + files |
| `backup:clean` + `backup:monitor` | daily | Retention + Telegram alert on failure |
| `model:prune`, `queue:prune-failed --hours=720`, log cleanup | daily | Keep the disk small |
| `woodex:digest daily` | 09:00 | Telegram digest (P5) |

Critical alerts (new lead) are **not** delayed by cron. They are sent right after the HTTP response.

---

## 6. Updating (every release)

**Git deploy (§2):** push (or Redeploy), then sign in and apply the database update when the admin asks (§2.5).

**SSH install (§3):**

```bash
cd ~/apps/woodexinterior/platform
php artisan backup:run --only-db            # safety copy before migrating
php artisan down --secret="<random>"        # optional; maintenance with bypass URL
git fetch --tags && git checkout <new-tag>
COMPOSER_MEMORY_LIMIT=-1 composer install --no-dev --optimize-autoloader --no-interaction
php artisan woodex:update                   # migrations, permissions, number sequences, theme images, cache clear
php artisan optimize
php artisan up
```

**Rollback:** `git checkout <previous-tag>` → `composer install …` → `php artisan optimize`. Migrations are
written forward-only; when a migration must be undone, restore the pre-deploy DB backup (step 1).

### 6.1 Optional: one-click deploy from GitHub Actions

`docs/ci/deploy-hostinger.yml`, activated by copying it to `.github/workflows/` (see `docs/ci/README.md`).
It runs from the manual "Run workflow" button:

1. Builds the assets (`npm ci && npm run build`).
2. Uploads `platform/public/build` with rsync over SSH.
3. Runs the update commands from §6 over SSH.

Repository secrets needed: `HOSTINGER_HOST`, `HOSTINGER_PORT` (65002), `HOSTINGER_USER`, `HOSTINGER_SSH_KEY`
(private key whose public part is added in hPanel), `HOSTINGER_APP_PATH`
(`/home/uXXXXXXX/apps/woodexinterior/platform`). Nothing runs until these secrets exist.

### 6.2 Without SSH

Use the Git deploy (§2): Hostinger installs the PHP packages, the front-end build is in the repository and the
database update is a button in the admin (System → Database update, owner/admin only).

---

## 7. Web server notes (LiteSpeed)

- `platform/public/.htaccess` = Laravel default + HTTPS redirect + security headers + `Options -Indexes`.
- Admin subdomain: **no page cache**, `X-Robots-Tag: noindex, nofollow`.
- Public website (P6): LiteSpeed cache for GET pages without session cookies. Cache purged by tag on publish.
  Public forms and chat use stateless JSON endpoints, so cached pages never contain CSRF tokens.
- Upload limits (PHP Configuration): `upload_max_filesize = 32M`, `post_max_size = 40M`, `max_execution_time = 60`,
  `memory_limit = 512M` (PDF generation).

---

## 8. Webhook URLs to register

| Service | URL | Verification |
|---|---|---|
| WhatsApp Cloud API | `https://admin.woodex.com.pk/webhooks/whatsapp` | Verify token (GET) + `X-Hub-Signature-256` |
| Meta (Messenger, IG, Lead Ads) | `https://admin.woodex.com.pk/webhooks/meta` | Verify token + signature |
| Telegram | `https://admin.woodex.com.pk/webhooks/telegram/{TELEGRAM_WEBHOOK_SECRET}` | `X-Telegram-Bot-Api-Secret-Token` header |
| LinkedIn | `https://admin.woodex.com.pk/webhooks/linkedin` | Challenge + HMAC signature |
| Live-site bridge (P1) | `POST https://admin.woodex.com.pk/api/public/leads` | CORS allow-list + Turnstile |

`php artisan woodex:telegram:set-webhook` registers the Telegram webhook automatically.

---

## 9. Go-live checklist

- [ ] All old credentials rotated (P-06). The new values exist only in `.env` / Settings.
- [ ] `APP_DEBUG=false`, `APP_ENV=production`, HTTPS forced, `SESSION_SECURE_COOKIE=true`
- [ ] Owner + admin accounts have **2FA** enabled. Default/demo users do not exist.
- [ ] Cron heartbeat is green on **System → Health**. A queued test job ran.
- [ ] Test email received (SPF/DKIM pass). Telegram test message received.
- [ ] Backup ran and was downloaded once. **Restore drill** done on staging.
- [ ] Error pages (404/500/503) show the Woodex design. Logs rotate daily.
- [ ] Admin subdomain sends `noindex`. robots.txt is correct for each domain.
- [ ] v2.1 import: final run report has equal checksums. v2.1 is set to read-only.
- [ ] (P6) 142-URL parity test green on production. Sitemap submitted. 404 log watched for 14 days.

---

## 10. Troubleshooting

| Symptom | Fix |
|---|---|
| Domain answers **403** after a Git deploy | The repository went into a folder without the root `.htaccess` (old commit) or into the wrong site. Redeploy the latest commit; check Root directory (§2.2, §2.7). |
| "Setup needed: PHP packages are not installed" | Hostinger's Composer step did not run or failed: read the deployment log (hPanel → Git → Deployments). Usually PHP is not 8.4 yet → set it, Redeploy. |
| "This site runs PHP 8.3" page | hPanel → PHP Configuration → 8.4 → Redeploy. |
| Every page opens `/install` | The site has no `.env` yet (or the installer's step 2 has not finished): complete the installer. |
| Admin shows "Woodex OS is being updated" | New migrations are waiting: the owner signs in and clicks Apply update (§2.5). |
| HTTP 500 right after deploy | `storage/` and `bootstrap/cache/` not writable → `chmod -R 775`. Check `storage/logs/laravel-*.log`. |
| "No application encryption key" | `php artisan key:generate` (only once; keep the key safe; changing it breaks encrypted settings) |
| CLI uses another PHP version | Use the full binary path (`/opt/alt/php84/usr/bin/php`) in SSH and cron |
| `composer install` killed (memory) | `COMPOSER_MEMORY_LIMIT=-1`. Always install from `composer.lock` (never `composer update` on the server). |
| Styles missing | `public/build/manifest.json` not uploaded → upload the build folder from CI |
| Queue not processing | Cron missing or wrong path → check the heartbeat; run `php artisan schedule:run` manually and read the output |
| Symlink 403 | LiteSpeed needs "SymLinksIfOwnerMatch". Owner of the symlink and target must match; otherwise use the copy method in §3.1. |

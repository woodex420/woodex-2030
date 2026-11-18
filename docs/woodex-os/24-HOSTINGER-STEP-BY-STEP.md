# WOODEX OS on Hostinger — what is ready, and your steps

**Written 18 November 2026** · master list T-006, T-143 to T-146 · decision DL-033 · release report 23

You asked for the production deployment to be started. This page says what is ready for it, what has been
proved and where, and the steps that only you can do, because only you can sign in to Hostinger. They take about
half an hour. Nothing on this page needs a programmer.

## 1. What is ready

| | |
|---|---|
| **A release** | The admin as a folder of files that a host can run as it is: the application, the packages it needs and its built screens. It holds no settings, no password and no data. It is made by GitHub from `main` each time the admin changes, after every test has passed, and written to the branch **`hostinger-release`** of the repository |
| **An installer** | The first page of a fresh site. It checks the server, asks for a code that only your hosting account can read, takes the database details and your own name, e-mail address and password, creates the tables and closes itself. There is no default password |
| **Updates** | A later release replaces the files. Your settings, uploads and the database are kept outside the website's folder and stay. If the new release changes the database, the admin shows you one page with one button, "Apply update" |

## 2. What is proved, and what is not

**Proved, on GitHub's machines, on every change:** the release is put on a web server laid out like a Hostinger
account (Apache, PHP 8.4, MariaDB 10.11, PHP running as an account named `u123456789`, the site in
`domains/…/public_html`). A browser then does what you will do: it opens the site, is sent to the installer, is
refused with a wrong code, installs with the right one, signs in, reads the System health screen, starts the
timed jobs with the line the installer printed, and signs out. Then the whole folder is replaced by a second
release that brings a database change: the browser signs in with the same password, presses "Apply update" and
works on. From outside, 60 addresses are asked for: no file of the application is handed out. The whole test
suite also runs on MariaDB 10.11 and 11.4, the two long-term versions of the database that Hostinger gives
under the name MySQL.

**Not proved:** anything on your Hostinger account. Nobody but you can reach it, and the place where this work is
done reaches only GitHub. Three things can differ there and will show in the first minutes:

- Hostinger's web server is LiteSpeed, not Apache. It reads the same `.htaccess` file, and the rules used are
  ones its open version is written to understand; it has not been seen doing so. The ten-second check in step 6
  shows it before anything is installed.
- Which PHP and which MariaDB version your plan offers. The installer's first page tells you if PHP is too old
  or an extension is off.
- The path of PHP in the cron line (step 9). System → Health tells you within three minutes whether it works.

If a step shows something other than what is written here, stop there and send me what you see.

## 3. Before you start: one choice

**Which address.** The first installation should be a trial on an address nobody uses yet, for example a
subdomain such as `staging.` or `os.` in front of a domain of yours. The public address of the admin is your
decision T-007 and can be set later; moving is a second installation, not a repair.

No real customer, price or order should be entered before your written approval (T-181). A trial site with an
empty database moves nothing.

## 4. The steps

The names are the ones hPanel used when this was written; they may differ slightly.

| # | Where | What |
|---|---|---|
| 1 | hPanel → Websites | Create the subdomain for the trial. Hostinger makes a folder for it: `domains/<the address>/public_html` |
| 2 | hPanel → Advanced → PHP Configuration | Choose **PHP 8.4** (or newer) for this site. Under PHP extensions, these must be on (most are already): `ctype` `curl` `dom` `exif` `fileinfo` `gd` `intl` `mbstring` `openssl` `pdo_mysql` `tokenizer` `xml` `zip` |
| 3 | hPanel → Databases → Management | Create a new, empty MySQL database and a user for it. Both names begin with your account number, like `u123456789_woodex`. Keep the name, the user and the password for step 8 |
| 4 | hPanel → File Manager | Open the site's `public_html` and delete what Hostinger put there (a default page). The folder must be empty |
| 5 | hPanel → Advanced → GIT | Repository `https://github.com/woodex420/woodex-2030.git` · branch `hostinger-release` · folder: leave empty (the site's `public_html`). Create it, then press **Deploy**. About 10,700 files arrive |
| 6 | Your browser | **First a ten-second check:** open the site's address followed by `/RELEASE.json`. It must answer with an error page, "Forbidden" or 403. If it shows text in curly brackets instead, the web server is not reading the folder's rules: stop here, empty `public_html` again and tell me. **Then** open the site's address followed by `/admin`. The page **"Install Woodex OS"** opens, with five checks of the server. Each should carry a tick |
| 7 | hPanel → File Manager | Open the file `WOODEX-SETUP-CODE.txt` in `public_html` and copy the code (six groups of four characters). It cannot be opened from the web; only your hosting account can read it |
| 8 | The installer | Paste the code. Leave the address as proposed (use `https://` once the certificate is active). Database: host `localhost`, then the name, user and password of step 3. Then your name, your e-mail address and a password of at least 10 characters with letters and numbers. Leave "Require two-factor sign-in" ticked. Press **Install Woodex OS** and leave the page open while the tables are created: seconds in the rehearsal, perhaps a minute or two on shared hosting |
| 9 | hPanel → Advanced → Cron Jobs → Custom | The last page of the installer shows one line that ends in `artisan schedule:run`. Enter it as a cron job that runs **every minute**. Reminders, alerts and queued mail depend on it |
| 10 | The admin | Press "Sign in to the admin" and sign in. Open **System → Health** |

**What System → Health should show:** the environment `production`; the database server by name and version;
the release (a commit number and when it was put together); "Database up to date", "Settings and uploads folder"
and "Front-end build" in order; and, within three minutes of step 9, a cron heartbeat. If the heartbeat stays
"never", the same card shows the line to enter again. The path of PHP at its start is then what does not fit
your account: send me what the card shows.

**If the first page says "Temporarily unavailable"** instead of the installer: PHP for this site is older than
8.4.1, or the files did not arrive. Check step 2, then step 5.

**Without Git.** If hPanel's GIT page is not offered or refuses: on GitHub, open the repository, choose the
branch `hostinger-release`, then Code → Download ZIP. Upload the ZIP in File Manager, extract it, and move
everything inside its one folder up into `public_html` (the hidden file `.htaccess` included). Then go on with
step 6.

## 5. What to send me afterwards

- The address of the site.
- A picture of System → Health.

With the address I can point GitHub's check from outside at the site, on every change and four times a day, as
it runs for the demo today. That closes your line T-006 for the trial site; no password and no key has to leave
Hostinger for it.

## 6. Afterwards

| | |
|---|---|
| **A new version** | hPanel → Advanced → GIT → Deploy. Then sign in: if the page "A database update is needed" opens, press **Apply update**. hPanel can also deploy by itself on every release (the GIT page shows an address to enter at GitHub under Settings → Webhooks); switch that on only when you want every merged change to go live without your hand |
| **When the repository is made private** (T-021, DL-022) | Hostinger then needs a key to read it. The GIT page shows one; it is added at GitHub under the repository's Settings → Deploy keys, as read-only |
| **The bare address** | The address without `/admin` forwards to the shop's demo on Vercel, as you chose for the earlier staging site. What it should show in the end is part of T-007 |
| **Mail** | The installer sets mail to be written to the log, not sent, until you give a mailbox (T-009). Until then "Forgot your password?" sends nothing: keep your password, and set up the second factor under your account → Security |
| **Backups** | Before real data goes in: one backup made and one restored into an empty database (T-137). hPanel's own backups are a second line, not the proof |
| **Where things are kept** | Settings: `woodex-os/<the address>/.env` in your account's home folder, outside every website folder, readable only by the account. Uploads and logs: `woodex-os/<the address>/storage/`. The database: the one of step 3. The website's folder holds only the release and can be replaced at any time |

## 7. The way back

Delete the cron job, empty the site's `public_html`, delete the database of step 3 and the folder
`woodex-os/<the address>`. Nothing else was touched: not your other websites, not the shop on Vercel, not its
database.

## 8. For whoever maintains this

| | |
|---|---|
| The release | `platform/tools/hostinger/build-release.sh <empty folder>` |
| The rehearsal in CI | `.github/workflows/os-ci.yml`, job `hostinger` (web server: `platform/tools/hostinger/Dockerfile`); published by the job `publish`, on `main` only |
| The browser | `scripts/os-install.mjs`, and with `--after-deploy` for the next deployment |
| From outside | `platform/tools/hostinger/check-bridge.sh <address> [<website folder>]` |
| Without Apache | `platform/tools/hostinger/rehearse.sh [port] [folder]`, then `redeploy.sh <release> <website folder>` |
| With a command line on the host | `php artisan woodex:install` and `php artisan woodex:update` do what the two pages do |

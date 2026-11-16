# Native Laravel staging release preparation

Owner selected native Laravel/Livewire first, PHP8.4.1+ available (owner report), GitHub Actions and staging-only preservation. This does not complete the React dashboard roadmap.

`hostinger-staging.yml` builds locked production PHP dependencies and native Vite assets into a private application candidate on this session branch. It does not deploy that artifact, configure .env, seed, migrate, create owners, replace public_html or alter the existing redirect. Its source commit is explicit: uncommitted local changes are NOT included. The package is not a fresh MySQL acceptance gate; os-ci.yml handles that separately.

## One-time secure operator setup

In GitHub repository Settings → Environments create `hostinger-staging`, ideally with required reviewer approval. Add environment secrets privately:

- `HOSTINGER_SSH_PRIVATE_KEY`: a dedicated staging deployment key with matching public key authorized in Hostinger. Never paste it into chat. A screenshot of a listed public key does not supply this secret.
- `HOSTINGER_SSH_KNOWN_HOSTS`: the verified `[72.60.93.138]:65002` host-key entry obtained/verified through a trusted hosting channel. Do not disable host verification or trust an unverified scan.

Then Actions → Hostinger staging release preparation → Run workflow → select `arena/69363bda-woodex-2030` → enable `check_hostinger`. The SSH job only checks PHP CLI and extensions. It cannot establish HTTP PHP version, path isolation or database readiness.

## Hosted writes remain gated

Before adding a write-capable deployment job: verify the actual staging document-root/private-source paths, keep source out of public_html, confirm a dedicated target marker and rollback copy, check web PHP/extensions and target database version, securely provision a rotated .env, preserve the imported migration records, and define native owner provisioning/TOTP without public installers or defaults. Never run migrate:fresh against the imported staging database. No guessed path, public web command runner, disabled 2FA or generic CRUD publication is authorized by package preparation.

The staging SQL was manually imported successfully according to the owner's phpMyAdmin screenshot (1027 queries); this is not application/database connection or MariaDB compatibility acceptance. Previously exposed passwords must be rotated. No hosting secrets are included in the artifact.

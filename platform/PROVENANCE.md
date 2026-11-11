# Provenance — where `platform/` came from

`platform/` is the Furniture copy of the WOODEX OS Laravel application.
It was copied on 2026-11-11 with the owner's approval. This file records what was copied and what was left out.

## Source

| Item | Value |
|---|---|
| Repository | `woodex420/woodexinterior` (public) |
| Branch and commit | `master` @ `603428cca1e45f9da2ffd9c4a0d88eb741285339` |
| Path in the source | `platform/` — 2,059 files |
| Hand-off archive | `WOODEX_OS_FULL_SOURCE_MASTER_v2_603428c.zip`, sha256 `5e8c271b5460fc5bb5a25f3aa478ac362f81f357b43cae7bb690df92752ee25c` |
| Check performed | All 2,059 archive files under `platform/` have the same git blob id as the public repository at that commit. One difference: the archive marks `artisan` executable; the repository does not, and neither does this copy |

Nothing in this copy was private before it was copied here.
The archive's three `EXPORT_INFO` hand-off notes are not in the public source repository and were not copied.

## What the first import commit contains

The first commit that adds `platform/` is a **verbatim** copy: no file was edited. Later commits adapt it, and each says what it changes.

| Decision | Files | What |
|---|---|---|
| Imported | 1,475 | The application: `app/`, `bootstrap/`, `config/`, `routes/`, `resources/`, `tests/`, `database/` (72 migrations, settings, seeders, factories), admin runtime assets in `public/` |
| Quarantined | 11 | Migrations that import, publish or edit Interior website content. Kept for reference in `database/quarantine/interior-content/`, outside the migration path. Never run them for Furniture |
| Excluded | 573 | Interior content — see below |

Excluded, by reason:

| Files | Reason |
|---|---|
| 277 | Interior photographs (`public/assets/img/`) |
| 159 | Interior public pages and page media (142 page folders, `public/img/`) |
| 108 | Interior website bundle, legacy URL map and legacy importer data (`database/data/`, `database/rehearsal/`) |
| 15 | Interior public-site stylesheets, scripts, price data and client list (`public/assets/*.css`, `*.js`, `assets/js/`, `assets/data/`) |
| 6 | Nohemi typeface files — commercial licence not confirmed |
| 3 | Interior project notes and history (`AGENTS.md`, `STATE.md`, `README.md`) |
| 2 | Interior public root files (`index.html`, `robots.txt`) |
| 2 | Interior logos |
| 1 | Interior public 404 page |

The decision for every source file, with its sha256, is in `PROVENANCE/import-manifest.tsv`.

## Verify the copy

At the import commit, every row marked `imported` or `quarantined` must hash to the value in the manifest:

```
cd platform
awk -F'\t' 'NR>1 && $4!="" {print $2"  "$4}' PROVENANCE/import-manifest.tsv | sha256sum -c --quiet
```

After later commits, files that were deliberately adapted will differ. `git log -- <file>` shows why.

## Still Interior-shaped — do not use for real business yet

- Default business settings (name, address, phone, e-mail, payment terms, signatories) are the Interior studio's.
- Reference seeders carry Interior prices, quotation templates and message copy.
- Layouts, validation messages and some PDF wording name the Interior studio.
- Timestamps are stored in Pakistan time; the owner has decided on UTC storage, which is not implemented yet.

These are tracked in `docs/laravel-os/`. No production use, deployment or real data until they are resolved and separately approved.

## Licences

Third-party components are listed in `THIRD-PARTY.md` (copied from the source).
Open items carried over: the Nohemi typeface is excluded until its licence is confirmed, and Preline Pro blocks may be used only with a purchased licence — whether any are present has not been verified.

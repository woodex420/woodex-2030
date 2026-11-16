# WOODEX OS — decision log

Spec 08 §2 makes the **latest explicit owner decision recorded here** the first source of truth.
One row per decision. A decision is never edited; a later row supersedes it and says so.

| # | Date | Decision | Source | Supersedes |
|---|---|---|---|---|
| DL-001 | 2026-11-16 | **Laravel WOODEX OS is the base.** The whole Agency OS (React dashboard + Node API) is migrated into the OS admin, by a master plan. | Owner's message in the session of 2026-11-16 | The Phase 1 answer of the same day ("the admin is the React dashboard at `/admin`") — as the *target*. It remains true of the live system until each module is cut over. |
| DL-002 | 2026-11-16 | The work stays in **this repository**; the Laravel application lives in `platform/`. No new repository. | Spec 08 §1 ("using the existing repository"); the Laravel branch already does this. *Read from the documents — not asked.* Reverse it by saying so. | Umbrella plan §1 ("new repository") |
| DL-003 | 2026-11-16 | The **shop stays a React application**; it is not migrated to Blade. | Spec 02 §1, spec 06; decision D-4 on the Laravel branch. *Read from the documents — not asked.* | Umbrella plan §1 ("migrate the storefront gradually to Blade") |
| DL-004 | 2026-11-16 | The **real catalogue** (170 products captured from woodexfurniture.pk) is the import source for the Laravel catalogue. The 148 sample products are test fixtures only. | Master plan §2, C-5. *Proposed by the plan — not asked.* | — |

## Asked on 2026-11-16 — answers recorded below as they arrive

| # | Question | Recommended | Answer |
|---|---|---|---|
| D-01 | Admin screens: Livewire, React on a Laravel API, or mixed | Livewire | *pending* |
| D-02 | Phase 2: finish and merge first, or pause | Finish first | *pending* |
| D-03 | Look: Agency OS, navy and wood, or the present Laravel look | Agency OS (spec 05) | *pending* |
| D-04 | First area after the menu and layout | Dashboard → commerce → CRM and sales | *pending* |

## Carried from the Laravel branch (`docs/laravel-os/`), not re-asked

D-1 preview first · D-3 rebuild one module at a time · D-4 keep the React storefront · D-7 "WOODEX OS" for the admin, "Woodex Furniture" on documents.
Open there and still open: **D-8** Furniture prices, terms, wording and signers · **D-9** which existing records to migrate · **D-10** Hostinger plan details.

# WOODEX OS — decision log

Spec 08 §2 makes the **latest explicit owner decision recorded here** the first source of truth.
One row per decision. A decision is never edited; a later row supersedes it and says so.

| # | Date | Decision | Source | Supersedes |
|---|---|---|---|---|
| DL-001 | 2026-11-16 | **Laravel WOODEX OS is the base.** The whole Agency OS (React dashboard + Node API) is migrated into the OS admin, by a master plan. | Owner's message in the session of 2026-11-16 | The Phase 1 answer of the same day ("the admin is the React dashboard at `/admin`") — as the *target*. It remains true of the live system until each module is cut over. |
| DL-002 | 2026-11-16 | The work stays in **this repository**; the Laravel application lives in `platform/`. No new repository. | Spec 08 §1 ("using the existing repository"); the Laravel branch already does this. *Read from the documents — not asked.* Reverse it by saying so. | Umbrella plan §1 ("new repository") |
| DL-003 | 2026-11-16 | The **shop stays a React application**; it is not migrated to Blade. | Spec 02 §1, spec 06; decision D-4 on the Laravel branch. *Read from the documents — not asked.* | Umbrella plan §1 ("migrate the storefront gradually to Blade") |
| DL-004 | 2026-11-16 | The **real catalogue** (170 products captured from woodexfurniture.pk) is the import source for the Laravel catalogue. The 148 sample products are test fixtures only. | Master plan §2, C-5. *Proposed by the plan — not asked.* | — |
| DL-005 | 2026-11-16 | **The admin's screens are Laravel Livewire.** One admin inside Laravel; the existing Laravel screens stay; every Agency OS module is rebuilt as a Laravel screen. React is used only for the shop and the page editor. | Owner's answer to D-01 | Spec 02 §1 and spec 09 §3 where they keep the React dashboard as the staff admin |
| DL-006 | 2026-11-16 | **Phase 2 (the real catalogue on the live React shop) is finished and merged first**; the Laravel code then comes in on that base. | Owner's answer to D-02 | — |
| DL-007 | 2026-11-16 | **The look is the Agency OS look**: charcoal sidebar, white pages, WOODEX green, Inter — spec 05 §5. | Owner's answer to D-03 | Umbrella plan §7 ("navy/charcoal … wood-inspired accent") |
| DL-008 | 2026-11-16 | **Order of the UI/UX upgrade:** the menu and layout first, then the Dashboard, then E-commerce, then CRM and sales. | Owner's answer to D-04 | — |
| DL-009 | 2026-11-16 | **The roadmap leaves the sidebar.** The sidebar shows only what can be opened; everything else — work still done in the Agency OS dashboard, switched-off modules, screens not built — is on a Feature status screen. | Spec 05 §3 ("no repeated Soon badges and no Legacy labels in normal navigation"; "list roadmap items in a separate … feature-status view"). *Read from the documents — not asked.* | The direction of 2026-11-11 on the Laravel branch, which showed switched-off modules in the sidebar as disabled "Soon" entries |
| DL-010 | 2026-11-16 | **Where the navigation differs from spec 05 §2, on purpose.** (a) The first Commerce entry is still called **E-commerce** — the owner's own wording of 2026-11-11 — where the specification says "Store Overview". (b) An entry keeps the wording and capitals of the screen it opens ("Ledger & reports", not "Ledger & Reports"), so that the menu and the page title agree. (c) Two working screens the specification does not list stay: **Board** (projects) and **Find & replace** (website). (d) The specification has a second group called WORKSPACE (preferences, onboarding); its two entries have no screen and are listed under System on Feature status. | Master plan. *Not asked.* Any of the four is a one-line change; say which you prefer. | — |
| DL-011 | 2026-11-16 | **The Wave 0 and Wave 1 work is not merged into `main` without the owner's go.** `main` is deployed to the live shop, and the merge changes storefront code (listed in the Wave 0–1 report). | Umbrella plan §2 and spec 08 §4 ("no production … deployment action without explicit approval"). The approval given on 2026-11-16 (DL-006) was for Phase 2. | — |
| DL-012 | 2026-11-16 | **Changing the live owner password and making the repository private are on hold.** | Owner's message ("is hold now"). The risk stays as recorded: the old password was printed in a public document. | — |
| DL-013 | 2026-11-16 | **One global design, taken from the storefront:** the admin uses the store's font and colours. | Owner's message ("clean design font color global using frontend store design"). | DL-007 where the two palettes differ (the store's green is `#629924`, the dashboard's `#4f9d21`; the store's charcoal is `#22262a`) |
| DL-014 | 2026-11-16 | **Deploy the Laravel admin on Vercel, now**, as its own Vercel project. | Owner's messages ("deployment start vercel woodex os laravel admin", "start deployment vercel"). Hostinger remains the production target of the specification; the Vercel deployment needs a MySQL database before anyone can sign in. | — |

## Asked on 2026-11-16, answered the same day

| # | Question | Options offered | Answer | Recorded as |
|---|---|---|---|---|
| D-01 | What the admin's screens are built with | Livewire · React on a Laravel API · mixed | **Livewire** | DL-005 |
| D-02 | Phase 2: finish and merge first, or pause | finish first · pause | **Finish and merge it first** | DL-006 |
| D-03 | The look | Agency OS · navy with a wood accent · keep the present Laravel look | **Agency OS** | DL-007 |
| D-04 | First area after the menu and layout | plan order · e-commerce · CRM and sales · dashboard only, then ask | **Follow the plan** | DL-008 |

All four answers were the recommended option.

## Carried from the Laravel branch (`docs/laravel-os/`), not re-asked

D-1 preview first · D-3 rebuild one module at a time · D-4 keep the React storefront · D-7 "WOODEX OS" for the admin, "Woodex Furniture" on documents.
Open there and still open: **D-8** Furniture prices, terms, wording and signers · **D-9** which existing records to migrate · **D-10** Hostinger plan details.

# Staff Experience builder team

Status: **set up with the owner on 2026-11-05.** This folder is the team's memory: what is built, what
the owner decided, the rules, and what to build next. The team reads it before every task and updates it in
the same PR as the code.

## 1. Team

| Role | Name | Job |
|---|---|---|
| Lead | **Kavitha** | Plans each piece, builds it, keeps this file current |
| Reviewer | **Aiman** | Checks every piece against the rules below, the tests and the owner's decisions before it reaches the owner |

Both are Claude builder sessions working for the owner through the coordinator session, B.E.R.N.I.S.S (formerly LUCY). Names chosen by
the coordinator (2026-11-05).

**Design (owner decision 2026-11-05, relayed by LUCY):** every builder team works with a UI/UX designer under the
Lead UI/UX Designer, **Daniel** (Design team, `docs/teams/design/TEAM.md`, guide `docs/design/GUIDE.md`,
checklist `docs/design/CHECKLIST.md`). This team is itself the UX team: it builds the shared shell and the
SMOKE-1 fixes to Daniel's guide, and Daniel's "design debt" list feeds section 9.

## 2. What this area covers

The job is to make KPOne easier and calmer for clinic staff on every screen, without changing what any
screen does.

**In scope (the team builds and maintains):**
- The staff app shell: `resources/js/layouts/ClinicLayout.vue`, `AdminLayout.vue`, `app.ts` layout choice,
  `components/workspace/WorkspaceHeader.vue` (header and mobile drawer), `WorkspaceBranchSwitcher.vue`,
  `lib/workspace-navigation.ts`, toasts (`components/ui/sonner/*`, `lib/flashToast.ts`).
- Cross-screen usability: phone fit (320 to 768 px), dark mode contrast, accessible names, page headings,
  focus order, labels, plain wording, empty states.
- The remaining SMOKE-1 findings (BASELINE row SMOKE-1): S1-004, S1-005, S1-009 to S1-015, S1-017.
  The SMOKE-1 report itself is not in the repo; reproduce each finding from the descriptions below.
- The Malay staff quick guides under `docs/guides/ms/` (`README.md`, `ca.md`, `doktor.md`, `penyelia-ca.md`,
  `bila-ada-masalah.md`): keep them in step with shipped screens. They stay marked "Draf untuk semakan klinik".

**Shared with other teams (change only through a small agreed change, told to B.E.R.N.I.S.S first):**
- Any page owned by another team (Registration and Patient: Patients & Registration; Dispensary: Dispensary &
  OTC; Inventory; Billing; Panel; Queue and TV: Queue & TV; Agents: Agent platform). A UX fix on their page is a
  small, layout-or-wording-only change, and the owning team is told through B.E.R.N.I.S.S before the PR.
- Clinical pages (encounter, Medical Certificate links): no clinical team yet; small UX fixes allowed, tell B.E.R.N.I.S.S.
- Insights pages: no team yet; small layout fixes allowed.
- Permissions catalogue: never changed by this team.

## 3. Where the code lives

| Kind | Files |
|---|---|
| Shell | `resources/js/layouts/*`, `resources/js/components/workspace/*`, `resources/js/lib/workspace-navigation.ts`, `resources/js/components/ui/sonner/*`, `resources/js/lib/flashToast.ts`. Starter-kit leftovers (`AppShell.vue`, `AppHeader.vue`, `AppSidebar.vue`, `components/ui/sidebar/*`) are not the staff header. |
| Guides | `docs/guides/ms/*` |
| Tests | frontend `r1c5-final-responsive-accessibility`, `r1c4-main-menu-navigation`, `nav-02-top-bar`, `oh-06d-dialog-hierarchy-and-navigation`, `reg-1-registration-phone-fit`, `dark-1-*`; add one test file per slice |

## 4. Permissions

This team adds and changes no permission. Server checks stay the authority; hiding or showing a control is a
usability aid only and must follow the permission the backend already enforces.

## 5. What is built (history)

| Slice | What it did | PR |
|---|---|---|
| R1C5 | Responsive and accessibility pass | #13 |
| NAV-02 | Shorter role-based top bar; everything else through Clinic management | #121 |
| UI-POLISH-1 / UI-LEFTOVERS-1 | Labels, guidance, wording fixes from DRY-RUN-1 | #195, #196 |
| DARK-1 | Dark-mode contrast on four pages (S1-001, S1-006, S1-007, S1-008) | #207 |
| Guides | Draft Malay quick guides for CA, doctor, CA Supervisor | #197, #199, #203 |
| UX-1 | Phone annoyances: the mobile drawer closes after a link is followed (S1-017); staff toasts show at the top centre under the header at every width instead of over sticky bottom actions (S1-015; `lib/toastPlacement.ts`, Clinic and Admin layouts only, public QR layout unchanged); Insights ranking cards fit 320 to 768 px with no sideways page scroll (S1-004). Frontend only; apart from the toast position, 1440 px unchanged | #241 |
| UX-2 | Medical certificates link (S1-005): the visit page offers it only when the visit has a consultation record, using one new boolean `visit.hasConsultation` from `VisitDirectoryService::detail` (owner decision 2026-11-05; Patients & Registration told through LUCY); otherwise a quiet line says why: "Medical certificates are available after the doctor starts the consultation." or, on a cancelled visit, "No medical certificate: this visit was cancelled." (design reviewed by Daniel). The visit history page offers it only when it holds a consultation record. No route or permission change | #254 |
| UX-3 | Screen-reader names and page headings (S1-009 to S1-014): a scripted pass (Chromium accessibility tree, 11 demo roles, 120 pages at 1440 and 390 px) found 8 pages with problems and finds none after the change; the review added the Patient identifier form, which only opens after a click, and a truer name for the Quick create list. Added a visually hidden `h1` to the Registration board, Consultation queue and TV screen; hidden labels on the Registration, Queue and Patient search boxes; names on the Billing approval limit fields, the Panel claim due days field, the Quick create results list (named by its visible caption), and the Patient identifier fields (the Pricing publish amount is left to Catalogue & settings' CAT-1). Frontend only; nothing changes on screen | #265 |
| UX-4 | Malay staff quick guides (`docs/guides/ms/`) brought up to date with screens merged since PR #203, read from the code: CA (tab counts, waiting colours, OTC allergy details, Dispensary allergy warning, label batch and expiry, controlled marker and REPRINT, Panel shortfall, Recorded by, Pay later to collect, Daily cash closing count, treatment steps, treatment-room usage, barcode scan, receiving transfers, proposing a merge, the Medical certificates line, toasts at the top), doctor (waiting colours, tab counts, treatment steps, MC links) and CA Supervisor (cash closing approval, refunds, transfer differences, supplier returns, usage reversal, drug groups, controlled flag, pricing, merges, change history, scanning). Docs only; still drafts for clinic review | pending |

## 6. Owner decisions already made (never ask again)

1. Registration and Patient screens use English labels; "Panggil semula" stays (2026-11-05). Staff UI text is
   otherwise Bahasa Melayu unless the surrounding screen is English (CLAUDE.md).
2. The Malay guides are drafts for clinic review; a CA, a doctor and a CA Supervisor must follow them on the
   real screens before use.
3. The backlog in section 9 was chosen by the owner (2026-11-05).
4. A "ship it" or decision relayed by B.E.R.N.I.S.S (formerly LUCY) counts as the owner's own (owner rule 2026-11-05).
5. Staff toasts show at the top centre at every width, desktop included, never over a sticky bottom action bar
   (owner decision 2026-11-05, UX-1).
6. UX-2 adds one backend field, `visit.hasConsultation` (true when a clinical encounter exists), so the Medical
   certificates link shows exactly when its page works (owner decision 2026-11-05, relayed by LUCY).
7. Backup push: whenever the team stops to wait for the owner with work not yet on GitHub, it commits that work to
   the piece's own feature branch and pushes it as a backup. No PR, never to `main` or another team's branch,
   never merge, never force-push. On a branch with an open PR, push only when the change is ready (it re-runs CI).
   The PR still opens only after "ship it", with the latest `origin/main` merged first. Confirmed by the owner
   directly in the Staff Experience session (2026-11-05).
8. UX-5: on the consultation page (focus mode), toasts show at the bottom left from 1024 px; below 1024 px and on
   every other page decision 5 still applies. The owner approved the matching change to the UX-1 ClinicLayout
   toast assertion (2026-11-05).

## 7. Open items and known limits

- SMOKE-1 open findings: S1-016 mixed Malay and English labels (Registration and Patient done in REG-2;
  "Panggil semula" kept). S1-004, S1-015 and S1-017 are fixed by UX-1 (PR #241); S1-005 by UX-2 (PR #254);
  S1-009 to S1-014 by UX-3 (PR #265).
- UX-2 note: the Call In to consultation gap (when the link was still a 404) is closed by `visit.hasConsultation`.
  On the history page a doctor who was not the attending clinician can still see the link and get 404
  (own-scope permission, from reading `MedicalCertificateAuthority::lock`, not measured); the link follows the
  existing permission check only.
- Design debt for Daniel (UX-2): on the visit page the Medical certificates line, like the Attendance
  confirmation links before it, sits above the page title (`h1`), against the checklist's "nothing above the
  h1". Pre-existing placement on a Patients & Registration page; left unchanged.
- UX-1 known limits: at 320 px a Today ranking card can scroll
  inside the card when every column holds a large ringgit figure; the page itself does not scroll sideways.
  Long ranking names are cut with "…" on phones (full name in the tooltip and in View all).
- Consultation page toasts (UX-5, measured 2026-11-05): from 1280 to 1920 px the bottom-left toast covers no
  control wherever the page is scrolled; at 1024 px with the page at the very top it briefly covers "Choose
  preset". The toast is capped at 320 px (Daniel's design review) so at 1024 px it ends before "Save clinical
  record"; with the page at the top it still briefly sits over the bottom-left of the clinical note box and its
  character count. Below 1024 px the toast stays at the top, so on a tablet or phone scrolled to the top it still briefly
  covers the toolbar links and buttons (Medical certificates, Panggil semula, Start treatment step, On Hold).
  Complete Consultation is clear at every width.
- UX-1 review note: Quick create (`Registration/Create.vue`) keeps its Register Visit card sticky at `max-lg:top-16`
  (64 px), the same height as the staff toast, so a flash toast there would briefly cover it. That page
  fires no toast of its own today; owned by Patients & Registration, so left unchanged.
- UX-3 limits: the pass opens pages, not dialogs, menus or forms that appear after a click; a code scan found no
  unnamed icon button on staff screens, and the review checked the click-to-open forms. The demo data has no
  Administrator account, so its pages were checked as the Director. The fields named only by their placeholder (the
  clinical note box, the visit cancellation reason, the Patient list "Issuer" box, the inline add fields in
  `catalogue/LocationPicker.vue`, `SupplierPicker.vue` and `PanelTariffEditor.vue`) are named by UX-6, which
  also found and named the six Panel form address fields (a pass over 431 pages as 9 roles). Also for the owning teams,
  not fixed here: the Quick create list holds wrapper divs and WhatsApp links besides its options and its search
  box is not a combobox; the Panel due-rule radios share no `name`; Billing approval limit Save and Clear
  buttons repeat the same name on every row. The public QR form's back buttons (`pages/PublicCheckIn/Show.vue`)
  name only their icon: a patient-facing page owned by Patients & Registration, passed to LUCY.
- UX-4 note for Dispensary & OTC: the OTC allergy card still says the check is "compared with medicine names by
  text only", but since DSP-1b it also compares catalogue drug groups. Passed to B.E.R.N.I.S.S; not changed here.
  The guides still need a CA, a doctor and a CA Supervisor to follow them on the real screens before use.
- UX-7 known limits: a failed submit on a long form (Quick create, Patient create) inserts several alerts at
  once; NVDA and JAWS read them in turn, VoiceOver may read only the last. A later step could move focus to the
  first invalid field or show one error summary. An identical error returned again on resubmit is not read
  again (nothing in the page changes). Billing's `pages/Billing/Invoice.vue` wraps the documents list in
  `aria-live="polite"`, so the IssuePrintButton error sits inside a second live region; most screen readers read
  it once, some NVDA and Firefox versions twice (Billing team's file, left unchanged). The Panel claim create
  summary (`pages/PanelClaims/Create.vue`, Panel team's file) now announces only the errors no field announces.
- `/patients/create` is about 433 px wide at 390 px when errors show: Patients & Registration team's item, not ours.

## 8. Rules (every task)

- Read `CLAUDE.md`, `AGENTS.md`, `docs/BASELINE.md` and this file first.
- Never open, print or edit `.env`. Synthetic `.test` data only.
- Layout, wording and accessibility only: no backend behaviour, payload, route, permission or data change
  unless the backlog item says so. Keep the KPOne theme, light and dark.
- Do not change any existing test assertion without the owner's approval; list each one first (exact-text
  tests are common on these screens).
- Full gate before hand-off, on a task-owned PostgreSQL 18 database (verify host, port and name first): backend
  tests, Pint, PHPStan, frontend types, lint, format, build, frontend tests, `migrate:fresh --seed`,
  `git diff --check`; screenshots at 320, 390, 768 and 1440 px, light and dark, before and after, for every
  screen touched.
- PHPStan stopgap until the session hook installs it: `git clone -q --depth 1 --branch 2.2.8
  https://github.com/phpstan/phpstan.git /tmp/phpstan-src && mkdir -p vendor/phpstan/phpstan && cp -r
  /tmp/phpstan-src/{phpstan,phpstan.phar,bootstrap.php,conf} vendor/phpstan/phpstan/`, then
  `kphp -d memory_limit=2G vendor/phpstan/phpstan/phpstan analyse --memory-limit=2G --no-progress <changed files>`.
  About 25 "str_starts_with expects non-empty-string" errors are known local noise.
- Independent review by the Reviewer (a subagent given the diff, this file and the rules) before hand-off.
- Send every new page or layout change to Daniel for a look through B.E.R.N.I.S.S before pushing; when a slice settles a
  pattern (for example toast placement or the drawer), tell B.E.R.N.I.S.S so Daniel can add it to the guide (owner
  decision 2026-11-05).
- Commit, push and PR only after the owner says "ship it"; never merge.
- A "ship it" or owner decision relayed by B.E.R.N.I.S.S (the coordinator session, formerly LUCY) counts as the owner's own (owner rule 2026-11-05); stop and ask if it conflicts with something the owner told you directly.
- Never edit `docs/BASELINE.md`; record finished pieces here and flag any change in the PR description. The coordinator updates BASELINE in one docs PR per batch (owner rule 2026-11-05).
- Record every new owner decision in section 6 and every finished piece in section 5, in the same PR.

## 9. Backlog (next pieces, in order)

Chosen by the owner on 2026-11-05; built one by one in this order, each its own PR. Choosing them is the owner's
authorisation to build them; the coordinator records it in `docs/BASELINE.md`.

1. **UX-1 Phone annoyances.** (a) S1-017: the staff mobile drawer (the `<Sheet>` in
   `components/workspace/WorkspaceHeader.vue`) closes when a link in it is followed. (b) S1-015: a success
   toast no longer covers the Complete Consultation button at 390 px (toast position or offset on small screens,
   for every page, not only the consultation page). (c) S1-004: the Insights ranking cards fit a 390 px screen.
   Prove no sideways scroll at 320, 390 and 768 px.
2. **UX-2 Dead link.** S1-005: the Medical Certificates link is offered before an encounter exists and returns
   404. Show it only when it can work (from data the page already has), or explain why it is not available yet.
   No permission or route change.
3. **UX-3 Screen-reader labels and page headings.** S1-009 to S1-014: every icon-only button and unlabeled
   control gets an accessible name, and every staff page has one clear `h1`. Find them with a scripted
   headless pass (axe-style checks are fine as a local script; add no new runtime dependency).
4. **UX-4 Malay guides up to date.** Update `docs/guides/ms/` for screens shipped on 2026-11-05: daily cash
   closing (BIL-4/BIL-4b), pay-later collection list (BIL-3), receipt "recorded by" (BIL-2), Panel shortfall
   (BIL-1b), allergy warning and drug groups at Dispensary (DSP-1, DSP-1b), controlled-medicine marker and batch
   and expiry on labels (DSP-2, DSP-3), transfer shortages (STK-6), supplier returns (STK-7), English
   Registration messages (REG-2), and any other merged screen staff use. Follow the real screens; mark anything
   unconfirmed `[PERLU DISAHKAN]`. Ask B.E.R.N.I.S.S for the list of merged PRs since the last guide update (PR #203).
5. **UX-5 Consultation page toasts.** Chosen by the owner on 2026-11-05 from the UX-1 known limit: on the
   consultation page from 1024 px, toasts move to the bottom left so they no longer cover the toolbar.
6. **UX-6 Placeholder-only field names.** Chosen by the owner on 2026-11-05 from the UX-3 leftovers: give each
   field that is named only by its placeholder a real accessible name. Nothing changes on screen.
7. **UX-7 Announce form errors.** Chosen by the owner on 2026-11-06 from Aiman's UX-6 review note: the shared
   `InputError` inserts its message as `role="alert"` so screen readers read each error when it appears, and
   callers no longer add a second alert around it (seven places, in Patients & Registration and Billing files;
   the Panel claim create summary now announces only errors no field announces).
   Nothing changes on screen. Linking every field to its error with `aria-describedby` is left to the owning
   teams; the existing links still work.
8. **UX-8 Dark-mode browser controls (design debt DD-07).** Chosen by the owner on 2026-11-06: `app.css` sets
   `color-scheme` (light on `:root`, dark on `.dark`, light again for printed documents and labels), so the
   browser draws its own control parts for the theme showing. The date and time picker icons were dark on dark
   and could not be seen; plain checkboxes and radios were glaring white in dark mode. Light mode is unchanged
   (screenshots identical). `docs/design/DEBT.md` belongs to the Design team, so DD-07 is closed there by Daniel.
   The shell's inline first-paint style sets the same, so scrollbars are right before `app.css` loads. The TV
   screen keeps following the TV account's setting rather than its own light/dark button (no form controls
   there; noted to Queue & TV).
9. **UX-9 Shared branch report page (design debt DD-23).** Chosen by the owner on 2026-11-06. On
   `pages/Reports/BranchReport.vue` (Daily summary, Panel summary, Stock movement, Transaction detail): the
   standard `OperationalTable`; columns whose filled cells are all numbers are right-aligned
   (`lib/report-cells.ts`); dates read "5 Nov 2026" and local date-times "5 Nov 2026, 17:36" on screen, with the
   original in `datetime`; identifier columns (SKU, numbers, codes) stay left-aligned even when all digits; the
   long basis text sits behind a closed "How this report counts (read before comparing totals)" disclosure. Downloads
   and the server rows are unchanged. The owner approved two assertion changes in `branch-reports.test.mjs`
   (the scroll box is now checked through `OperationalTable`, and the stock note is found after
   `</OperationalTable>`). The basis wording itself is DD-14 (Billing). After Daniel's review the first column
   (Local date or Row) stays in view while a wide report scrolls sideways. His second nit, the page title
   "Transaction Detail" in sentence case, is left: the title comes from `TransactionReportService` and the
   pilot-scale golden file (`tests/Fixtures/PilotScale/original-small-reports.json`) pins it.
12. **UX-12 Staff page formats (design debt DD-43).** Chosen by the owner on 2026-11-06 from the Lead UI/UX
   Designer's UX-11 review. On the staff page a long work email ran into the Staff number column (by 43 px at
   1280 and 1440 px); it now wraps inside its own column, preferably after the "@", on the staff page and the
   directory. Dates and times use the shared `formatDate` and `formatDateTime` ("1 Jan 2026", "5 Nov 2026, 5:16 PM",
   Kuala Lumpur time) instead of the browser's own format and time zone, and an assignment reads "From 1 Jan 2026,
   no end date" or "1 Jan 2026 to 31 Dec 2026 (inclusive)". No assertion changed.

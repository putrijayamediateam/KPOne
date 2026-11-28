# Staff Experience builder team

Status: **set up with the owner on 2026-11-05.** This folder is the team's memory: what is built, what
the owner decided, the rules, and what to build next. The team reads it before every task and updates it in
the same PR as the code.

## 0. Handoff for a fresh session (written 27 Nov 2026, by Kavitha and Aiman)

**Read this first.** The owner moved every team to a fresh session on a new model, one slice per session (B.E.R.N.I.S.S, 27 Nov).
Sections 1 to 9 below are the older memory; this section is what is true now.

### What the owner decided on 25 Nov (Clean Look replaces Classic)
- The whole frontend moves to the owner's locked "KPOne Clean Look" canvas (design https://claude.ai/artifact/Jyr8UV231y4roWRyyqxxRJ,
  clickable demo https://claude.ai/artifact/KUkn7hc8xW9k2REtRUCFbG). **In place**: same shared components, tokens and files; no second
  version, no switch, no duplicate page. A PR that adds a parallel page or component is refused. The spec is
  `docs/design/CLEAN-LOOK-SPEC.md` (Daniel). Deadline for the live CA test at Puchong: **Mon 30 Nov 08:30 MYT.**
- Colours: **blue** for everything interactive: `--primary` `#006ae0` (fills and text, 5.07:1), `--accent-blue` `#007aff` (non-text only: rail
  icon, selection wash, focus ring), `--link` `#006ae0`; pink stays on the logo mark only. Dark: `#4d94ff` and `#6ba6ff`.
- Process: Daniel's look and the Reviewer's OK, green CI on the exact head with main contained, then B.E.R.N.I.S.S merges; the owner gives one
  ship-it per group and approves changed class-string tests as a group. Changed existing assertions are listed in the PR body and to
  B.E.R.N.I.S.S **first**. Do not refresh main until B.E.R.N.I.S.S calls your turn. A PR opens only after the owner's ship-it. Teams never merge.
- New rule (owner): a migration that grants a permission to `ca` must also grant it to `medical_assistant`.
- Owner answers: build everything before Monday; the red-zone alert backend is Amirul's (the homes show his alert, they do not build their own);
  the CA Supervisor home is built from the CA home pieces.

### Merged since the last section 5 entry (all on `main`)
APPR-1a approvals inbox (#1062), Branch Manager aggregate reports (#1061), My day DAY-1a, DAY-1b, DAY-1c (#1063, #1064, #1065),
INS-1 Insights decoupling (#1092), DisclosureText clamp fix (#586), HR-22d roster station on My day (#1100, with `StationLink` and the
separate `stationHref` prop), **Clean Look tokens, fonts and ui components (#1120)**, **Clean Look shell: icon rail, hover panel, phone drawer,
server-built `ShellMenu` (#1125)**. Fonts are self-hosted in `public/fonts` (Geist and Inter, OFL licences beside them).

### CL2-HOMES2 (Clean Look stage 2, area homes and Insights; 27 Nov 2026, Kavitha and Aiman)
Brief: restyle the area homes and Insights in place to match the demo; frontend only; no PHP, route, permission, migration, payload or
assertion change. State on 28 Nov 2026: **all three are merged: #1260 (27 Nov, 22:08 UTC), #1280 (27 Nov, 22:27 UTC) and #1278 (28 Nov, 06:02 UTC).**
- **#1260** (merged) `feature/cl2-homes2` (frozen at `b10de601`): `shadow-card` on every `space-card` (`Spaces/Show.vue`, `SpaceAskPanel.vue`,
  `MyRoutine.vue`); Insights cards get the soft shadow; Dashboard pink hover and Overview amber dots use tokens; the routine tick uses
  `--primary`; a greeting line ("Good morning, <first name>") on the department homes and My day; up to four count tiles under the greeting,
  built only from totals already in the props; and, from 1024 px, **balanced two-column CSS columns** (`column-count: 2`) with the header,
  tabs, greeting, tiles, as-of line, Me home and recent activity spanning both. Why columns and not a grid: a row grid with
  `align-items: start` left holes under short cards (Daniel's "right column ends early"). Cards use `break-inside: avoid`.
  The change also restyles the #1135 CA, supervisor and doctor homes; Daniel re-checked them.
- **#1278** (merged) `fix/shell-locum-home`: the rail logo and phone-header logo link to `workspace.defaultUrl` (`/workspace`, which redirects via
  `WorkspaceLandingService`) instead of `/dashboard`, which is 403 for a locum. `WorkspaceHeader.vue` still has `/dashboard` but nothing
  imports it and `r1c4-main-menu-navigation.test.mjs` pins it. Also hardens `NextThings.vue` (only a well-formed poll payload replaces the
  block). The console error `reading 'live'` was **not reproduced** on 11 of 12 roles; the hardening is not a proven root cause.
- **#1280** (merged) `fix/greeting-timezone` (was stacked on #1260; the coordinator retargeted it to `main` after #1260 merged): the greeting follows the
  branch clock, not the device clock (`resources/js/lib/greeting.ts`; My day uses `today.time.timezone`, department homes parse the
  server's `asOf` text, both fall back to the browser clock), with `tests/Frontend/cl2-greeting-timezone.test.mjs`. It changes behaviour,
  so it was taken to the owner separately from the look-only approval.
- **Demo-only, not built** (the demo shows them, KPOne has no data or feature for them): the Director "Needs your approval" card with
  Approve buttons; Finance "Today's money by branch"; the BM live and "My week" cards; the routine ring, tiles, "My week" and "For you"
  cards on the other demo homes; expandable "Are you sure?" confirms. Card re-ordering was not done: each home's first card is already
  its action card.
- **Not done:** no per-page layout parity beyond the shared card style; no 1440 light, 1440 dark and 390 light screenshots from this
  session (Daniel shot the homes from the branch); the dark-mode contrast of the white tick on the dark `--primary` was not measured.
- **Test environment lessons:** this container's `php` is 8.3 and the project needs 8.4. Put a `php` script that execs `kphp` first on the
  `PATH` for `node --test tests/Frontend/*.mjs`, `npm run build` and `types:check`. `kphp` starts Docker on demand; a task-owned
  `postgres:18` on port 55432 plus an env file passed through `KPHP_ENV_FILE` (never `.env`) runs the app, and the demo users
  (`demo.<role>@kpone.test`) come from `KPOnePresentationSeeder`. The DB needs `DB_SSLMODE=prefer` for a local container. Docker and the
  server stop when the session resumes, so restart them.
- **Rules that held:** no existing assertion was changed; a push to a new branch needed the owner's word in the session each time; after the
  coordinator froze #1260, further changes went in new PRs.

### Open PRs (not built by this session; check their state first)
- **#1135** "Restyle homes with shared Clean Look components" (branch `feature/clean-look-homes-publication`, head `e3b14e164` on 26 Nov).
- **#1138** "Fix Queue and Registration filter control sizes" (branch `fix/clean-look-filter-targets`, head `fae04b354` on 26 Nov).

### Unmerged work, pushed (no PR; nothing is only local)
- `feature/home-next-things` at `6892683e4`: the backend for the role homes. `RoleNextThings` (one list "Your next things", a live strip and the
  doctor's own sales line, all from the boards' own services and permissions) and `GET /my-day/next` (`my-day.next`), plus
  `tests/Feature/DepartmentSpace/RoleNextThingsTest.php`. My day already carries it as the `next` prop. **No Vue yet.** Branch from the
  current `main` and merge or cherry-pick it; do not trust it blindly, it predates the Clean Look merges.

### Next slice for this team (Stage 1 and 2 from the spec, section 11)
1. Homes and My day for CA, CA Supervisor and Resident Doctor on the Clean Look components, using `RoleNextThings`, the live strip, the doctor's
   "My sales" card, and Amirul's red-zone alert when it exists. The My day tiles and the pink Clock in come from `space-shell.css` and
   `space-shell-layout.css` and still carry the old colours: they move to tokens here (do not let page teams fix them one by one).
2. Stage 2 (by Sun 29 Nov 18:00): Staff, Admin, AccessControl, Branches, ProfileSettings, PasswordPages, SignIn, AuditLog, HqView, and the
   Director, Finance, Administrator and Office homes (`DirectorHome`, `DirectorOverview`, `HomeFin`, `HomeAdm`, `HomeOffice`). The website
   boards were assigned here too in the spec, tokens only unless time.
3. HR step 0 (from earlier): My day "Waiting for me" for people who hold only to-read tasks (`SopsToRead`, `MemosToRead` extraction and
   APPR-1d slice 1), then TASK-kind approval sources (APPR-1e). Wait for Liyana's branch; the `/approvals` page and the account-button number
   stay deciders only.

### Aiman's follow-ups on the shell (not blocking, not done)
1. `HandleInertiaRequests` builds the shell menu twice per request (`hasDestinations` and `ShellMenu`): build it once and reuse it.
2. The branch switcher is reachable only with the panel open; make it reachable from the closed rail.
3. `Registration/Index.vue` accepts `?tab=` for every board tab; guard a value the person cannot see so no tab is left unselected.
Also noted: a keyboard user tabbing across the rail opens the panel on each icon (usable, noisy); `PageHeader` shows the branch subline only
on pages that use it (Registration and a few pages write their own header, so the rail chip covers them); the Clean Look spec's switch,
sheet, date-field, avatar, breadcrumb, empty state and disclosure components are **not** restyled yet; about 56 pages still write their own
all-caps small table headers (page PRs); the old `WorkspaceHeader` and `AppHeader` files are unused and can go in the Stage 2 cleanup; the
rail has no Waiting count chip (no endpoint). The backend gap for Aisyah: the canvas "Pharmacy" board is a list page and no `/dispensary`
list route exists (the rail links to the Registration board's Dispensary tab).

### Tests that guard the look
`clean-look-tokens.test.mjs` (contrast of every text and control pair, light and dark; blue accent; self-hosted fonts),
`clean-look-shell.test.mjs`, `ShellMenuTest`, and the `classic-only-co*-kept*.test.mjs` structure pins (the shell changed
`classic-only-co10-kept-layouts`: the layouts now render `WorkspaceRail`). The **Classic guard test** is the owner's decision of 25 Nov: a new test, to be added on Sun 29 Nov after Daniel's sweep, that fails if
old Classic styles come back (12 px card corners, the pink accent, retired classes). It may not change existing assertions without the owner's
approval. It does not exist yet. Class-string pins will keep changing as pages move; list each one in the PR body.

### Working notes for a fresh session
- Never open or write a `.env`. Cloud sessions use `kphp` for PHP 8.4. The Wayfinder routes need PHP 8.4: with the host PHP 8.3 put a `php` shim
  that runs the Docker PHP first on `PATH` before `npm run build`, and run the frontend tests the same way (two tests read generated routes).
- GD is missing locally, so image-based PHP tests run in CI only. A PHP test needs the task-owned PostgreSQL container; start Docker and the
  container first (it stops between turns).
- Screenshots: run the app against a **task-owned** database with explicit DB env (never plain `php artisan serve`); the `APP_URL` port must match
  the port you serve on; serve static files from `public` through a router that returns false for existing files.
- A red `ci` check on an old head is usually a cancelled run from a newer push: check the job conclusions before fixing anything.
- DisciplineTest line 69 and `PharmacySpaceTest` pinned strings that the shell menu now puts on every page; the shell menu links are shared page props.

## 1. Team

| Role | Name | Job |
|---|---|---|
| Lead | **Kavitha** | Plans each piece, builds it, keeps this file current |
| Reviewer | **Aiman** | Checks every piece against the rules below, the tests and the owner's decisions before it reaches the owner |
| Classic-only Lead | **Haris** | Removed the Version 2 (V2) layout slice by slice so everyone sees Classic, losing no function (CO-1 to CO-10c, all merged by PR #1059 on 2026-11-22) |
| Classic-only Reviewer | **Nadiah** | Reviews every Classic-only plan and slice for missed files, broken links, permission gaps and lost functions |

**Added 2026-11-20 (owner request):** Haris and Nadiah joined this team from a short-term session run on 19 and 20 Nov 2026. The shared shell and layouts are this team's area, so retiring V2 (plan: `docs/design/CLASSIC-ONLY-PLAN.md`) sits here.

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
| UX-5 | Consultation page toasts: in focus mode from 1024 px, toasts show at the bottom left, at most 20 rem wide, so they no longer cover the doctor's toolbar (owner decision; the one approved toast exception in the design guide) | #277 |
| UX-6 | Thirteen fields named only by their placeholder get a real accessible name, including the clinical note, the visit cancellation reason, the passport issuer, the picker add fields and the Panel address fields | #287 |
| UX-7 | The shared `InputError` inserts its message with `role="alert"`, so screen readers announce form errors; seven duplicate alerts removed | #306 |
| UX-8 | Dark-mode browser controls (DD-07): `app.css` sets `color-scheme` per theme, so date and time picker icons, checkboxes and radios read in dark mode; prints stay light | #310 |
| UX-9 | Shared branch report page (DD-23): `OperationalTable`, right-aligned number columns, KPOne date formats, the counting rules behind a disclosure, a sticky first column | #332 |
| UX-10 | Shared confirm dialog colours: theme surface and border instead of a fixed navy in dark mode, and a default icon (red warning for a destructive question) | #333 |
| UX-11 | Staff pages' pale panels and emerald accents in dark mode (DD-08) moved to theme tokens | #337 |
| UX-12 | Staff page formats (DD-43): long emails wrap in their column; dates and times in the shared KPOne formats | #346 |
| UX-13 | Shared shell colours (DD-20): top bar marker and wordmark, branch switcher selection and `InputError` on `brand`, `brand-strong` and `destructive`; the drawer's active marker now shows | #348 |
| UX-14 | Malay guides for the 100 × 62 mm landscape drug label (CA and CA Supervisor) | #357 |
| UX-15 | Shared `PageHeader` on the Staff pages and Clinic management (DD-17, part), with optional `title-extra` and `description` slots | #369 |
| UX-16 | Confirm dialog's non-destructive tile on the brand tokens; closes DD-20 | #365 |
| CO-1 | Classic only: the "Try the new layout" switch and the new-look preview are no longer offered; everyone sees Classic, and the department spaces and Me home open in the Classic layout (`user_space_preferences` kept, no longer read) | #1000 |
| CO-3 | Shared components back to Classic only (the V2 breadcrumb and other V2 branches in shared code removed) | #1002 |
| CO-4 | V2 front desk and clinical pages removed (Registration, Queue, Consultation, Dispensary and related) | #1003 |
| CO-5 | V2 money pages removed (Billing, Panel and reports) | #1004 |
| CO-6 | V2 Inventory pages removed | #1005 |
| CO-7 | V2 HR pages removed; the HR composables kept for the Classic pages | #1009 |
| CO-8 | V2 catalogue, pricing, settings, staff, admin, document and agent pages removed | #1034 |
| CO-9 | V2 public check-in pages removed (never shown) and the V2 page registry emptied | #1044 |
| CO-10a | The agent office's V2 control-room look removed | #1052 |
| CO-10b | The V2 machinery removed: `components/v2`, the V2 shell, `V2Layout`, `useUiVersion`, `lib/v2-*`, `v2.css`, `UiVersionResolver`, `V2ShellNavigation`, `config/v2-pages.php`, the Geist font and the V2 shared props | #1056 |
| CO-10c | The last V2 leftovers: unused V2-only props and options, seven backend fields and the inline "add a Panel" route (`POST /catalogue-panels`), and stale V2 comments | #1059 |
| CL2-HOMES2 | Clean Look stage 2 for the area homes and Insights: card shadow, balanced two-column card flow, greeting, count tiles; logo to the role's landing page; branch-clock greeting (see section 0) | #1260, #1278, #1280 |

**Classic only: done (2026-11-22).** Every slice of `docs/design/CLASSIC-ONLY-PLAN.md` is merged (its section 8 lists them). Each slice listed its changed or deleted test assertions and had the owner's approval before the commit that changed them. Owner, 22 Nov 2026: keep the unused `user_space_preferences` table (plan Q4), so there is no CO-11. The Classic leave warning (section 7, item 17) remains its own later slice.

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
- **DD-84 follow-up (owner: "classic later"):** the refused-request notice (403 and 419) is V2 only. Classic still shows
  Inertia's raw error page in a modal. Giving Classic the same handler is a separate, later piece, not yet started
  (see `lib/v2-refused-request.ts`; Classic is proven untouched by a real-app check).

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
10. **UX-10 Confirm dialog colours.** Relayed by the coordinator for the owner on 2026-11-06 (
   Yasmin's token string): the shared `OperationalConfirmDialog` uses `bg-popover` and `dark:border-border`
   instead of the fixed `dark:bg-slate-900`, matching the Queue & TV Call next dialog. Light mode: the dialog
   is white instead of the page grey; dark mode: neutral near-black with a full-strength border instead of navy.
   After Daniel's review the icon tile is never empty: seven dialogs on six pages passed no icon, so the tile
   showed as a blank square; the shared component now falls back to a warning icon (destructive) or a question
   icon, and a destructive question uses the destructive tone. No assertion changed.
11. **UX-11 Pale panels in dark mode (design debt DD-08, Staff pages).** Chosen by the owner on 2026-11-06. The
   Staff pages (directory, staff page, create and edit, and the role and branch dialogs) used pale palette
   backgrounds and fixed emerald accents with no dark style, so the header icon tiles and the Primary, Active and
   assignment-state badges stayed light in dark mode. They, the checkboxes, the search focus ring, the audit-list
   rule and "No current assignment" now use the theme tokens (`success`, `warning-strong`, `brand`, `muted`,
   `ring`), as `StatusBadge` does. The shared `PatientBoard` chips were left to Queue & TV (DD-38, PR #331, and
   Cancel Visit in PR #336), as the Lead UI/UX Designer asked. No assertion changed.
12. **UX-12 Staff page formats (design debt DD-43).** Chosen by the owner on 2026-11-06 from the Lead UI/UX
   Designer's UX-11 review. On the staff page a long work email ran into the Staff number column (by 43 px at
   1280 and 1440 px); it now wraps inside its own column, preferably after the "@", on the staff page and the
   directory. Dates and times use the shared `formatDate` and `formatDateTime` ("1 Jan 2026", "5 Nov 2026, 5:16 PM",
   Kuala Lumpur time) instead of the browser's own format and time zone, and an assignment reads "From 1 Jan 2026,
   no end date" or "1 Jan 2026 to 31 Dec 2026 (inclusive)". No assertion changed.
13. **UX-13 Shared shell colour tokens (design debt DD-20).** Chosen by the owner on 2026-11-06. The top bar (active
   link marker in the menu and the drawer, the "KPOne" wordmark), the branch switcher's selected row and the shared
   `InputError` used fixed palette colours (`pink-600`, `pink-700`, `pink-50/800/950/200`, `text-red-600
   dark:text-red-500`). They now use `brand`, `brand-strong` and `destructive`. Light mode is the same for the top
   bar (the tokens equal those pinks) and a slightly deeper red for errors; in dark mode the wordmark and the
   selected branch are a lighter, readable pink. The confirm dialog's non-destructive pink tile followed in UX-16. No assertion changed.
14. **UX-14 Malay guides for the landscape drug label.** Chosen by the owner on 2026-11-06, handed over by Dispensary &
   OTC after DSP-10 (PR #344) and the "Print label" casing fix (PR #321). `docs/guides/ms/ca.md` and
   `docs/guides/ms/penyelia-ca.md` now describe the 100 × 62 mm landscape label: "Print label", the expiry printed as
   "EXP: dd/mm/yyyy" only for exactly one batch (otherwise "EXP: -"), no batch number, "Ubat Terkawal/Controlled
   Medicine" below the border, and dosage, frequency and instruction sharing 3 rows with Route not printed. Every
   quoted string was checked against the merged code. Docs only.
15. **UX-15 Shared page header on the Staff Experience pages (design debt DD-17, part).** Approved by
   B.E.R.N.I.S.S for the owner on 2026-11-06. The staff directory, the staff page, Create staff, Edit staff and
   Clinic management drew their own titles (Clinic management at `text-lg`, the Staff pages with an optional
   icon tile). They now start with the shared `PageHeader`: one `h1` at `text-2xl`, one description line and the
   actions on the right; the icon tiles are dropped, as the guide allows. `PageHeader` gains two optional
   slots, `title-extra` (the staff page's Active or Inactive badge) and `description` (the staff number and
   email line, which keeps its wrapping); pages that use neither render as before. Layout only: no data,
   permission or route change. Other teams' pages keep their own titles for now. The Lead UI/UX Designer's optional nit, a small back link above the
   title instead of "← Directory" among the actions, waits for a back-link slot in `PageHeader`. No assertion changed.
16. **UX-16 Confirm dialog tile token (the rest of design debt DD-20).** Approved by B.E.R.N.I.S.S for the owner
   on 2026-11-06, after UX-10. The shared confirm dialog's icon tile for a non-destructive question used fixed
   pink palette colours (`bg-pink-50 text-pink-700 dark:bg-pink-950/50 dark:text-pink-300`); it now uses the brand tokens
   (`bg-brand/10 text-brand-strong dark:bg-brand/15`), which carry their own dark values. It looks almost the
   same: the icon's contrast on its tile is 5.2:1 in light and 6.9:1 in dark (3:1 needed for an icon). A
   destructive question keeps its red tile. No assertion changed.
17. **Classic leave warning on long forms (not built yet).** Recorded with the owner's approval on 2026-11-20 (CO-3): an unsaved-changes prompt built fresh on Classic's `OperationalConfirmDialog` (not ported from V2), on chosen pages only (consultation note, Register visit, Billing adjustments, HR forms), with the Lead UI/UX Designer's look, as its own slice after CO-10.

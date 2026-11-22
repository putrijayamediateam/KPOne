# Classic only: plan to remove Version 2

**Status: done (2026-11-22).** Every slice is merged; see section 8. The sections below are the plan as approved.

Owner decision, 19 Nov 2026: remove the "Try the new layout" Version 2 (V2) look, so every person always sees
Classic, and lose no function. This file is the Phase 1 survey and plan, reviewed by Nadiah (her ten findings are folded in), from the Classic-only team (Haris, Lead;
Nadiah, Reviewer). **It removes nothing.** Nothing is built until the owner approves it through the coordinator.

Surveyed at `origin/main` `6acd9d3` (PR #993). PR #996 (Stock value in Classic) is open, not merged.

## 0. How V2 works today (evidence)

- One backend for both looks. `AppServiceProvider::boot()` calls `Inertia::transformComponentUsing()`, which asks
  `UiVersionResolver::pageFor()` for the page name. A page listed in `config/v2-pages.php` `pages` (95 pages) becomes
  `<Dir>/V2/<Name>` when `isV2()` is true. Controllers, routes, permissions and props are the same.
- `isV2()` is true when the person's saved switch is on (`user_space_preferences.enabled`) **or the request is any
  `/spaces` page**, except for the waiting-room TV account (never offered).
- `public_pages` (the two patient QR pages) follow one global switch, `'public' => false`, so no patient has ever
  seen a V2 QR page.
- The "New look (preview)" pilot (`user_space_preferences.new_look`, `data-look="new"`) only applies inside V2.
- `resources/views/app.blade.php` sets `data-ui="v2"` on `<html>` with the same rule; `resources/css/app.css` has the
  `v2:` Tailwind variant and the V2 and new-look colour blocks.
- `HandleInertiaRequests` shares `workspace.spaces` (switch state), `workspace.newLook` and `workspace.shell` (the V2
  sidebar from `V2ShellNavigation`, only in V2).
- `ClinicLayout` and `AdminLayout` draw `V2AppShell` when `useUiVersion()` says V2;
  `resources/js/app.ts` picks `V2Layout` for V2 pages.

**The trap is real.** Classic's account menu has a "Me" row (`AccountMenuLinks`, every person who can enter Me)
linking to `/spaces/me`. Because `isV2()` is true for every `/spaces` page, **a Classic person who clicks "Me" lands
in the V2 shell**, with the V2 sidebar and the department spaces in it. So the spaces are used from Classic today;
they are not a V2-only corner that can simply be deleted.

## 1. Every V2 artefact and what to do with it

### 1.1 Pages (106 files under `resources/js/pages/**/V2/`, `.vue` and `.ts`)

| Artefact | Action | Reason |
|---|---|---|
| 95 staff V2 pages listed in `config/v2-pages.php` `pages`, plus `Dispensary/V2/Partials/*` (6 `.vue`, 3 `.ts`) (Registration, RegistrationReview, PublicCheckInLinks, Patient, Queue, Clinical, Dispensary, Billing, PanelClaims, Pricing, Medicine, ClinicalService, Inventory (19), HR (23), Agents (5), Staff (4), settings pages, ClinicManagement, AuditLogs, Branches, AccessControl, Attendance, MC, Referrals, Visits/History, QueueDisplay/Settings) | **Remove** | Every one has a Classic page at the same name (checked: 95 of 95 exist). No V2 page reads a prop that its Classic page does not (scripted check; only `Billing/V2/Show` reads the shared `branchContext` for its own completion dialog, and `Pricing/V2/PriceHistory` reads `timezone`, which Classic shows its own way). Functions only in V2 are in section 2. |
| `PublicCheckIn/V2/Show.vue`, `PublicCheckIn/V2/Status.vue` | **Remove** | Never shown: `'public' => false`. No patient loses anything. |

### 1.2 Components, composables, libraries, layouts (124 non-page files)

| Artefact | Action | Reason |
|---|---|---|
| `components/v2/*` (34 files) | **Remove**, except `V2MoneyInput.vue`, which **moves to Classic** (renamed `MoneyInput.vue` under `components/ui` or `components/billing`) | Classic `components/billing/PanelAdjustmentPanel.vue` imports `V2MoneyInput`. |
| `components/*-v2/*`, `components/*/v2/*`, `components/hr/**/V2*.vue` (agents, billing, clinical-service, clinical, hr, hr attendance, hr credentials, hr records, inventory, medicine, panel, patient, public, queue, registration, staff, visits) | **Remove** | Used only by V2 pages (scripted import check). |
| `components/workspace/V2AppShell.vue`, `V2ShellNav.vue`, `V2JumpDialog.vue`, `V2NewLookSidebar.vue`, `V2NewLookRailButton.vue` | **Remove** | V2 sidebar and the new-look pilot. Classic has its own top bar and the Clinic management hub with search. |
| `components/workspace/V2PageCrumbs.vue` | **Remove**, after taking it out of Classic `components/ui/page-frame/PageFrame.vue` | PageFrame imports it (it renders only in V2). |
| `layouts/V2Layout.vue` and its branch in `app.ts` | **Remove** | |
| `layouts/SpacePassThroughLayout.vue` | **Remove** once Spaces sit under `ClinicLayout` (Q1 = keep) | Only `Spaces/Show.vue` uses it. It also mounts `ClockPhotoDialog`, which Me home's photo Clock in needs: the Classic layout that replaces it must mount the dialog (`ClinicLayout` already does; verify in the slice). |
| `composables/useUiVersion.ts`, `lib/ui-version.ts` | **Remove** in the last slice | Used by `ClinicLayout`, `AdminLayout`, `agents/office/OfficeScene.vue`, Classic `Dispensary/ReturnsToCheck.vue`; each becomes plain Classic. |
| `composables/billing/useBillingWorkspaceV2.ts`, `clinical/useConsultationV2.ts`, `queue/useQueueBoardV2.ts`, `composables/inventory-v2/*` | **Remove** | V2 pages only. |
| `lib/v2-leave-popstate.ts`, `lib/v2-leave-rule.ts` | **Move to Classic** (rename `leave-popstate.ts`, `leave-rule.ts`) | Classic `composables/useLeaveGuard.ts` imports both. |
| `lib/registration-v2.ts` | **Move** the `RowAction` type to a Classic file, then remove | Classic `composables/registration/useRegistrationRowActions.ts` imports the type. |
| `lib/v2-spaces.ts` | Depends on Q1 | Only `Spaces/Show.vue` uses it. |
| Other `lib/*v2*` (`billing-v2`, `hr-attendance-v2`, `hr-roster-v2`, `inventory-v2-words`, `panel-claims-v2`, `patient-v2`, `queue-v2`, `v2-date*`, `v2-money`, `v2-refused-*`, `v2-staff-history`, `v2-time`, `v2-week-grid`) | **Remove** | V2 only. Each slice re-checks with `grep` before deleting. |
| `v2:` class variant used in 5 Classic files (`WorkspaceHeader`, `WorkspaceBranchSwitcher`, `BillingWorkspace`, `Billing/Invoice`, `Dispensary/ReturnsToCheck`) | **Strip the `v2:` classes** (Classic classes stay as they are) | They never apply once `data-ui="v2"` is gone; leaving them is dead code. |
| **Not V2, do not touch:** the `v2` in `lib/inertia-auth-history-boundary.ts` storage keys (`kpone:authentication-history:…:v2`) and the `^v2:organisation:` draft-key pattern in `types/inventory-operations.ts` | **Keep unchanged** | These are storage-format versions, not the V2 look. Renaming them would reset sign-in history state and lose saved stock drafts. |

### 1.3 PHP, config, views, CSS

| Artefact | Action | Reason |
|---|---|---|
| `config/v2-pages.php` | **Remove** | |
| `AppServiceProvider` `transformComponentUsing` | **Remove** | Every render is Classic. |
| `UiVersionResolver` | **Remove** | `ClinicManagementController` uses it only to relabel links for V2 (UX-21); that branch goes. |
| `V2ShellNavigation` | **Remove** (its `SPACE_FOR_PATH` crumb map goes with it) | V2 sidebar only. `ClinicManagementNavigation` keeps every link. |
| `SpacePreferenceService`, `SpacePreferenceController`, route `PUT spaces/preference` | **Remove** | The switch itself. |
| `HandleInertiaRequests` `workspace.spaces`, `workspace.newLook`, `workspace.shell` | **Remove**, with their TS types in `types/global.d.ts` (`spaces`, `newLook`, `shell: V2Shell`) | |
| `ClinicManagementController` UX-21 branch (calls `V2ShellNavigation::label`) | **Remove before** `V2ShellNavigation` | Otherwise the hub breaks. |
| `SpacePreferenceController` redirects to `spaces.index` / `spaces.show` | **Removed with the controller** | |
| `UserMenuContent.vue` `inNewLayout` rule (treats any `/spaces` URL as V2) | **Remove** in the first slice | Otherwise the menu hides the Classic Clock in row on the spaces pages. |
| `UserMenuContent.vue` rows "Try the new layout"/"Back to Classic layout", "Spaces" (shown only when the switch is on), "New look (preview)", and the "Clock in or out" link row used in V2 | **Remove**; the Classic "Clock in" shortcut row stays | |
| `app.blade.php` `data-ui` / `data-look` and the V2 inline colour blocks; `app.css` `v2` variant, `:root[data-ui='v2']`, `.dark[data-ui='v2']` and new-look blocks | **Remove** | |
| `user_space_preferences` table (migrations `2026_11_14_000100`, `2026_11_19_000100`) | **Keep the table, stop reading it** in the removal slices; **drop it in a last, separate slice only if the owner agrees** (Q4), with a `down()` that recreates it | Never edit a merged migration. Dropping loses each person's saved choice, which no longer means anything; the `space_preference.changed` audit rows stay. |
| Department spaces backend (`DepartmentSpaceController`, `DepartmentSpaceNavigation`, the 9 `*Space` services, `SpaceAskService`, `SpaceQuestions`, `Explain/*`, `SpaceDisplay`, `AccountMenuLinks`, `SpaceAskController`, routes `spaces`, `spaces/{space}`, `me`, `spaces/{space}/ask`) | **Keep** (recommended, Q1) | See section 2. |
| `resources/js/pages/Spaces/Show.vue`, `components/spaces/*` | **Keep and restyle to Classic** (recommended, Q1) | |
| `WorkspaceHeader` "Return to workspace" | **Keep** | It links to `workspace.defaultUrl` (`/workspace`, the Classic home), not to a space. Unaffected. |

### 1.4 Docs

`docs/design/V2-CHECKLIST.md` and `docs/design/NEWLOOK-PILOT.md`: mark **Retired (owner, 19 Nov 2026)** at the top and
keep as history. `GUIDE.md`, `RESTYLE.md`, `DEBT.md` and the team TEAM.md files that mention V2: the Design team and
each owning team update their own files; this team does not edit other teams' TEAM.md. `docs/BASELINE.md` is the
coordinator's.

## 2. Functions only in V2 or only inside the spaces shell

| Function | Where today | How Classic will offer it |
|---|---|---|
| **Stock value report** (`/inventory/stock-value`, its journal download) | Linked only from `Inventory/V2/Index` | PR #996 adds the Classic page and link. **Blocker: #996 must merge before any slice removes `Inventory/V2/*`.** |
| **Me home** (`/spaces/me`): today's shift, the one-press Clock in with the photo notice (`ClockPhotoNotice`), leave balance, claims, credentials due, payslip links, "Ask" about my next day off and leave | Spaces shell (rendered as V2 even for Classic people, via the account menu "Me") | Keep, rendered Classic (Q1). Clock in itself is also in Classic (account menu "Clock in" row and `HR/Attendance`), so attendance is not lost either way. |
| **Eight department space homes** (Front desk, Pharmacy, Consultation, Billing, Panel, Stock, HR, Management): about 40 widgets of counts and short lists, each linking to an existing Classic page | Spaces shell only | Keep, rendered Classic (Q1). |
| **Space "Ask"**: 29 preset questions with answers (for example "busiest hours", "waits this week", "left without being seen", "who owes most", "closings missing"), plus the "Why?" wait explainers (`Explain/*`) | Spaces shell only | Keep with the spaces (Q1). Some answers (busiest hours, week's waits, left waiting) are not shown on any Classic page; removing the spaces would lose them. |
| V2 sidebar and jump search | V2 shell | Classic top bar and the Clinic management hub with its search (`clinic-hub-search`) already list every link the sidebar has (the sidebar is built from the same `ClinicManagementNavigation` rows plus the spaces). Nothing lost. |
| "New look (preview)" pilot | V2 only | Removed with V2 (owner decision). |
| V2 QR patient pages | Never live | Nothing to offer. |
| Page crumbs above titles (UX-20) | V2 only | Classic pages keep their own back links. Nothing lost. |

A scripted comparison of every V2 page with its Classic page (routes, URLs and props used) found no other V2-only
action: the extra URLs in V2 pages are back links (`/inventory`, `/clinic-management`, `/registration`, `/hr`) and the
stock value link. `/hr` in V2 crumbs has no route (it is a V2 bug that goes away with V2). Each removal slice repeats
the check for its own pages before deleting, and Nadiah re-reads both files of each pair.

## 3. Existing test assertions that change or are deleted (owner approval needed)

**Deleted as whole files (V2 only):**
- PHP (8 files, 38 tests): `tests/Feature/V2PageDispatchTest.php` (8), `V2PublicPagesSwitchTest.php` (9),
  `V2ShellNavigationTest.php` (6), `V2ClinicHubTest.php` (3), `V2SpacesShellTest.php` (3, see Q1: kept parts move to a
  Classic spaces test), `SpacePreferenceTest.php` (5), `UiVersionResolverTest.php` (3),
  `Billing/BillingV2PageTest.php` (1).
- Frontend (138 files): every `tests/Frontend/*v2*`, `*space*` and `new-look-*` file (for example `ui-v2-gate`,
  `ux-17-v2-shell`, `ux-21-v2-clinic-hub`, `ux-24-v2-spaces`, `ux-24-part2-spaces`, `ds-0-space-tokens`,
  `ds-1-space-shell`, `new-look-sidebar`, `new-look-switch`, all `hr-v2-*`, `v2-inventory-*`, `bv2-*`, `ag-1x-v2-*`,
  the `*-parity` files). The `ds-*`/`ux-24*` space tests are reviewed one by one: assertions about space content that
  stays are rewritten for the Classic spaces page, not dropped.

**Possible extra change (depends on Q2):** a Clinic management group for the spaces changes
`ClinicManagementNavigation` and the exact destination map in `ClinicManagementTest`, an existing assertion.
CO-1's Spaces menu row changes the account-menu tests (`AccountMenuLinks`, `UserMenuContent` frontend tests).

**Mixed files: only the V2 assertions change (41 frontend, 7 PHP):** `ag-13-office-version-gate` (8 V2 lines),
`pca-2-panel-claim-adjustment` (8), `stk28-stock-value` (7), `ag-32-inbox-prepare` (7), `ui-restyle-d1-returns-to-check`
(6), `hr-photo-storage` (6), `ux-20-page-crumbs` (5), `hr-g2b-late-leave-hours` (5), `stk40b-reason-after-count` (4),
`hr-undo-clock-out`, `hr-g2a-late-overtime`, `hr-3b-overtime`, `hr-21-work-permits`, `ag-25-agent-tidy` (3 each),
`stock-value-dates-required`, `stk40-blind-count`, `hr-s1-break-line`, `hr-m1-paid-overtime`,
`hr-discipline-date-empty`, `hr-7d-mtd`, `hr-7a-payroll`, `hr-15g-locum-pay`, `hr-15f-month-calendar`,
`hr-15d-locum-hire`, `ag-33-transfer-from-line`, `ag-24-outline-close`, `ag-21-cannot-decide-reason` (2 each),
`ux-5-consultation-toast`, `stk29-journal-download`, `pca-1-panel-invoice-printing`, `hr-photo-clock-in`,
`hr-one-clock-step3`, `hr-7c-protected-exports`, `hr-7c-payslips-exports`, `hr-7b-payroll-runs`,
`hr-3a-one-announcement`, `hr-16a-location-check`, `hr-15e-online-interview`, `clinic-hub-search`,
`ag-20-office-pause-motion` (1 each), `dd-51-field-borders`, `i1b-inventory-demo-workspace`, `scan-input-v2-options`
(to check); PHP `HR/SopsTest` (4), `Clinical/InventoryOperationsTest` (3), `Inventory/PurchaseOrderPrintTest` (2),
`Inventory/TreatmentUsageTest`, `TransferShortageTest`, `SupplierReturnGroupTest`, `CycleCountTest` (1 each).
Counts are lines that mention V2, not yet exact assertions; **each slice's PR lists every changed or deleted assertion
with its file and line before the owner is asked**, and the matching Classic assertion is kept or added so the
function stays tested in Classic.

## 4. Slice order (each its own branch, gate and PR)

0. **Wait for PR #996** (Stock value in Classic). No V2 Inventory page is removed before it merges.
1. **CO-1 Switch off for everyone, spaces in Classic (one PR; Nadiah's finding).** Switching V2 off alone is not
   safe: once `isV2()` is false the spaces lose `workspace.shell` and fall back to their older DS-1 sidebar (a third look,
   neither Classic nor V2), and the only way into the eight department spaces today is the V2 sidebar. So one change:
   `isV2()` returns false for everybody, including `/spaces`; `Spaces/Show.vue` uses `ClinicLayout` (which mounts
   `ClockPhotoDialog`) instead of `SpacePassThroughLayout`, Classic chips instead of `V2Chip`, and a Classic space
   tab strip; the account menu keeps a **Spaces** row for everyone who can enter a space (no longer tied to the switch)
   and the "Me" row, and loses "Try the new layout", "New look (preview)" and the `inNewLayout` rule;
   `PUT spaces/preference` is removed. Every V2 page file still exists but is unreachable, so this is reversible and
   the owner can try it before anything is deleted. Design team look required (the spaces get a new Classic look).
   If Q1 is (b) or (c), this slice instead removes the matching spaces and their tests.
2. **CO-2 (reserved)** for the Q2 placement if the owner wants a Clinic management group as well as the menu row.
3. **CO-3 Move shared bits to Classic.** `V2MoneyInput` → `MoneyInput`, `v2-leave-*` → `leave-*`, the `RowAction`
   type, `PageFrame` without crumbs. No visible change.
4. **CO-4 to CO-9 Delete V2 pages and components area by area,** about 15 to 25 files each so a reviewer can read the
   pairs: (4) Front desk, Patient, Queue, Clinical; (5) Dispensary, Billing, Panel, Visits; (6) Inventory (after #996);
   (7) HR and Attendance; (8) Catalogue, Pricing, settings, Staff, Branches, Access, Audit, MC, Referrals, Agents;
   (9) staff QR pages (`RegistrationReview`, `PublicCheckInLinks`) and the patient QR V2 pages (never live). Each slice removes its names from `config/v2-pages.php` and its V2-only tests.
5. **CO-10 Remove the machinery:** `config/v2-pages.php`, `UiVersionResolver`, the transform, `V2Layout`,
   `V2AppShell` and the other shell parts, `useUiVersion`, `data-ui`/`data-look`, the CSS blocks and every `v2:` class,
   the shared props, the V2 docs marked retired.
   Also (CO-4 note, owner-approved 20 Nov): the optional `beforeStart` hook on the Classic
   `components/queue/CallNextControl.vue` and `HeldCallNext.vue` existed only for the V2 consultation page (deleted in
   CO-4); Classic passes none, so remove the hook and its plumbing here, after checking nothing else passes it.
6. **CO-11 (only if Q4 = yes)** drop `user_space_preferences` with a reversible migration.

## 5. Risks

- **Losing a V2-only function unnoticed.** Mitigation: the per-pair scripted check above, rerun in every slice, plus
  Nadiah reading both files of each pair; any find stops the slice and goes to the coordinator.
- **Classic files that quietly depend on a V2 file.** Five need a move (`PanelAdjustmentPanel`, `PageFrame`,
  `useLeaveGuard`, `useRegistrationRowActions`, `Spaces/Show`), done in CO-1 and CO-3 before any deletion. Five more import
  V2 code only to switch looks (`app.ts`, `ClinicLayout`, `AdminLayout`, `agents/office/OfficeScene`, Classic
  `Dispensary/ReturnsToCheck`); CO-10 strips those imports before the files they import are deleted, and no deletion
  slice may delete `V2AppShell`, `V2Layout` or `useUiVersion` early; `npm run build`
  and `vue-tsc` catch any missed import.
- **The "Me" link** sends every Classic person to `/spaces/me` today; CO-1 must make that page Classic in the same PR,
  or remove the link, so nobody lands in a half-styled page.
- **People who are in V2 now** are moved to Classic with no warning. Q3 asks whether to show a one-time notice.
- **Other teams.** Removing V2 pages touches every area, including Registration pages (Patients & Registration) and
  the shared layouts. Each slice is announced to the coordinator before it starts; no slice edits
  `PermissionCatalogue.php`, migrations of other teams, or `docs/teams/patients-registration/`. The Triage team's new
  screens must be Classic only from now (the coordinator to tell them).
- **Open PRs that add or change V2 pages** will conflict; the coordinator should ask teams to stop V2 work now.
- **Test volume.** About 170 test files change; deletion slices stay small so CI stays readable.

## 6. Questions for the owner

1. **Department spaces and Me home:** today they show in the V2 look, and every Classic person reaches them through
   the account menu "Me". Options:
   (a) **Keep them, shown in the Classic look** (recommended: no function lost, including the 29 Ask answers and the
   one-press Clock in);
   (b) keep only Me home in Classic and remove the eight department spaces and Ask (loses about 40 widgets and the
   Ask answers, some of which no Classic page shows);
   (c) remove all spaces; "Me" goes to My attendance (loses the Me home and all of the above).
2. If (a): CO-1 keeps a "Spaces" row in the account menu for everyone who can enter a space. Should they also get a
   group in Clinic management (changes an existing hub test)?
3. Show people who were using V2 a one-time note ("The new layout has been retired; everything is in Classic"), or
   switch silently?
4. Drop the saved switch table (`user_space_preferences`) at the end, or keep it?
5. Approve the slice order above, starting with CO-1 (switch V2 off for everyone and show the spaces in Classic,
   without deleting files), and the
   test changes it lists (each slice lists its exact assertions before its PR).

## 7. Decisions (coordinator for the owner, "be me, i approve all", 19 Nov 2026)

1. Keep the spaces, shown in the Classic look. No function may be lost.
2. No Clinic management group: the Spaces row in the account menu only, so the hub test stays as it is.
3. Switch silently: no "new layout retired" note.
4. Do not drop `user_space_preferences` (no CO-11 for now); the data stays until the owner decides.
5. Slice order approved. Phase 2 starts with CO-1 only. Each slice reports every changed or deleted assertion with its
   reason, approved per slice before the commit that changes them. CO-1 needs the Design team's look (1440 and 390 px
   screenshots, light and dark, synthetic data on a task-owned database). The two storage-format "v2" keys stay
   untouched. Inventory V2 pages wait for PR #996. No PRs from this team; the coordinator opens them.

## 8. Done (2026-11-22)

Every slice is merged. Everyone sees Classic, and no function was lost. Each slice listed its changed or deleted test
assertions, and the owner approved them before the commit that changed them.

| Slice | What it removed | PR |
|---|---|---|
| CO-1 | The "Try the new layout" switch and the new-look preview; the spaces and Me home open in Classic | #1000 |
| CO-3 | V2 branches in shared components | #1002 |
| CO-4 | V2 front desk and clinical pages | #1003 |
| CO-5 | V2 money pages | #1004 |
| CO-6 | V2 Inventory pages (after PR #996) | #1005 |
| CO-7 | V2 HR pages (the HR composables stay for Classic) | #1009 |
| CO-8 | V2 catalogue, pricing, settings, staff, admin, document and agent pages | #1034 |
| CO-9 | V2 public check-in pages (never shown) and the page registry | #1044 |
| CO-10a | The agent office's V2 control-room look | #1052 |
| CO-10b | The V2 machinery: `components/v2`, the V2 shell, `V2Layout`, `useUiVersion`, `lib/v2-*`, `v2.css`, `UiVersionResolver`, `V2ShellNavigation`, `config/v2-pages.php`, the Geist font, `data-ui` and the V2 shared props | #1056 |
| CO-10c | The last leftovers: V2-only props and options, seven backend fields, `POST /catalogue-panels` with its controller, request and `PanelAdministrationService`, and stale V2 comments | #1059 |

The CO-10 split into 10a, 10b and 10c was approved by the owner, because the office page still used `useUiVersion`.
Still open: decision 4 (drop `user_space_preferences` or keep it) is with the owner. The table is no longer read, and
its two merged migrations stay unedited. The Classic leave warning for long forms is its own later slice, not part of
this plan.

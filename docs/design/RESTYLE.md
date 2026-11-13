# KPOne restyle plan (proposal, 2026-11-12)

**Version 2 only (owner decision, 2026-11-12).** Every restyle slice is for Version 2 only: the new look shows behind the per-user "Try the new layout" switch (default off, each person chooses for themselves), and Classic must stay pixel-identical (before and after shots of the main Classic pages at 390 and 1440 px, light and dark, with V2 off). New tokens live under `[data-ui=v2]`; components keep their old classes and add `v2:` ones. In V2 every page sits inside the spaces' sidebar shell, and a page with no space goes under "More". Gate and token split first, then the component reworks, then the V2 shell (first check: Quick create), then the spaces, then the shared approval panel.

**Classic restyle slices closed (owner decision, 2026-11-12).** The owner said "close": 38 Classic restyle PRs (Wave 0 and every page restyle) were closed unmerged, because Classic stays exactly as it was. All new-look work now goes into per-page Version 2 layouts, built from V2-only blocks under `components/v2/` and designed page by page (Registration first, then Queue, Billing, Dispensary and the rest). The wave plan and slice list below are kept only as a record of the first proposal.

**Status: superseded by the owner starting UI-RESTYLE on 2026-11-12. Teams now send their own page plans to the coordinator; this file is kept only as a record of the first proposal and of which shared pieces Staff Experience owns (Wave 0).** It follows GUIDE section 19 (the app-wide
look). Every slice is layout only: no behaviour, permission, route, payload, data or wording change. Each is a small PR
with before and after shots at 390 and 1440 px, light and dark, reviewed by the Lead UI/UX Designer through the
coordinator. Sizes: S is one or two pages, M is three to six, L is seven or more or has a risky screen.

## Wave 0: shared pieces first (Staff Experience, with Design)

Pages inherit these, so they change first and each page PR afterwards stays small.

| # | Shared piece | What changes | Size |
|---|---|---|---|
| 0.1 | `resources/css/app.css` tokens | Cobalt `primary` and `ring`, new page, surface and hairline values, tint and gradient tokens, radius scale, 44 px mobile target. Pink stays `brand` for the logo only | M |
| 0.2 | `ui/button` | Cobalt main action, outline default for the rest, 44 px below 1024 px, pink `accent` variant retired (list who uses it first) | S |
| 0.3 | `ui/page-header`, page frame | One frame, 1280 px centred content from 1440 px, Recent activity strip slot | S |
| 0.4 | `ui/tabs` (`OperationalTabs`) | Segmented control with counts and a phone fade | S |
| 0.5 | `ui/table` (`OperationalTable`), `CompactPagination` | Hairline frame, header row, row height, card layout below 1024 px | M |
| 0.6 | `ui/status` (`StatusBadge`), `ui/card` | Pill with dot and word, info tone, card radius and hairline | S |
| 0.7 | `ui/dialog`, `ui/sheet`, `OperationalConfirmDialog`, dropdown menus | Surface, radius, close button, bottom sheet for forms on a phone | M |
| 0.8 | `ui/input`, `ui/select`, `ui/date-field`, `OperationalSelect`, `FileChooseButton` | Field height, radius, focus ring | M |
| 0.9 | Shell (`AppHeader`, branch switcher, account menu) | Already moving to the department spaces (DS plan); align tokens | M |

Riskiest: 0.1 (every screen changes colour at once) and 0.5 (about 40 pages use the table). Do 0.1 behind a visual diff
of the ten busiest pages, and ship 0.5 before any page-level table work. Many frontend tests pin class strings, so
expect a list of owner-approved assertion changes in 0.1, 0.2 and 0.5.

## Wave 1: the daily clinic flow

| Order | Pages | Owning team | Size | Riskiest screen |
|---|---|---|---|---|
| 1 | Registration board, Quick create, Register Visit, patient board | Patients & Registration (board shared with Queue & TV) | M | The Registration board: `PatientBoard` is also the Queue board, polls every 10 seconds and has the not-ready and treatment chips |
| 2 | Queue board, My Queue | Queue & TV | M | The Queue board in the busy state (many rows, chips, Call next) |
| 3 | Consultation page (two-column focus mode), Treatment Plan, MC, referral, attendance pages | Queue & TV for the page, Patients & Registration for documents | L | The consultation page: focus mode, toolbar, toasts at the bottom left and the unsaved-work guard |
| 4 | Dispensary case page, Completed case, label preview, OTC allergy step, Returns to check | Dispensary & OTC | L | The Dispensary case page: three columns of editable lines, allergy warning and batch pickers |
| 5 | Billing page, Invoice, Manage payments, Invoices (visit history), cash closing, Pay later list | Billing | L | Billing/Show: payment, Panel and adjustment dialogs and the Due Now summary |

## Wave 2: money and stock

| Order | Pages | Owning team | Size | Riskiest screen |
|---|---|---|---|---|
| 6 | Panel Claims list, claim page, Create claim, Panel invoices, Panel payments, follow-up, corrections, Checkout settings | Panel | L | The claims list (table to cards, bulk selection and the claim pack tick) and the claim pack print, which must stay white |
| 7 | Inventory page, stock count and write-off, Receiving, Supplier returns, Treatment usage, Purchase requests, Reorder suggestions, Pilot readiness | Inventory | L | Verified receiving and the governed stock count (long forms, two-person approvals) |
| 8 | Reports (Daily summary, Panel summary, Stock movement, Transaction Detail, Billing Detail), Insights | Billing for reports, Staff Experience for Insights | M | The shared branch report page (`BranchReportPage`) and the wide Insights tables |

## Wave 3: setup and back office

| Order | Pages | Owning team | Size | Riskiest screen |
|---|---|---|---|---|
| 9 | Medicine and Clinical Service catalogues, Pricing, price check and history, Business details, Medicine details, Suppliers, Preset diagnoses, Registration settings | Catalogue & settings | L | Pricing (many fields per row, cards on a phone) |
| 10 | Staff directory, staff pages, access control, branches, audit logs, Clinic management hub, security settings | Staff Experience | M | Staff Create and Edit (roles and branch assignment dialogs) |
| 11 | KPOne HR (roster planner, leave, attendance, records, credentials, SOPs, claims, payroll, memos) | HR | L | The roster planner grid and the payroll pages |
| 12 | Agents (registry, inbox), Website drafts and releases | Agent platform for Agents; Website pages come last, after the clinic pages, with no team named yet (owner decision 2026-11-12) | M | The agent inbox decisions. Leave the SVG office page as drawn |
| 13 | Patient directory and patient pages, merges, QR intake review | Patients & Registration | M | Patient create and edit (phone and identifier fields) |

## Not in the restyle

Printed documents and labels, the waiting-room TV screen, the sign-in screens and the public QR pages keep their own
look (GUIDE section 19). Careers (public) stays with its own branding.

## Rules every slice follows

1. One page family per PR, layout only. A wording or behaviour fix found on the way becomes its own PR.
2. Use tokens and shared components only. No hex, no palette class, no new `dark:` colour in a page.
3. Shots at 390 and 1440 px, light and dark, for every changed screen, plus the key dialog states, before and after.
4. No change to an existing test assertion without the owner's approval, listed in the PR.
5. A team does not start its slice until the owner approves this plan and Wave 0 pieces it depends on are merged.

## Proposed order of work

Wave 0 (0.1 first, 0.5 before any page) then Registration and Queue together (the shared patient board), Consultation,
Dispensary, Billing, Panel, Inventory, Reports, then setup and back office. HR and Staff Experience pages can run in
parallel from Wave 1, because their pages share few components with the clinic flow. Two teams never restyle the same
shared component at the same time: Wave 0 owns the shared pieces.

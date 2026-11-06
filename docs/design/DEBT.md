# Design debt list

Screens that break [GUIDE.md](GUIDE.md), found by the DES-1 survey (2026-11-05, `main` at `322228f`, 35 screens,
390 and 1440 px, light and dark) and by SMOKE-1. Kept by Daniel (Lead UI/UX); the owning team fixes each item in its
own PR and tells B.E.R.N.I.S.S (formerly LUCY). See [REVIEW.md](REVIEW.md) for how items are added.

**Severity:** **Blocker** = a REVIEW.md section 4 blocker (would stop a new PR); **Major** = clearly hurts daily work;
**Minor** = inconsistent or untidy.
**Status:** Open, In progress (PR named), Fixed (PR named), Accepted (owner decision, date).

| ID | Screen and file | Problem | Guide | Owner team | Severity | Source | Status |
|---|---|---|---|---|---|---|---|
| DD-01 | Insights Today, `pages/Insights/Today.vue` | Page is 490 px wide on a 390 px phone (ranking cards) | 16 | Staff Experience (Insights has no team; small layout fixes allowed) | Blocker | S1-004, DES-1 | Open |
| DD-02 | Create patient, `pages/Patient/Create.vue` | Page is 433 px wide on a 390 px phone even with no errors (a field grid) | 16 | Patients & Registration | Blocker | REG-2 note, DES-1 | Fixed (REG-6, PR #285) |
| DD-03 | Registration board, Consultation queue, QR intake review: `pages/Registration/Index.vue`, `pages/Queue/Index.vue`, `pages/RegistrationReview/Index.vue` | No `h1` (may be `sr-only` on boards) | 4, 12 | Staff Experience (S1-009 to S1-014), page teams told: Patients & Registration, Queue & TV | Blocker | S1-009 to S1-014, DES-1 | Open |
| DD-04 | Pricing, `pages/Pricing/Index.vue` | 10 of 13 fields have no label | 7 | Catalogue & settings (Adlina, Ravi; designer Nadia) | Blocker | DES-1 | Fixed (CAT-1, PR #263) |
| DD-05 | Add panel, `pages/PanelSettings/Form.vue` | 7 of 23 fields have no label | 7 | Panel | Blocker | DES-1 | Open |
| DD-06 | Payment Methods, `pages/PaymentMethods/Index.vue` | One button with no accessible name | 12 | Billing | Blocker | DES-1 | Open |
| DD-07 | All date and time pickers (`pages/Reports/*`, `pages/PanelInvoices/Index.vue` and others) | Calendar and clock icons vanish in dark mode: the app does not set `color-scheme: dark` | 13 | Staff Experience (one shell line in `resources/css/app.css`) | Blocker | DES-1 | Open |
| DD-08 | Pale palette colours without a dark style (candidates, check each in dark mode): `pages/Dispensary/Show.vue` (5), `pages/RegistrationReview/Show.vue` (4), `pages/Staff/Index.vue` (3), `pages/Staff/Show.vue` (3), `pages/Registration/Create.vue` (3), `components/patient-board/PatientBoard.vue` (3), `components/registration/QrIntakeTable.vue` (3), `components/queue/QueueRows.vue` (2), and about 25 more files with one or two | `bg-*-50`, `bg-*-100` or `bg-white` with no `dark:` class in the same class list | 1, 13 | Each page's team: Dispensary & OTC, Patients & Registration, Queue & TV; Staff Experience for Staff pages and shared components | Major (Blocker for any line that shows as a pale panel in dark) | DES-1 code scan | Open |
| DD-09 | Toast covering Complete Consultation at 390 px, `pages/Clinical/Show.vue` | Toast sits on the main action | 9 | Staff Experience | Blocker | S1-015 | In progress (UX-1, PR #241: toasts at the top, owner decision) |
| DD-10 | Mobile drawer, `components/workspace/WorkspaceHeader.vue` | Drawer stays open after choosing a link | 12 | Staff Experience | Major | S1-017 | In progress (UX-1, PR #241) |
| DD-11 | Medical Certificates link before an encounter exists, `pages/Registration/Show.vue`, `pages/Visits/History.vue` | Link opens a 404 page | 11 | Staff Experience | Major | S1-005 | In progress (UX-2, sketch OK with one change) |
| DD-12 | Inventory, `pages/Inventory/Index.vue`, and the governed Inventory pages (`GovernedStock.vue`, `SupplierReturns.vue`) | Links above the page title in several styles; a second "Return to Workspace" beside the header's | 4, 15 | Inventory | Major | DES-1 | Open |
| DD-13 | Medicine Catalogue, `pages/Medicine/Index.vue` | Three text buttons per row, the third cut off at 1440 px; on a phone only Code and Name are visible (prices, status and actions off screen) | 5, 6 | Catalogue & settings (Adlina, Ravi; designer Nadia) | Major | DES-1 | Fixed (CAT-1, PR #263) |
| DD-14 | Daily summary and other branch reports, text from `app/Domain/Shared/Services/DailySummaryReportService.php`, shown by `pages/Reports/BranchReport.vue` | About 15 lines of rule text before the form on a phone, in internal language | 4, 15 | Billing | Major | DES-1 | Open |
| DD-15 | Patient board empty message, `components/patient-board/PatientBoard.vue` | "No Patients match this operational view.": jargon, capitalised noun, no next step | 11, 15 | Patients & Registration (board shared with Queue & TV) | Minor | DES-1 | Open |
| DD-16 | Hand-built status chips in `components/patient-board/PatientBoard.vue` and `components/registration/QrIntakeTable.vue` | Palette chips (`bg-rose-100/70`, `bg-pink-100/70`, `statusClass`) instead of `StatusBadge` tones | 10 | Patients & Registration, Queue & TV | Minor | DES-1 | Open |
| DD-17 | Page titles across pages (65 own `h1`s, only 18 use `PageHeader`): `pages/Reports/*` (`text-xl`), `pages/ClinicManagement/Index.vue` (`text-lg`) and others | Four title sizes; no shared header | 2, 4 | Each page's team as it next touches the page; Staff Experience owns `PageHeader` | Minor | DES-1 | Open |
| DD-18 | Browser `confirm()` on retire identifier, `pages/Patient/Show.vue` | Not themed, ignores KPOne focus rules | 8 | Patients & Registration | Minor | DES-1 | Open |
| DD-19 | "Return to Workspace" / "Return to workspace" in `pages/FinancialWork/Index.vue`, `pages/Billing/PayLaterCollections.vue`, `pages/Inventory/Index.vue` | Mixed capitals; repeats the header's button | 4, 15 | Billing, Inventory | Minor | DES-1 | Open |
| DD-20 | Shared components with one-off colours: `components/ui/OperationalConfirmDialog.vue` (`dark:bg-slate-900`, `bg-pink-50`), `components/workspace/WorkspaceHeader.vue` (`pink-600`, `pink-700`), `components/InputError.vue` (`text-red-600`) | Palette colours instead of tokens (they render correctly today) | 1 | Staff Experience | Minor | DES-1 | Open |
| DD-21 | Raw tables (28) against `OperationalTable` (11), no phone layout on several lists | Missing caption or label, focus region and phone cards | 6 | Each page's team as it next touches the page | Minor | DES-1 | Open |
| DD-22 | Patient board Status column, `components/patient-board/PatientBoard.vue` (Queue and Registration boards) | With Q-2 treatment-progress chips the Status cell wraps onto 4 or 5 lines at 1440 px. Proposed: Status 9% to 15%, Payment 24% to 18% | 6 | Queue & TV with Patients & Registration (shared board; needs Nurul's agreement) | Major | Q-2 hand-off | Open: owner approved the column change on 2026-11-05 (including the `panel-note.test.mjs` `w-[24%]` assertion change); Amirul (Queue & TV) builds it as Q-2b after Q-2, with Nurul's agreement; design OK needs 1440 px screenshots with a long Panel name and note, the note tooltip working, and the 390 px cards unchanged |
| DD-23 | Shared branch report page, `pages/Reports/BranchReport.vue` (Daily summary, Panel summary, Stock movement and the other branch reports) | Money cells left-aligned and not `tabular-nums`; raw ISO dates (`2026-11-05`) on screen; a raw `<table>` instead of `OperationalTable`; no collapsible "How this report counts" block (the long basis text is DD-14); dark date-picker icons are DD-07 | 2, 4, 6, 15 | Staff Experience (interim; the reports have no team) | Major | Siti (Billing), BIL-6b review | Open |
| DD-24 | Dispensary pages (`pages/Dispensary/Show.vue`, `Completed.vue`) at tablet widths | Tap targets still 32 to 36 px (phones fixed by DSP-8) | 12 | Dispensary & OTC | Minor | Aisyah, DSP-8 | Open |
| DD-25 | Dispensary case page header, `pages/Dispensary/Show.vue` | Patient name shows twice at the top (page title and patient card) | 4 | Dispensary & OTC | Minor | Aisyah, DSP-8 | Open |
| DD-26 | Dispensary medicine line at 320 px, `pages/Dispensary/Show.vue` | The batch dropdown cuts off the quantity | 16 | Dispensary & OTC | Major | Aisyah, DSP-8 | Open |
| DD-27 | "Added by CA" badge on Dispensary lines, `pages/Dispensary/Show.vue` | Low contrast in dark mode (hand-styled pink chip) | 1, 10, 13 | Dispensary & OTC | Major | Aisyah, DSP-8 | Open |
| DD-28 | Panel statement results, `pages/PanelRemittances/Index.vue` (PNL-7 "Fill the split from a Panel statement") | Desktop results are a plain table, not `OperationalTable` (no shared label, empty or loading row, focus region) | 6 | Panel | Minor | Arjun, PNL-7 | Open |
| DD-29 | Billing workspace buttons, `components/billing/BillingWorkspace.vue` | A scoped rule `.billing-workspace :deep(button) { height: 30px; min-height: 28px }` overrides utility classes, so compact Billing buttons are 30 px on a phone. BIL-5b fixes print, Reprint, the refund decision buttons and the visit-page prints (`data-touch-target`, 44 px at 390); the rest are still 30 px | 12 | Billing | Major | Farah, BIL-5b | Open |
| DD-30 | Queue board tabs at 390 px, `pages/Queue/Index.vue` | The third tab is cut to "Se" under the "Live · every 3 seconds" label, so Serving Now and Consultation Completed are hidden on a phone | 16, 12 | Queue & TV | Minor | Q-4 design look | Fixed (PR #296) |
| DD-31 | Panel Claims list on phones, `pages/PanelClaims/Index.vue` | At 390 px the claims table scrolls inside its box and shows only Amount, Claim no and Panel, so the patient and status are off screen while picking claims for a PNL-8 claim pack | 6, 16 | Panel | Minor | Syafiq, PNL-8 | Open |
| DD-32 | Create patient description, `pages/Patient/Create.vue` | "Organisation-level canonical identity. This is not a registration or encounter.": internal words on a staff screen | 15 | Patients & Registration | Minor | REG-6 design look | Open |
| DD-33 | "Added by CA" and "Edited by CA" chips on the Invoices page, `pages/Visits/History.vue` | Hand-styled `bg-pink-100 text-pink-900` chips with no dark style (the same chip DD-27 fixed on the Dispensary page); use the accent `StatusBadge` | 13, 10 | Billing | Major | DD-24 to DD-27 design look | Open |
| DD-34 | "Record changed elsewhere" panel on the consultation page | The panel has no dark-mode colours | 13 | Queue & TV | Major | Q-4b design look (Yasmin) | Open |
| DD-35 | Call next confirm dialog (Q-4 and Q-4b) | Uses a hard-coded `dark:bg-slate-900` instead of the theme tokens | 1, 13 | Queue & TV | Minor | Q-4b design look (Yasmin) | Open |
| DD-36 | "My Queue" back link on the consultation page, `pages/Clinical/Show.vue` | Uses `text-emerald-800` with no dark style, so the way back to the queue is barely visible in dark mode | 13, 1 | Queue & TV | Major | DD-34/35 review (Mira) | Open |

## Not debt (out of scope, owner-approved)

The waiting-room TV look, the public QR pages' own Bahasa Melayu design, the public website profile, and the invoice,
receipt and drug-label print layouts.

## Change log

- 2026-11-05: list started (DES-2) with 21 items from DES-1 and SMOKE-1.
- 2026-11-05: DD-04 and DD-13 owner set to Catalogue & settings (no team yet), interim Staff Experience (LUCY). DD-22 added from the Q-2 hand-off; the owner approved the column change the same day.
- 2026-11-05: Catalogue & settings team set up (owner); DD-04 and DD-13 assigned to it as CAT-1; DD-22 planned as Q-2b.
- 2026-11-05: DD-23 added (shared report page, found by Siti during BIL-6b).
- 2026-11-05: DD-12 widened to the governed Inventory pages (seen during the STK-9 look).
- 2026-11-05: DD-24 to DD-27 added (Dispensary items found by Aisyah during DSP-8).
- 2026-11-05: DD-28 added (PNL-7 statement table) and DD-29 added (Billing workspace button height, found by Farah during BIL-5b).
- 2026-11-05: DD-30 added (Queue board tab strip at 390 px, seen during the Q-4 look).
- 2026-11-05: DD-31 added (Panel Claims list on phones, flagged by Syafiq during PNL-8) and DD-32 added (Create patient wording, seen during the REG-6 look).
- 2026-11-06: DD-02 marked Fixed (REG-6, PR #285: Patient create and edit fit 320 and 390 px).
- 2026-11-06: DD-04 and DD-13 marked Fixed (CAT-1, PR #263: Pricing labels and phone cards; Medicine Catalogue Edit plus "…" menu and phone cards).
- 2026-11-06: DD-30 marked Fixed (PR #296: Queue board tabs scroll with a right-edge fade on phones, 44 px tall).
- 2026-11-06: DD-33 added (Invoices page CA chips with no dark style, seen during the DD-24 to DD-27 look).
- 2026-11-06: DD-34 and DD-35 added (consultation page dark-mode gaps noted by Yasmin during Q-4b).
- 2026-11-06: DD-36 added (consultation page "My Queue" link in dark mode, noted by Mira during the DD-34/35 review).

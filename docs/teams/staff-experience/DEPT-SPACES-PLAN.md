# Department spaces: plan (no code yet)

Staff Experience (Kavitha, Aiman). Written 2026-11-11 for B.E.R.N.I.S.S and the owner. Plan only: nothing here is built or authorised
beyond the owner decisions quoted in the brief (restart; the sketch; "agree map"; the earlier 2026-11-10 plan).

## 1. How the gates were checked

The role to space map was computed from `PermissionCatalogue::roles()` as it is on `main` today, not from memory. For each space
I chose a list of "any of these permissions" and ran every catalogue role through it. The result matches the owner's map for every
role except the two gaps in section 2. The gate lists:

| Space | A user sees it if they hold at least one of | Roles that get it today |
|---|---|---|
| Front desk | `public_intakes.review.branch`, `queue.view.branch` | Director, CA, CA Supervisor |
| Pharmacy | `dispensary.view.branch` | CA, CA Supervisor (not the Director: gap 1) |
| Billing | `billing.view.branch`, `finance.work.view.branch` | Director, CA, CA Supervisor, Finance Officer |
| Consultation | `queue.view.own` | Resident Doctor (not the Director, not a locum: gaps 1 and 2) |
| Panel | `panel.work.view.branch`, `panel.claims.manage.branch`, `panel.remittances.record.branch`, `panel.invoices.export.branch` | Director, Panel Officer, Finance Officer |
| Stock | `inventory.stock_reviews.manage.branch`, `inventory.references.manage.organisation` | Director, CA Supervisor, Finance Officer, Administrator |
| HR | `hr.records.view.organisation`, `hr.roster.manage.organisation` | Director, HR Manager |
| Management | `access.manage.organisation`, `business_details.manage.organisation` | Director, Administrator, Technical Admin |
| Me | `hr.roster.view.own` | every human role incl. Marketing, Business Development and a locum |
| TV | `queue.display.branch` | the TV account only, which sees nothing else |

Why those lists and not the obvious ones: `inventory.view.branch` would also give the CA the Stock space; `catalogue.settings.manage.organisation`
would give Management to Panel Officer and Finance Officer; `staff.manage.organisation` would give it to the HR Manager; `visits.view.branch`
would give the Resident Doctor the Front desk; `queue.view.branch` alone would give the CA and CA Supervisor the Consultation space. The
lists above are the smallest ones that reproduce the owner's map exactly.

## 2. Two gaps the owner must decide (hard rule 3: no permission change, so I stop here)

1. **The Director "all 8" but holds neither `dispensary.view.branch` nor `queue.view.own`.** Today the Director cannot open the Dispensary pages
   (403) or a doctor's own queue. Without a change the Director would see 6 spaces. Options:
   - (a) **Recommended.** A documented rule in `DepartmentSpaceNavigation`: the protected Director authority (the codebase already special-cases it,
     `PermissionCatalogue::PROTECTED_AUTHORITY_ROLE`) sees all 8 spaces, and every widget inside is still filtered by what the Director can
     really open. The Director's Pharmacy and Consultation spaces would be a read-only overview built from permissions the Director has (stock,
     the branch queue board through `queue.view.branch`); nothing the Director cannot open today appears. No permission change.
   - (b) Grant the Director `dispensary.view.branch` and `queue.view.own`. A separate role decision (patient dispensing data and clinical queue;
     `AGENTS.md` forbids granting clinical content by implication). Not recommended.
2. **"Resident Doctor / locum doctor: Consultation".** The `locum` role has 4 permissions (`profile.view.own`, `branch_context.switch.branch`,
   `hr.roster.view.own`, `hr.open_shifts.request.own`). A locum doctor would see only "Me" until the owner gives locum doctors clinical permissions
   (a separate role decision under `AGENTS.md`, not part of this work). The matrix test will pin "Me only" for the locum so that a later grant
   shows up as a deliberate change.

Also noted, not a gap: Marketing and Business Development get "Me" and keep their Website pages; the Administrator's Stock space shows setup
widgets only (reference data, readiness), no counts that need a clinical permission.

## 3. Slices (each its own PR, Daniel looks at each screen against the sketch)

| Slice | What | Notes |
|---|---|---|
| DS-0 | Design note and scoped tokens | Daniel's sketch is cobalt and Apple-like with pink only on the logo; the design guide says pink brand. Tokens must live under one class on the new shell (for example `.space-shell`) so every existing screen is untouched. Light and dark. Needs Daniel's addendum to `docs/design/GUIDE.md`, then the owner's nod. |
| DS-1 | Shell | `DepartmentSpaceNavigation` (domain service, like `ClinicManagementNavigation`) returning the user's spaces; a new layout with the grouped sidebar and a bottom "Recent activity" strip; an empty home per space. Matrix test (section 5). No data widgets yet. |
| DS-2 | The switch | Per-user preference "Try the new layout", default off, Director first, switching back always possible. New table (migration) with `lock_version`, an audit row on every change, permission and branch checks like any state change. Fresh check under lock, idempotency key on the toggle. Visible only to users who have at least one space. |
| DS-3 | Pharmacy space | First space, as the owner chose. |
| DS-3a | Ask panel (shared) | The shared plumbing and the panel, with Pharmacy's questions first; later spaces add their own questions in their own slices (section 6a). No agent or gateway involved. |
| DS-4 .. DS-10 | Front desk, Billing, Consultation, Panel, Stock, HR, Management | One at a time, owner order to confirm. |
| DS-11 | Me | Own roster, leave, staff claims. **Payslips are not built yet** (HR-7b and HR-7c), so "Me" shows them only when those slices exist. |

The existing work screens stay as they are; a space is a front door (rows link to the current pages).

## 4. Data each widget reads (existing services only; nothing new)

Every widget is built on the server and filtered by the viewer's own permission and active branch; a widget with no permission is not rendered
and its count is not sent. "Recent activity" is a per-space list built from the space's own records (never the audit log, which needs
`audit.view.organisation`), so it can only show what the viewer could already open.

| Space | Widgets (existing service) |
|---|---|
| Front desk | waiting, serving and not-ready counts (`VisitDirectoryService`, `QueueDirectoryService`, `QueueWaitingCountService`); QR intakes waiting (`PublicIntakeReviewService`); actions: Register visit, Registration, QR intake. |
| Pharmacy | cases waiting or dispensing (`DispensaryDirectoryService`); OTC list (`OtcQueueService`); returns waiting for a check, only for checkers (`DispensaryReturnService::canCheckReturns`); links to Dispensary and Inventory. |
| Billing | outstanding bills (`OutstandingBillService`); pay later to collect (`PayLaterCollectionService`); today's cash closing status (`CashClosingService`); refunds waiting. Counts and amounts only, no patient names unless the viewer already sees them there. |
| Consultation | own waiting count and held consultations (`QueueDirectoryService`, `QueueWaitingCountService`); Call next (`CallNextService`, the Q-4 block). Own patients only. |
| Panel | pending and overdue claims, follow-up list, remittances waiting (`PanelClaimService`, the PNL-4 and PS-03 queries). |
| Stock | low stock, expiring and expired on hand (`InventoryStockReviewService`); receiving exceptions and approvals waiting (`InventoryOperationsDirectoryService`); setup readiness (`InventoryReadinessService`) for the Administrator. |
| HR | the existing HR dashboard sections, each behind its own permission (`HrDashboardService`). |
| Management | shortcuts built from `ClinicManagementNavigation` rows the viewer already has; staff and access counts only for holders of `staff.view.organisation`. No patient data in any widget. |
| Me | own roster (HR-1), own leave balance (HR-2a), own claims (HR-6), own attendance (HR-3a); own scope only. |

## 5. Tests and existing assertions

- **New:** a PHP test that pins the exact role to space map above for every catalogue role (13 roles; the Director row follows the owner's
  answer to gap 1), computed from the real catalogue so any later permission grant or revoke that changes a space fails it. A test per
  space that a widget is absent without its permission and that its payload carries no key the viewer cannot see. A switch test: default
  off, lock_version, audit, stale write refused. Frontend tests for the shell and each space.
- **Existing assertions that would change: none planned.** The switch defaults off, so every existing page, the top bar, the Clinic management
  hub and `WorkspaceLandingService` behave as today for everyone who has not switched. Only when the switch is on does the landing page
  change, and that is a new branch with its own tests. If DS-1 turns out to need a change in `ClinicManagementTest`, `DashboardTest` or
  `nav-02-top-bar.test.mjs`, I send the line to B.E.R.N.I.S.S first.

## 6a. Ask panel (owner: "yes add the ask panel, option 1", 2026-11-11; design settled by B.E.R.N.I.S.S with Irfan)

Each space gets a small **Ask** panel of preset questions for that space and role. It is **not an AI service and not an agent**: it does not run as
Dato' Arif, starts no agent run and does not go through `AgentToolGateway`, so the agent contract limits stay exactly as they are. KPOne calls no
AI (`NoAiServiceGuardTest` stays as it is). There is no text box, so nothing typed is ever interpreted: a person picks from a fixed list and gets a
fixed sentence or a short table, recomputed each time and never stored.

**How it is built.**

- A new domain folder `app/Domain/DepartmentSpace` (a sibling of `Agents`, as the architecture allows) holds `DepartmentSpaceNavigation` and a small
  registry, `SpaceQuestions`, in the same shape as `QuickQuestions`: key, space, label, the permission that offers it, a date mode and a builder.
- Each answer calls **the existing page service** with that page's own permission and the viewer's active branch scope (the same service that feeds
  the page or the widget) and returns a fixed sentence or a short table. Nothing reads the database around those services.
- Where an answer is a pure aggregate that an existing agent data source already computes (`money.day_summary`, `panel.claims_summary`,
  `inventory.*`, `clinic.day_summary`), the builder calls the **same underlying service** for consistent numbers, **not the gateway**. Irfan checks
  which questions map to those services.
- **Patient-level rule:** a patient-level answer is allowed only when the same role already sees exactly those rows and fields on that page. A
  question never adds a field the page does not show. HR answers follow the HR-9 dashboard rules: no leave reasons, no pay, no scores.
- One endpoint, `POST /spaces/{space}/ask` with a question key: throttled, no-store, idempotent (it only reads). It re-checks the question's
  permission and the viewer's branch on every call and returns **403** for a question the viewer is not offered, 404 for an unknown key. A question a
  role cannot ask is absent from the panel, never greyed out. No new permission.

**Slice choice.** One shared slice, **DS-3a, right after DS-3 Pharmacy**: the registry, the endpoint, the Ask panel component (Daniel designs it in
the GUIDE section 18 style: a list of questions in the side column, the answer as a sentence and a small table with the same chips and timer rings)
and the tests, with Pharmacy's questions first. Later spaces add their own questions inside their own slices (a registry row and a read), so there are
no separate Ask slices.

**Questions per space (first set; the owner's examples marked \\*).** The permission is the page's own; Management and Me have no extra permission
beyond what each count's page needs.

| Space | Question | Offered to a viewer who holds | Source (existing service) | Patient-level? |
|---|---|---|---|---|
| Front desk | Who is waiting longest?\\* | `visits.view.branch` | Registration board (`VisitDirectoryService`) | **Yes**: the Registration board's own row and fields |
| Front desk | How many are waiting, per tab? | `visits.view.branch` | Registration tab counts (`VisitDirectoryService`) | No |
| Front desk | How many QR check-ins wait for review? | `public_intakes.review.branch` | `PublicIntakeReviewService` | No (a count) |
| Pharmacy | What is left to dispense?\\* | `dispensary.view.branch` | Dispensary list (`DispensaryDirectoryService`) | **Yes**: the Dispensary list's own row and fields |
| Pharmacy | Which returns wait for a check? | `dispensary.view.branch` and the return checkers (`DispensaryReturnService::canCheckReturns`) | Returns to check (`DispensaryReturnService`) | As the Returns to check page shows (DSP-7b) |
| Billing | Which bills are still open today?\\* | `outstanding.view.branch` | Outstanding bills (`OutstandingBillService`) | **Yes**: the Outstanding page's own row and fields |
| Billing | What is unpaid today?\\* | `billing.view.branch` or `finance.work.view.branch` | `BillingDirectoryService` and the daily money figures (the `money.day_summary` source's service) | No (amounts) |
| Billing | Which cash closings wait for approval?\\* | `payments.cash_closing.approve.branch` | `CashClosingService` | No (days and amounts) |
| Consultation | How many are waiting for me? | `queue.view.own` | `QueueWaitingCountService` | No |
| Consultation | Which of my consultations are on hold? | `queue.view.own` | the doctor's own queue (`QueueDirectoryService`, Held) | **Yes**: the doctor's own queue row, own patients only |
| Panel | Which claims are overdue?\\* | `reports.panel_summary.view.branch` | the `panel.claims_summary` source's service (Panel summary) | No (aggregates per Panel) |
| Panel | Approved but not yet claimed, per Panel | `reports.panel_summary.view.branch` | same | No |
| Stock | What expires in 30 days?\\* | `inventory.view.branch` | Stock review (`InventoryStockReviewService`) | No |
| Stock | What is below reorder level?\\* | `inventory.view.branch` | Stock review (`InventoryStockReviewService`) | No |
| HR | Who is on leave today?\\* | `hr.leave.approve.organisation` or `hr.leave.manage.organisation` | the HR team calendar service | Staff names only, "On leave" or "half day", never type or reason |
| HR | Which approvals wait for me? | the permission of each approval kind (leave, overtime, claims) | the HR approvals services (counts) | No |
| Management | Which approvals wait in each team? | each team's own approve permission; a count shows only for a team the viewer can approve for | each team's own count service | No |
| Me | How much leave do I have left?\\* | `hr.leave.request.own` | own leave balance (HR-2a service) | Own data only |
| Me | When is my next shift?\\* | `hr.roster.view.own` | own roster (HR-1 service) | Own data only |

The exact permission names above are checked against the catalogue when each space is built; where a page uses a different one, the page's own wins
and the table is corrected in the same PR. **Patient-level questions** (the four marked Yes) are listed for B.E.R.N.I.S.S as asked.

**Tests.** For every question: (1) a role without the permission does not see it in the space payload and gets 403 when it posts the key directly;
(2) a viewer at a branch they are not assigned to gets nothing; (3) for each patient-level question, the answer carries no key the page's own row
does not carry; (4) a stale or unknown key is refused. The registry is also pinned (every key, its space and its permission), so a change shows up
as a failing test. Existing assertions that would change: none (the Agent platform is untouched), and I send B.E.R.N.I.S.S the line first if one
turns out to be needed.

## 6. Other things the owner or the coordinator should know

- **One migration** (DS-2, the preference table). Staff Experience has no migration range yet; I suggest `2026_11_14_000100` onward.
- **No permission, route-permission or role change** anywhere in this work. If a space needs one, I stop and tell B.E.R.N.I.S.S.
- **Performance:** the sidebar's space list is built on every page like `ClinicManagementNavigation::hasDestinations`; it will read no staff
  record and be built once per request.
- **Security:** no count or widget leaks. Aggregates are sent only to a viewer who already holds the permission to open the page they came from.
  Server-side branch scope (`BranchAccessService`) on every widget.
- **Open questions for the owner:** gap 1 (Director) and gap 2 (locum doctor); whether the owner wants Pharmacy then the others in the order
  Front desk, Billing, Consultation, Panel, Stock, HR, Management; and Daniel's confirmation that the cobalt sketch may sit beside the pink design guide.

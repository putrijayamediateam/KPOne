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

## 6. Other things the owner or the coordinator should know

- **One migration** (DS-2, the preference table). Staff Experience has no migration range yet; I suggest `2026_11_14_000100` onward.
- **No permission, route-permission or role change** anywhere in this work. If a space needs one, I stop and tell B.E.R.N.I.S.S.
- **Performance:** the sidebar's space list is built on every page like `ClinicManagementNavigation::hasDestinations`; it will read no staff
  record and be built once per request.
- **Security:** no count or widget leaks. Aggregates are sent only to a viewer who already holds the permission to open the page they came from.
  Server-side branch scope (`BranchAccessService`) on every widget.
- **Open questions for the owner:** gap 1 (Director) and gap 2 (locum doctor); whether the owner wants Pharmacy then the others in the order
  Front desk, Billing, Consultation, Panel, Stock, HR, Management; and Daniel's confirmation that the cobalt sketch may sit beside the pink design guide.

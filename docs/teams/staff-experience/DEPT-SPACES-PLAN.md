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
| DS-3a | Ask panel (shared) | The shared plumbing and the panel, with Pharmacy's questions first; later spaces add their own questions in their own slices (section 6a). Needs the agreement with Irfan on the gateway entry. |
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

## 6a. Ask panel (owner: "yes add the ask panel, option 1", 2026-11-11)

Each space gets a small **Ask** panel of preset questions for that space and role. It is **not an AI service**: KPOne calls no AI (standing
owner decision, `NoAiServiceGuardTest`). There is no text box, so nothing typed is ever interpreted; a person picks from a fixed list and gets
a fixed sentence plus a small table, recomputed each time and never stored.

**What I found in the Agent platform (AG-5), and what I propose to reuse and not reuse.**

- Reuse the **shape**: `QuickQuestions` (a registry of key, group, label, data source, date input, extra permission), `QuickAnswer` (sentence,
  columns, rows, totals, scope) and `AggregatePayloadGuard`. Reuse the existing data sources (`clinic.day_summary`, `money.day_summary`,
  `panel.claims_summary`, `inventory.stock_review` and the rest) for the aggregate questions.
- **Not reusable as it is:** `DirectorAssistant` starts a Dato' Arif run through `AgentRunRecorder` with `AgentPermissions::ASSISTANT`, which is
  Director-only, and `AgentToolGateway::call()` needs a started agent run and an agent allowlist. A CA, a Finance Officer or an HR Manager has no
  agent run, and giving them the assistant permission or a new "agent" row is exactly the kind of change hard rule 3 forbids (and it would label a
  fixed list as an "AI agent").
- **Proposed shared change, to agree with Irfan (Agent platform) before it is built:** add to `AgentToolGateway` one new entry, for example
  `callForPerson(User, toolCode, ?Branch, input)`, that performs the same checks as `call()` (active user, the tool's required permission, an
  effective branch assignment for a `.branch` tool, input schema, `AggregatePayloadGuard`) but needs no agent run and writes one structural audit
  row (`space.ask_answered`: question key and branch id only) instead of a run step. The checks move into one private method that both entries use,
  so there is still one place that decides. The existing `AgentToolGatewayTest` is extended, not changed. If Irfan prefers, the fallback is a thin
  `AskReader` in `app/Domain/Access` that calls the tools directly and repeats those checks; I advise against it because it duplicates the rules.
- Questions that need **patient rows** or per-person data (below) are not agent data sources and must not be: the gateway's guard refuses
  personal-data keys on purpose. Those are answered by the space's own domain services, the same ones that feed its widgets.

**Slice choice.** One shared slice, **DS-3a, right after DS-3 Pharmacy**: the registry (`SpaceQuestions`), the answer endpoint (one `POST` with a
question key, throttled, no-store), the Ask panel component, the gateway entry and the tests, with Pharmacy's questions as the first set. After
that, each later space adds its own questions inside its own slice (a registry row and a read), so there are no separate Ask slices. Daniel designs
the panel in the GUIDE section 18 style (a list of questions in the side column, the answer as a sentence and a small table, the same chips and
timer rings).

**Gating.** Every question names the permission it needs (the data source's own, or the page's); it is offered only to a viewer who holds it, is
answered at the viewer's active branch (`BranchAccessService`), and the endpoint re-checks both on every call. A question a role cannot ask is
absent, never greyed out. No new permission.

**Questions per space (first set; the owner's examples marked \*).**

| Space | Questions | Data (existing) | Names patients? |
|---|---|---|---|
| Front desk | Who is waiting longest?\*, How many are waiting, per tab?, How many QR check-ins wait for review? | `VisitDirectoryService`, `QueueDirectoryService`, `PublicIntakeReviewService` | **Who is waiting longest**: yes |
| Pharmacy | What is left to dispense?\*, Which returns wait for a check? | `DispensaryDirectoryService`, `DispensaryReturnService` | **What is left to dispense**: yes |
| Billing | Which bills are still open today?\*, What is unpaid today?\*, Which cash closings wait for approval?\* | `OutstandingBillService`, `PayLaterCollectionService`, `CashClosingService` | **open bills**: yes |
| Consultation | How many are waiting for me?, Which of my consultations are on hold? | `QueueDirectoryService`, `QueueWaitingCountService` | **on hold**: yes (own patients) |
| Panel | Which claims are overdue?\*, Approved but not yet claimed, per Panel | `panel.claims_summary` source, `PanelClaimService` | no (aggregates) |
| Stock | What expires in 30 days?\*, What is below reorder level?\* | `inventory.stock_review` source | no |
| HR | Who is on leave today?\*, Which approvals wait for me? | HR leave calendar and approvals services | the leave calendar shows "On leave" only, never type or reason |
| Management | Which approvals wait in each team? (counts only) | each team's own counts | no |
| Me | How much leave do I have left?\*, When is my next shift?\* | own HR records only | own data only |

**Patient-level answers (listed as the owner asked):** Front desk "Who is waiting longest?", Pharmacy "What is left to dispense?", Billing "Which
bills are still open today?", and Consultation "Which of my consultations are on hold?". Each uses the **same projection and masking the board
already shows that role** (the Registration board row, the Dispensary list row, the Outstanding bill row, the doctor's own queue row), through the
same service, **with no new patient field**. A role that sees no patient names on that board gets the aggregate answer only. A test per question
asserts the answer carries no key the board row does not carry.

**Tests and assertions.** A test pins, for every question, the permission that offers it and the data it reads; one asserts the endpoint refuses a
question the viewer cannot be offered and a branch they are not assigned to; one asserts a stale or unknown key is refused; one asserts no
`ai`-provider wording enters the code (`NoAiServiceGuardTest` stays as it is). Existing assertions that would change: none; `AgentToolGatewayTest`
is extended for the new entry only, and I send B.E.R.N.I.S.S the line first if a change turns out to be needed.

## 6. Other things the owner or the coordinator should know

- **One migration** (DS-2, the preference table). Staff Experience has no migration range yet; I suggest `2026_11_14_000100` onward.
- **No permission, route-permission or role change** anywhere in this work. If a space needs one, I stop and tell B.E.R.N.I.S.S.
- **Performance:** the sidebar's space list is built on every page like `ClinicManagementNavigation::hasDestinations`; it will read no staff
  record and be built once per request.
- **Security:** no count or widget leaks. Aggregates are sent only to a viewer who already holds the permission to open the page they came from.
  Server-side branch scope (`BranchAccessService`) on every widget.
- **Open questions for the owner:** gap 1 (Director) and gap 2 (locum doctor); whether the owner wants Pharmacy then the others in the order
  Front desk, Billing, Consultation, Panel, Stock, HR, Management; and Daniel's confirmation that the cobalt sketch may sit beside the pink design guide.

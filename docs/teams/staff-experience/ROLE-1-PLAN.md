# ROLE-1: three new roles and their access grants (plan and permission table)

Status: **plan only, for Aiman's review and the owner's approval of the table.** Nothing is built. Written by Staff Experience
(Kavitha) on 2026-11-21 from `docs/decisions/2026-11-20-finance-inventory-proposals.md` (owner decisions 20-21 Nov 2026, items
A1 to A3, A5 to A7). Synthetic data only; not production approval.

## 1. What ROLE-1 is, and is not

- **Is:** three new roles (`branch_manager`, `account_assistant`, `store_admin`) in `PermissionCatalogue::roles()` and one
  additive migration that creates them, plus three access grants to existing roles (audit log, cost view, branch-scoped
  report download). Assignment goes through `StaffProvisioningService` and the existing `canAssignRoles` rule.
- **Is not:** any new approval workflow, screen or page. Void, refund and day-lock approvals by the Branch Manager (owner items 9
  to 11) and the PO and supplier-bill flows (items 20 and 22) are Billing's and Inventory's slices **after** ROLE-1. ROLE-1 gives
  each role **only permissions that already exist**, and creates **no new permission** (so no `PermissionCatalogue::all()` growth).
- **Staff-authority rules unchanged:** none of the three roles holds anything in `AUTHORITY_OVER_PEOPLE_AND_ACCESS`
  (`staff.manage`, `branches.manage`, `access.manage`, `clinician_credentials.manage`), so none can administer anyone and each
  is administrable by Director, Technical Admin and HR Manager like every other non-authority role. `canAssignRoles` is not touched.

## 2. Permission table: the three new roles

All three also get the shared base: `dashboard.view.own`, `profile.view.own`, `staff.view.own`, `insights.view.organisation`
(every catalogue role has it), and the HR self-service grants every human role has (section 4). Scope words: **B** = `.branch`
(works only at the person's assigned and active branch), **O** = `.organisation`.

### 2.1 `branch_manager` (assigned per branch, like CA Supervisor)

| Area | Permissions | Why / source |
|---|---|---|
| Base | `staff.view.branch`, `branches.view.branch`, `branch_context.switch.branch` | the CA Supervisor pattern |
| Registration, queue (view only) | `visits.view.branch`, `visits.history.view.branch`, `queue.view.branch` | item 1 "branch views of registration and queue" |
| Billing (view only) | `billing.view.branch`, `billing.summary.branch`, `outstanding.view.branch` | item 1 "branch views of billing" |
| Daily close | `payments.reconcile.branch`, `payments.cash_closing.approve.branch` | item 11: the Branch Manager approves the close (these exist today; CA counts, so the CA's count right is unchanged) |
| Stock (view only) | `inventory.view.branch` | item 1 "branch views of stock" |
| Reports, own branch | `reports.daily_summary.view.branch`, `reports.daily_summary.export.branch`, `reports.stock_movement.view.branch`, `reports.stock_movement.export.branch` | item 7. All four are `.branch`, so "own branch only" is already enforced by the effective-branch rule |
| Branch settings | none | not asked for |

**Deliberately NOT granted in ROLE-1 (decision D1):** `invoices.void.branch`, `payments.refunds.approve.branch`,
`payments.refunds.record.branch`, `payments.reverse.branch`. They exist today and a grant would let a Branch Manager void or approve
a refund of any amount **now**, before Billing builds the "refund over RM300 goes to Finance first" rule and the day-lock rule
(items 9 to 11). Billing's slices add what each approval needs and grant it to `branch_manager` then.

### 2.2 `account_assistant` (HQ finance: records and views, never approves)

| Area | Permissions | Why / source |
|---|---|---|
| Base, HQ reach | `staff.view.organisation`, `branches.view.organisation`, `branch_context.switch.organisation` | the Finance Officer pattern, so reports and supplier bills can be read for any branch |
| Supplier bills | `inventory.supplier_invoices.record.branch`, `inventory.view.branch` | item 22: records the bill; **not** `inventory.supplier_invoices.approve.branch` (Finance accepts) |
| Cost | `inventory.cost.view.organisation` | item 6 |
| Audit log | `audit.view.organisation` | item 5 (read only) |
| Reports, view | `reports.daily_summary.view.branch`, `reports.stock_movement.view.branch`, `reports.panel_summary.view.branch` | "views reports"; view only, no download (decision D2) |
| Never | any `.approve.`, `.reverse`, `.void`, `payments.*`, `prices.publish`, `pricing.references.manage`, patient permissions | "never approves" |

### 2.3 `store_admin` (HQ central store: stock and purchasing only)

| Area | Permissions | Why / source |
|---|---|---|
| Base, HQ reach | `branches.view.organisation`, `branch_context.switch.organisation`, `staff.view.branch` | needs to work at the central store branch and see branch names for requests |
| Stock | `inventory.view.branch`, `inventory.receiving.branch`, `inventory.stocktake.branch`, `inventory.warehouse.organisation` | "receive" and "count the store" |
| Purchasing | `inventory.purchase_orders.create.organisation`, `inventory.purchase_requests.create.branch` | item 20: prepares POs; **not** `inventory.purchase_orders.approve.organisation` (Director approves every PO) |
| Branch requests | `inventory.stock_requests.approve.branch`, `inventory.transfers.dispatch.branch`, `inventory.transfer.organisation` | "issue branch requests" (the existing stock-request and transfer permissions) |
| Cost | `inventory.cost.view.organisation` | item 6 |
| Never | patient, visit, queue, billing, payments, reports, `inventory.suppliers.manage`, `inventory.references.manage`, variances/holds/recalls approvals | "no patients, no money" |

### 2.3b Inventory team's wider proposal for `store_admin` (Zulkifli, 21 Nov) and how it differs from 2.3

Zulkifli proposes everything in 2.3 **plus**: `inventory.references.manage.organisation` (items, SKUs, locations, batches, unit
verification), `inventory.reorder.manage.branch`, `inventory.receiving.exceptions.approve.branch`,
`inventory.stock_requests.create.branch`, `inventory.transfers.receive.branch`, `inventory.transfer.branch`,
`inventory.supplier_returns.request.branch`, `inventory.batch_holds.place.branch`, `inventory.stock_reviews.manage.branch`,
`inventory.opening_balance.branch`; later, if the owner approves those slices, `inventory.differences.report.branch` and
`inventory.cost.enter.organisation`. NOT: PO approve, supplier invoice approve, supplier return approve, batch hold release,
valuation view, variance approve, `inventory.suppliers.manage.organisation`.

Staff Experience's concerns (each needs the owner's word, not mine):
- A "stock and purchasing only" role would hold the **reference-data** power (`references.manage`: base-unit verification, SKUs,
  locations) and `opening_balance`. Their existing independence rules (a second person verifies the opening; the base-unit sign-off)
  still apply, but the Store Admin becomes a day-to-day author of stock reference data. Acceptable if the store runs it.
- `receiving.exceptions.approve.branch` for a role that also **receives**: the rule that the approver is never the receiver exists
  in the domain and PostgreSQL, so one person cannot approve their own receipt; a one-person central store could never approve
  anything. Confirm there will be a second approver (CA Supervisor, Finance Officer or Director).
- `stock_reviews.manage`, `batch_holds.place`, `supplier_returns.request`, `transfers.receive`, `transfer.branch`, `reorder.manage`,
  `stock_requests.create` are consistent with "stock and purchasing only".
- The two **later** permissions (`differences.report`, `cost.enter`) do not exist yet: they are added by Inventory's own slices
  and granted then, not in ROLE-1. Cost entry by hand (item 24) is limited to Finance Officer or Store Admin by the owner.

**Shape for the owner:** (A) narrow, the table in 2.3 (what the owner's list names); (B) wide, 2.3 plus the list above. I
recommend (B) minus `references.manage` and `opening_balance` until the owner says the store may author reference data
(decision D3). `account_assistant` per Zulkifli matches 2.2 (he proposes fewer: only `inventory.view`, `supplier_invoices.record`,
`cost.view`; I also give the audit log, the organisation reach and three report views from owner items 5 and 6 and A2).

Open for Zulkifli/Mei Ling (D3): whether `inventory.stock_requests.create.branch` (the store asking a supplier) and
`inventory.suppliers.manage.organisation` (supplier records) belong to the store role; I left them out because the owner's list
does not name them.

## 3. Access grants to existing roles

| Grant | To | Note |
|---|---|---|
| `audit.view.organisation` | `finance_officer`, `account_assistant` (above), `panel_officer` | read only; Director, HR Manager, Technical Admin keep it. The audit log page is a plain list of structural events (no values); owner item 5 |
| `inventory.cost.view.organisation` | `store_admin`, `account_assistant` (above) | Director and Finance Officer already hold it; Doctors never |
| branch-scoped report download | `branch_manager` (above) | via four existing `.branch` permissions; the organisation-wide report exports (`reports.sales.export.organisation`, `reports.payments.export.organisation`) stay with Director, Finance Officer, Administrator only |

## 4. HR self-service for the new roles

Every human role holds the twelve `hr.*.own` self-service permissions (roster, leave, clock, records, credentials, SOPs, memos,
messages, payslips, claims submit, incidents, appraisal view). They come from `RosterPermissions::HUMAN_ROLES`. The three new
roles are **added to `HUMAN_ROLES`** so they get them in one place (and the SOP/memo audiences include them). This is the
single biggest source of existing-test changes (section 6). The TV account and the locum stay outside the list.

## 5. Provisioning and who can assign what

- Roles are created by one **additive** migration (`firstOrCreate` role and permissions, `givePermissionTo` the missing ones,
  never `syncPermissions`, `down()` a no-op), the ADM-01 pattern; the catalogue mirrors it so a fresh install agrees.
- `canAssignRoles` requires the assigner to hold every permission of the role being assigned. Expected after the grants (to be
  proven by tests, not assumed): the **Director** can assign all three; the **Finance Officer** can assign `account_assistant`
  (its permissions are a subset of the Finance Officer's once `audit.view` is added); `store_admin` and `branch_manager` need the
  Director (neither is a subset of any other non-Director role). I will pin this in `StaffAuthorityBoundaryTest`-style tests.
- A person gets a branch through the existing branch assignment (`BranchAssignmentService`). A Branch Manager is assigned to one
  branch (or more, as a CA Supervisor can be); `account_assistant` and `store_admin` get an HQ assignment. No change to the
  "exactly one effective primary branch" rule.
- The Access control page, staff Create/Edit role cards and the role labels read the role list from the catalogue and the database, so
  they should show the new roles with no screen change; the role display names ("Branch Manager", "Account Assistant", "Store
  Admin") are added where `RosterRules` and the access fixture keep a name map. **No screen is designed in ROLE-1**; if a screen
  needs to change, Daniel looks first.

## 6. Existing assertions I expect to touch (leave failing until the owner approves the table)

I have not run the suite. This is a prediction from reading the code; I will replace it with the exact failing list by running the
affected suites on the branch **before** changing any test, and send that list for approval.

- Role-list pins in Access and staff tests: the catalogue's exact role list and count, `AdministratorRoleTest`-style "exactly these
  roles hold" lists, `tests/Frontend/fixtures/access-control-roles.json` and the Access-control parity tests.
- HR tests that pin "all human roles" (the `HUMAN_ROLES` pins in the roster, leave, attendance, records, credentials, SOP, memo,
  claim, message and payslip tests) and the HR-PLAN pins.
- `ClinicManagementTest`'s exact destination map **only if** a role's hub rows change; the grants above add no new page, so I expect
  none, but a new role's hub list is a new case.
- `StaffAuthorityBoundaryTest`: its invariant that Director can provision and administer every role except Director grows by the
  new roles (an addition, not a weakening); the authority-set pin is **unchanged** (no new permission is administrative).
- Tests of `audit.view.organisation` and `inventory.cost.view.organisation` holder lists (these gain holders).
- Report permission holder lists (`reports.*` branch holders gain `branch_manager`/`account_assistant`).

## 7. Steps

1. This note: Aiman's review, then the owner approves the table (via B.E.R.N.I.S.S). Decisions D1 to D4 below get answered.
2. On a branch: add the roles and grants to `PermissionCatalogue` and `HUMAN_ROLES`, write the additive migration, and **run the
   affected suites to get the exact failing list**; send it for approval; only then update tests.
3. New tests: each role holds exactly its table (and nothing in the authority set), the migration (fresh grant, already-seeded,
   up/down/up, never removes, missing role logged), who may assign each role, and a sample of server refusals (a store admin
   gets 403 on a patient page, a branch manager 403 on `invoices.void`, an account assistant 403 on an approve route).
4. Full gate (Pint, PHPStan, frontend checks, build, parallel tests, PostgreSQL contention suite) and, if any screen changes,
   Daniel's look. No PR without the owner's ship-it.

## 8. Decisions for the owner (via B.E.R.N.I.S.S)

- **D1** Branch Manager gets void and refund **approval** in Billing's later slices, not now (section 2.1). Confirm.
- **D1b** (agreed with Farah, Billing, 21 Nov, open on one point): ROLE-1 grants only existing permissions and **creates none**;
  the new "approve a void" permission is created and granted in Billing's void slice, one owner OK per slice. The Branch Manager
  has **no recording rights** (no `payments.add`, `refunds.record`, adjustments, responsibility proposals). **Open point:**
  today's `payments.reconcile.branch` is the permission to **record the cash count** and to open the daily-close pages; granting
  it lets a Branch Manager also count (the approver is never the counter, so they still cannot approve their own count). If the
  owner wants "approves, never records", Billing's close slice adds a view-only permission instead and ROLE-1 grants only
  `payments.cash_closing.approve.branch` plus that. Farah proposed `payments.refunds.approve.branch` now; I keep it for the refund
  slice, because the amount rule (over RM300 to Finance first) is built there.
- **D2** Which reports may the Branch Manager download and the Account Assistant view? I propose Daily summary and Stock movement
  for the Branch Manager; Daily summary, Stock movement and Panel summary (view only) for the Account Assistant. The two reports
  that list patients by name (Transaction detail, Billing detail) and the organisation-wide Sales and Payments downloads are
  **not** included; say if you want any added.
- **D3** Store Admin: narrow (2.3) or wide (2.3b); reference-data authoring and opening balances; supplier records (`suppliers.manage`).
- **D5** (from Zulkifli) Does the Finance Officer lose `inventory.purchase_orders.approve.organisation` (owner item 20, "the Director approves every PO")? Today the Finance Officer approves POs (STK-19, owner decision 11 Nov 2026). **ROLE-1 removes no permission from any existing role**; any removal belongs to Inventory's slice (20) after the owner's explicit word. The Director keeps `supplier_invoices.approve.branch` (with the Finance Officer; STK-22).
- **D4** Audit log (checked in the code, 21 Nov): `audit.view.organisation` gives (1) the **Audit logs** page (`/audit-logs`):
  organisation-wide, **all branches**, newest first, 25 a page, no filter. Each row shows only: the event name (for example
  `billing.payment_added`), the actor's name, the branch code, a generic subject label ("Patient record", "Financial record"…),
  the time and the actor's role names. For financial and clinical-safety events the actor shows as "Authorized user" or "Clinical
  user", the role names are empty, and the subject id is hidden for every patient, visit, queue, clinical, dispensary, stock and
  check-in record. **No metadata, values, amounts, names of patients or free text are shown** (the only extra is a one-line note
  on Call next events). (2) A second, easy-to-miss effect: the **staff page** shows each staff member's **15 most recent access
  and branch-assignment events** (event, actor, role names, time) to any viewer holding `audit.view.organisation` who can open
  that page. So Finance Officer, Account Assistant and Panel Officer would also see staff access history through the staff page
  where they can open it. Please confirm they may read all of this.
  they may read every audit row (not just their own area).
- **Not decided here, for Billing:** the exact Branch Manager permissions for void, refund and day lock, and whether a Branch
  Manager at one branch may approve another branch's items (I assume not: `.branch` scope).

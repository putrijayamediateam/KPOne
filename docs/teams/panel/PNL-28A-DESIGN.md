# PNL-28a design note: claims created when a Panel bill is approved

**Status:** draft for Priya's review, then Daniel's look and the owner's ship-it. Docs only. It authorises nothing and
builds nothing. Written by Syafiq (Panel Lead), 21 Nov 2026.

## 1. Owner decisions this note follows (round 2, 21 Nov)

1. The system creates the claim when the Panel bill is approved. The audit log names the approver. No new permission.
2. **Overdue** means a Prepared claim not submitted by its Panel's due date.
3. A "Create claims for waiting invoices" button for the Panel Officer.
4. **Submit** records today's date. Photos and remarks can still be added on the claim page.

## 2. What happens today

- `ResponsibilityService::approve()` (Billing) approves a Panel allocation in its own `DB::transaction`, under the
  approver's `coverage.approve.branch` permission and Panel approval limit. The invoice is already finalized by then.
- A Panel Officer opens Pending, presses Create claim, and `PanelClaimService::create()` builds the claim (Submitted).
  `prepare()` builds a Prepared claim. Both go through `buildLockedSnapshot()` and `requireClaimable()`.
- `prepare()` and `create()` need `panel.claims.manage.branch`. The CA who approves does not hold it.

## 3. Proposal

### 3.1 Automatic Prepared claim (system step)

- `ResponsibilityService::approve()` wraps its work in `DB::transaction(..., 3)`, which can retry on deadlock. The
  creator is therefore called **after `DB::transaction` returns** (not inside the closure, so it can never run twice
  from a retry and a claim problem can never undo the approval), for `kind = panel` only, as
  `PanelClaimAutoCreator::forAllocation($allocationId, $approverUserId)`, inside `try/catch (Throwable)` with a log, so a
  claim failure can never turn the approval response into a 500. It runs inline in the approver's request (adds a
  little latency, no queue in v1).
- `PanelClaimAutoCreator` (new, `app/Domain/Visit/Billing/Services`) runs its own `DB::transaction`. There is no acting
  person, so it skips `TransactionalActorAuthority::lock()` (the actor and permission check). **This deviates openly
  from the AGENTS.md rule of a permission check on every state change:** the authority is the approval's own
  `coverage.approve.branch` check and Panel approval limit, already passed. To keep the deviation small and tested,
  `PanelClaimService` gets one explicit entry point, `prepareForApprovedAllocation(User $approver, CoverageAllocation)`,
  which loads the branch from the allocation (organisation and branch must match the approver's), runs
  `requireClaimable` and the same row locks (invoice, then allocation, the order `approve()` uses), and reuses
  `buildLockedSnapshot()` after its `$actor`/`$branch` inputs are supplied by that entry point. A test fails if any other
  caller reaches it.
- `created_by_user_id` stays the approver (the column is NOT NULL with a foreign key to users, the identity trigger
  compares it, and the event, `PanelShare::recordVersion` and audit all take a `User`). Because the approver did not
  press anything, the audit row and event carry `system_step: true` and `approved_by_user_id`, so nobody misreads them.
- Idempotency: the guard is the unique `original` claim per allocation (`coverage_allocation_id`) and the exists-check in
  `buildLockedSnapshot`. No idempotency key is written (`creation_idempotency_key` is a `uuid` column and belongs to
  `create()`, not `prepare()`). "A claim already exists" and a unique violation on `coverage_allocation_id` are
  treated as **skip (`already_claimed`)**, not an error, so the button and a double approval race cleanly.
- Audit `panel_claim.auto_created` with the claim id, allocation id, amount, `system_step`, `approved_by_user_id`
  (ids and codes only, no patient values). Event `prepared` is written as for `prepare()`.
- Failure is isolated and **visible**: if the step throws, the approval stays, nothing is half-written, an audit row
  `panel_claim.auto_create_failed` (allocation id and error code only) is written in a separate transaction, and the
  Pending tab shows "N approved invoices have no claim yet" with the button from 3.3. No retry loop in v1.
- **No new permission.** The claim is created by the system, not by the approver, so the CA gains nothing.

### 3.2 Invoices that must not get an automatic claim

`requireClaimable()` already refuses these and the creator treats each refusal as "skip, no claim, no error":
voided or reissued invoices (the allocation is no longer the current guard), `correction_hold`, `source_stale`, an
allocation that is not `approved`, a Panel share that is zero or above the invoice total, and a claim that already
exists (`already_claimed`, including a lost race). Each has a reason code. A later reissue produces a
new allocation and so a new automatic claim; the old claim path (PCA-2 supplementary claims) is untouched.
A test per case proves no claim row appears.

### 3.3 "Create claims for waiting invoices" button

- On the Pending tab, for `panel.claims.manage.branch` holders, one button creates Prepared claims for every approved
  Panel allocation at the active branch that has none (the ones the system step skipped or failed on, and everything
  approved before this change).
- At most 50 at a time (the existing bulk create limit is 20 and the pack limit 50; 50 is chosen because each claim is
  its own transaction), oldest first, each through the same creator, so one bad invoice never blocks the rest. The
  result says how many were created, how many were skipped (reason code per invoice) and "N still waiting, press
  again". Nothing is silently left behind. Audit `panel_claim.bulk_auto_created` with count and the actor.
- Existing Create claim (Submitted at once, photos) stays as it is for officers who want to submit in one step.

### 3.4 Overdue

- A claim is **Overdue** when it is `prepared`, has not been submitted, and the Panel's due date (existing
  `PanelClaimDueRule` for the visit date and the Panel's rule) is in the past in the branch time zone.
- **This redefines Overdue, it does not refine it.** Today Overdue is invoice-only (`PanelClaimDirectoryService`
  `INVOICE_TABS`, the Overdue count and `summary()`), and the claims tabs have no prepared or overdue filter. After
  3.1 most invoices have a claim straight away, so: Pending lists only approved invoices with no claim (and carries
  the "N have no claim yet" line); Overdue becomes a claims query on `status = 'prepared'` with
  `PanelClaimDueRule::isOverdue`; its count and `summary()` switch with it. A Submitted claim is never Overdue. The
  claims list gains a Prepared status label so many Prepared rows read clearly.
- Panel summary already treats a Prepared claim with no `submitted` event as unclaimed
  (`PanelSummaryReportService`), so its figures keep their meaning; a test pins it.
- **Follow-up list changes:** it counts `prepared` as open with Prepared events as actions, so an auto-created claim
  nobody touches would appear there after the Panel's follow-up days. Decision proposed: the Follow-up list excludes a
  Prepared claim that was never submitted (that is Overdue's job, not follow-up's), so Follow-up keeps its meaning of
  "submitted and waiting on the Panel". The owner may overrule.

### 3.5 Submit

- `submit()` already takes no date: the submitted date is the event's `occurred_at` (now), so "today's branch date"
  needs no code change. The note only names it; the claim page shows it in branch time. Photos and remarks stay
  editable on the claim page afterwards.
- Create claim keeps its date field (an officer may back-date within the visit date to today).

## 4. Rules that stay the same

Amounts, approval limits, `lock_version`, the claim pack, remittances, reversals, PCA-2 supplementary claims and
Farah's invoice-side Panel claim button. No migration expected. If the Overdue list needs an index on
`(status, panel_id)` that is the only schema change and will be raised first.

## 5. Tests proposed

- Approval creates exactly one Prepared claim; the audit row names the approver; the approver gained no permission.
- Approval still succeeds when the claim step throws (forced failure); no half-written claim.
- Voided, reissued, correction-hold, stale-source and wrong-status allocations produce no claim.
- Two simultaneous approvals or an approval racing the button: one claim (real two-process race in the serial
  Contention suite).
- Button: creates the missing claims, skips and reports the others, respects the 50 limit and branch scope.
- Overdue list: a Prepared claim past due appears; a Submitted one never does; a Panel's rule (days, daily, weekly,
  monthly) is honoured.
- Submit records today's branch date; photos and remarks can be added afterwards.
- Panel summary and Insights Payments figures are identical before and after for the same data. The Follow-up list
  excludes never-submitted Prepared claims (a deliberate change, tested).
- Approval still returns normally when the creator throws, and `auto_create_failed` is audited.
- The new entry point cannot be called from anywhere but the approval path and the button (source test).

## 6. Existing assertions expected to change (for the owner, through B.E.R.N.I.S.S, before any code)

To be confirmed by running the suite on a spike, but these are the likely ones:

1. Any test that approves a Panel allocation and then calls `prepare()` or `create()` now meets "A claim pack already
   exists": the PC-02 create tests, the PNL-2 bulk claim tests, `PanelClaimsTest` generally.
2. `PanelClaimsTest` and `panel-claims` frontend tests that pin the Pending and Overdue tab contents.
3. Likely setup-only changes: the PNL-9 contention tests, `PanelClaimAdjustment` tests, the Panel claim-pack tests,
   the Follow-up list tests and the Insights and agent Panel tool fixtures.

**Spike result (21 Nov, throwaway branch, not kept).** A minimal creator hooked after `approve()` was run against
`tests/Feature/Billing`, `Visit`, `Agents` and `Insights` on PostgreSQL (contention suite excluded), compared with
the same classes on unmodified `main`. The baseline has 8 errors that are not ours (6 in `PanelClaimsTest` and 2 in
`DocumentLetterheadTest`, photo and logo tests that need the GD extension, which this container lacks). The spike adds
**123 new failing tests in 23 classes**. Almost all fail for the one reason in item 1 (a fixture approves a
Panel allocation and then creates the claim itself). Counts of new failures per class:

| Class | New failures |
|---|---|
| PanelClaimsTest | 16 |
| PanelClaimAdjustmentTest | 13 |
| PanelBulkClaimTest | 12 |
| PanelRemittanceTest | 10 |
| PanelPaymentCorrectionTest | 9 |
| PanelStatementTest | 7 |
| PanelShortfallTest | 7 |
| PanelFollowUpTest | 7 |
| PostgresPanelPaymentRegressionTest | 6 |
| InvoiceAdjustmentReportingTest | 6 |
| PanelClaimPackTest | 5 |
| PostgresPanelClaimEditRaceTest, PostgresPanelBulkClaimRegressionTest, PostgresPanelAdjustmentRegressionTest | 4 each |
| PostgresPanelBulkVsDecisionRaceTest, PanelStaffNameVisibilityTest, PanelAdjustedInvoiceTotalsTest, OutstandingBillsTest | 2 each |
| SalesSummaryExportTest, DailyReportExportTest, MoneyFlowWalkTest, InvoiceBillToTest, InvoiceAdjustmentTest | 1 each |

The shared helper `ApprovedPanelAllocations::approvedAllocation()` (used by the Panel tests) and a few direct
`ResponsibilityService::approve()` fixtures are where an automatic claim appears. The plan is to fix them **in setup,
not in expectations**: a test-only way to approve without the creator (for example a container binding the helper
swaps in), so every existing expectation stays exactly as written. Only the tests that deliberately check the new
behaviour (Pending and Overdue contents, Follow-up exclusion) get new or changed expectations, and those are listed
for the owner one by one once built. Frontend tests were not part of the spike. Whether a handful of the 123 need
expectation changes rather than setup changes is only known after the setup fix; I will report that count before
the PR.
For fixtures that only need to start without an automatic claim, the preferred fix is a setup flag (approve through
the service with the creator disabled), not edited expectations.

No assertion will be loosened: each changes only in what exists after approval, with the new expected value pinned.

## 7. Priya's review (21 Nov) and what changed

Answers: call the creator after `DB::transaction` returns, in try/catch (section 3.1); `created_by_user_id` stays
non-null and is the approver, marked as a system step; 50 is fine if the "N still waiting" message exists. Findings
taken in: the idempotency key was dropped (uuid column); the entry point is explicit and tested; the race counts as a
skip; Follow-up no longer floods; Overdue is stated as a redefinition; failures are visible; Submit needs no code
change; the existing-assertion list is widened and comes from a spike run.

## 8. Order

28a (this note) → Priya's review → Daniel's look at the Pending/Overdue tabs and the button → owner's ship-it → build.
16b/16c and the Back-to-invoice end follow separately.

## 9. Daniel's wording and layout (21 Nov, OK with changes) and owner answer

Owner (relayed 21 Nov): the Follow-up list leaves out never-submitted Prepared claims. Daniel needs real shots at
1440 and 390, light and dark, before the PR.

- **Pending tab:** "N approved invoices have no claim yet" is a warning-token line with a dot and words (not red),
  shown only when N > 0. One-line meaning under the tabs: Pending = "Approved invoices with no claim."; Overdue =
  "Prepared claims not submitted by the Panel's due date."
- **Button:** "Create claims for 12 waiting invoices"; above 50: "Create claims for the oldest 50 of 73 waiting
  invoices". Outline unless it is the only action (one filled button per page), 44 px below 1024 px, disabled and
  "Creating…" while running. Beside it: "Creates a Prepared claim for each. Nothing is submitted."
- **Result panel** (not only a toast): "Created 12 claims. Skipped 3. 8 still waiting — press again." Skipped invoices
  listed by invoice number with a plain reason ("Invoice was reissued", "Panel share is zero", "Held for
  correction", "Claim already exists"), the code in a muted title. Dismissible, stays after the list refreshes.
- **Status:** Prepared chip = neutral dot and the word "Prepared"; Overdue = warning dot and the word "Overdue". Tab
  counts equal what each tab lists (also at 390 with the scrolling tabs). On phone cards the chip is on the first line.
- **Follow-up page:** muted line "Claims not yet submitted are on the Overdue tab."
- **Claim page log line:** "Claim prepared automatically when the Panel bill was approved by <name>" (staff-visibility
  rule, "Staff member" otherwise). Submit button "Submit claim" with "Submitted date will be today, dd/mm/yyyy
  (branch time)." and no date box; Create claim with its date field stays as the second route.

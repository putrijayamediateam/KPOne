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

- After the approval transaction **commits** (never inside Billing's transaction, so a claim problem can never undo or
  delay an approval), `ResponsibilityService::approve()` dispatches `PanelClaimAutoCreator::forAllocation($allocationId,
  $approverUserId)` for `kind = panel` only.
- `PanelClaimAutoCreator` (new, `app/Domain/Visit/Billing/Services`) runs its own `DB::transaction` with no actor
  permission check, because there is no acting person: it is a system step. It checks the same invariants as a person
  would (organisation and branch of the allocation, `requireClaimable`, no `original` claim yet) under the same row
  locks (invoice, then allocation) and reuses `buildLockedSnapshot()` unchanged. `created_by_user_id` is the approver, so
  the claim snapshot is the same shape as today's.
- Idempotency: the unique `original` claim per allocation already refuses a second claim. The key is deterministic
  (`auto:{allocation_id}`), so a retry, a queue replay or the button in 3.3 finds the existing claim and returns it.
- Audit `panel_claim.auto_created` with the claim id, allocation id, amount and `approved_by_user_id`
  (ids and codes only, no patient values). Event `prepared` is written exactly as for `prepare()`.
- Failure is isolated: if the step throws, the approval stays, the failure is logged, nothing is half-written
  (own transaction), and the invoice stays in Pending, where 3.3 picks it up. No retry loop in v1.
- **No new permission.** The claim is created by the system, not by the approver, so the CA gains nothing.

### 3.2 Invoices that must not get an automatic claim

`requireClaimable()` already refuses these and the creator treats each refusal as "skip, no claim, no error":
voided or reissued invoices (the allocation is no longer the current guard), `correction_hold`, `source_stale`, an
allocation that is not `approved`, a Panel share that is zero or above the invoice total. A later reissue produces a
new allocation and so a new automatic claim; the old claim path (PCA-2 supplementary claims) is untouched.
A test per case proves no claim row appears.

### 3.3 "Create claims for waiting invoices" button

- On the Pending tab, for `panel.claims.manage.branch` holders, one button creates Prepared claims for every approved
  Panel allocation at the active branch that has none (the ones the system step skipped or failed on, and everything
  approved before this change).
- At most 50 at a time (as the pack limit), oldest first, each in its own transaction through the same creator, so
  one bad invoice never blocks the rest. The result says how many were created and how many were skipped, with the
  reason code per skipped invoice. Audit `panel_claim.bulk_auto_created` with count and the actor.
- Existing Create claim (Submitted at once, photos) stays as it is for officers who want to submit in one step.

### 3.4 Overdue

- A claim is **Overdue** when it is `prepared`, has not been submitted, and the Panel's due date (existing
  `PanelClaimDueRule` for the visit date and the Panel's rule) is in the past in the branch time zone.
- Pending and Overdue today list invoices with no claim. After 3.1 most invoices have a claim straight away, so the
  Pending tab keeps only invoices without one and the Overdue tab lists Prepared claims past due. A Submitted claim is
  never Overdue. Counts, filters and the claim page's due line follow the same rule. Panel summary's
  "unclaimed" column counts Prepared and no-claim invoices together, so its figures do not change meaning (test).

### 3.5 Submit

- The Submit action on a Prepared claim records today's branch-local date as the submitted date (no date picker), with
  the existing version check and idempotency key. Photos and remarks stay editable on the claim page afterwards.
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
- Panel summary, Follow-up list and Insights Payments figures are identical before and after for the same data.

## 6. Existing assertions expected to change (for the owner, through B.E.R.N.I.S.S, before any code)

To be confirmed by running the suite on a spike, but these are the likely ones:

1. `PanelClaimsTest`: tests that approve a Panel allocation and then expect the Pending list to hold the invoice, or
   expect no claim row before an officer presses Create claim, now see a Prepared claim already there.
2. `PanelClaimsTest` and `panel-claims` frontend tests that pin the Pending and Overdue tab contents.
3. Any Panel summary or Follow-up fixture that counts claims after approval (expected to need setup changes only).

No assertion will be loosened: each changes only in what exists after approval, with the new expected value pinned.

## 7. Questions for Priya

1. Is dispatching after commit from `approve()` safe against a rollback of the caller, or should it be an
   `afterCommit` listener?
2. Should the creator write `created_by_user_id` as the approver, or null with a system marker?
3. Is a 50 limit on the button right, or should it page?

## 8. Order

28a (this note) → Priya's review → Daniel's look at the Pending/Overdue tabs and the button → owner's ship-it → build.
16b/16c and the Back-to-invoice end follow separately.

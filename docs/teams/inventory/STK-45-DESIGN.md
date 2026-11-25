# STK-45: Report a difference

Revision 1, for Mei Ling's review, Daniel's look and the owner's approval. **Nothing is built until the owner approves
this note.** Synthetic data only; not production-approved.

**Owner decision:** "Stock quantities still never typed in directly; add **Report a difference** for staff, which
opens a count case for approval" (`docs/decisions/2026-11-20-finance-inventory-proposals.md`, item 18). The order
STK-44 → STK-45 is the owner's (TEAM.md decision 47). The permission is the owner-approved **P10**; its holders are
listed below for the owner to confirm the exact name.

## The idea in one paragraph

A CA notices the shelf doesn't match KPOne (for example KPOne says 200 Paracetamol tablets, the box clearly holds
far fewer). Today only a CA Supervisor or Store Admin can open a stock count, so the CA has to find one and explain.
With STK-45 the CA presses **Report a difference** on that stock line, picks what they saw and why, and sends it.
That opens the **existing governed stock count case** (STK-5) for that batch and location. From there nothing
changes: a counter counts it blind, someone else recounts any difference, and a third person approves. **The report
itself never changes stock.** No quantity is typed into stock by the reporter.

## What staff see

**Where the button is.** On the Inventory stock list (the balance rows at the active branch) and on Stock review
rows, a row menu item **Report a difference**. It shows only to holders of P10, and only for stock at the active
branch.

**The sheet** (a side sheet on desktop, full screen below 640 px):

- Header: the medicine, batch and expiry, location, and "KPOne shows 200 tablets".
- **What did you see?** (required, one choice):
  - "Fewer than KPOne shows"
  - "More than KPOne shows"
  - "Some are damaged or expired"
  - "Not sure; please count it"
- **About how many?** (optional, whole or decimal number in the stock unit, labelled "Your rough count, if you
  have one. The counter won't see it."). This is a note for the approver, not a stock figure.
- **Note** (optional, 200 characters, plain text; hint: "Don't write patient names or details.").
- Buttons: **Send for counting** and **Cancel**.

After sending, a toast: "Sent. A stock count was opened for this batch." The row shows a small neutral chip
"Count open" (STK-5 already lists open cases on Stock count).

**If a count is already open** for the same batch and location, the sheet says "A count is already open for this
batch. Your report has been added to it." and adds the report to that case rather than opening a second one. This
avoids two cases on one balance.

**Where the reports show.** On the governed Stock count page, an open case started by a report shows "Reported by
<name>: Fewer than KPOne shows" under the item. The **reporter's rough count and note are hidden from the counter
and recounter** (the count stays blind) and shown only to the approver, beside the counted figures.

**My reports.** A small "My reports" list on the same sheet's page (the last 25 of mine at this branch: item,
what I saw, when, and the case state in words: "Waiting for count", "Being recounted", "Waiting for approval",
"No difference found", "Approved"). Read only.

## Who can do what

| Action | Permission | Roles |
|---|---|---|
| Report a difference | **P10** (proposed name `inventory.differences.report.branch`) | CA, CA Supervisor, Store Admin, Branch Manager, Medical Assistant (the CA → Medical Assistant rule) |
| Count and recount | existing `inventory.stocktake.branch` | CA Supervisor, Store Admin (unchanged) |
| Approve | existing `inventory.variances.approve.branch` | CA Supervisor, Finance Officer, Director (unchanged) |

**The second-person rule.** The reporter can never count, recount or approve the case their report opened or joined
(added to the existing STK-5 exclusion list: counter, recounter and proposer). So a CA Supervisor who reports a
difference needs another holder to count it, as with any STK-5 case today. A test proves each refusal.

## Rules

- **The report never moves stock.** It writes one report row and, if no count is open, one STK-5 count case (state
  `awaiting_count`). The case starts from a snapshot of the balance at that moment, exactly as STK-5 `propose()`
  does. Only an approval after an independent recount can post a movement, unchanged.
- **One open count per balance.** Inside the transaction, with the balance row locked, if a case of kind `count` is
  open on that balance (not `closed_zero` or `posted`), the report joins it; otherwise a new case is created. A
  PostgreSQL partial unique index on open count cases per balance backs this up. *(To check during the build: if
  STK-5 data already allows two open count cases on one balance, the index goes on report rows instead and the
  service rule stays.)*
- **The case's proposer** is the reporter (`proposer_user_id`), so the existing STK-5 exclusion already keeps them
  away from approval; STK-45 adds them to the counter and recounter exclusion too. The service calls a new internal
  `GovernedStockService::openCountForReport()` that skips the `inventory.stocktake.branch` check (the report is the
  permission) but keeps everything else from `propose()`. No new public route can open a case without a report.
- **Stock that can't be counted** is refused with a plain message: a deactivated SKU, a returns location (it can be
  counted only by STK-5 directly), or a balance at another branch (404).
- **Every write:** permission and active branch checked again under the user's row lock (`InventoryAuthorityService`),
  `DB::transaction`, the balance and any open case locked in the STK-5 order (case, then SKU, batch, balance),
  the balance `lock_version` from the page (a stale page is refused: "Stock changed. Reload before reporting."),
  an idempotency key (`retry_key`, the STK-5 retry pattern) and one audit row.
- **The rough count** is stored in the stock unit with three decimals (`StockUnitQuantity`), must be zero or more, and
  is never used in any calculation.
- **The note** is encrypted at rest (as other free-text staff notes), never audited, never sent to agents, and not in
  any `ai_read` view.

## Data

One new append-only table, `inventory_difference_reports`:

- `id`, `public_id`, `organisation_id`, `branch_id`, `balance_id`, `case_id` (the STK-5 case it opened or joined),
  `reporter_user_id`;
- `observed` (`fewer`, `more`, `damaged_or_expired`, `unsure`; PostgreSQL CHECK);
- `rough_quantity` (decimal 15,3, nullable, ≥ 0), `base_unit`;
- `note` (encrypted text, nullable);
- `opened_case` (boolean: did this report open the case, or join one);
- `retry_key`, `payload_hash`, `created_at`.

PostgreSQL: update, delete and truncate refused (the STK-5 append-only trigger pattern); an insert trigger checks
that the case is a `count` case on the same organisation, branch and balance. Migrations in the Inventory range,
next free number after `2026_11_07_003500`: the table, then the **P10 grant** (additive, never revoking, a missing
role logged and skipped, `down()` a no-op).

## Audit

`inventory.difference_reported`: report id, case id, balance id, `observed` code and `opened_case`. No note, no rough
count, no names.

## Not in this slice

- No photo upload (a possible later slice).
- No reminder or message to the counter; the open case shows on Stock count as today.
- No change to the STK-5 counting, recount or approval rules or screens beyond the "Reported by" line and the hidden
  rough count for the approver.
- No report from a phone camera or barcode scan (STK-9 scan can still find the row).

## Known limits

- A report on a balance whose stock is all held (STK-2 hold) still opens a count; that is intended (counting a held
  batch is allowed in STK-5).
- If the stock changes between the report and the count, STK-5's snapshot check already refuses the count and asks
  for a new snapshot; the report stays linked to the old case, which closes without a movement.

## Existing assertions this may change

None expected. If the build finds an exact permission list or menu list pinned by an existing test (for example the
role permission pins or the Inventory row menu), I'll list it before and after for the owner before changing it.

## Tests (planned)

- Each P10 holder can report; every other role gets 403; Medical Assistant matches CA.
- A report opens one `awaiting_count` case with the right snapshot; a second report on the same balance joins it.
- The reporter is refused as counter, recounter and approver (service and PostgreSQL where possible).
- The report writes no stock movement and changes no balance (before-and-after table snapshot).
- Stale balance version refused; retry with the same key returns the same report; a changed payload with the same key
  is refused.
- Rough count and note never reach the counter's page data, the audit row, or the agent data sources.
- Two-process race: two people reporting the same balance at once give one case and two reports (Contention suite).
- Frontend: the menu item shows only with P10; the sheet's choices, labels and 44 px targets below 1024 px.

## Size

About two days: one table and a grant, one service method plus the internal STK-5 entry point, the sheet and row menu
on two pages, the "Reported by" line, "My reports", and the tests above.

# STK-45: Report a difference

Revision 4 (Mei Ling: OK; Daniel: OK with changes, applied), for Daniel's look and the owner's approval. **Nothing is built
until the owner approves this note.** Synthetic data only; not production-approved.

**Owner decision:** "Stock quantities still never typed in directly; add **Report a difference** for staff, which
opens a count case for approval" (`docs/decisions/2026-11-20-finance-inventory-proposals.md`, item 18). The order
STK-44 → STK-45 is the owner's (TEAM.md decision 47). The permission is the owner-approved **P10**; its holders are
listed below for the owner to confirm the exact name.

## The idea in one paragraph

A CA notices the shelf doesn't match KPOne (for example KPOne says 200 Paracetamol tablets, the box clearly holds
far fewer). Today only a CA Supervisor or Store Admin can open a stock count, so the CA has to find one and explain.
With STK-45 the CA presses **Report a difference** on that stock line, picks what they saw, and sends it. That opens
the **existing governed stock count case** (STK-5) for that batch and location, or adds the report to one already
open. From there nothing changes: a counter counts it blind, someone else recounts any difference, and a third person
approves. **The report itself never changes stock.** No quantity is typed into stock by the reporter.

## What staff see

**Where the button is.** A row menu item **Report a difference** on rows that are one batch at one location:

- the Inventory stock list (balance rows at the active branch);
- Stock review **Expiring** and **Expired** rows, and the **At risk in 6 months** list.

It is **not** on Stock review **Low stock** rows: those are reorder-level rows for a medicine, not one batch, so there
is nothing to count. It shows only to holders of P10, and only for stock at the active branch. At 390 px the row
menu items are 44 px.

When a live count is already open on that row, the menu item reads **Add to the open count** instead (same sheet),
so there is never a chip and a menu item that seem to do the same thing.

**The sheet** (a side sheet on desktop; below 640 px full screen with the actions pinned at the bottom, padded for
the safe area; the close X is a 44 px target, not the shared 16 px one, design debt DD-119):

- Header: the medicine, batch and expiry, location, and "KPOne shows 200 tablets".
- **What did you see?** (required): a radio group stacked one per row, each row a 44 px target with the whole label
  clickable, **nothing chosen at first** (the reasons-start-empty rule):
  - "Fewer than KPOne shows"
  - "More than KPOne shows"
  - "Some are damaged or expired"
  - "Not sure; please count it"
- **About how many?** (optional, a number, with the stock unit shown beside the box, for example "tablets"). The hint
  stays visible under the field, not in a tooltip: "Your rough count, if you have one. The counter won't see it."
  This is a note for the approver, not a stock figure.
- **Note** (optional, 200 characters, plain text), with the hint visible under it, "Don't write patient names or
  details.", and a counter "0 / 200".
- Buttons: **Send for counting** (the only filled button) and **Cancel** (outline). Send stays unavailable, with the
  line "Pick what you saw to continue.", until a choice is made.

**When the second-person rule blocks you** (you already counted or recounted the open case on that batch), the menu
item is not hidden. The sheet opens to a plain refusal, "You counted this batch, so another person has to report it.",
with only **Close**. A refusal is better than a missing button.

After sending, the sheet closes, focus returns to the row's menu button, and a one-line toast says "Sent. A stock count
was opened for this batch." or, when the report joined a count already open, "Sent. Added to the stock count already
open for this batch." The row then shows a neutral status chip, a dot plus the words **"Count open"**; the chip is the
record.

**Where the reports show.** On the governed Stock count page, a case with reports shows "Reported by <name>: Fewer
than KPOne shows" under the item. The **reporters' rough counts and notes are hidden from the counter and recounter**
(the count stays blind) and shown only to the approver, beside the counted figures.

**My reports.** On the Inventory page, a closed disclosure near the stock list with a chevron and the count in its
label, "My reports (3)", so people find the outcome where they reported from. With nothing in it, it still shows, with
"You have not reported anything at this branch." It lists the last 25 of my reports at this branch (item, what I saw,
when, and the case state as a neutral dot plus words):

- "Waiting for count", "Being recounted", "Waiting for approval", "No difference found", "Approved";
- **"Needs a new report"**, with the line "Stock changed before the count. Report again if it still looks wrong.",
  when the case's snapshot no longer matches the stock (see Rules), because STK-5 can no longer count that case.

Read only.

## Who can do what

| Action | Permission | Roles |
|---|---|---|
| Report a difference | **P10** (proposed name `inventory.differences.report.branch`) | CA, CA Supervisor, Store Admin, Branch Manager, Medical Assistant (the CA → Medical Assistant rule) |
| Count and recount | existing `inventory.stocktake.branch` | CA Supervisor, Store Admin (unchanged) |
| Approve | existing `inventory.variances.approve.branch` | CA Supervisor, Finance Officer, Director (unchanged) |

**The second-person rule.**
- Anyone who reported on a case can never count, recount or approve it. This is based on the reports table, not on the
  case's proposer, so ordinary STK-5 proposers can still count their own cases, as today.
- Anyone who has already counted or recounted a case can't add a report to it (otherwise the reporter and the counter
  would be the same person).
- Both rules are enforced in the service, in PostgreSQL (below) and in the Stock count page's `canCount` and
  `canApprove`. So a CA Supervisor who reports a difference needs another holder to count it, as with any STK-5 case.

## Rules

- **The report never moves stock.** It writes one report row and, when no live count is open, one STK-5 count case
  (first state `awaiting_count`), taken from a snapshot of the balance at that moment, exactly as STK-5 `propose()`
  does. Only an approval after an independent recount can post a movement, unchanged.
- **Open and live.** STK-5 keeps a case's state in its latest `inventory_governed_actions` row.
  - A count case is **open** when its latest state is `awaiting_count`, `awaiting_recount` or `awaiting_approval`.
  - It is **live** when it is open, its `expected_balance_version` and `expected_quantity` still equal the
    balance's, and its `base_unit` still equals the SKU's stock unit (the same checks STK-5's `act()` makes).
  - A case whose snapshot went stale stays at its last state forever: STK-5 refuses to count it ("Stock changed after
    the snapshot") and has no cancel step.
  - A report **joins the newest live count case** on that balance (the highest id) if there is one; otherwise it
    opens a new case. It never joins a stale case. (Two live cases can exist, because STK-5's `propose()` can still
    open one beside a report's case.)
- **No new index.** Today's data can already hold two open count cases on one balance (STK-5's `propose()` never
  checks), so a unique index isn't possible. One live case per report is kept by the balance row lock plus the
  service check above. **STK-5's own `propose()` is unchanged** and can still open a count beside a report's case.
- **Lock order** (the STK-5 order: case, then SKU, batch, balance):
  1. Read the candidate: the newest live case (highest id), without a lock.
  2. Lock it `FOR UPDATE`.
  3. Lock the source with STK-5's `lockSource` (SKU, batch, balance).
  4. Under the balance lock, check that the candidate is still the newest live case.
     If not, roll back and retry the whole transaction (up to 3 times, as STK-5 does).

  This matters: inserting a report row with a foreign key to a case that isn't locked takes `FOR KEY SHARE` on it,
  while a concurrent count holds the case `FOR UPDATE` and waits on the balance. That would deadlock.
- **Opening the case.** The service calls a new internal `GovernedStockService::openCountForReport()`.
  - It checks P10 with `InventoryAuthorityService::lockForBranch(…, P10)` instead of `inventory.stocktake.branch`.
  - Everything else is as in `propose()`.
  - The reporter is the case's `proposer_user_id`, which satisfies STK-5's case trigger: the creation action's actor
    must equal the proposer.
  - No public route can open a case without a report.
- **Stock that can't be reported** is refused with a plain message: a deactivated SKU, a returns location (count it
  with STK-5 directly), or a balance at another branch (404).
- **Every write:**
  - permission and active branch checked again under the user's row lock;
  - `DB::transaction`;
  - the locks above;
  - the balance `lock_version` from the page (a stale page is refused: "Stock changed. Reload before reporting.");
  - an idempotency key (`retry_key`, the STK-5 retry pattern);
  - one audit row.
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

PostgreSQL:
- Update, delete and truncate on the table are refused (the STK-5 append-only trigger pattern).
- An insert trigger checks:
  - the case is a `count` case on the same organisation, branch and balance;
  - the case is open and live (latest state, snapshot version and quantity, and stock unit, as above), so even a
    direct insert can't attach a report to a closed or stale case;
  - the reporter has not counted or recounted it.
- **STK-5's trigger function changes.** `guard_governed_stock_action` is replaced (`CREATE OR REPLACE`) so that, for
  `counted`, `recounted` and `approved`, it also refuses an actor who has a report on that case. Its other rules stay
  word for word, and the migration's `down()` restores the STK-5 body.

Migrations in the Inventory range, next free numbers after `2026_11_07_003500`:
1. the table and the trigger change;
2. the **P10 grant** (additive, never revoking, a missing role logged and skipped, `down()` a no-op).

## Audit

`inventory.difference_reported`: report id, case id, balance id, `observed` code and `opened_case`. No note, no rough
count, no names.

## Not in this slice

- No photo upload (a possible later slice).
- No reminder or message to the counter; the open case shows on Stock count as today.
- No cancel step for stale STK-5 cases (an STK-5 gap, noted for later); STK-45 only avoids joining them.
- No change to STK-5's counting, recount or approval screens beyond the "Reported by" line, the hidden rough count for
  the approver, and the reporter exclusion.
- No report from a phone camera or barcode scan (STK-9 scan can still find the row).

## Known limits

- A report on a balance whose stock is all held (STK-2 hold) still opens a count; that is intended (counting a held
  batch is allowed in STK-5).
- If the stock changes after a report, its case goes stale: it stays at its last state without a movement and can't be
  counted. "My reports" says so, and the next report on that balance opens a new, live case.
- STK-5 cases that are already stale stay listed on Stock count, as today.

## Existing assertions the build will probably touch (for the owner, before building)

These will be listed line by line, with before and after, once the build finds them. None is changed without the
owner's approval:

1. the exact role permission pins for `ca`, `ca_supervisor`, `store_admin`, `branch_manager` and `medical_assistant`
   (they gain P10);
2. the MA-1a "Medical Assistant mirrors CA" test (it should still pass, because both gain P10, but it is listed in
   case it pins a count);
3. any test pinning the Stock review or Inventory row props or row menu items;
4. STK-5 trigger tests, if any asserts the body of `guard_governed_stock_action`.

## Tests (planned)

- Each P10 holder can report; every other role gets 403; Medical Assistant matches CA.
- A report opens one `awaiting_count` case with the right snapshot; a second report on the same balance joins it.
- A report joins a live case but not a stale one (it opens a new case instead).
- The reporter is refused as counter, recounter and approver, in the service and by a direct insert into
  `inventory_governed_actions` (PostgreSQL trigger).
- Someone who already counted a case is refused when reporting on it (service and the reports insert trigger).
- An ordinary STK-5 proposer can still count their own case (no regression); STK-5 `propose()` is not blocked by a
  report's case.
- The report writes no stock movement and changes no balance (before-and-after table snapshot).
- Stale balance version refused; retry with the same key returns the same report; a changed payload with the same key
  is refused.
- Rough count and note never reach the counter's page data, the audit row, or the agent data sources.
- Two-process races (Contention suite): two people reporting the same balance at once give one case and two reports;
  a report against a count on the same case (the lock order above) finishes without a deadlock.
- Frontend: the menu item shows only with P10 and not on Low stock rows, and reads "Add to the open count" when a live
  count is open; the radio group starts empty and Send stays unavailable until a choice; the blocked reporter sees the
  refusal sheet with only Close; hints are visible; 44 px targets (rows, close X, menu items) below 1024 px; focus
  returns to the menu button after sending; "My reports (N)" shows its empty line and the "Needs a new report" state.

## Size

About two and a half days: one table, a trigger change and a grant, one service method plus the internal STK-5 entry
point, the sheet and row menu on two pages, the "Reported by" line, "My reports", and the tests above.

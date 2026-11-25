# STK-61: "Return" on a waiting write-off, and "Change quantity" before dispatch (design note, revision 2)

Revision 2 applies Mei Ling's review.

Two gaps from the 25 Nov gap list; the owner chose by tick box (25 Nov 2026, relayed by B.E.R.N.I.S.S) to build both
before Monday. Neither needs a new permission.

## 1. Return a waiting write-off

**Today:** a write-off (or count variance) waiting for approval can only be approved. An approver who thinks it is
wrong has no way to send it back: it stays waiting, and once the stock changes its snapshot goes stale and it can never
be approved, yet it still shows as open.

**Change:** the approver (the existing `inventory.variances.approve.branch`, under the same rule as Approve: not the
counter, recounter, proposer, or anyone with an STK-45 report on the case, in `act()`, the trigger and `page()`'s
`canReturn`) gets **Return** beside Approve, with a fixed reason: "wrong quantity", "wrong batch or
location", "wrong reason", "not needed", "other" (a note of up to 200 characters, no patient details).

- Return appends a `returned` action and closes the case in a new final state `returned`. Nothing moves in stock.
- The proposer, and anyone else who may propose, can start a new write-off or count for the same stock straight away
  (the open-case checks treat `returned` as closed).
- The case page and My reports (STK-45) show "Returned: <reason>" with who returned it and when; the note is shown
  only to the proposer and approvers, never in audit.
- **Storage:** the `returned` action row gets two new nullable columns, a reason code and an encrypted note, with a
  CHECK that they are set only on `returned` (append-only like every action row). Neither reaches audit or `ai_read`.
- **Open-state readers:** `GovernedStockService::OPEN_STATES`, `CycleCountService::OPEN_STATES` and STK-45's report
  trigger list only the three waiting states, so `returned` counts as closed with no change; a new STK-45 report on
  that balance opens a new case (tested). My reports and the Stock count page gain the "Returned" label.
- **Reversals:** Return is offered on reversal cases too, and `propose()`'s "already reversed" check leaves out a
  returned reversal, so a correction can still be proposed again (tested).
- Every write as today: permission and branch under lock, `DB::transaction`, the case row lock, `lock_version`,
  retry key, audit with ids and codes only.
- **PostgreSQL:** the migration starts from the **current** (STK-45) body of `guard_governed_stock_action`, keeps the
  reporter clauses, and adds an explicit `returned` branch: previous state `awaiting_approval`, `quantity` and
  `movement_id` null, actor not the proposer, a counter, a recounter or a reporter, new state `returned`. It also
  rewrites `governed_stock_action_shape` (actions and states). `down()` restores the STK-45 body (tested). A returned
  case never changes again.

## 2. Change quantity before dispatch

**Today:** the person dispatching a stock request must send exactly the approved quantity ("Dispatch quantities must
match the approved request"). With less on hand, they cannot send anything.

**Change:** on the dispatch form, each line can be sent **in full or in part**:

- "Change quantity" opens the line's quantity box. Only a **smaller** quantity, greater than 0, is allowed (never more,
  never 0: a line that cannot be sent at all stays with "Not sent", below).
- A reason is required for any changed line: "not enough stock", "batch expires too soon", "keeping stock for this
  branch", "other" with a note.
- Before saving, a **confirm dialog** lists every changed line: "Send 6 of 10 tablet of Synthamol 500 mg?". Nothing is
  sent until they confirm.
- A line can also be marked **Not sent** with the same reasons, so a request with one line out of stock can still go.
- The request records approved, sent and the reason per line. The receiving branch sees "Sent 6 of 10 (not enough
  stock)" on the receipt form and receives against the sent quantity, as today.
- What was not sent is **not** kept open: the request is dispatched as sent, and the branch starts a new request for
  the rest (the receipt page offers "Ask for the rest" pre-filled with the shortfall).
- Every write as today (permission, branch, lock, `lock_version`, idempotency key, audit with ids, counts and reason
  codes only).
- **Database:** `stock_request_lines_quantity_check` today requires `dispatched_quantity` NULL or above 0; it is
  rewritten to allow 0 for Not sent, between 0 and `requested_quantity`, with a reason exactly when sent differs from
  approved. A not-sent line gets **no** receipt line (`transfer_receipt_lines_reconcile` requires a positive dispatched
  quantity, so it is left unchanged), and `StockRequestService::receive` skips not-sent lines instead of refusing the
  receipt.
- **No double count with STK-6:** the receipt reconcile check measures accepted + short + damaged against the line's
  dispatched quantity, so "sent 6 of 10" received in full is not a shortage (tested). Reorder suggestions' incoming
  quantity already uses the dispatched quantity (0 for not sent).
- **Cost:** the dispatch movement carries only the sent quantity into the request's in-transit pool, and a not-sent
  line moves nothing, so the cost engine needs no change; a test shows a partly sent request arrives at the cost it
  left at.

## 3. Existing assertions

Section 2 changes the rule "Dispatch quantities must match the approved request" (`StockRequestService.php`, the
dispatch check). Any existing test that expects a partial dispatch to be refused changes to expect it accepted with a
reason; the exact lines go to the owner before build. Section 1 adds a state and changes no existing assertion that I know of; any that does is listed too.

## 4. Pages

Write-off and count case pages: a **Return** button beside Approve, with its sheet. Dispatch form: "Change quantity"
and "Not sent" per line, the reason, and the confirm dialog. Receipt form: "Sent N of M (reason)" and "Ask for the
rest". Clean Look, shared components only; Daniel's look first.

## 5. Tests

Return: approver only, not the counter or proposer; reason rules; no stock movement; the case closed and a new case
allowed; audit with no note. Change quantity: smaller only, never more or 0; reason required; Not sent lines; the
receiving branch receives against the sent quantity; "Ask for the rest" pre-fills the shortfall; the PostgreSQL rules;
a race of a dispatch against an approval change on the same request; a reporter cannot return; the trigger refuses a
`returned` action from the proposer, a counter or a reporter by direct insert; a returned reversal does not block a new
reversal; a not-sent line skipped at receipt; 6 of 10 received with no shortage; the in-transit cost of a partly sent
request; `down()` restores the STK-45 trigger body.

## Daniel's conditions (25 Nov, OK with changes; build to these)

- Return: Approve stays the one filled button; Return is outline beside it (stacked, 44 px at 390). Its dialog:
  reasons as radios, none selected ("Pick a reason to continue."), the "other" note 0 / 200 with "Do not write
  patient details.", the line "This sends the case back. Nothing changes in stock. The proposer can start a new one.",
  and a filled (not red) confirm.
- After: a neutral "Returned" chip with reason, who and when, kept as history; under the note "Only the proposer and
  approvers see this note.". Someone who counted or proposed sees "You counted this case, so someone else decides."
- Dispatch: each line starts at the full quantity with two outline actions, "Change quantity" and "Not sent"; reasons
  as radios; the quantity field shows the allowed range ("1 to 9 tablets") and refuses outside it beside the field,
  never clamping. Changed lines carry a chip ("Sending 6 of 10", "Not sent") and the page shows "2 of 5 lines
  changed".
- Confirm dialog: each changed line with its reason, then one filled "Send". If every line is Not sent, it says nothing
  will be sent and points to the existing cancel flow; no empty dispatch.
- At 390 each line is a card with the actions at the bottom, 44 px.
- Receipt: "Sent 6 of 10 (not enough stock)" with the chip "Short by 4", a quiet "Ask for the rest" link and "The rest
  is not kept open on this request."
- Shots at 1440 and 390 of the dispatch form with one changed and one not-sent line, and the confirm dialog.

## Settled while building (Mei Ling, revision 2 OK)

- The receipt chip reads **"4 not sent"**, not "Short by 4": "short" stays STK-6's word for sent but not arrived
  (to confirm with Daniel).
- A proposer or reporter sees "You proposed or reported this case, so someone else decides."; a counter or recounter
  sees "You counted this case, so someone else decides."
- An empty dispatch (every line Not sent) is refused in `StockRequestService` as well as on screen, and tested.

# STK-61: "Return" on a waiting write-off, and "Change quantity" before dispatch (design note, revision 1)

Two gaps from the 25 Nov gap list; the owner chose by tick box (25 Nov 2026, relayed by B.E.R.N.I.S.S) to build both
before Monday. Neither needs a new permission.

## 1. Return a waiting write-off

**Today:** a write-off (or count variance) waiting for approval can only be approved. An approver who thinks it is
wrong has no way to send it back, so it waits forever until the stock changes and the snapshot goes stale.

**Change:** the approver (the existing `inventory.variances.approve.branch`, under the same "not the counter,
recounter or proposer" rule) gets **Return** beside Approve, with a fixed reason: "wrong quantity", "wrong batch or
location", "wrong reason", "not needed", "other" (a note of up to 200 characters, no patient details).

- Return appends a `returned` action and closes the case in a new final state `returned`. Nothing moves in stock.
- The proposer, and anyone else who may propose, can start a new write-off or count for the same stock straight away
  (the open-case checks treat `returned` as closed).
- The case page and My reports (STK-45) show "Returned: <reason>" with who returned it and when; the note is shown
  only to the proposer and approvers, never in audit.
- Every write as today: permission and branch under lock, `DB::transaction`, the case row lock, `lock_version`,
  retry key, audit with ids and codes only. PostgreSQL: the governed action and state checks gain `returned` (a
  migration rewrites the two CHECK constraints and the transition trigger); a returned case never changes again.

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
  codes only). PostgreSQL: `dispatched_quantity` between 0 and `requested_quantity`, a reason exactly when they differ.

## 3. Existing assertions

Section 2 changes the rule "Dispatch quantities must match the approved request". Any existing test that expects a
partial dispatch to be refused changes to expect it accepted with a reason; the exact lines go to the owner before
build. Section 1 adds a state and changes no existing assertion that I know of; any that does is listed too.

## 4. Pages

Write-off and count case pages: a **Return** button beside Approve, with its sheet. Dispatch form: "Change quantity"
and "Not sent" per line, the reason, and the confirm dialog. Receipt form: "Sent N of M (reason)" and "Ask for the
rest". Clean Look, shared components only; Daniel's look first.

## 5. Tests

Return: approver only, not the counter or proposer; reason rules; no stock movement; the case closed and a new case
allowed; audit with no note. Change quantity: smaller only, never more or 0; reason required; Not sent lines; the
receiving branch receives against the sent quantity; "Ask for the rest" pre-fills the shortfall; the PostgreSQL rules;
a race of a dispatch against an approval change on the same request.

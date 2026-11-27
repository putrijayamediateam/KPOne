# STK-46: cost typed by hand (design note, revision 2)

> Continuation26Nov: the attached full handoff answers the original pending questions: both exact permission sets, replay from the start with Changed on, and pre-STK-10B receipts are approved. Original text below is preserved as design history; see STK-46-IMPLEMENTATION-BATCH.md for the concrete migration review batch.

Revision 2 applies Mei Ling's review: no amount in audit, its own page, one stated replay rule, stricter PostgreSQL
rules, and the pre-STK-10B and reversed-receipt cases.

Owner decision 24 (20-21 Nov 2026): cost comes from the supplier receipt as today, **and may also be typed by
hand**, under four rules: only a Finance Officer or Store Admin types it; it is labelled "entered by hand"; it is
audited; and a second person checks it before it counts. Build order and "build before Monday": owner by tick box,
25 Nov 2026, relayed by B.E.R.N.I.S.S.

## 1. What problem it solves

Weighted average cost (STK-27, accountant-approved 25 Nov) takes cost only from a purchase receipt or an opening
balance. Stock that came in with **no cost** (an opening entered without a unit cost, a receipt from before STK-10B)
is "no cost": counted apart and never valued. Stock that came in at the **order's estimate** is valued but marked
"includes estimates". Today nobody can put that right without a new receipt. STK-46 lets Finance or the Store Admin
type the real cost for one such stock entry, and lets it count once someone else has checked it.

## 2. What can be given a hand-typed cost

Only one stock entry at a time, and only an entry that **brings stock in with a cost of its own**: a purchase receipt
or an opening balance (the two `COSTED_IN` movement types), and only while its cost is **missing** or **an estimate**.
An entry that already has an actual cost (`cost_source = actual`, or an opening with a unit cost) is refused: a wrong
actual cost is corrected by the existing receiving correction, never by typing over it.

## 3. The flow

1. **Enter.** A Finance Officer or Store Admin opens **Inventory > Costs to fill in**, its own page (Stock value is
   Director-only, so the people who enter costs could not open a tab there). The page lists entries with a missing or
   estimated cost: SKU, branch, date, quantity and its current cost basis, and never a stock value total, COGS or
   margin. They pick an entry and type the **total paid for that entry in RM** (the same "Total paid for this
   batch" wording receiving uses), with a short reason from a fixed list ("supplier invoice found", "opening price list",
   "estimate was wrong", "other" with a note of up to 200 characters, no patient details) and an optional reference.
2. **Waiting.** The entry shows "Entered by hand · waiting for a check". It does **not** count yet: valuation, COGS
   and the journal summary still treat the entry as before.
3. **Check.** Another Finance Officer, Store Admin or the Director (never the person who typed it) checks it on the
   same page: **Accept** or **Reject** with a reason. Accept makes it count (see section 4 for how); Reject closes it
   and the entry can be typed again.
4. **After.** An accepted hand cost is never edited. A wrong one is replaced by a new hand cost through the same two
   steps; the newest accepted one counts, and the earlier one stays in the history.

## 4. How it counts

`WeightedAverageCostService` reads accepted hand costs with the movements. For an entry with an accepted hand cost,
its own cost is the hand total (whole sen x 1000), with **basis `hand`** and `estimate = false`. Everything else in
the STK-27 rules is unchanged.

**Replay rule (owner to confirm, with the accountant if needed):** recommended (a) **from the start**. The ledger is
replayed, so an accepted hand cost corrects the average from that entry onwards, including months already shown.
Because the accountant books from the journal summary, a restated month is flagged: each accepted hand cost keeps its
acceptance time, and the Stock value page and the journal summary CSV show, for any month it changes, "Changed on
<date>: a hand cost was accepted for <SKU>. A summary downloaded before then differs." The alternative (b) **from
acceptance onward** (a dated adjustment in the replay, past months never change) is built only if the owner or the
accountant asks for it.

Every reader of the engine's basis names the new value: Stock value labels ("Entered by hand" beside "Receipt",
"Opening" and "Estimate"), Medicine sales' "(estimate)" marker, and STK-42's at-risk "(estimate)", "(part)" and "No
cost recorded" markers (which change on their own when uncosted stock gains a cost). REL-STK.md gains one rule line:
"A missing or estimated receipt or opening cost may be replaced by a hand-typed total, checked by a second person
(STK-46)." The banner and disclaimer are unchanged.

## 5. Data

One new append-only table, `inventory_hand_costs`: organisation, branch (of the entry's location, or null for the
organisation store), the stock movement, `cost_total_sen`, reason code, note (encrypted), reference, status
(`waiting`, `accepted`, `rejected`), entered by and at, decided by and at, decision reason, `lock_version`,
idempotency key and payload hash. PostgreSQL:

- one waiting entry per movement (partial unique index);
- the decider is never the enterer (CHECK);
- the decision fields are null exactly while `waiting`; once `accepted` or `rejected` the decider and time are set,
  and a rejection has its reason (CHECK);
- only `waiting` changes, only to `accepted` or `rejected`, and only the status, decision fields and `lock_version`
  change; the amount, movement, branch, enterer and every other column are immutable (trigger); no delete or truncate;
- `cost_total_sen >= 0` (0 is allowed, for free goods, and labelled "Free (entered by hand)");
- the movement must be a purchase receipt or opening in the same organisation, with a missing or estimate cost, and
  the hand cost's branch must equal the movement's destination location branch (null for the organisation store)
  (insert trigger);
- a receipt already reversed by a receiving correction is refused (insert trigger, and the service under the receipt's
  row lock). A receiving correction made after a hand cost is accepted reverses the receipt as today; its reversal
  movement leaves at the average, so the existing average-cost reversal policy remains in force. The accepted cost can still affect the value of remaining stock after that reversal (see the continuation review example).

Stock movements are not changed, so every existing figure, hash and test stays as it is until a hand cost is accepted.

## 6. Permissions (need the owner's approval)

- **New** `inventory.cost.enter.organisation`: Finance Officer and Store Admin. Enter a hand cost.
- **New** `inventory.cost.check.organisation`: Finance Officer, Store Admin and Director. Accept or reject one
  someone else entered.
- Seeing the "Costs to fill in" tab also needs the existing `inventory.cost.view.organisation`, which the Director,
  Finance Officer and Store Admin already hold on main (decision 6). No other grant changes.

Every write: fresh permission check under lock, `DB::transaction`, row lock on the receipt (or opening) and the hand
cost, `lock_version`, idempotency key, and an audit row with ids, codes and the state only. **Never the amount**: the
audit log is readable by roles that may not see cost (as STK-28 and STK-44 do), and never the note.

The page needs the enter or check permission plus the existing `inventory.cost.view.organisation`; anyone else gets
403.

## 7. Open questions for the owner

1. The replay rule in section 4: (a) from the start, recommended, or (b) from acceptance onward.
2. **Receipts from before STK-10B:** the cost engine treats a unit cost with an empty cost source as actual, but STK-10
   found many of those copied the order's estimate. Recommended: they may also take a hand cost, labelled "pre-STK-10B
   cost", under the same two-person check. Otherwise they stay uncorrectable (a known limit). Whichever the owner
   chooses, the page's list, the service and the insert trigger's "missing or estimate" check follow the same rule.

## 8. Known limits

Ask KPOne's `ai_read.stock_movements` view keeps showing such movements with no cost or an estimate. Adding hand costs
to a Director-only view is a later step with its own AG-7 privacy check.

## 9. Not in this slice

No hand-typed quantity (decision 18 stands: quantities are never typed in). No change to how a receipt records cost.
No cost on stock that came back without a purchase (patient returns, count gains): those still enter at the average.
No bulk entry; a later slice can add a CSV if Finance asks.

## 10. Tests

Entering, waiting (no effect on valuation), checking by someone else, refusal of the same person, rejection and
re-entry, replacement by a newer accepted cost, refusal on an entry with an actual cost or of the wrong type, refusal
on a reversed receipt, a pre-STK-10B receipt per the owner's answer, the audit row holding no amount, 403 for an
Account Assistant and a Panel Officer, the valuation figures before and after, a journal summary downloaded before and
after acceptance with the "changed" note, the PostgreSQL rules, and two-process races of two checkers and of an entry
against a receiving correction on the same receipt. The grant migration names both roles for each permission.

## Daniel's conditions (25 Nov, OK with changes on revisions 1 and 2; build to these)

- Its own page under Inventory, in the Inventory menu only for the permission holders, with the standard page header
  (title and one subline saying what it is for), and the calm "Management report" banner once.
- Rows: item, batch, entry date and a dot-plus-word state chip ("No cost recorded", "Estimate RM 4.20 each"); one
  outline "Enter cost" per row. Empty state "Nothing needs a cost."
- Enter cost sheet: "Total paid (RM)" money field (tabular, "RM" beside it) echoing the per-unit result under it
  ("= RM 0.2000 per tablet"); reasons as radios, none selected ("Pick a reason to continue."); optional reference;
  note with 0 / 200 and the no-patient-details hint; one filled "Send for checking".
- Waiting: neutral chip "Entered by hand · waiting for a check" with who and when (staff-visibility rule). The person
  who typed it sees "You entered this, so someone else checks it." instead of the check controls.
- Accept is outline and opens a dialog with a warning-wash alert and icon: "This changes earlier months: the average
  is worked out again from the start." (if the owner chooses the from-the-start rule), showing only item, batch,
  quantity, the typed total and the per-unit result. Reject opens a dialog with a required reason.
- Basis chip "Entered by hand" in the same neutral chip family as Receipt, Opening and Estimate.


## Continuation review: average-cost reversal

Independent Billing review26Nov corrected the earlier claim that a hand cost stops mattering after reversal. Example:10 units valued at RM10 each, then10 received units with accepted hand cost RM20 each, produce20 units at RM15. Reversing the receipt removes10 at the current average RM15, leaving10 valued at RM150, not the original RM100. Preserve this existing reversal rule, and add a new regression example alongside historical replay tests.

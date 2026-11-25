# STK-46: cost typed by hand (design note, revision 1)

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

1. **Enter.** A Finance Officer or Store Admin opens "Costs to fill in" (a new tab on the Stock value page), picks an entry, and types the **total paid for that entry in RM** (the same "Total paid for this
   batch" wording receiving uses), with a short reason from a fixed list ("supplier invoice found", "opening price list",
   "estimate was wrong", "other" with a note of up to 200 characters, no patient details) and an optional reference.
2. **Waiting.** The entry shows "Entered by hand · waiting for a check". It does **not** count yet: valuation, COGS
   and the journal summary still treat the entry as before.
3. **Check.** Another Finance Officer, Store Admin or the Director (never the person who typed it) checks it:
   **Accept** or **Reject** with a reason. Accept makes it count from then on; Reject closes it and the entry can be
   typed again.
4. **After.** An accepted hand cost is never edited. A wrong one is replaced by a new hand cost through the same two
   steps; the newest accepted one counts, and the earlier one stays in the history.

## 4. How it counts

`WeightedAverageCostService` reads accepted hand costs with the movements. For an entry with an accepted hand cost,
its own cost is the hand total (whole sen x 1000), with **basis `hand`** and `estimate = false`. Everything else in
the STK-27 rules is unchanged. Because the ledger is replayed, a newly accepted hand cost changes the average from
that entry onwards, including months already shown; the page says so: "Changes earlier months: the average is worked
out again from the start." Reports label the basis "Entered by hand" next to "Receipt", "Opening" and "Estimate".

## 5. Data

One new append-only table, `inventory_hand_costs`: organisation, branch (of the entry's location, or null for the
organisation store), the stock movement, `cost_total_sen`, reason code, note (encrypted), reference, status
(`waiting`, `accepted`, `rejected`), entered by and at, decided by and at, decision reason, `lock_version`,
idempotency key and payload hash. PostgreSQL:

- one waiting entry per movement (partial unique index);
- the decider is never the enterer (CHECK);
- only `waiting` changes, and only to `accepted` or `rejected` (trigger); no delete or truncate;
- the movement must be a purchase receipt or opening in the same organisation, with a missing or estimate cost
  (insert trigger).

Stock movements are not changed, so every existing figure, hash and test stays as it is until a hand cost is accepted.

## 6. Permissions (need the owner's approval)

- **New** `inventory.cost.enter.organisation`: Finance Officer and Store Admin. Enter a hand cost.
- **New** `inventory.cost.check.organisation`: Finance Officer, Store Admin and Director. Accept or reject one
  someone else entered.
- Seeing the "Costs to fill in" tab also needs the existing `inventory.cost.view.organisation`, which the Director,
  Finance Officer and Store Admin already hold on main (decision 6). No other grant changes.

Every write: fresh permission check under lock, `DB::transaction`, row lock on the movement and the hand cost,
`lock_version`, idempotency key, and an audit row with ids, codes and the sen amount only (never the note).

## 7. Not in this slice

No hand-typed quantity (decision 18 stands: quantities are never typed in). No change to how a receipt records cost.
No cost on stock that came back without a purchase (patient returns, count gains): those still enter at the average.
No bulk entry; a later slice can add a CSV if Finance asks.

## 8. Tests

Entering, waiting (no effect on valuation), checking by someone else, refusal of the same person, rejection and
re-entry, replacement by a newer accepted cost, refusal on an entry with an actual cost or of the wrong type, the
valuation and journal figures before and after, the PostgreSQL rules, a two-process race of two checkers, and the
page for each role.

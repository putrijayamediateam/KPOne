# STK-43: 7, 14 and 30-day sales in Reorder suggestions

Owner decision 26, second half (20-21 Nov 2026, `docs/decisions/2026-11-20-finance-inventory-proposals.md`):
**7/14/30-day sales columns in reorder suggestions.** Design note for Mei Ling's review. Classic layout only
(coordinator, 21 Nov). Synthetic data only.

## What staff see

On Reorder suggestions (Classic, `resources/js/pages/Inventory/ReorderSuggestions.vue`), each row gains three
numbers under one heading, "Sold (dispensed)": **7 days**, **14 days** and **30 days**. Each is the quantity of that
SKU dispensed to patients from that location over the last 7, 14 or 30 days, counted in branch-local days ending
today, in the SKU's stock unit ("12 tablets"). A dash means nothing was dispensed.

A note under the table says: "Sold counts what was dispensed to patients. Treatment-room use is not a sale and is not
counted here; the suggested quantity still uses the 30-day average of both, as before."

On a phone (cards below 1024 px) the three numbers sit on one line: "Sold: 7 d 12 · 14 d 20 · 30 d 41 tablets".

## Rules

- **Sold = dispensing movements** (`stock_movements.movement_type = dispense`) out of the row's location. That is
  what the owner calls sales of medicine. Treatment-room usage and waste are not sales, so they are left out of these
  columns. A medicine returned after dispensing (DSP-7) is not taken off: a return is checked separately and may never
  re-enter stock.
- Windows: the last 7, 14 and 30 branch-local days including today, the same way the existing 30-day look-back
  counts (`ReorderSuggestionService::used()`, from the start of the day 29 days ago).
- **The suggested quantity does not change.** It keeps its 30-day average of dispensing plus treatment use, times the
  lead time (STK-16, STK-30). The new columns are shown only; nothing reads them.
- One extra grouped query for the rows on the page, with three conditional sums over the last 30 days. No N+1, and
  the same row set (only low-stock rows, as today).
- Read only. No migration, permission, route or new parameter. The page payload gains `sold7`, `sold14` and `sold30`
  per row (decimal text in the stock unit, or null).
- No cost or money is shown (the columns are quantities), so nothing changes for who may see cost.

## Tests

- Dispenses 3, 10 and 25 days ago, plus one 31 days ago, give 7 d = 3, 14 d = 13 and 30 d = 38. The 31-day one is
  left out.
- Treatment usage, a reversed usage, a transfer and a write-off in the window are not counted as sold.
- Branch-local day boundary: a dispense just after local midnight 7 days ago counts in 7 d; one just before does not
  (frozen clock, Asia/Kuala_Lumpur).
- The suggested quantity is unchanged against the existing STK-16 and STK-30 tests (they must still pass untouched).
- Another branch's dispensing from its own location is not counted.
- Frontend: the heading, the three columns, the dash, the note and the phone line.

## Not in this slice

A sales value in ringgit (that is the medicine sales page, STK-44), and any change to the suggestion formula. Also
the V2 page, since the work is Classic only.

## Open point for Mei Ling

Should "sold" also subtract medicine returns that were checked and quarantined after dispensing (DSP-7)? I left them
in, because a return is rare, checked later, and its stock is never usable. Say if you want them taken off.

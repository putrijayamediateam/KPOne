# STK-43: 7, 14 and 30-day sales in Reorder suggestions

Owner decision 26, second half (20-21 Nov 2026, `docs/decisions/2026-11-20-finance-inventory-proposals.md`):
**7/14/30-day sales columns in reorder suggestions.** **Classic layout only** (coordinator, 21 Nov). Synthetic data
only.

Revision 2, after Mei Ling's review (OK with changes, 21 Nov): the card layout, one query and one clock reading, OTC
and per-location wording, the payload format, and the extra tests are spelled out below.

## What staff see

Reorder suggestions (Classic, `resources/js/pages/Inventory/ReorderSuggestions.vue`) shows every row as a card at every
width. Each card's `<dl>` gains one entry, **"Sold (dispensed)"**, with three numbers: "7 d 12 · 14 d 20 · 30 d 41
tablets". Each number is the quantity of that SKU dispensed from **that location** over the last 7, 14 or 30
branch-local days ending today, in the SKU's stock unit. A number is a dash when nothing was dispensed in that window.

The note under the list gains: "Sold counts what was dispensed to patients from this location, OTC included.
Treatment-room use is not a sale and is not counted. Medicine returned after dispensing is not taken off. The
suggested quantity still uses the 30-day average of dispensing and treatment use, as before."

## Rules

- **Sold** means dispensing movements (`stock_movements.movement_type = dispense`) out of the row's location. That
  includes consultation and OTC dispensing, which use the same movement, and covers that one location only, not the
  whole branch.
  - Treatment-room usage and waste are not sales, so they are left out.
  - A medicine returned after dispensing (DSP-7) is not taken off. A return is recorded and checked later. Only the
    quarantine outcome writes a `dispensary_return` movement, into a patient-returns location, and that stock is never
    usable. Taking returns off would also move past days' figures after the fact.
- **One query, one clock reading.**
  - The three figures come from the existing dispense query in `ReorderSuggestionService::used()`, with three
    conditional sums (7, 14 and 30 days), using the same `$since` start and the same "now". So the 30-day sold figure
    and the 30-day average always cover the same days.
  - Like `used()`, it runs over every low-stock row (filtered by the search) before the page is cut, so it adds no
    extra query.
  - `used()` keeps returning exactly what it returns today for the average; the sums are returned alongside.
- Windows: the last 7, 14 and 30 branch-local days including today. A day starts at local midnight (Asia/Kuala_Lumpur
  for every branch today). The 30-day window is exactly today's look-back.
- **The suggested quantity does not change.** It still uses the 30-day average of dispensing plus treatment use, times
  the lead time (STK-16, STK-30). Nothing reads the new figures.
- **Payload:** each row gains `sold7`, `sold14` and `sold30`, each formatted with `StockUnitQuantity::format` like
  `averageDailyUse` (null when nothing was dispensed).
  - `ReorderSuggestionTest.php:93` pins exact values, not the list of keys, so no existing assertion changes.
  - If any test turns out to pin the keys, it is listed before and after, left unedited, and the PR waits for the
    owner's approval.
- Read only. No migration, permission, route or new parameter. No cost or money is shown, so nothing changes for who
  may see cost.

## Tests

- Dispenses 3, 10 and 25 days ago, plus one 31 days ago, give 7 d 3, 14 d 13 and 30 d 38. The 31-day one is left out.
- An OTC dispense counts.
- These are not counted as sold:
  - treatment usage and a reversed usage;
  - a transfer and a write-off;
  - a quarantined DSP-7 return, which leaves all three figures unchanged.
- Dispensing from another location at the same branch (for example the store) is not counted in this location's row.
  Another branch's dispensing isn't either.
- Frozen clock in Kuala Lumpur time:
  - just after local midnight 6 days ago counts in 7 d, and just before it doesn't;
  - just after local midnight 29 days ago counts in 30 d, and just before it doesn't.
- The 30-day sold figure is never more than the 30-day usage behind the average.
- A row with no dispensing has null in the payload and shows dashes.
- The query count does not grow with the number of rows (no query per row).
- With a search filter, the figures follow the filtered rows.
- The suggested quantities are unchanged: the existing STK-16 and STK-30 tests pass untouched.
- Frontend (Classic): the "Sold (dispensed)" entry, the three numbers, the dashes and the note.

## Not in this slice

A sales value in ringgit (that is the medicine sales page, STK-44), and any change to the suggestion formula. Also the
V2 page, since the work is Classic only.

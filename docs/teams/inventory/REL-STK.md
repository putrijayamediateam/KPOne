# REL-STK: the clinic's accountant has approved the stock cost policy

**Approval:** owner-confirmed, verbal, 25 Nov 2026. The owner told B.E.R.N.I.S.S that the clinic's accountant has
approved the stock cost policy ("dah approve"). Nothing is written down from the accountant yet.

Revision 2. **Approved by the owner on 25 Nov 2026** (relayed by B.E.R.N.I.S.S: "Lulus + stok pembukaan juga"),
including both assertion changes, with two decisions: (a) the accountant also confirmed opening stock at its entered
cost, so the journal label becomes "Opening stock"; (b) the `AGENTS.md` line changes in this PR, using the wording
below. Synthetic data only; not production-approved.

## What changes and what doesn't

This is a **wording** release. It changes what the cost pages say, not how cost is worked out, not who sees it, and
not any stored figure.

- **Weighted average cost stays the default.** It is the only method KPOne has (STK-27), and the approval is taken to
  cover it as built. Its rules, so the owner can check they are what the accountant approved:
  - an accepted purchase receipt moves the average: (value before + receipt cost) ÷ (quantity before + received),
    using the lot's actual total when one is entered (STK-10B), otherwise unit cost × quantity;
  - a cost taken from a purchase order's estimate is used but marked "(estimate)" until that stock is gone;
  - opening stock enters at its entered unit cost;
  - dispensing, treatment use and waste, write-offs, supplier returns, counts and adjustments out leave at the average;
  - a transfer leaves the sending branch at its average and arrives at that same cost;
  - stock that comes back without a purchase (patient returns, count gains, adjustments in) enters at the branch's
    current average; with no average yet it is "no cost", counted apart and never valued at zero;
  - held stock is valued; rejected lots never enter stock; supplier credit notes don't move the average;
  - no landed-cost add-ons (delivery or handling charges are not added to cost).
- **Who sees cost does not change:** cost view stays as it is (`inventory.cost.view.organisation`), the Stock value
  page and the journal summary stay Director-only (`inventory.valuation.view.organisation`), and the medicine sales
  page stays Director and Finance Officer (`inventory.medicine_sales.view.organisation`).
- **Still a management report.** A verbal approval of the method doesn't make these pages statutory accounts, and
  KPOne still sends nothing to an accounting system. The new wording keeps both points.

## The new wording

| Where | Today | After REL-STK |
|---|---|---|
| `WeightedAverageCostService::DISCLAIMER` | "Set by KPOne defaults at the owner's instruction; not professional accounting advice." | "Weighted average cost, as approved verbally by the clinic's accountant on 25 Nov 2026; not a statutory financial statement." |
| `StockValuationReportService::BANNER` (Stock value, Stock review "At risk", Medicine sales) | "Management report — cost policy to be confirmed by the clinic's accountant. " + DISCLAIMER | "Management report — " + DISCLAIMER |
| Journal summary CSV, first line (`StockJournalSummaryService`) | The same "to be confirmed" sentence + DISCLAIMER | "Management report — " + DISCLAIMER (the line "No real accounting system receives this file." stays) |
| Opening import page (`OpeningImport.vue`) | "…policy to be confirmed by the accountant." | "…uses the weighted average cost policy approved by the clinic's accountant." |
| Code comments (`WeightedAverageCostService`, `StockJournalSummaryService`, `StockHealthService`, `StockValue.vue`, `MedicineSales.vue`) | "KPOne defaults… not professional accounting advice", "valuation waits for the accountant's cost policy" | Say the policy was approved verbally by the clinic's accountant on 25 Nov 2026 |

**Design (Daniel, OK with changes, 25 Nov 2026):** the banner moves from the amber warning to the neutral info note
(the "How this is counted" style, an information icon, "Management report" kept at the start), because the policy is
settled and amber is kept for real warnings such as "6 medicines have stock with no cost recorded". Done on Stock
value, Medicine sales and Stock review "At risk". Daniel also asked to show it only once on Stock value (at the top,
not again inside the tabs); that needs the existing assertion change 3 below, so the tab copies stay, in the neutral
style, until the owner approves it.

## Opening stock

The owner confirmed the accountant also approved opening stock at its entered unit cost (decision (a)). The journal
summary's account label "Opening stock (to be confirmed by accountant)" becomes "Opening stock".

## What this approval does not cover (left as it is)

- **HR payroll, leave and claims retention and SST.** Those "the accountant confirms…" notes are a different
  approval and are not touched.
- **A written copy.** When the accountant's approval is written down, this note's approval line can point to it.

## AGENTS.md

`AGENTS.md` says "valuation is labelled pending the accountant's cost policy". After this release that is no longer
true. Replacement, approved by the owner (decision (b)) and made in this PR:
"valuation is labelled with the weighted average cost policy the clinic's accountant approved verbally on 25 Nov 2026".
`docs/BASELINE.md` is the coordinator's to record.

## Existing assertions this changes (approved by the owner)

1. `tests/Feature/Inventory/StockJournalSummaryTest.php:133`, in
   `test_the_csv_is_safe_carries_the_disclaimer_and_writes_only_one_audit_row`.
   - **Before:** `assertStringContainsString('not professional accounting advice', (string) $csv)`
   - **After:** `assertStringContainsString('approved verbally by the clinic\'s accountant on 25 Nov 2026', (string) $csv)`,
     plus the same check for "not a statutory financial statement".
2. `tests/Frontend/stk35-opening-import.test.mjs:11`.
   - **Before:** `/Cost\s+policy to be confirmed by the accountant\./`
   - **After:** `/uses the weighted average cost policy approved by the\s+clinic's accountant\./`

3. **Waiting for the owner's approval (Daniel's second change):**
   `tests/Frontend/classic-only-co6-kept-direct.test.mjs:21-25` (the Classic-only team's test).
   - **Before:** comment "The banner shows above the summary and on every tab." and
     `(classicTemplate.match(/\{\{ report\.banner \}\}/g) ?? []).length >= 3`
   - **After:** comment "The banner shows once, above the summary (REL-STK, Daniel)." and
     `(classicTemplate.match(/\{\{ report\.banner \}\}/g) ?? []).length === 1`
     (the following check, that the banner sits above the summary, stays).

Every other test reads the banner through the `StockValuationReportService::BANNER` constant, so it follows the new
wording without a change. A new test pins the exact new DISCLAIMER and BANNER text.

## Size

About half a day: two constants, one CSV line, one page sentence, comments, the two assertions above, a new test,
TEAM.md, and shots of the three pages for Daniel.

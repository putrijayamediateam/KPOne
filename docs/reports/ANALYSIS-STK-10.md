# STK-10 analysis — stock valuation: what an accountant must decide first

Date: 2026-11-05. Inventory team (Zulkifli, reviewed by Mei Ling), at the owner's request "start STK-10 analysis
only". Branch `docs/stk-10-valuation-analysis` from `main` at `0b6e006`. **Analysis only: it builds nothing and
authorises nothing.** Synthetic data only; not production-approved.

## 1. Why this comes first

STK-10 in `ANALYSIS-STOCK.md` section 8 is "valuation and export: an accountant-approved cost policy and a dated
reconciliation or export to accounting", kept separate from the quantity-only Stock movement summary (RPT-01e). The
stock analysis is clear that the accounting package owns value and cost of goods sold, and KPOne may later only
supply reviewed physical evidence and an export (`ANALYSIS-STOCK.md` lines 82, 155, 181, 194).

So before anyone builds a valuation, an accountant has to say how stock is valued. This note lists what KPOne
records today, the gaps that would make any valuation wrong, and the questions the accountant needs to answer.

## 2. What KPOne records today

| Where | What is kept | Notes |
|---|---|---|
| Purchase order line | `estimated_unit_cost_sen`, optional | Typed when the order is drafted (`ProcurementService`). |
| Receipt lot (STK-3) | `unit_cost_sen`, optional | If the receiver types nothing, the order's **estimate is copied in** (`ProcurementService.php:191`). |
| Purchase receipt movement | `unit_cost_sen` and `supplier_id` | The lot's cost, copied onto the stock movement. |
| Opening balance movement | `unit_cost_sen` and supplier, optional | From Stock setup or the Medicine Catalogue setup. |
| Supplier return (STK-7) | `credit_amount_sen`, optional | A reference to the supplier's credit note only. It is not tied to the lot's cost. |
| Every other movement | no cost | Dispense, transfers, counts, write-offs, treatment usage and waste, patient returns. |

- Costs are **whole sen per stock unit**: every quantity is in the SKU's one verified base unit (STK-1).
- Cost is never shown on a report. RPT-01e and the agent data sources leave it out on purpose. The owner's
  read-only `ai_read` view of stock movements (Ask KPOne, AG-7) does include `unit_cost_sen`.
- No permission grants "see cost" today. Cost is visible on the Inventory Operations screens to the people who
  draft orders and receive stock.

## 3. Gaps that would make a valuation wrong

1. **Missing costs.** Cost is optional everywhere, so many batches will have none. A valuation would have to
   show them as "no cost" rather than as zero.
2. **An estimate can pose as an actual cost.** When the receiver types no cost, the order estimate is saved as
   the lot's cost, and nothing marks it as an estimate.
3. **Whole sen per stock unit is too coarse.** A box of 100 tablets bought for RM 12.34 costs 12.34 sen a tablet,
   which cannot be stored. Rounding to 12 sen loses RM 0.34 a box. A valuation needs either a finer unit (for
   example thousandths of a sen) or the cost per purchase pack with its pack size.
4. **No landed cost.** Delivery charges, discounts on the invoice, free or bonus stock ("buy 10, get 1"), and tax
   are not recorded. Bonus stock arrives with the order's estimate, or with nothing.
5. **No cost on movements out.** Dispensing, write-offs, treatment usage and waste, supplier returns and transfers
   carry no cost. A valuation must work their cost out from a method (section 4, question 1).
6. **Supplier credit is not matched to cost.** A credit note amount can differ from the returned lot's cost, and
   KPOne does not say where the difference goes.
7. **Corrections.** An STK-3 receipt correction reverses quantities but does not say whether it reverses the cost
   too.
8. **No period close.** Nothing fixes the value at a month end, so a later correction changes an old month.

## 4. Decisions for the accountant

Each question lists the usual choices; the accountant picks one, or says it stays outside KPOne.

1. **Cost method.**
   - By batch (each batch keeps its own receipt cost): KPOne already keeps cost on each batch's receipt, so this
     is the smallest build.
   - First in, first out (FIFO) by SKU and branch.
   - Weighted average cost by SKU and branch.
   - Standard cost (a set price per SKU).
2. **What counts as cost.** The supplier's net price after discounts? With or without tax? Are delivery charges
   spread over the lines? How are bonus or free units valued: at zero, or by spreading the cost over all units?
3. **Precision.** Per stock unit to a fraction of a sen, or per purchase pack with its size? This decides whether
   KPOne changes how it stores cost (gap 3).
4. **Missing or estimated cost.** Must every receipt have an actual cost before stock posts? If not, how is a
   batch with no cost, or only an estimate, valued?
5. **Opening stock.** At what value is the stock counted on the first day (supplier invoices, last purchase price,
   or a list the accountant supplies)?
6. **Stock that leaves without a sale.** How are write-offs (expiry, damage), treatment-room usage and waste,
   patient returns kept for a decision, and count differences valued and grouped?
7. **Transfers between branches.** Does stock move at its cost with no profit, and does each branch keep its own
   value?
8. **Supplier returns and credit notes.** Is the return valued at the lot's cost or at the credit-note amount, and
   where does any difference go?
9. **Period close.** Which day and time ends a month (the branch's local midnight, as other reports use)? Is a
   month locked once closed, with later corrections dated in the next month?
10. **Who may see cost.** Finance Officer and Director only? Today no permission covers it.
11. **Where the value goes.** Which accounting package, and what file does it take (columns, one row per batch, SKU
    or branch, a date range or a month end)? Is a downloadable CSV enough, as the other finance reports use?
12. **Checking.** How will the accountant reconcile KPOne's stock value with the package's stock account, and how
    often?

## 5. What KPOne could offer, once the policy is set

These are options for the owner to choose between later, smallest first. None is authorised by this note.

- **A. Nothing in KPOne.** The accountant values stock from supplier invoices in the accounting package. KPOne
  keeps quantities only. This is today's position.
- **B. Clean the cost evidence only.**
  - Mark an estimate as an estimate.
  - Store cost finely enough (gap 3).
  - Optionally require an actual cost on receipt.

  No valuation screen. This makes any later choice cheaper.
- **C. A read-only stock value report**, for the permission chosen in question 10:
  - quantity on hand times cost, by the method chosen in question 1;
  - per branch and SKU at a chosen date;
  - batches with no cost listed separately.

  It is a new report beside RPT-01e, which stays quantity-only.
- **D. A month-end export** for the accounting package, in the format the accountant specifies, with the
  reconciliation they want.

Each later step also needs its own tests:
- historical values never change when today's data changes;
- returns, write-offs and period boundaries;
- exact sen and quantity arithmetic;
- no change to any stock or money record.

## 6. Questions for the owner

1. Who is the clinic's accountant, and which accounting package do they use?
2. Should option B be done anyway, before the accountant answers, so that cost evidence collected during the pilot
   is usable later?
3. Who should see stock cost in KPOne: Finance Officer and Director only, or also the CA Supervisor who receives
   stock?

## 7. What this note does not do

It changes no code, permission, migration, screen or report. It does not authorise any valuation, cost access,
export, accounting integration, tax handling or change to how cost is entered. RPT-01e stays quantity-only. Any
step in section 5 needs the owner's go-ahead, and steps C and D also need the accountant's written policy.

# STK-48: request for quotation (RFQ) before the purchase order (design note, revision 1)

Owner decision 21 (20-21 Nov 2026): "RFQ before the PO: pick items, one RFQ per supplier, convert the chosen quote to
a PO." Build before Monday: owner by tick box, 25 Nov 2026, relayed by B.E.R.N.I.S.S.

## 1. The flow

1. **Start.** Someone who may raise purchase orders (the existing `inventory.purchase_orders.create.organisation`:
   Store Admin, CA Supervisor, Finance Officer) picks the items and quantities once, chooses a delivery location, and
   picks **one or more suppliers**. KPOne makes **one RFQ per supplier**, all with the same lines, grouped as one
   "quote round" so they can be compared. RFQ numbers read `RFQ-YYYYMM-XXXXXXXX`.
2. **Send.** Each RFQ has a clean A4 print (clinic letterhead, supplier, items, quantities, "please quote by <date>",
   no prices). KPOne sends nothing: staff print or save it as PDF and send it themselves. Marking it **Sent** records
   who and when.
3. **Record the quote.** When the supplier answers, the same group of staff type the quote on that RFQ: a price per
   line (pack price, pack size, or "can't supply"), lead time in days and a valid-until date, plus an optional
   supplier quote reference. A quote can be corrected until it is chosen; each save is a new version.
4. **Compare and choose.** The round shows the RFQs side by side: per line the price per stock unit, the total, the
   lead time and which supplier is cheapest, plus each RFQ's total. Someone picks **one** RFQ as chosen (with a reason
   when it is not the cheapest: "faster delivery", "preferred supplier", "stock availability", "other" with a note).
5. **Convert.** The chosen RFQ becomes a **draft purchase order** for that supplier through the existing
   `ProcurementService::create`, with the quoted price per stock unit as each line's estimated cost and a link back to
   the RFQ. From there the existing flow applies (submit, then the Director approves under STK-50). The other RFQs in
   the round close as "Not chosen". A line the chosen supplier can't supply is left off the order and listed.
6. **Cancel.** An RFQ or a whole round can be cancelled with a reason before conversion.

RFQs are optional: a purchase order can still be raised without one.

## 2. Rules

- Every RFQ action uses the existing `inventory.purchase_orders.create.organisation`. **No new permission.**
- Choosing and converting are not approvals: approval stays on the purchase order (Director only, STK-50).
- Quoted prices are cost: they are shown only to holders of `inventory.cost.view.organisation` (Director, Finance
  Officer, Store Admin). Others with the create permission (CA Supervisor) see the RFQ and its lines, lead times and
  "can't supply", but not prices, and cannot record quotes or choose (the service refuses without cost view).
- Supplier quote prices also update nothing else: the STK-13 supplier price list is not changed automatically (a
  later slice can offer "save as the supplier's price").

## 3. Data

New tables `inventory_rfq_rounds`, `inventory_rfqs` (round, supplier, status `draft`, `sent`, `quoted`, `chosen`,
`not_chosen`, `cancelled`, quote reference, lead time, valid until, chosen reason, purchase order id, lock_version,
idempotency keys) and `inventory_rfq_lines` (SKU, quantity, quoted pack price in sen, pack size, can't supply).
PostgreSQL: one chosen RFQ per round; a converted RFQ links to exactly one purchase order of the same supplier and
organisation; statuses move only forward; nothing is deleted. Every write: fresh permission check under lock,
`DB::transaction`, row locks (round, then RFQs in id order), `lock_version`, idempotency key, audit with ids and codes
only (never prices or notes).

## 4. Pages

Inventory > Purchasing gets an **RFQs** tab: a list of rounds with their status, a round page with the comparison
table (cards below 1024 px), the quote sheet and the print. Clean Look, shared components only. Daniel's look first.

## 5. Tests

One RFQ per supplier with the same lines; the print has no prices; quotes saved and corrected until chosen; prices
hidden without cost view and refused when recording; the comparison picks the cheapest per line and in total; choosing
a non-cheapest RFQ needs a reason; conversion creates the draft order with the quoted estimates and closes the others;
can't-supply lines left off; cancel rules; the PostgreSQL rules; a two-process race of two people choosing in one
round.

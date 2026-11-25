# STK-48: request for quotation (RFQ) before the purchase order (design note, revision 2)

Revision 2 applies Mei Ling's review.

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
   line (pack price, and "stock units in one pack (<unit>)", or "can't supply"), lead time in days and a valid-until
   date, plus an optional supplier quote reference. A quote can be corrected until it is chosen; each save is a new,
   append-only version, the version in force is frozen when the RFQ is chosen, and the purchase order records which
   version it came from.
4. **Compare and choose.** The round shows the RFQs side by side: per line the price per stock unit, the total, the
   lead time and which supplier is cheapest, plus each RFQ's total. Ranking uses the exact pack price divided by the
   pack size (never a rounded figure); ties go to the shorter lead time, then the lower RFQ number. Someone picks
   **one** RFQ as chosen (with a reason when it is not the cheapest: "faster delivery", "preferred supplier", "stock
   availability", "other" with a note). A quote past its valid-until date is refused unless they tick "Still valid,
   confirmed with the supplier".
5. **Convert.** The chosen RFQ becomes a **draft purchase order** for that supplier through the existing
   `ProcurementService::create`, with the quoted price per stock unit as each line's estimated cost (whole sen per stock
   unit as STK-10B requires, rounded half up with whole-number maths) and a link back to the RFQ. Conversion is refused
   if the supplier has since been archived. From there the existing flow applies (submit, then the Director approves under STK-50). The other RFQs in
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

## 3. Quoted prices on the purchase order

The converted draft order carries quoted prices as estimates, so the order side must hide them too:

- The purchase order screens (Operations panel and order detail) and the print show estimated cost only to
  `inventory.cost.view.organisation` holders. The print already does (`PurchaseOrderPrintService`); the panel and the
  edit form are checked and fixed in this slice.
- `ProcurementService::updateDraft` replaces every line. **Only when the actor lacks cost view** (their form has no
  cost fields), each line keeps its existing estimate, matched by SKU; a line they add has no estimate. A cost-view
  holder's request stays final, as today, so they can still clear an estimate. A SKU may appear on only one line of an
  order, so the match is unambiguous (the request is refused otherwise). Tested.

## 4. Conversion: locks and retries

In one transaction: lock the actor (create permission and cost view), then the round, then its RFQs in id order; check
under the round lock that the RFQ is `chosen` and has no purchase order; call `create()` in that same transaction (so
the order stays actor, round, RFQs, supplier, location, SKUs); then link the order and mark the others `not_chosen`.
The conversion has its own idempotency key, and a retry returns the order already made. PostgreSQL makes
`inventory_rfqs.purchase_order_id` unique, so a second click can never make two orders.

## 5. Data

New tables `inventory_rfq_rounds`, `inventory_rfqs` (round, supplier, status `draft`, `sent`, `quoted`, `chosen`,
`not_chosen`, `cancelled`, quote reference, lead time, valid until, chosen reason, purchase order id, lock_version,
idempotency keys) and `inventory_rfq_lines` (SKU, quantity, quoted pack price in sen, pack size, can't supply).
PostgreSQL: one chosen RFQ per round; a converted RFQ links to exactly one purchase order of the same supplier and
organisation; statuses move only forward; nothing is deleted. Every write: fresh permission check under lock,
`DB::transaction`, row locks (round, then RFQs in id order), `lock_version`, idempotency key, audit with ids and codes
only (never prices or notes).

## 6. Pages

Inventory > Purchasing gets an **RFQs** tab: a list of rounds with their status, a round page with the comparison
table (cards below 1024 px), the quote sheet and the print. Clean Look, shared components only. Daniel's look first. A new tab may touch existing assertions in
`dd-12-inventory-headers.test.mjs` or the purchasing tab lists; any such change is listed for the owner first.

## 7. Tests

One RFQ per supplier with the same lines; the print has no prices; quotes saved and corrected until chosen; prices
hidden without cost view and refused when recording; the comparison picks the cheapest per line and in total; choosing
a non-cheapest RFQ needs a reason; conversion creates the draft order with the quoted estimates and closes the others;
can't-supply lines left off; cancel rules; the PostgreSQL rules; a two-process race of two people choosing in one
round and of two conversions in one round (one order); a CA Supervisor never receives prices in the RFQ props or on the
converted order; editing the converted draft without cost fields keeps the estimates; an expired quote; an archived
supplier; the rounding of a pack price that does not divide evenly.

## Daniel's conditions (25 Nov, OK with changes on revisions 1 and 2; build to these)

- Rounds list: one row per round (items, delivery location, "2 of 3 quotes in", a state chip); RFQ states are chips,
  only Chosen in success tone. One filled button per view: "Start a quote round" on the list, "Make purchase order"
  on a round once one quote is chosen.
- Comparison: suppliers as columns, items as rows, then lead time, valid-until and Total. Price per stock unit with
  "RM 12.00 / pack of 30" muted under it. "Cheapest" word plus success dot (with the reason in words when a tie is
  broken: "Cheapest, faster delivery"). "Can't supply" muted; "Waiting for a quote" neutral chip.
- **Unequal totals:** a supplier that can't supply every line shows "Total for 3 of 4 lines", and no cheapest-total
  marker is given across unequal sets.
- An expired quote shows "Expired" in warning tone; Choose needs the required checkbox "Still valid, confirmed with the
  supplier" (shown only when expired, "Tick to continue.").
- A cost-hidden role sees "Prices are hidden for your role." in price cells and no Record quote or Choose controls.
- Below 1024 px one card per item with supplier rows inside; actions at the bottom, 44 px.
- Record quote: one block per line (pack price, "Stock units in one pack (<unit>)", live "= RM 0.4000 per tablet"),
  a Can't supply switch with a word, lead time, valid-until (window shown), reference 0 / 60, one filled "Save quote",
  and once at the top "This records the supplier's answer. KPOne does not send or receive anything."
- Choose: outline per supplier; dialog shows the gap ("RM 120.00 more than the cheapest") and reasons as radios when
  not the cheapest. Make purchase order: a confirm listing lines left off. Mark as sent outline; cancel with a reason
  (red only there).
- Print: A4 white in both themes, letterhead, RFQ number, supplier, location, items and quantities, "Please quote by
  <date>", page numbers; no prices, notes or costs.
- Shots at 1440 and 390 of the comparison with an unequal-lines example.

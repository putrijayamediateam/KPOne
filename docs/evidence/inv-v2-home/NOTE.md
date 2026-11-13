# Inventory department — Version 2 note (Zulkifli, Inventory)

For Daniel's one-time department approval, with the home page shots in this folder. Synthetic `.test` data only.

## Pages, in build order (one PR per page or small group)

1. Inventory home (`Inventory/Index`) — this set
2. Stock count and write-off (`GovernedStock`)
3. Treatment room usage
4. Stock review + Reorder suggestions
5. Purchase requests
6. Supplier invoices
7. Supplier returns
8. Batch safety holds + Recalls
9. SKUs + Supplier price list
10. Stock other branches need + Stock health
11. Controlled register (list and detail) — full review, as Daniel asked
12. Pilot readiness + Opening stock import

Stay Classic, never listed: the purchase order print and the controlled register print (A4 paper).

## The department pattern

- **Frame:** `PageFrame` (wide for the home and the long lists, default for forms), crumb through `usePageCrumbs`
  ("Inventory", then "Inventory > <page>"), `V2WorkLayout` title and one description line.
- **Tabs:** `V2Tabs` only where Classic has tabs: home (Stock overview, Batch and expiry, Movements), Stock count /
  Write-off, Controlled register. Sentence case.
- **Rail:** none on the home. Pages with a worklist summary (Stock review, Recalls, Purchase requests: "N waiting
  for you") get a rail from 1536 px and one `V2DisclosureBar` above the table below that, as Registration.
- **Toolbar:** `V2Toolbar` search, one or two main filters from 640 px, "More filters" for the rest, Apply and Clear
  (outline). Phone: search plus "Filters (n)" opening a `V2Dialog` sheet with every filter, Reset and Apply.
- **Tables:** `V2Table cards="xl"` (cards below 1280 px with the sidebar). Four or five columns so a normal phone card
  stays under 250 px: the item cell carries the SKU and medicine; status chips sit with the quantity.
- **Row menu groups (`V2RowMenu`, from server flags only):** Open (view, print) · Move (dispatch, receive, transfer) ·
  Check (count, recount, approve, check by a second person) · Money (supplier invoice decisions; cost only for
  Director and Finance Officer) · Danger last (cancel, reject, reverse, lift hold) in a `V2Dialog` with a required
  reason. A disabled item shows its reason.
- **Status:** `V2Chip` with sentence-case words, mapped from the Classic labels and the same rules (a held batch
  shows its hold reason).
- **Forms:** `V2FormCard` + `V2Field`, `V2StickyBar` with one cobalt button on phones; every lock version and
  idempotency key kept exactly as Classic, with a parity test per page.

## Blocks

- Reused: `PageFrame`, `usePageCrumbs`, `V2WorkLayout`, `V2Tabs`, `V2Toolbar`, `V2Table`, `V2Chip`, `V2Pager`,
  `V2DisclosureBar`, `V2Dialog`; later `V2RowMenu`, `V2FormCard`, `V2Field`, `V2StickyBar`, `V2OptionGroup`.
- New: none.
- Reused unchanged inside V2 for now: the Classic `InventoryOperationsPanel`, `InventoryReferenceDataPanel` and
  `InventorySearchSelect` (location picker). Converting them is a later PR with its own parity test.

## Home page shots (this folder)

Opened: `stock|batches|movements-1440|390-light|dark` (12), `stock-1024-light`, `stock-1280-light`,
`cards-<tab>-390-light|dark` (6), `empty-1440-light`, `empty-390-dark`, `error-1440-light`, `error-390-dark`,
`more-filters-open-1440-light`, `filters-sheet-390-dark`, `tasks-open-390-dark`, `operations-open-1440-light`,
`setup-open-1440-light`. One item has a long name (Amoxicillin and clavulanate potassium 625 mg film-coated tablet).
The error state is the Classic server rule (date to before date from) shown on the page it returns to.

Not opened: the location picker's own list (a Classic component), the inside of the operations panel forms.
No waiting-time rows (not a queue page). Checked: no sideways scroll at 320, 390, 768, 1024, 1280 and 1440; phone cards
212 px (stock and batches, median), 241 px (movements), 260 px for the long-name row.

**Compare:** Classic `pages/Inventory/Index.vue` is unchanged (not in the diff).

## Known limits

- Closing a "Receiving, transfers and stock requests" or "Stock setup" bar clears a half-filled form inside it
  (`V2DisclosureBar` renders its panel only while open).
- The shared `V2Table` empty state is about 10 px too wide in cards mode on a 390 px phone; the home shows its own
  empty card until the block is fixed (reported).

# Inventory V2 home — round 2 (head 455a5a0b)

| Daniel's round 2 point | Done | Shots |
|---|---|---|
| a. Repeated inner heading; keepMounted | The Classic panel heading ("Inventory Operations", "Stock Setup") is kept for screen readers only. Panels stay mounted (`v-show`) and are `inert` when closed. Checked: typed "42" in Ordered quantity, closed, reopened, still "42". #649 is not on main yet; I switch both bars to `V2DisclosureBar keepMounted` the moment it is, and the "known limit" is gone already | `operations-open-top-*`, `setup-open-top-*` |
| b. Both panels open at 390 and 1440 with buttons, light and dark; V2 wrapper | Applied (`StockPanelBar`, V2 page only): every filled button is outline (submits included; the page has no main action), 44 px controls below 1024 px (smallest measured 44 px at 390), no pink. Classic compare: Classic keeps its filled buttons | `operations-open-buttons-*`, `setup-open-buttons-*`, `classic-compare-top-1440-*`, `classic-compare-panels-1440-*` |
| c. Native date boxes | `V2DateRange` in More filters and the phone sheet | `more-filters-dates-1440-*`, `filters-sheet-dates-390-*` |
| d. Classic location picker | Time-boxed exception, in the PR description (DD-77) | (in the sheet shots) |
| e. Tab strip | `V2Tabs` scrolls the chosen tab into view on load; label stays "Movements" | `filters-sheet-dates-390-*` (Movements opened from the URL) |
| f. "Filters (n)" | Shown with a status filter set | `filters-count-390-*`, `filters-sheet-set-390-*` |
| g. Units | "40 units", "1 unit" (V2 only; Classic unchanged) | `chips-*` |
| h. All chips | Held (Held: Recall, amber), Expired, Valid, Available, Patient return | `chips-batches-*`, `chips-stock-returns-*` |

No sideways scroll at 390 or 1440 in any of these. Stock cost: V2 shows nothing new. The home page shows no cost; the
Classic panels are the same components with the same server data, so they show exactly what Classic shows today.

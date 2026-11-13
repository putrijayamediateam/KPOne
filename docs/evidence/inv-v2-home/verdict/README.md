# Inventory V2 home — after Daniel's verdict (head 3c82a14f)

Real V2 shell, seeded synthetic data: a long item name with a long SKU code
(SYN-SKU-AMOXICILLIN-CLAVULANATE-625MG-FILM-COATED), an expired batch (SYN-B-5c5631, expired 1 Oct 2026) and a held
batch (SYN-B-fb2a78, Held: Recall).

| Daniel's point | Done |
|---|---|
| (a) "2 filters applied" beside Clear, "Filters (2)" on the phone button | Yes |
| (b) Cards below 1280; check 1024, 1280, 1366 | Yes (`cards="xl"`); shots at 1024, 1280, 1366 |
| (c) V2 wrapper for the Classic stock panels | `StockPanelBar` (Inventory V2 only): 44 px controls below 1024 px (measured: smallest 44 px at 390), one filled button per form (other default buttons outline). Time-boxed; each panel rebuild is its own full review |
| (d) Closing a bar must not lose a form | Panel kept mounted (`v-show`). Checked: typed "42" in Ordered quantity, closed, reopened, still "42". Switch to `V2DisclosureBar keepMounted` when Kavitha adds it |
| 1. Phone filters: search + "Filters (n)" sheet | Yes: `filters-open-390-dark` |
| 2. Chips never wrap; sentence case | Yes: chips in no-wrap spans, columns merged; "Treatment room use", "Opening balance" (mapped in V2 only, Classic labels unchanged) |
| 3. "Not mapped to Medicine" | The medicine line shows only when the SKU is mapped; real minus sign "−40 unit" |
| 4. Zone name | "Kuala Lumpur time" |
| 5. Crumb equals title | Left to Rashid's rule in `usePageCrumbs` (shell fallback); the page passes `[{ title: 'Inventory' }]` |
| 6. Batch and expiry tab, 320/1024/1280/1366, long SKU, expired, held, light and dark | Yes |

Files: `<tab>-<width>-<scheme>` for stock, batches and movements at 320, 390, 1024, 1280, 1366 and 1440, light and dark
(36); `cards-<tab>-<320|390>-<scheme>` (12); `rows-long-expired-held-*` and `rows-<tab>-long-expired-held-*` (10);
`empty-*`, `error-*`, `filters-open-*`, `operations-open-*`, `setup-open-*` at 1440 light and 390 dark.

Checks: no sideways scroll at 320, 390, 1024, 1280, 1366, 1440 on every tab. Phone cards (median / max): stock 212 / 277
(the long-name row), batches 212–234 / 261, movements 241–247 / 264. Classic `pages/Inventory/Index.vue` unchanged.

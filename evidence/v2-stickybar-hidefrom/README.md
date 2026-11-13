Filled "action" buttons visible per width (real browser).

Block specimen mirroring Roster (header action from 640 px, bar, rail from 2xl):
width            390 640 768 1024 1279 1280 1535 1536
default (no prop)  1   2   2   2    2    2    2    1
hideFrom="sm"      1   1   1   1    1    1    1    1

Real pages on main (as the page opens; bar + other filled buttons):
Queue display settings (no rail): 390: bar 1 + page 0; 640 to 1536: 0 + 0 (the action there is not a filled button).
Registration create: the bar is not rendered in the initial state (it appears after a patient is chosen), so it was not measured with the bar showing; page filled buttons at 640 to 1536: 1 (rail), 390: 0.
Not measured: Roster (needs a draft week with shifts; the block specimen has the same structure), Registration edit (needs a visit), Staff create (#695, no header action: bar below 1280, rail button from 1280, by construction), Medicine, Clinical Service, Business details, Billing, Menu pages (not on main).

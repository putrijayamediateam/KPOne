# V2 Payment Methods shots (feature/v2-payment-methods, head b053490f)

Real Version 2 shell, synthetic data only: Cash (with a description), card, bank transfer (reference required, long
description), and an inactive, unclassified e-wallet method with a long name. Finance officer unless the name says
otherwise. File name = `<width>-<screen>.png`. Widths: 1440, 1280, 1024, 768, 390, 320 (no sideways scroll at any).

Every width: `list-light`, `list-dark`, `disclosure-open`, `add`, `add-errors`, `row-menu`, `row-menu-inactive`, `edit`,
`classification`, `classification-stale-lock` ("This classification changed. Reload before saving."), `deactivate-confirm`,
`deactivate-only-cash-warning` (client line: only active Cash method).
At 1440 and 390 also: `add-errors-dark`, `classification-dark`, `deactivate-confirm-dark`, `empty-state` (the page
response was edited in the browser to send no methods), `director-list`, `administrator-list`.

The administrator view uses the demo "marketing" login with its role switched to administrator in the preview database
only. Not opened: the stale-lock error in dark mode and the edit dialog in dark mode. Edit, Publish and Deactivate carry no
lock version in Classic or on the server (only the classification change does), so there is no stale state to show for them.

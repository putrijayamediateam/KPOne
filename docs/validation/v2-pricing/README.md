# V2 Pricing shots, round 3 (feature/v2-pricing, head 96717bb5)

Real Version 2 shell, finance officer (can publish) unless the name says director, synthetic data only: a long charge
name, a charge with no price, a scheduled price, an inactive charge, two price books. File name = `<width>-<screen>.png`.
Widths: 1440, 1280, 1024, 768, 390, 320. No sideways scroll at any width.

Every width: `list-light`, `list-dark`, `no-price-bar-open`, `publish` (price book not chosen), `publish-book-chosen`,
`publish-empty-amount`, `publish-stale` (own alert with "Reload prices"; the Amount field keeps its normal border),
`publish-stale-after-reload` (dialog stays open, typed amount kept, nothing published), `new-charge`, `new-charge-errors`,
`new-price-book`, `new-price-book-errors`, `row-menu`, `deactivate-confirm`, `empty-state`.
Phones also: `filters-sheet`. 1440 to 768: `filter-applied`.
1440 and 390 also: `publish-dark`, `new-charge-errors-dark`, `row-menu-dark`, `deactivate-confirm-dark`, `director-list`.

Note on `publish-stale-after-reload`: the demo price entry added underneath the open dialog has the same amount as the one
already shown, so the current price line does not change in this shot; the dialog state (kept amount, alert gone) is the point.
Not opened: Check price list and Price history (not touched), the stale alert in dark mode.

## Round 4 (feature/v2-pricing, head 54c4742f): Daniel's last four items
`round4/` holds 1440 and 390 shots of the whole Pricing set again, plus `<width>-director-list.png` and
`<width>-director-row-menu.png` (the disabled "Publish price" with "You can't publish prices with your role." and the
one-line note on the page), `<width>-publish-stale-after-reload.png` (the "Prices reloaded. Check the current price, then press
Publish price." status line), and the list shots where "Ear syringing" reads "No price" and the Cetirizine "Next: …" line sits on
its own row with "9:22 AM" kept together.

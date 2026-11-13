# V2 Pricing shots, round 2 (feature/v2-pricing, head ee557b04)

Real Version 2 shell, finance officer (can publish) unless the name says director, synthetic data only: a long charge
name, a charge with no price, a scheduled price, an inactive charge, two price books. File name = `<width>-<screen>.png`.
Widths: 1440, 1280, 1024, 768, 390, 320. No sideways scroll at any width.

Every width: `list-light`, `list-dark`, `no-price-bar-open`, `publish` (price book not chosen), `publish-book-chosen`
("Current price in <book>"), `publish-empty-amount`, `publish-stale`, `new-charge`, `new-charge-errors` (red borders),
`new-price-book`, `new-price-book-errors`, `row-menu`, `deactivate-confirm`, `empty-state`.
Phones also: `filters-sheet` (Filters (n), Reset, Apply). 1440 to 768: `filter-applied`.
1440 and 390 also: `publish-dark`, `new-charge-errors-dark`, `row-menu-dark`, `deactivate-confirm-dark`, `director-list`
(no Publish price button).

Not opened: Check price list and Price history (not touched), the stale error in dark mode.

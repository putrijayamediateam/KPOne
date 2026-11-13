# V2Table empty state width (fix/v2-table-empty-width)

Specimen: a V2Table in cards mode with no rows, and one with four rows with a long unbreakable email.
Measured page scrollWidth against the viewport, before and after (before-*, after-* = padding and max-width only, after2-* = plus the grid column fix):
- before: 390 px viewport -> page 400; 320 -> 330; 1100 (cards xl) -> 1110; 1440 (table mode) -> table box scrolls 24 px sideways.
- after: every size equal to its viewport (390, 320, 1100, 1440); the 1440 table box no longer scrolls.
- populated cards with a long email: 403 px page at 390 and 320 before wrapping was added; equal to the viewport after.
Files: before-*/after-*/after2-* are the empty state; populated-390.png is the populated card list after.

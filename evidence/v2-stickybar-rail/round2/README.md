# V2StickyBar round 2 (Daniel: capped row, last row never behind the bar)

Specimen: a form column 48rem wide (the bar's cap) inside a V2WorkLayout, the bar with Cancel and Save, scrolled to the end. Measured:
- always, 1440: bar background 1280 px (the column), the row 768 px (48rem) starting at the column's left edge, buttons 160 px each, right-aligned: their right edge lines up with the form column's right edge.
- always, 768: row 736 px, buttons 160 px. xl rail at 1100: row 768, buttons 160.
- 390 (always, and no-rail): row 358 px, the buttons stack at full width (358 px).
- last content row bottom is above the bar top in every case (16 px clear at the end of the page: 699 against 715 at 1440, 647 against 663 at 390). The bar is sticky in the flow, so it never covers the last row.
No sideways scroll. Specimen only.

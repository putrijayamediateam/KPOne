# V2StickyBar rail-aware (feature/v2-stickybar-rail)

Specimen: a V2WorkLayout with the bar as the last child of its main column. Measured display and position per case:
- no rail: 390 shown at the foot (bottom 800 = viewport); 700 and 1440 hidden (unchanged from before: shown below 640 px only)
- always: shown at 390 and at 1440
- rail from xl: shown at 1100, hidden at 1440 (rail shows from 1280)
- rail from 2xl: shown at 1440, hidden at 1600 (rail shows from 1536)
No sideways scroll. The block inside a layout, not a real page. File names: <case>-<width>.png.

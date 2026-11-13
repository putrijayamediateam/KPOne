# V2SelectionBar hint text contrast (feature/v2-selection-bar @ 5cab9d58)

Measured from pixels of the specimen at 390 px (hint text colour against the bar background; 4.5:1 needed):
- warning, light: 8.76:1 (text 106,58,0 on 244,246,251)
- warning, dark: 13.28:1 (251,217,80 on 18,20,26)
- error, light: 6.05:1 (179,38,30 on 244,246,251)
- error, dark: 8.06:1 (255,138,128 on 18,20,26)
All pass 4.5:1. Method: most common colour of the hint row is the background; the text colour is the commonest of the 40 pixels furthest from it.
Note for pages: the block never clears a hint by itself (no timer). An error hint stays until the page changes it, so the page must clear it when the person acts, and the text must say in words what happened.

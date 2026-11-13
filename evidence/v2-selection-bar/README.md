# V2SelectionBar specimen (feature/v2-selection-bar)

Static specimen of the block alone (14 claim rows, bar with "3 claims selected · Panel X · RM 1,240.00", Create 3 claims, Clear).
390 light and dark, 1024, 1440 light and dark: bar visible, sticky at the foot of the viewport, buttons 44 px below 1024 px and 36 px from it.
1600 with railFrom 2xl and 1440 with railFrom xl: bar hidden (display none). 390 with visible=false: no bar, empty live region.
The specimen found a real bug (a wrapper as tall as the bar stopped it sticking), fixed with display: contents. Not shot in the real shell yet.

## Round 2 (in-layout/): the bar inside V2WorkLayout, reading the rail breakpoint itself

Specimen with a V2WorkLayout and a rail. railFrom xl: 1440 bar hidden (rail shows), 1100 bar shown. railFrom 2xl: 1440 bar shown, 1600 hidden.
A layout with no rail at 1600: bar shown. 390 with a disabled action and its hint ("Choose at most 20 invoices"). 390 busy: both buttons disabled, aria-busy true, spinner with the label kept.
Still a specimen, not the real shell.

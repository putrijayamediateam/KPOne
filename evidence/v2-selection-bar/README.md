# V2SelectionBar specimen (feature/v2-selection-bar)

Static specimen of the block alone (14 claim rows, bar with "3 claims selected · Panel X · RM 1,240.00", Create 3 claims, Clear).
390 light and dark, 1024, 1440 light and dark: bar visible, sticky at the foot of the viewport, buttons 44 px below 1024 px and 36 px from it.
1600 with railFrom 2xl and 1440 with railFrom xl: bar hidden (display none). 390 with visible=false: no bar, empty live region.
The specimen found a real bug (a wrapper as tall as the bar stopped it sticking), fixed with display: contents. Not shot in the real shell yet.

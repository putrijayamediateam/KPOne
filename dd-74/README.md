# DD-74 shared Button focus ring (never merge)

Director on /pricing, keyboard focus, device scale 1, the button cropped with 12 px around it. Taken at 1440 and 390 px,
light and dark, in Classic and Version 2. `btn1` and `btn2` are two buttons per shot: an outline button, and the filled
"Create price book" (at 1440 `btn1` is "Check price list"). The ring is measured just outside the button's left edge,
against the surface 8 px further out.

| | Before (40%, 3 px) | After (full strength, 2 px) |
|---|---|---|
| Classic light | 1.91 | 4.36 to 4.60 |
| Classic dark | 2.09 | 7.93 to 7.98 |
| V2 light | 1.99 | 5.95 to 6.43 |
| V2 dark | 2.15 | 6.69 to 7.47 |

The destructive and accent variants aren't on this page. Computed from the theme tokens, their lowest after the change
is 3.85 (destructive, Classic dark, on muted) and 3.47 (accent, V2 light, on muted).

**Classic compare (56 shots):** 54 are identical. The other two are the Director Workspace page in dark mode: 28 pixels,
one shade apart, on a card edge. That is noise already on main, because main compared with main differs on the same
pixels.

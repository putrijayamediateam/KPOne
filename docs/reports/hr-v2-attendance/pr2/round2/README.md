# HR V2 Attendance PR 2, round 2 (feature head c35396aa)

Daniel's notes after round 1. Real V2 shell, synthetic `.test` accounts, task-owned `*_testing` databases.

| Shot | What it shows |
|---|---|
| `overtime-card-before-save-1440-light` | Nothing saved yet: "Not set up yet…" and the defaults label sit above the form, muted |
| `overtime-card-after-save-1440-light`, `overtime-card-after-save-390-dark` | After the first save: "In force since 16 Nov 2026."; the label is gone |
| `branch-switch-dialog-390-light/dark` | Typed a radius, then picked Puchong: filled red "Discard and switch", outline "Keep editing" |
| `two-changed-overtime-390-light/dark`, `two-changed-location-390-light/dark` | Overtime and location both changed: overtime Save filled, location outline with "You also have unsaved changes above." There is no "below" line any more, because a Save keeps the other cards' typing |
| `month-stepper-390-light/dark`, `month-stepper-1440-light/dark` | "‹ Previous month · November 2026 · Next month ›" in words, no native month control (the labels collapse to arrows below 640 px) |

Checked in the browser: typing grace minutes 12 and radius 250, then saving the overtime card, saves a new overtime version and keeps 12 and 250 in the other two cards.

The two location-card shots at 390 have the sticky top bar drawn over the middle, a capture artifact of a card taller than the screen.

# HR V2 Attendance PR 2: shots

Real V2 shell, synthetic `.test` accounts, task-owned `*_testing` database. Feature head `82cbd8cd`.

| Screen | Widths and themes |
|---|---|
| Overtime tab: 2 waiting cards, decided month (approved for fewer minutes, rejected, reversed, withdrawn) | `overtime-390-light/dark`, `overtime-1440-light/dark` |
| Approve with fewer minutes (the "choose why" line, filled button off until a reason) | `dialog-approve-fewer-390-light/dark` |
| Reject (red) | `dialog-reject-390-light/dark` |
| Reverse (red) | `dialog-reverse-390-light/dark` |
| Summary, month: table with Total from 1280, cards with a Total card below | `summary-1280-light/dark`, `summary-390-light/dark` |
| Settings, Director, overtime rules and location card both changed: overtime Save filled, location outline with "You also have unsaved changes above." | `settings-two-dirty-1440-light`, `settings-two-dirty-390-dark` |

Not taken: dialogs at 1440 (not asked for); settings at 1440 dark and 390 light.

`classic/`: Classic after the composable refactor (the compare found all 32 shots byte-identical to the base build, 8 pages at 390 and 1440, light and dark).

Fixed after the first round of shots: the month stepper stretched the month field to full width at 1440 and wrapped at 390; decided cards had an empty actions row and the reason beside the chips; the Day column split "1:10 PM"; the Summary date had a Clear button that pushed Next month onto its own line.

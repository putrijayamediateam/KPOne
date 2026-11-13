# V2 Consultation queue board: shots (branch feature/v2-queue-board)

Real V2 shell (V2 switched on through the saved preference), synthetic data. Light unless the name says dark.

- `board-{light,dark}-{1440,1366,1280,1024,768,390,320}.png`: the supervisor's branch queue at every width, urgent, not-ready, carry-over ("From yesterday") and serving rows, a long name, and a finished treatment step. No sideways scroll at any width (measured).
- `branch-queue-director-1440.png`: Director, branch scope with the doctor select; Call next is outline and disabled until a doctor is applied.
- `doctor-noroom-1440.png`, `doctor-noroom-390.png`: a doctor with no room chosen (Call next disabled with its reason).
- `doctor-room-ready-1440.png`, `doctor-ready-390.png`: room chosen; Call next cobalt with "Next: A-004 · ...".
- `callnext-dialog-1440.png`: the Call next confirm (V2Dialog, cobalt Confirm, names the patient).
- `sound-on-1440.png`: the sound toggle on (off is the default in every other shot).
- `treatment-running-1440.png`, `treatment-done-1440.png`, `treatment-done-390.png`: treatment step rows.
- `dialog-call-1440.png`: Call in dialog open (the poll made 0 requests while it was open, and resumed after Esc).
- `notready-dialog-1440.png`, `notready-board-1440.png`: Mark not ready.
- `row-menu-1440.png`, `row-menu-serving-1440.png`, `filters-sheet-390.png`, `empty-1440.png`: menu, sheet, empty state.
- `failed-1440.png`, `failed-390.png`: "Live refresh paused. Showing last successful results (time)." (the poll request blocked).

Not shot: a cancel-visit dialog in this set (shown in the earlier artifact), the TV settings page (next), 320 for every state.

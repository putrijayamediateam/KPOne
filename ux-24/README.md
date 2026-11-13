# UX-24 part 1: Me home and Management in the V2 shell (never merge)

Director, demo data. Each page is shown full page at 320, 390, 768, 1024, 1280, 1440 and 1600 px, in light and dark.
At 390 and 1440 px there are also these shots:
- `-focus`: keyboard focus on the first control in the content;
- `-account`: the account menu open;
- `-branch`: the branch switcher open.

Measured:
- No sideways scroll at any width.
- The h1 starts at the content edge: 16 px on phones, 272 px beside the sidebar, 312 px at 1600 px, where the
  1280 px frame is centred.

The page's own sidebar, top bar and phone overlay are gone on these two pages, so there is no second menu. The V2 shell
draws the sidebar, search, branch switcher, Back to Classic and account menu.

Clock shortcut:
- **From 1024 px:** an outline "Clock in" in the top bar. It is hidden on Me home, Roster and Attendance.
- **On phones:** the header action next to the primary action, plus the account menu row. At 1440 px the account menu
  shows no clock row, because the top bar has it.
- **`clock-dialog-1440.png`:** pressing it opens the photo dialog. "Not now" closes it, records nothing (the clock
  state stays "out") and puts focus back on the control.

**fixes/ (Daniel's round 1):** Management at 320, 360, 390 and 1440 px, light and dark, cropped to the top.
- **The sidebar mark:** it was missing in some earlier shots because the shared `/kp-mark.png` is 758 KB and arrived
  after the screenshot. The V2 sidebar now uses a 56 px copy (4 KB) and shows it at first paint; the shared file is
  unchanged.
- **The header below 360 px:** the filled space action is full width on top, with Clock in under it, both 44 px. From
  360 px they share one row.
- The 320 px shot also shows the top bar with the menu, search, branch switcher and avatar all present.

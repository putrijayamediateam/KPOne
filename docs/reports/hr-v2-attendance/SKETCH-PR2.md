# HR V2 Attendance, PR 2: sketch for an early look

PR 1 (#822) left four parts on their Classic components inside the V2 frame: the Overtime tab, the Summary tab, the
overtime rules card and the location check card on Settings. PR 2 moves them into V2. Same rules as PR 1: Classic stays
pixel-identical (each Classic component's script moves into a composable and its template stays byte-identical, then a
0-pixel compare), the same props, routes, permissions and server rules, and no new field or request.

## Overtime tab (Director, HR Manager)

- Header line: "Overtime" with the summary on the right (as today).
- **Waiting for a decision**: one card per request (a V2 card list, not a table: each request has a reason and a note
  to read). Line 1 the person, day and branch; line 2 the day type and "asked for 1 h 30 min" (times never split);
  line 3 the reason and note, "Asked by …" when on someone's behalf; the week line with its V2Chip. Outline
  **Approve** and **Reject** (or **Withdraw**, or the muted "Someone else decides this one."). No filled button here.
- **Decided in {month}**: a stepper "‹ Previous month · month field · Next month ›" (the same pattern as the Review day
  stepper: labels hide on phones), then a V2Table from 1280 px (Person, Day, Day type, Minutes, Status, actions) and
  cards below. Status as V2Chips; outline **Reverse** where allowed.
- **Decision dialog** (V2Dialog):
  - Approve: a minutes field that starts at the minutes asked; lowering it turns the title into "Approve fewer
    minutes?" and needs a reason (OperationalSelect). The filled confirm reads "Approve 90 min".
  - Reject and Reverse: tone danger, a reason is required, the confirm stays disabled with "Pick a reason to
    continue." until one is chosen (as the PR 1 correction decision).

## Summary tab (Director, HR Manager)

- Filters: Branch (OperationalSelect) and Period (Week or Month, V2OptionGroup), with a ‹ › stepper.
- "{period}" heading, the not-set-up line when settings are missing.
- V2Table from 1280 px: Person, Rostered, Worked, Late, Left early, No clock-in, No clock-out, Overtime approved
  (numbers right-aligned, tabular), a Total row, "Other branch" as a muted V2Chip on the person; cards below 1280 px
  with label and value pairs. Read-only: no filled button on this tab.

## Settings tab

Three V2FormCards, one after another: **Attendance rules** (PR 1), **Overtime rules**, **Location check**.

- **Overtime rules**: roles that may claim as a two-column checkbox list; the three multipliers in one row (Normal
  day, Rest day, Public holiday), "Warn from" and "Weekly limit" hours, "Applies from" (V2DateField). Save
  "Save overtime rules".
- **Location check**: branch (OperationalSelect), latitude and longitude, radius, the consent text in English and
  Bahasa Melayu, "Turn on" / "Save changes" and an outline "Turn off" with the existing confirm. The pilot branch
  keeps it off (owner decision); nothing here changes that.
- **One filled action per screen**: each card's Save is filled only while that card has changes (or before its first
  save), outline otherwise; the leave guard covers all three cards. When two cards both have changes, both Saves are
  filled; the question for Daniel is below.

## Questions for Daniel

1. Settings with two changed cards: allow two filled Saves in that case, or keep only the most recently changed card's
   Save filled?
2. Overtime waiting list as cards at every width (proposed) rather than a table from 1280 px?
3. Summary on phones: cards with eight label and value pairs, or a horizontally scrolling table with the person column
   kept in view (V2Table's scroll mode)?

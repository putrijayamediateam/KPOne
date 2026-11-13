# V2 Roster: Hui Min's sketch (HR, 2026-11-13)

Written sketch for Daniel; the built page and its real-shell shots are next to this file (`v2/`). Classic Roster stays exactly as it is.

## Frame (all tabs)

- Crumb "KPOne HR › Roster" (no link on "KPOne HR": the HR dashboard is HR-only). V2WorkLayout title "Roster", one line "Your shifts, and the week plan for each branch."
- V2Tabs: My roster | Planner | Minimum staffing (the last two for Director and HR Manager only, as in Classic).
- Week switcher under the tabs: ‹ Previous week | This week | Next week › (outline), then "16 – 22 Nov 2026". Not on Minimum staffing.

## My roster (everyone; no rail; one column, max 48rem)

- Header: "Clock in" is the one filled action (to Attendance); "Open shifts" outline. Credential reminders as one warning line with a link.
- One V2FormCard per day ("Fri 13 Nov"): "8:00 AM – 5:00 PM", the branch name, then "Full day · Break 60 min · Room 2" muted. "On leave" or "Half day leave" in words.
- Empty week: "No approved shifts this week."

## Planner (Director, HR Manager)

- Branch select, then the week status chip (dot and word: Draft, Waiting for approval, Approved, Replaced) and a muted version line ("Version 3 · sent by <name> on 12 Nov 2026, 4:53 PM").
- One filled action, by state: Start draft, Send for approval, or Approve week (approver only, never the sender). Outline: Copy last approved week, Change this week, Add shift, Return to draft. Discard draft in the row menu's Danger group, through a danger V2Dialog.
- Week grid (V2WeekGrid, from 1280): people as rows, Mon to Sun as columns, sticky name column, a shift as text with times ("8:00 AM – 2:00 PM · Morning · Room 2"), "On leave" in words, day totals with "Short". Below 1280: day cards, each listing its people and shifts with Edit and an "Add shift" text link. Until V2WeekGrid ships, day cards at every width.
- Shift editor: V2Dialog (bottom sheet below 640): Staff member, Date, Start, End (next-day hint), Break (minutes), Session, Proposed room ("No room" first). Refusals (approved leave, overlap) show on the field.
- Rail from 1536 ("Check before approval"): the week's warnings and HR-5b compliance warnings, each with a warning chip and one line. Below 1536: one V2DisclosureBar above the days, "18 things to check before approval". "Some shifts were not copied" is its own disclosure after Copy last approved week.
- Phone: the filled action is a 44 px sticky bar.

## Minimum staffing (Director, HR Manager)

- One V2FormCard per session; each role is a labelled number field you can type into, with minus and plus buttons (44 px on phones). Help said once: "Shown as a warning on the planner. It never blocks approval."
- Save is filled only when the form has changed. Empty: "No minimums set for Cheras yet. Every day shows as covered."

## Words

Times "8:00 AM", dates "Mon 16 Nov" and "16 – 22 Nov 2026", branch names in full, no codes, statuses as a dot plus a word.

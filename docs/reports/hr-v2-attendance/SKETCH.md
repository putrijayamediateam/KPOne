# V2 Attendance: Hui Min's sketch (HR, 2026-11-15)

A written sketch for Daniel, before the build. Classic Attendance stays exactly as it is: same props, routes,
permissions, lock versions and idempotency keys, all through `useAttendancePage`, which Classic uses too. Synthetic
data only.

## Frame (all tabs)

- Crumb "KPOne HR › Attendance" ("KPOne HR" is not a link: the HR dashboard is HR-only). The title is "Attendance",
  with one line: "Clock in and out on your own account. Someone else approves each correction."
- V2Tabs: My attendance | Review (n) | Overtime (n) | Summary | Settings. Which tabs show depends on the person's
  permissions, as in Classic. The numbers count what is waiting.
- One polite live region announces state changes only ("Clocked in at 8:02 AM"). The ticking server time is never in a
  live region.

## My attendance (everyone; one column, max 40rem; no rail)

- **Clock card** (the hero). Today's date · branch, then the state in words ("Clocked in at 8:02 AM" or "Done for
  today"). Then **one filled action**, full width and 56 px: Clock in, or Clock out (which asks to confirm, as in
  Classic). It reads "Checking location…" while locating and "Clocking in…" while sending.
- Under the button, muted 13 px: "Server time 8:05:12 AM" (tabular), "Today: 8:00 AM – 5:00 PM" or "No approved shift
  today", the location note where the branch checks location ("This branch checks your location when you press. Only
  inside, outside or not available is kept, never where you are."), the last result, and the privacy line ("KPOne
  records the time and a photo of you at each press. HR can see it. Kept for 7 years (to confirm).") with the text link
  "About clock-in photos". On a shared computer: "Signed in as … Sign out if this is a shared computer."
- A refusal shows as plain text under the button, never red. "Attendance is not set up yet…" and the stale-session
  line sit in a V2DisclosureBar above the card only when present.
- **Needs your clock-out time** (only when present): a warning V2FormCard, one row per day ("Tue 10 Nov · Clocked in
  at 8:01 AM") with an outline "Add clock-out time".
- **This week** · "9 – 15 Nov": the hours line ("38.5 h this week") with the week chip (warning tone near or past the
  limit, a word, never colour alone). Outline "Add a day I forgot to clock" on the right.
  - One V2FormCard per day: the shift ("Shift 8:00 AM – 5:00 PM · Cheras"), then "In 8:02 AM · Out 5:04 PM · Worked
    9 h 2 min" (a time never splits), your own photo thumbnails (In, Out), and flags as warning chips with words
    ("Late", "Left early") plus "Correction waiting" (neutral). The row actions are outline: "Ask for overtime" when
    allowed, and "Correct" or "Add clock-out time".
- **My corrections**: a list (day · kind, "Recorded 5:00 PM · New 6:30 PM", reason), a status chip in words, and a
  ghost "Withdraw" while it is pending.
- **Overtime**: the "N days can still be asked for" line with outline "Ask for overtime", then My overtime (Classic's
  `MyOvertime` content, restyled).

## Review (Director, HR Manager)

- Filters in a V2Toolbar: Branch (select), then the day stepper "‹ Previous day | 13 Nov 2026 | Next day ›". "Add a
  forgotten day" (outline) is on the right.
- The summary line ("12 on the roster · 10 clocked in · 2 flags").
- From 1280 px, a V2Table: Person | Shift | In | Out | Worked | Flags | (actions). In and Out each show the time, the
  photo thumbnail (anyone else's photo needs Show, which is recorded) and the location result in words ("Inside",
  "Outside", "Not available"; never a position). Below 1280 px, one card per person with the same content: In and Out
  each on their own line, named, so each Show belongs to its own press.
- "Corrections waiting" below: one row per correction (person, day, kind, recorded beside new, reason, who proposed
  it). Approve and Reject are outline in the row; the filled action lives in the decision dialog. "Someone else
  decides this one." shows where the viewer may not decide.
- No filled button on the Review list itself.

## Overtime, Summary and Settings

- Overtime and Summary keep their current content: `OvertimeTab` and `SummaryTab`, restyled with V2 cards and V2Table.
- Settings: three V2FormCards (Attendance rules: grace minutes, forgotten clock-out window and correction days, each a
  number field with − and +, plus "From" as a V2DateField; Overtime settings; Location check). Each card has its own
  clearly named Save. A Save is filled only while its card has changes and no earlier card does, so at most one filled
  button shows (the Roster rule). History is a muted list under the first card.

## Dialogs (V2Dialog everywhere)

- Clock out: "Clock out now?" with filled "Clock out" and outline "Cancel".
- Location consent: the English and Bahasa Melayu text (marked as draft until the PDPA review), and two equal outline
  choices, neither preselected: "Use my location" and "Clock in without location". Closing it records nothing.
- Correction: the kind as a V2OptionGroup (Change clock-in, Set clock-out, Add a day). Times are a V2DateField plus a
  V2TimeField, which build the same "YYYY-MM-DDTHH:MM" value Classic sends, with the "Recorded … · New …" line under
  them. Then the reason (select) and an optional note. The filled action is "Send correction".
- Decision: Approve (filled "Approve") or Reject (a reason is required; danger-tone filled "Reject").
- About clock-in photos: the notice text, as in Classic.

## Split (for Daniel to confirm)

The Classic page is about 2,000 lines plus nine components, so I propose two PRs:
1. **This PR**: the frame, My attendance, Review and every dialog in V2. Overtime, Summary and Settings show their
   current components inside the V2 frame, unchanged.
2. **Next PR**: Overtime, Summary and Settings in V2.

V2 is only for people who switch on "Try the new layout", so the interim state is a visible but contained mix.

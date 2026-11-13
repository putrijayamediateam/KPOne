# Queue & TV, Version 2: department note (for Daniel's one-time approval)

## Pages, in order
1. `Queue/Index`: the consultation queue board (highest traffic, queue control).
2. `QueueDisplay/Settings`: TV settings (a normal staff page).
3. `Clinical/Show`, shell only (hold, resume, call next while held, waiting dropdown, recall, treatment-room call): last, and only if the coordinator confirms who owns the doctor workspace.
Out: `QueueDisplay/Screen` (the waiting-room TV; not in the V2 shell). The public QR pages are not ours.

## Queue board (page 1)
- Tabs (V2Tabs, same counts): All active, Waiting, Serving now, Consultation completed.
- Toolbar (V2Toolbar): search (name or queue number); filters: doctor (branch scope only), urgency; Apply; Reset. Actions at the right of the toolbar: Call next, Notification sound on/off.
- Doctor's "My room today": a quiet strip above the table, warning tone until a room is chosen (a doctor only).
- Live line: "Live · every 3 seconds" and the scope ("Clinician scope" or "Branch scope"), muted, under the tabs.
- Rail (from 1536 px) / disclosure bar (below 1536): "Needs attention": entries waiting 60 min or more, not-ready flags, treatments done and ready to resume, and a held consultation over 30 minutes (each links to its row). Counts only; the data is the same board rows.
- Table (V2Table; cards below 1280): patient and KP number; queue number chip (never wraps); arrived; visit notes (clamped, tooltip); doctor chip (tooltip); payment with Panel note; waiting ring (WaitRing: calm under 30 min, amber 30 to 59, red 60 or more, screen-reader text kept); status chips (V2Chip: waiting, serving now, on hold, urgent, returned, not ready, treatment step, ready to resume); WhatsApp icon; row menu.
- Row menu groups (V2RowMenu): Open (View visit, View patient, Edit visit, Open consultation, Open dispensary), Call (Call in, Call anyway, Call again, Call to treatment room, Call to dispensary, Dispense), Move (Send to waiting, Mark not ready or Ready to be called), Treatment (Treatment progress…), Danger (Cancel visit…). Same items and rules as Classic; a group with nothing allowed is left out.
- Pagers (V2Pager): today and carry-over; "Showing the latest 25 of N" lines under Serving now and Completed.
- Dialogs: reuse QueueCallDialog and VisitCancellationDialog; V2Dialog for the treatment-room call, not-ready, ready and treatment-step dialogs.

## TV settings (page 2)
V2FormCard sections: Branch; Rooms (V2Table, cards below 1024, add, edit, activate or deactivate); Screen content (call mode as V2OptionGroup with its privacy help, scrolling text, YouTube link, seconds per poster); Posters (file chooser, list, remove). Preview link in the page header.

## Blocks
Reuse: V2WorkLayout, V2Tabs, V2Toolbar, V2Table, V2Chip, V2RowMenu, V2Pager, V2Dialog, V2DisclosureBar, V2FormCard, V2Field, V2OptionGroup, WaitRing, PageFrame, usePageCrumbs. Need new: none planned (Call next and the room strip are page-local compositions of V2Chip and buttons; I will ask Kavitha before adding one).

## Rules kept
Same props, routes, permissions, lock versions; polling (3 s, backoff, pause when hidden); no logic moved out of Classic (a V2 composable copy plus a parity test); English text; 44 px below 1024; no sideways scroll at 320, 390, 768, 1440; Classic stays byte-identical; every PR carries a Classic compare.

## Shots (via validation/* branch, after V2-CHECKLIST ticked)
1440 and 390, light and dark: board with one long name, a 30-minute and a 60-minute wait, a not-ready flag, a treatment done, carry-over rows; empty board; error state ("Live refresh paused"); row menu open; Call next dialog; TV settings.

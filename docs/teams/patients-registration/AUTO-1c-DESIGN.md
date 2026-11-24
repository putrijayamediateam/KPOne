# AUTO-1c: warn the CA when an assigned doctor becomes busy or leaves (design note, nothing built)

Status: DESIGN ONLY. Follows AUTO-1 (automatic doctor assignment, PR pending the owner's typed ship-it). Owner answer A13
(relayed by B.E.R.N.I.S.S., 24 Nov 2026): "the assigned doctor becomes busy or leaves: keep the assignment, show the CA a
warning, and the CA reassigns." Nothing here is built until the owner answers the questions in section 6 and ships it.

## 1. What exists today

- A waiting patient's doctor is `visits.assigned_doctor_user_id`; the Registration board and the Queue board show the name
  (`VisitDirectoryService` row projection `doctorName`, `QueueDirectoryService` the same; both feed `PatientBoard.vue`).
- Changing the doctor on a waiting visit already works and keeps the patient's place: Edit visit
  (`VisitAdministrationService::update`, lock version, doctor lock first, audited). It is the reassign path.
- "On duty" comes from `RosterOnDuty` (approved roster, minus leave and on call); "busy" from
  `ConsultationLocks::doctorBusy`; the room from `DoctorRoomService::resolve`. AUTO-1 added `DoctorAssignmentService`
  (free, waiting, room per doctor).
- The boards poll (Registration about every 10 s, Queue every 3 s), so a warning computed on the server appears without a
  reload.

## 2. What changes

A small, read-only warning on a WAITING consultation row, with an action to reassign. Nothing moves by itself: the
assignment stays (owner A13). No new permission, table or migration is expected: the CA already holds `visits.update`
(Edit visit) at the branch, and the warning is derived at read time from data the server already has.

Row payload gains one optional key, for viewers who may open Edit visit on that row:
`doctorWarning: { code, label } | null`, where `code` is one of `off_roster`, `on_leave`, `no_room`, `busy_while_others_free`
(final list depends on Q1 and Q2) and `label` is a fixed English phrase. No sales, targets, ids of other doctors or
patients. A doctor is named only by the existing `doctorName` and, in the reassign dialog, by the same list the Register
Visit screen shows (free, "Busy, N waiting", "No room today").

## 3. When a row warns (proposal; Q1 and Q2 decide)

Only for a Waiting consultation entry (not Serving, held, completed, cancelled, OTC) whose assigned doctor, right now:
1. is not on today's approved roster any more (shift ended, week replaced, doctor not rostered): `off_roster`;
2. has approved leave covering now: `on_leave` (RosterOnDuty already removes them, so 1 and 2 share one lookup; the label
   can still say which);
3. has no room today at a branch that has rooms: `no_room`;
4. (optional, Q2) is busy (Serving a patient who is not held) while another rostered doctor with a room is free:
   `busy_while_others_free`. Without the "another doctor is free" condition every waiting patient behind a busy doctor
   would warn all day, which is normal queueing and would train the CA to ignore the chip.

Visits assigned by hand are warned the same way unless Q3 says to limit it to auto-assigned ones.

## 4. Screens

- Registration board and Queue board, in the doctor cell under the name: a chip, status as a word plus an icon, not colour
  alone ("Doctor off duty", "Doctor on leave", "Doctor has no room", "Others are free"), warning tokens (as the other
  board chips, DD-38), and a "Reassign" link that opens the existing Edit visit page on the doctor field (keeps the
  place). Phone cards show the same chip. At 390 the target is at least 44 px (the board's own chip buttons already are).
- The doctor's own queue and the TV never show it (no patient/doctor warning data leaves the CA and supervisor boards).
- Wording stays English (staff screens).

## 5. Cost and safety

- One batched computation per board load, not per row: today's roster once (`RosterOnDuty::onDutyOn`), doctor rooms once,
  busy doctors once (one grouped query), and free doctors once. A test pins the query count for a board with many waiting
  rows, as the pilot-scale tests do for Registration.
- Display only: no lock, no write, no audit row (reading a board writes nothing today). The reassign itself is the existing
  audited Edit visit.
- Read-only payload key; the Call In, Call next, hold and not-ready rules are untouched.
- Tests planned: each code, a doctor back on the roster clears the chip, a Serving or held patient never warns, OTC never
  warns, a viewer without Edit visit gets no key, the TV and the doctor board payloads stay identical, query count flat.

## 6. Questions for the owner (tick one each; * = my recommendation)

Q1 What counts as "leaves"?
 [*] Not on today's approved roster now, on approved leave now, or no room (the three facts the system knows)
 [ ] Only when the shift has ended
 [ ] Also when the doctor presses nothing for N minutes (needs a new signal: not available)
Q2 What counts as "busy"?
 [*] Busy while another rostered doctor with a room is free right now (a real chance to be seen sooner)
 [ ] Busy at all (every patient behind a busy doctor warns)
 [ ] Never warn for busy, only for leaves
Q3 Which visits warn?
 [*] Every waiting consultation visit
 [ ] Only visits assigned by Auto
Q4 What does the CA see on the chip?
 [*] A neutral phrase and a "Reassign" link (no doctor names of others, no figures)
 [ ] Also "Dr X is free" (names the free doctor; shows who the system would pick)
Q5 After a reassign, should the warning stay off if the CA reassigns back later?
 [*] Yes, the chip simply follows the facts
 [ ] No, remember the CA's choice
Q6 Do other roles see it? (Director, CA Supervisor already open Edit visit)
 [*] Whoever may open Edit visit on that row
 [ ] CA only

Dependencies: Queue (Amirul: the Queue board rows and the busy/held definitions), HR (RosterOnDuty and leave), Daniel (the
chip and the Reassign link).

## 7. Answers (24 Nov 2026, approved by B.E.R.N.I.S.S. for the owner during "BE ME", marked "check this" on the owner's board)

All six take the recommended option:
- Q1: "leaves" = off today's approved roster, on approved leave now, or no room today.
- Q2: busy warns only while another rostered doctor with a room is free.
- Q3: every waiting consultation visit.
- Q4: a neutral phrase and a "Reassign" link; no other doctor is named and no figure is shown.
- Q5: the chip simply follows the facts (nothing remembered after a reassign back).
- Q6: whoever may open Edit visit on that row.

Build waits until AUTO-1 has merged. These answers carry B.E.R.N.I.S.S.'s label, not the owner's own words; the owner's own
confirmation is added to this note when it arrives.

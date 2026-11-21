# Q-14 and Q-15: design note (owner decisions of 21 Nov 2026, both yes)

Q-12 (vitals "Check" limits from the TRI-1a guideline) is on hold; it is going back to the owner.

## Q-14: Complete Consultation never completes with unsaved clinical work

### Today (survey of main)
- "Complete Consultation" lives in `TreatmentPlanPanel.vue` (`sendOpen = true`) and opens the "Complete consultation?" dialog (service dispositions), whose button posts `/visits/{visit}/encounter/complete-consultation` with the SAVED plan's lock version.
- The clinical record form (note, vitals, diagnoses) is `Show.vue`'s `form`; the plan draft is the panel's own `form`. Neither is checked on Complete. Unsaved vitals or notes are left behind, and an unsaved plan draft is not what Dispensary receives.
- The OH-06c Hold guard already does this for Hold: `hasUnsavedWork`, `UnsavedClinicalWorkDialog` (Save and hold / Discard and hold / Cancel), `saveAndHold`, `panel.saveForHold`, `discardDraft`.

### Proposed (frontend only; the server cannot see unsaved browser state)
1. The panel's Complete button no longer opens its dialog directly. It emits `request-complete`; `Show.vue` decides:
   - nothing unsaved: call `panel.openComplete()` (the existing dialog, unchanged);
   - unsaved clinical record or plan draft: open `UnsavedClinicalWorkDialog` in a new `complete` mode.
2. The dialog gets a `mode: 'hold' | 'complete'` prop (default `hold`, so the Hold wording and events stay exactly as they are). In `complete` mode: title "Unsaved clinical work", the same one-line list ("Vitals and clinical note not saved yet."), buttons **Save and continue**, **Discard and continue**, **Cancel**, with new events `save-and-complete` and `complete-without-saving`. "Continue" because the next step is still the existing confirm dialog, never a silent completion.
3. Save and continue: `form.patch` the clinical record (as `saveAndHold` does), then `panel.saveForHold(...)` for a plan draft, then `panel.openComplete()` so the doctor still confirms the dispositions. A failed save keeps the dialog open with the error and completes nothing.
4. Discard and continue: `form.reset()`, `panel.discardDraft()`, then `panel.openComplete()`.
5. Cancel: close; nothing changes.
6. Covers vitals auto-filled from the note (Q-11): they make the form dirty.
7. No change to the server, routes, permissions or the complete-consultation payload. The panel's lock versions are read from props at send time, so after "Save and continue" the confirm dialog sends the freshly saved plan.

### Tests
- Frontend source tests: the panel emits `request-complete` and no longer sets `sendOpen` directly; `Show.vue` routes it through the guard; `complete` mode strings and events; Hold mode strings unchanged; the guard covers `form.isDirty` and the plan draft.
- A behaviour test of the guard decision (extracted to a small pure helper, as the Q-11 helper was): nothing unsaved opens the dialog straight away; unsaved clinical record, unsaved plan draft, or both opens the guard.
- No backend change, so no backend test.

## Q-15: "N to check" next to the doctor's Queue tab

### Today
- The Q-13 block marks the doctor's own removed rows whose case waits for the doctor's check, on the "Consultation Completed" tab, which a doctor rarely opens. Tab counts come from `tabCounts()` (one aggregate query) and ride every 3 s poll.

### Proposed
1. `QueueDirectoryService` adds a top-level `checksWaiting` integer to the payload (NOT inside `tabCounts.tabs`, so the existing tab-count shape stays as it is). It is every case waiting for this doctor's check at the active branch (not only the 25 rows listed).
2. One aggregate `COUNT` in `DoctorDispensaryAttentionService` (a method beside `checksForVisits`, same guards: resident doctor, `dispensary.doctor_check.own`, `encounters.view.own`, active branch and effective assignment, own assigned visits, attending clinician, registered visit, removed `sent_to_dispensary`, open handoff, status `awaiting_doctor_check`). It returns 0 without a query for everyone who is not that doctor (CA, CA Supervisor, Director, TV). One indexed count per poll for doctors only. This is Aisyah's file: I will ask her to add it, or add it with her agreement.
3. `Queue/Index.vue` shows a small warning-token pill after the "Consultation Completed" tab label: "N to check", only when N is above 0. It has a screen-reader label ("N medicine checks waiting for you"; the visible text is hidden from the reader to avoid reading it twice). Not a live region, no sound or toast. The tab itself stays the control; the pill is not a second control.
4. Nothing else changes: no filter, no deep link, no new permission, migration or route.

### Tests
- Backend: the count equals the doctor's waiting cases across more rows than the 25 listed; 0 for another doctor, CA, CA Supervisor and Director; hidden-at-0 value; a bounded query count (one aggregate, no per-row query).
- Frontend: the pill is shown only when `checksWaiting > 0`, text, tokens, screen-reader label, not interactive, carried from the payload to the page.

## Existing assertions that may change (to confirm by grep at build time; I will list them for the owner and leave them failing)
- Q-14: the OH-06c frontend tests that pin the dialog's title, button labels and event names (Hold mode is unchanged, so none expected); tests that pin the panel's `sendOpen = true` click handler (`@click="sendOpen = true"`), which becomes `request-complete`.
- Q-15: any test that pins the exact queue payload keys (none found for row keys in Q-13; to re-check for the top-level payload).

## For Daniel
- Q-14: wording "Save and continue / Discard and continue / Cancel", and whether "Continue" is clear enough.
- Q-15: the pill's position on the tab at 390 (the tab strip scrolls) and its tone.

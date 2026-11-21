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
2. A **second thin dialog component**, `UnsavedClinicalWorkContinueDialog.vue`, around the same `OperationalConfirmDialog` (Mira Q14-B1): the Hold dialog stays byte for byte as it is, because `oh-06d-dialog-hierarchy-and-navigation.test.mjs` pins its literal `confirm-label="Save and hold"` and `secondary-label="Discard and hold"`. The new dialog: title "Unsaved clinical work", the same one-line list ("Vitals and clinical note not saved yet."), buttons **Save and continue**, **Discard and continue**, **Cancel**, events `save-and-continue` and `continue-without-saving`. "Continue" because the next step is still the existing confirm dialog, never a silent completion.
3. Save and continue: `form.patch` the clinical record, then `adoptLatestRecord`, then the plan draft save, then `panel.openComplete()` inside the success callback, in the same order as `saveAndHold`. The panel gets a neutrally named expose `saveDraft` pointing at the same function as `saveForHold` (kept as it is, because two tests pin `panel.saveForHold(proceedToHold`), and an expose `openComplete` (`sendOpen` is local to the panel). A failed save keeps the dialog open with the error and completes nothing. The guard also does nothing while `form.processing` or the dialog is processing.
4. Discard and continue: `form.reset()`, `panel.discardDraft()`, then `panel.openComplete()`.
5. Cancel: close; nothing changes.
6. Covers vitals auto-filled from the note (Q-11): they make the form dirty.
7. After the save, the panel's watcher on `plan.services` resets the service dispositions, so the doctor fills them in the confirm dialog again (intended: a changed service set is re-confirmed).
8. The panel's button stays a plain `request-complete` emit with no local fallback, so the guard cannot be bypassed. The only completion path is that button (`complete-consultation` is posted from one place).
9. No change to the server, routes, permissions or the complete-consultation payload. The panel's lock versions are read from props at send time, so after "Save and continue" the confirm dialog sends the freshly saved plan.

### Tests
- Frontend source tests: the panel emits `request-complete` and no longer sets `sendOpen` directly; `Show.vue` routes it through the guard; `complete` mode strings and events; Hold mode strings unchanged; the guard covers `form.isDirty` and the plan draft.
- A behaviour test of the guard decision (extracted to a small pure helper, as the Q-11 helper was): nothing unsaved opens the dialog straight away; unsaved clinical record, unsaved plan draft, or both opens the guard.
- No backend change, so no backend test.

## Q-15: "N to check" next to the doctor's Queue tab

### Today
- The Q-13 block marks the doctor's own removed rows whose case waits for the doctor's check, on the "Consultation Completed" tab, which a doctor rarely opens. Tab counts come from `tabCounts()` (one aggregate query) and ride every 3 s poll.

### Proposed
1. `QueueDirectoryService` adds a top-level `checksWaiting` integer to the `$dynamic` payload (so it rides every 3 s poll; `mergeQueuePollSnapshot` carries new keys; add `checksWaiting?: number` to `QueuePollSnapshot`), NOT inside `tabCounts.tabs`, so the existing tab-count shape stays as it is. It is every case waiting for this doctor's check at the active branch (not only the 25 rows listed). Queue only: the Registration page is untouched.
2. One aggregate `COUNT` in `DoctorDispensaryAttentionService` (a method beside `checksForVisits`, same guards: resident doctor, `dispensary.doctor_check.own`, `encounters.view.own`, active branch and effective assignment, own assigned visits, attending clinician, registered visit, removed `sent_to_dispensary`, open handoff, status `awaiting_doctor_check`). It returns 0 without a query for everyone who is not that doctor (CA, CA Supervisor, Director, TV). Query shape (Mira): one `DispensaryCase` query on the board index (`organisation_id, branch_id, status = awaiting_doctor_check`), then `whereExists` for the visit (registered, assigned to the doctor), the queue entry (removed, `sent_to_dispensary`), the encounter (attending clinician) and the open handoff, then `count()`; no `whereIn` on the 25 listed rows. No migration is needed. This is Aisyah's file: I will ask her to add it, or add it with her agreement.
3. The shared `OperationalTabs.vue` gets an optional `badge?: { text: string; srLabel: string }` on a tab, rendered after the count only when present (so every other page is unchanged and the existing tab tests, which pin the button classes and the count span, still hold). `Queue/Index.vue` uses it for the pill after the "Consultation Completed" tab label: "N to check", only when N is above 0. It has a screen-reader label ("N medicine checks waiting for you"; the visible text is hidden from the reader to avoid reading it twice). Not a live region, no sound or toast. The tab itself stays the control; the pill is not a second control.
4. Nothing else changes: no filter, no deep link, no new permission, migration or route.

### Tests
- Backend: the count equals the doctor's waiting cases across more rows than the 25 listed; 0 for another doctor, CA, CA Supervisor and Director; hidden-at-0 value; a bounded query count (one aggregate, no per-row query).
- Frontend: the pill is shown only when `checksWaiting > 0`, text, tokens, screen-reader label, not interactive, carried from the payload to the page.

## Existing assertions that may change (to confirm by grep at build time; I will list them for the owner and leave them failing)
- Q-14: none expected (Mira): the Hold dialog is left byte for byte as it is; no test pins the panel's `@click="sendOpen = true"`; `consultation-layout.test.mjs` still finds the Complete button and `<Dialog v-model:open="sendOpen">`.
- Q-15: none expected (Mira): no test pins the exact queue payload keys; `OperationalTabs.vue` stays additive.

## For Daniel
- Q-14: wording "Save and continue / Discard and continue / Cancel", and whether "Continue" is clear enough.
- Q-15: the pill's position on the tab at 390 (the tab strip scrolls) and its tone.

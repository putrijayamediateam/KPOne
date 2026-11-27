# Next UI integration proposal (not yet implemented)

The backend and fetch helper are separate frozen artifacts. No existing assertion has been changed. Source-only approval is not a browser approval.

Use the real canonical grouping: own My patients primary tabs plus Needs me; branch Queue/Doctors. Add held as an independently paginated section; treatment and medicine checks use the new workload endpoint, never local filters of the latest25 ordinary rows. Existing PatientBoard mapper and actions must be reused, and selected workload rows must join the action-source lookup so versions/cancellation/consultation actions remain valid. Do not copy a row or invent versions. Poll workload requests without awaiting them in the main Queue loop; reset on scope/branch/hidden/unmount; preserve page only within one selection. Server-clamped page updates the selection.

Treatment wording: canonical “Back from treatment” needs persistent explanatory copy: “Finished treatment steps awaiting doctor review; includes not-successful outcomes. This does not confirm the patient has returned.” Alternative label recommended by design: “Treatment updates”. Neither label authorizes auto-resume/acknowledgement. Coordinator decision pending; implementation can proceed on fetch/render structure without modifying old label assertions.

Do not relabel current removed list “Done today”: it contains all dates and includes medicine-check workflow. A later separate local-day completed query is necessary before that exact demo label is true. Do not relabel current Serving list “Seeing now” while it still includes held rows; its separate query must exclude active holds before adopting that label.

Existing assertion impact inventory for the eventual exact tab switch:
1. q-5-completed-tab-count.test.mjs / “the board uses the helper with the live rows and the server count”: current regex pins Consultation Completed to all-date tabCounts.removed. Preserve until correct local-day projection exists; eventual replacement must test new query total/page rather than hiding rows behind a rename.
2. q-5b-serving-tab-count.test.mjs / “the board wires the Serving Now line to the live serving rows and the server count”: pins old label and latest25 helper. Preserve until the proper active-not-held projection exists.
3. q-15-checks-waiting-pill.test.mjs / “the Queue page gives the Consultation Completed tab a badge only when checksWaiting is above 0”: moving the check action to Needs me/Medicine check needs exact replacement proving own-only check count, correct open action and no nested interactive badge. Preserve generic badge accessibility checks and backend count-shape assertions.
4. tab-counts-1.test.mjs / “Consultation badges use full aggregate totals rather than capped section lengths”: exact four-entry tab array must become role/section-aware tests while retaining full aggregate vs25-page proof, zero and unavailable states. No blanket regex relaxation.

These are an impact inventory, not a request to approve speculative assertion edits. The final concrete diff must accompany the implemented tab contract; no existing assertion should change before that exact approval. Current backend and new fetch helper need no existing assertion changes.

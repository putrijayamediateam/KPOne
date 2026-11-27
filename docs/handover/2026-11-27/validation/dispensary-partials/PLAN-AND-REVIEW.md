# Dispensary shared partials — bounded presentation slice

Base merged shell88210df. Source ownership: Clinical/Partials/DispensaryAttentionPanel.vue and components/dispensary/DoctorCheckActions.vue only. No active integration/root edits, no commits/push. Previous Show slice remains frozen and separate.

Spec mapping: semantic warning/success/destructive tokens replace attention-panel raw palette;14px panel radius; acknowledgement minimum36px desktop44below1024. Doctor check buttons and radio rows now retain44px through tablet widths (old sm boundary reduced them at640). Both note fields use shared field-fill/input/ring tokens and existing labels/maxlength. Outline Verify/Send back and destructive confirm remain as existing workflow requires. All script bytes remain identical, including reason rules, permissions, request keys and commands.

One pending existing assertion approval: PROPOSED-DD57-ASSERTION.patch changes ONLY attention panel expected surface from border-amber-200 bg-amber-50/40 dark: to border-warning/25 bg-warning-wash. Existing repository tests untouched. DD57 per-line dark coverage retained; DSP23 reasons/conditions/labels/guard assertions and ClassicCO4 import/render contract unchanged.

Scoped ESLint and Prettier PASS; git diff --check PASS. Three-file focused test log records exactly one expected pending-approval DD57 surface failure; no other failure. No types/build/browser claimed yet. Next: after owner assertion decision, rerun focused tests and coordinate bounded build/runtime attention status + doctor/supervisor dialogs390/768/1440 in both themes. Use own synthetic fixtures; no mutation of active root cases.

Risk: inherited OperationalConfirmDialog controls must be measured separately since portal content escapes page-level overrides. No shared dialog change presumed. Keep acknowledgement text/processing status and correction reasons untouched. Historical full test gates are not substitutes for final changed-source checks.

Proposed commit/PR: Align Dispensary attention and doctor-check controls with shared tokens. Explain tablet44px correction and semantic attention states; list approved single assertion and actual final gates once complete.

## Completed validation (26 Nov)
Full isolated vue-tsc and production build PASS (types.log/build.log). No validation-only source overlays added. Preview is merged-shell base plus only these two partials; it intentionally does not include other unmerged Stage1/Surface/dialog changes, so screenshots are scoped partial evidence rather than a final whole-page design certificate.

Guarded read-only dump of own synthetic DSP database created new kpone_dsp_partials_preview_test; effective DB+port preflight passed before HTTP3051. Separate patient-declined domain fixture produced attention state; original source DB unchanged. Browser results24 responsive/theme checks at390/768/1440: both supervisor open buttons, all three override radio rows, all five send-back radio rows and attention acknowledgement measure exactly44/44/36px. Both light/dark no horizontal overflow, zero JS errors. Textarea focus confirmed. Real acknowledgement succeeded through UI, producing Acknowledged state; no doctor Verify or Send back command submitted.25 screenshots recorded. browser-results.json contains exact per-target measurements.

Visual self-review: tablet dark override sheet readable; attention390dark label/action wrap cleanly. Base preview still contains historical clinical pink/link and multiple filled actions outside current two-partial token/target scope. Shared Dialog close target uses baseline component here; the separately approved dialog fix must be included by integration. No claim those unrelated base gaps are fixed by this slice.

Only remaining focused-test blocker is the single pending owner-approved DD57 surface expectation; no historical assertion edited. Runtime/type/build work is complete independently of that approval.

Final approval: coordinator relayed owner approval of exact single DD57 assertion. Applied only that proposed line; affected28tests now28/28PASS (focused-approved.log), diff checkPASS. Final DISPENSARY-PARTIALS-CL1.patch and manifest.json freeze two partials plus one test. Earlier pending-approval wording above is historical evidence, now resolved. No unchanged heavy gates rerun; source matches prior runtime/type/build evidence. No commit/push.

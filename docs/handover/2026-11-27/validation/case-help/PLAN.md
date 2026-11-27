# Proposed next bounded case helpful-info slice

Continuation candidate after the case-context review/publication, keeping preceding PRs frozen. Reference PharmacyCase.dc.html has header Show/Hide helpful info and right-side About/Related/Good to know disclosures. Actual case has scanner warnings and action instructions but no matching optional context panel.

Smallest safe scope: Show.vue plus meaningful new disclosure runtime tests and TEAM.md. Add a header Helpful info toggle and two native details sections in the right column: About this page and Good to know. Use concise copy describing existing case content, scan-not-save behavior and existing text-based allergy matching limitation. Existing warnings, refusal messages, mandatory reasons, allergy/stock blockers and action controls remain outside the optional panel. Native details preserve keyboard semantics; toggle aria-expanded/aria-controls and 44px mobile/36px desktop target. No links in this first slice because the demo Related pages list must not bypass the real role-specific back/label guards. No fake daily counts, dates, action status or saved clinical changes.

Mapping gaps: Related pages intentionally deferred until exact existing backLink/label guards can be represented without duplicating controls; full reference parity is not claimed. Show no Ready wording; Verify-completes and all existing transitions remain unchanged. Readonly copy describes viewing only; no invitation to mutate. No new permission, backend or schema.

Before editing: Design review exact copy/placement; inspect actual PageHeader actions slots to preserve existing print visibility and readonly header. Tests execute real disclosure state and actual template bindings; browser390/1440 light/dark including readonly, toggle off/on and keyboard. This is prepared work only until preceding context slice closes.

## Exact proposed copy and placement

Header actions always retain their existing Print/View-label button guard, moved from the slot template to that same button so a separate Helpful info toggle is available without implying print authority. Toggle starts shown to match the reference; label is Hide helpful info / Show helpful info. Panel sits at the end of the right column, after existing action/check instructions. It uses v-show so native details open state survives toggling; About starts open, Good to know closed. All existing safety/action/error content stays outside.

About: readonly — “The medicine and service details prepared for this case. This view does not allow changes.” OTC — “Review the medicines supplied in this OTC case. Save edited lines and confirm what the patient reports about allergies before completing.” Consultation — “Review the doctor's order beside what is supplied. Save edited lines before completing the case.”

Good to know: “Scanning only checks a box against the listed medicines. It does not save or dispense anything.” “The text-based allergy check can miss a match. Existing allergy warnings and confirmation requirements still apply.” No reminder rewrites the actual Verify/doctor-check transition, and no optional panel becomes the sole place a critical warning appears.

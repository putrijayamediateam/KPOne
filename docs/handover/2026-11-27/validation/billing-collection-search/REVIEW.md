# Billing collection search and due-date filters — bounded review

The existing Bills to collect page now supports literal name/KP/invoice search and due-date ranges across the entire authorised eligible collection set before totals and pagination. Branch-wide totals remain unchanged; filtered totals describe matching bills. Pagination retains applied criteria rather than unsaved text. Due today or later and overdue-only controls resolve contradictory UI selections explicitly.

Five-file isolated slice based on frozen PR1141 head8013a87483f273a1e18155fc7831c39a8029b6aa. No migration, grant, financial write/calculation, eligible-invoice or existing assertion changes. Page size remains20. Kept patient name/current KP and visible merged-from KP can match; hidden historical names and contact/identity-document fields cannot. Age boundaries use existing effective_due and branch calendar day.

Validation:27 backend tests /450 assertions pass, covering original collections and corrected-bill contracts plus six new search cases. Changed-source PHPStan0, focused frontend103 pass. Full frontend2210 run had2209 passes and one environment-only missing generated routes/index.ts error; after generating Wayfinder with forms, the affected complete13-test class plus four new tests passed17/17. No implementation/assertion change for that environment failure. Targeted ESLint/Prettier pass. Types/build and actual new UI browser evidence are pending their scheduled slot.

Independent Inventory backend review verifies entire eligible SQL and totals unchanged and no scoped blocker. Independent Staff Design source review reran four actual-page-script tests successfully. See their separate review notes. No independent browser approval is claimed yet.

Initial additional correction fixture queried a nonexistent payments.invoice_id; corrected the new fixture to existing patient_id and reran all27 successfully. No product workaround or financial assertion relaxation.

Suggested commit: feat(billing): filter collection bills by search and due date
Suggested PR: Add collection search and due-date filters
Publication remains pending coordinator review of final evidence. No commits or pushes yet.

Final local gate additions: vue-tsc and production build pass (10.70s build), Pint pass. Actual synthetic preview3095 passed populated390/1440 light/dark captures with no document overflow; search input, due-date select and Apply button measure44px mobile and36px desktop. Enter applies search; unmatched query shows truthful empty state without zeroing branch totals; selecting Due today or later then overdue-only removes conflicting age. Screenshots, script and machine measurements are saved alongside this record. Independent final visual review requested from Staff Design.

Demo comparison: follows audited Bills-to-collect search/due filtering and contextual board composition, preserving live dates/money and backend-authorised routes. Remaining full-demo gaps (including Paid this week historical query and other Billing/Panel tabs) are explicitly outside this slice. Demo wrapper forces light; dark evidence checks actual semantic contrast and layout, not a fabricated dark reference. Existing older header/pagination target debt is not claimed corrected by these three new44/36 controls.

Publication authorised by coordinator under owner's standing commit/PR delegation: committed exact five reviewed files as93d162dcac39cfa6f06f76880d26b70d2dd54bbc, pushed feature/billing-collection-search, opened draft https://github.com/putrijayamediateam/KPOne/pull/1160 with basefeature/billing-demo-collections (PR1141). Attestation queued at first snapshot. Parent owns attachment and release; no merge. Independent final six-image visual review clear. Stacked base is deliberately recorded, not claimed current-main ready.

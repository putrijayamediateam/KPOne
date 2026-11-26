# Billing collection layout assertion decision — 26 November 2026

The owner resumed already-started work and delegated technical decisions/approvals to B.E.R.N.I.S.S. The coordinator instructed this team to apply the previously proposed two BIL23 presentation-test adjustments on PR1141, preserving all financial and read-only checks. This records a delegated technical decision, not a new direct owner answer to the earlier assertion question.

Scope: `tests/Frontend/bil-23-bills-to-collect.test.mjs` only. The compact-table test now verifies Invoice/Due/Still owed headers, six columns, and Bill details with Pay later and Due now definition labels. The second change verifies Approved pay later as a definition label inside the expandable details rather than as a table header. The visible owed amount, date semantics, agreed pay-later date, card breakdown order and explanatory copy checks stay unchanged. All BIL26 review-required checks and read-only page tests stay unchanged.

Reason: the original nine-column table hid the primary action behind horizontal scrolling at1440 when composed with the demo sidebar. The reviewed six-column layout keeps the amount and action visible and retains the full financial breakdown in a native disclosure. Mobile cards remain unchanged. Independent design review covered the frozen source, twelve populated/read-only view states and expanded/error states; no financial behavior was changed.

No grant, migration, business rule, financial calculation, approval/retry/lock logic or production operation is authorized by this decision. Remaining full-demo work and the next search/age-filter slice remain outside this PR. This draft must not be merged merely because the previously blocked tests pass.

Validation: scoped frontend suite98/98 passed. Independent staff/design reviewer approved the two replacements as faithful to the verified disclosure layout, independently reran BIL23 plus the new frame tests9/9, and confirmed the prior twelve-state visual review remains applicable because runtime source is unchanged. No merge was authorized by this review.

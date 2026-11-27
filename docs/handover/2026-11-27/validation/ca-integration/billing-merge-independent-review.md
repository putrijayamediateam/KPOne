# Independent Billing merge review — 26 November 2026

Reviewer: Codex Billing/Panel agent; scoped source review only, no historical-person signoff. Reviewed exactly `app/Domain/Access/BillingPermissions.php` and `app/Domain/Shared/ReadOnlyReviewAudit.php` in the combined CA tree against current main, standalone recovered BIL36/BIL37, approved BIL38 and frozen Queue patch listed in source-inputs.json. No actionable finding.

Programmatic byte-exact reconstruction of BillingPermissions from main plus the three standalone additions matches combined source. Finance retains BIL36 print and print-patient-identity; Director retains BIL36 print and gains only approved BIL38 prices.publish.organisation; Resident Doctor retains BIL37 billing.view_readonly.branch. All other role entries and existing grants remain identical. This file introduces no staff-management grant. This conclusion is limited to the requested merge; caller own-patient/branch enforcement remains the separately reviewed BIL37 implementation and test gate.

ReadOnlyReviewAudit is byte-identical to standalone BIL37 after adding only Queue's queue.details_viewed event. Both queue.details_viewed and billing.reviewed remain allowlisted; invoice remains an allowed ID key. Existing event/key validation, integer/string-only IDs, committed audit transaction and fail-closed failure check are unchanged. No unrestricted content key or event was introduced.

Reviewed SHA256:
- BillingPermissions.php: 97d3ec407fd82368bb033294e744c5137cbc0e9c2ea3a5d8fed33d471d639e10
- ReadOnlyReviewAudit.php: 10060e3f9be6fa004fa0755f454e31dbbf9c8a67b224f2b73bdfb10421601e0d

No source edits or tests run by this reviewer; coordinator owns combined test gates.

# DSP-F06 existing case-type filter

Locked Pharmacy.dc.html To prepare has Type All/Consultation/Medicine only. Existing published workspace1140 (825582b34536071e7ace1e9e545b02c61d49f341, KPOne-dsp-workspace) already displays actual caseType per row; DispensaryCase constants TYPE_CONSULTATION/TYPE_OTC are immutable domain facts, but list has no type filter. This is a bounded real read interaction, not a new case lifecycle.

Proposed separate stack1140 after F11 publication: append optional caseType argument to DispensaryListService::page; normalize all/consultation/otc; apply same existing case_type predicate to row and three status-count queries. Preserve current count independence from text search, branch-local Done window, active-branch/view gate, ordering and25-row clamp. Add separate normalized list.caseType output, no new joins/queries/grants. Controller accepts scalar query only; arrays/unknown safely all.

Index.vue gets explicit-label real Type select (All cases/Consultation/Medicine only),44/36 target. GET selection resets page1; preserve submitted search and type across tabs/pagination/15-second refresh. Actual three states remain; no Ready, no all-branch switch, no doctor-name directory. Clear selected type through All; truthful filter-aware empty copy. Counts labeled as type-scoped while preserving existing search semantics.

Exact planned files: app/Domain/Clinical/Dispensary/Services/DispensaryListService.php; app/Http/Controllers/DispensaryController.php; resources/js/pages/Dispensary/Index.vue; new backend CaseTypeFilterTest; new actual-SFC runtime test; TEAM.md. Inventory current assertions before edit; new tests for both types/search/counts/tabs/month/branch/pagination/invalidarrays/fixedquerycount/authority. Staff and Inventory plan/source review, actual synthetic browser3901440lightdark. Existing mayPrint/read-only/return-link capability untouched; Verify completes and OTC neverrequiresdoctorcheck preserved.

Remaining F06 Doctor filter and F05 medicine/queue search stay separate; no full-demo completion claim.

## Coordinator correction before implementation

Locked C0 defines Type only on To prepare. Coordinator explicitly resolved scope to exact demo behavior: show/apply Type only on Prepare, clear leaving Prepare, normalize toAll on Waiting/Done requests. Selected type scopes Prepare rows/count only; Waiting/Done counters keep existing scope and search semantics. Search/paging/refresh retain type while still on Prepare. Existing Waiting page is unchanged; its separate Whose-cases demo gap is not implemented or inferred here. This correction replaces earlier shared-three-tab filter wording above. No additional owner approval required.

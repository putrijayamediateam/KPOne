# F02 — reciprocal Returns workspace navigation

Bounded slice implemented after F07 publication and backend plan review; final UI/gates pending. Use published age-filter1225 branch as the isolated base, keeping its exact Waiting/Checked/Controlled/Age/Outcome semantics. Do not fold the unrelated Doctor/row-context stack into this Returns slice.

## Actual gap and source

Locked Pharmacy workspace has Dispensing/Returns segmented navigation. Published workspace slice1140 adds an authority-filtered Returns link to Index, but ReturnsToCheck has no reciprocal workspace navigation. This separate1225 base retains older Index presentation; destination proof must not claim it includes the unmerged1140 workspace. No cross-stack code is imported. The existing Dispensing route is authorized through DispensaryDirectoryService::canView plus active branch. Inspect the complete Returns entry gate rather than assuming a bare returns permission implies access.

Actual source disposition: waitingList requires a fresh active actor with dispensary.view.branch AND mayCheck; mayCheck requires CHECK AND ca_supervisor role. It also verifies effective active-branch assignment and organisation. These exact successful-page preconditions satisfy the existing canView branch-view+CA-Supervisor predicate. Therefore every authorized current Returns-page viewer can already enter Dispensing. There is no reachable Returns-authorized/Dispensing-denied viewer under this contract. Do not fabricate that fixture or introduce a redundant nullable DTO. A simple existing-route navigation link is sufficient; both server gates remain unchanged. Backend peer must confirm this implication before code.

ReturnsToCheck displays the same compact segmented group: authorized Dispensing link and current Returns label. Render only within this already authorized page, never as a global permission-independent menu. Reuse the already imported Inertia-based ActionLink with44/36 targets; preserve the entire script byte-for-byte. Match the already reviewed Index workspace navigation composition and visible focus; selecting Dispensing navigates to the real default Prepare route. Browser Back remains normal history; do not transfer return-only filters into Dispensing. No inline return checks, no Ready state, no all-branch scope or follow-up link.

## Files and tests

- ReturnsToCheck.vue, one new actual-template/runtime test and TEAM.md only. No backend change, query, count or DTO extension. Existing assertions untouched unless exact delegated adaptation is separately documented.
- Positive existing CA Supervisor actual navigation and CA/direct unauthorised Returns refusal. Removing branch view authority denies the Returns page itself; do not weaken that guard to manufacture a hidden-link state. No grants or production role changes.
- Existing backend authorization evidence is carried with explicit unchanged-source qualification; run focused relevant checks if required by repository policy, not a redundant authority implementation test.
- Actual synthetic Waiting/Checked, keyboard navigation/back,390/1440 light/dark, no return-filter query leakage to Dispensing. Static/meaningful focused tests plus final peer review before separate draft.

This closes one existing-route navigation gap, not full workspace parity. No source change is authorized by the uploaded demo itself; standing coordinator continuation and peer review govern this bounded implementation.

Backend plan review confirms the exact successful-page implication. Implementation may proceed within the standing coordinator authorization using the established workspace navigation pattern; Design final source/browser review remains required.

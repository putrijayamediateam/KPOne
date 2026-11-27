# F06 Prepare Doctor filter — proposed bounded next slice

Status: implemented after coordinator and peer disposition; final frozen review pending. Age-filter1225 is published separately with all six CI checks green.

Implementation authorized by coordinator27 November after both peer plan reviews. Actual User/StaffProfile schema has no public identifier. Rather than add a migration or expose internal IDs, selectors use Laravel authenticated encrypted organisation/branch/doctor tokens; readers resolve only eligible case-derived users. The validated selected token is reused across option pages. Inventory source review accepts this no-grant/no-schema adaptation; root informed. An opaque HMAC actor/branch context key lets the UI invalidate pending option reads on context changes without disclosing IDs. Ordinary list polling does not replace an explicit pending option search.

## Exact source and demo evidence

Locked `project/Pharmacy.dc.html` Prepare tab has Doctor options All, fictional doctor names and OTC counter. Waiting has separate Whose cases; Done has no Doctor control. The Ready tab conflicts with the explicit Verify-completes decision and remains excluded.

Current candidate base is published medicine-search1222, head f2d64c2247737232329ced2e053b65c4c11bea99. `DispensaryListService::page` already exposes the assigned doctor's name from each authorized case's visit. Its `tabQuery` scopes organisation and active branch. Type applies only Prepare; text search does not change status counts. No doctor directory projection exists in this slice.

## Proposed contract for peer decision

Add Prepare-only Doctor filtering: All; OTC counter (immutable OTC case type); an assigned doctor associated with an otherwise authorized Prepare consultation. Filter the Prepare rows and Prepare count consistently; Waiting/Done remain unchanged and normalize Doctor to All. Type/search/paging compose, changing Doctor resets page1, and leaving Prepare clears it. Contradictory Type/Doctor selections truthfully show an empty result with a clear path.

Do not enumerate the staff directory or infer staff visibility from a new grant. Options must derive solely from the actor's existing scoped Prepare cases, independently of the selected Doctor and text search. Use public user identifiers rather than internal IDs. Missing/unassigned consultation doctors remain accessible under All and must not be relabelled OTC.

A native select with an unbounded distinct-doctor list is not acceptable. Proposed bounded implementation is an authorized read-only option endpoint, 25 distinct doctors per page with an explicit next/previous path and escaped name search. All/OTC remain fixed options; a server-validated selected option stays visible when it is not on the loaded options page. The exact interaction needs Design review before code because the static demo does not specify overflow handling. No new grant, mutation, migration or cross-branch directory is proposed.

Peer disposition: Inventory and Design accept this bounded direction subject to exact source review. Pin a separate labelled option-search and native select for loaded choices, explicit Previous/Next with page/total, and a persistent named picker region focused synchronously on paging. Never auto-select the first result after search/page changes. Keep the current validated selected label separately pinned. Options are independent of Type as well as selected Doctor and case text search, so contradictory selections yield a truthful empty case list without disappearing choices. Use literal escaped option search, deterministic ordering and sensitive no-store responses. Resolve a selected public ID only through eligible scoped Prepare cases. A stale/foreign/unknown selection normalizes to All on the server, with explicit filter-reset feedback and no rejected doctor's label returned; the UI must not imply that All results match the rejected selection.

## Intended files

- DispensaryListService.php: optional trailing doctor filter, scoped predicate/count and normalized DTO.
- DispensaryController.php and existing dispensary routes: scalar-safe input and bounded authorized options read.
- A small Dispensary doctor-options reader if separation keeps list complexity bounded.
- Dispensing Index.vue: Prepare-only existing-data selector and GET query retention.
- New backend/frontend tests and TEAM.md. Inspect exact old assertion impacts before changing any.

## Required evidence and risks

Prove branch/organisation/role restrictions, foreign or stale doctor IDs, zero/unassigned/OTC cases, unknown/array inputs, Type intersection, status-count semantics, search independence, paging and leaving Prepare. Options must have a fixed query budget and 25-row bound even with more than25 doctors; never silently hide overflow. Actual synthetic GET browser proof at390/1440 light/dark must cover normalized selection, clear/reset, empty state, long names, keyboard focus and44/36 targets. Independent backend and design review before publication. No full Pharmacy parity claim.

This is a proposal, not an inferred approval for a new staff directory. Root may prefer a different bounded existing-case option interaction after review. F08 Whose cases remains a separate unresolved interaction.

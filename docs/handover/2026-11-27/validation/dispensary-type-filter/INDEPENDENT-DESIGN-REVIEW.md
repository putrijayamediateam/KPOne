# Independent F06 Type filter review

CLEAR for scoped draft publication at `1069fab922209645b533eccb34b64bff47d40a2a2eb9bbfb9ea7455028782e63`. Independently verified patch and all six manifest files, inspected service/controller and actual page wiring, and reran both new runtime tests successfully.

The corrected contract matches Prepare-only scope: server normalization ignores Type on Waiting/Done, only Prepare query/count is type-scoped, other status counts remain unchanged, search narrows rows independently of counts, and leaving Prepare clears the selection. The GET helper preserves submitted search and resets pagination on changes. Array input safely normalizes to All. Existing authority, case transitions and checking actions are untouched.

Inspected medicine-only390 dark and filtered-empty1440 light. The native labelled selector, count-scope explanation and truthful empty guidance are readable. Builder20-state JSON records44 mobile/36 desktop, no Type control on Waiting/Done, page2 preservation, clear-on-leave/return All, array normalization, no errors and no writes. Broader11 PostgreSQL tests/282 assertions,10 frontend/types/build/static and Inventory backend clearance are supplied builder/peer evidence, not independently rerun here.

No blocking finding remains. F08 Whose cases and other full-demo workspace gaps remain separate; this is not full-page/demo or production acceptance. Inherited duplicate branch/shared rail issues remain unchanged. No merge performed.

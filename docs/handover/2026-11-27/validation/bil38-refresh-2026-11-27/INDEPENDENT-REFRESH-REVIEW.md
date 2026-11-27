# BIL-38 independent refresh review

CLEAR for source scope at exact `eff0f716e51a8c57a3c8b1b731bc1d70bec50e85`, subject to coordinator gates/CI. Independently inspected the main-relative eleven-file scope and migration, checked all current parity-table blob IDs, and ran diff whitespace check. Nine slice blobs remain original, including the approved additive migration and every approved test file.

The BillingPermissions conflict resolution is exactly the current main map plus one Director entry: `prices.publish.organisation`. Main's Finance/Director print and doctor billing-read rules remain intact. No staff-account management permission, role creation or other role grant changes are introduced. The migration grants only this existing operational permission to an existing web Director role, skips/logs a missing role, clears permission cache, revokes nothing, and preserves its approved no-op down method. TEAM is the upstream union plus historical BIL-38 continuation text; its old 'no PR yet' sentence is historical implementation-stage reporting, not current release status.

The original individually approved pricing and import assertion changes remain byte-identical; this refresh introduces no new assertion changes. Coordinator focused PostgreSQL/integration gates are still separate evidence and were not rerun by this reviewer. No merge, migration execution or production claim.

## SUG main refresh checkpoint

CLEAR local head74c28354bf79d042615db62952788eebc9ae906d against mainf8dd19cb. Independently verified exactly11 main-relative files and every scoped blob byte-identical to reviewed eff0f716, including permission map, additive migration and all approved assertion files. No new role/staff-management/permission/migration scope. Root reports73 PG tests/1556 assertions passing. Publication is still held for the fixture main release and subsequent final exact-head parity, not claimed complete here.


## Triage fixture main refresh

CLEAR exact `c7334e3e0c87272d0777b49936e4c7c33d2e13ca`. Independently verified main `9764037af1bfcb24ebad3102a124ff3f6cd7acd2` ancestry and all 11 main-relative scoped blobs identical to reviewed `74c28354bf79d042615db62952788eebc9ae906d`. Refresh-only diff is the upstream TriageDoctorViewTest fixture correction; no BIL38 permission, migration, assertion or product-source change. Earlier 73 PostgreSQL tests/1,556 assertions remain coordinator evidence for the unchanged slice, not newly rerun here. Final remote CI is still required; no merge performed.

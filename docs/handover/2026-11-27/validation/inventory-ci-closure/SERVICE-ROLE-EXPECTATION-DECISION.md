# Approved Store Admin expectation restoration

CI at service c97e9716, backend shard2 job140018870493, ran1373 tests and found two failures in RoleOneRolesTest: exact approved role table and additive/idempotent role migration. Both used the same Store Admin expectation list, which omitted the already-approved inventory.cost.check.organisation and inventory.cost.enter.organisation grants.

Coordinator confirms the owner explicitly approved these exact two additions in this chat, call86d6d13df20e492b98d8870b7828a8a5 question1, covering both assertions. This is restoration of that existing approval, not a new role decision or new delegated approval.

Change: add only those two sorted strings to Store Admin's expected set. Keep every exact-set assertion, staff/access denial, other role expectation and migration idempotence/non-removal check unchanged. No production code, grants or migration changes. Independent Billing review confirms both strings match approved110100 migration/catalogue and no denial/assertion is weakened. Fresh full RoleOneRolesTest plus StaffAuthorityBoundaryTest execution is recorded in service-role-boundary.log; final status must be read there.

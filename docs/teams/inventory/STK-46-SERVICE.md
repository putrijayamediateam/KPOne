# STK-46 service/schema slice — 26 November 2026

Hand-entered cost evidence can now be recorded, independently accepted/rejected, retried safely, and replaced by a new checked record while retaining history. This is a backend-only first slice: accepted costs do not yet feed valuation, and there is no entry/check screen yet.

Owner approved both exact migrations with no-op downs, then separately approved the one-line CREATE OR REPLACE FUNCTION rebuild fix. The role sets are unchanged from the handoff: Finance/Store Admin enter; Finance/Store Admin/Director check; existing cost visibility is also required. No staff-account permission changes.

Implementation: new HandCost model/service, encrypted note, immutable PostgreSQL evidence/guard table, actor/payload-bound entry and decision keys, independent checker/version enforcement, receipt-before-movement-before-hand-cost locks, fail-closed audit containing only IDs/codes/status. Actual costs and reversed receipts cannot be entered or accepted. A waiting cost can still be rejected after reversal. New permissions are registered in the catalogue and the approved additive migration. No-op downs preserve evidence/guards/grants. StockMovement changes are schema PHPDoc only; no ledger writer or valuation function changed.

Validation, all final processes exit0:

- Two successive migrate:fresh --seed runs on the SAME owned kpone_stk46_rebuild_test database pass.
- 52 focused Unit/Feature tests / 1,206 assertions pass, covering new service/constraints plus unchanged receiving cost, verified receiving, staff authority and contention registration guards.
- Three real-process races / 58 assertions pass: two checkers, correction versus entry, correction versus acceptance. Both worker sessions demonstrably block before the receipt barrier releases. Original correction may complete after an earlier cost entry/acceptance; the accepted history remains, preserving the approved average-cost reversal semantics.
- Scoped PHPStan: zero errors. Changed PHP files pass Pint; whitespace checks pass.
- Independent Billing review: no blocker. Added its optional regression proving another authorized checker cannot reuse the original checker's decision key.

No existing test assertion was changed. phpunit.xml only registers the new process test in the serial Contention suite/shard and excludes it from Feature, as existing guards require. The first harness failed to pump queued GO bytes; fixed without weakening blocked-worker assertions. The original CREATE FUNCTION rebuild defect is fixed only after the owner's exact approval; no function-cleanup workaround exists.

Remaining: weighted-average replay override and historical Changed on provenance, report/CSV integration including transfer propagation, dedicated UI, integrated full branch gate and stakeholder UAT. Preserve the residual-value example from independent review:10×RM10 +10×RM20 →20×RM15; reverse10 at average leaves10 valued RM150. The first slice does not alter that existing engine policy.

Patch targets main23639679e and includes all untracked files. PermissionCatalogue.php and TEAM.md overlap the separately recovered STK50/51 patch, so integrate deliberately. No commit, push, PR, production migration or data import performed.

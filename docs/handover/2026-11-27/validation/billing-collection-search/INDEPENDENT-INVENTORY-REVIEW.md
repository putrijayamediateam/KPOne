# Independent collection read-filter review

26 November 2026, Inventory reviewer. Read the service/controller diff, PLAN, and new CollectionSearchTest in /workspace/KPOne-billing-collection-search. No source edits or independent heavy test rerun.

No bounded source blocker. Independently compared the complete eligible SQL and totals implementation with HEAD: both remain byte-identical. Fresh actor authority and branch/organisation selection precede every query; search predicates are grouped AND conditions on the authorized eligible set. Displayed kept name/current number, visible merged-from number, and invoice number are the only search targets. Bound parameters plus explicit !/%/_ escaping preserve literal matching and do not expose a hidden original name after merge.

Filters precede shown totals/page clamping/20-row pagination; branch-wide all totals remain before filters. Existing effective_due supplies disjoint age buckets (>60,31–60,1–30,today-or-later), using immutable branch-local calendar dates. The existing overdue flag composes as an intersection. Controller rejects unknown ages/overlong or non-string queries. No eligibility formula, ledger mutation, permission, schema, or lock changes.

New tests meaningfully cover a match beyond page one, filtered totals/clamping, six boundary dates, branch midnight, literal wildcard/injection-shaped input, kept/merged identities, corrected due-now breakdown, role/branch denial, and unchanged financial/audit rows. This is source/test-design review; author must report final test execution and UI validation separately. No merge authorization or complete UI review claimed.

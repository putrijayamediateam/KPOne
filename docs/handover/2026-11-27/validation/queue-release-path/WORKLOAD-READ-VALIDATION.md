# Workload read/client candidate validation

Frozen patch438cb661821365752aac518dc85bdbc28914cabc2584496e2da140da6b3ee35a,12files, based on reviewed visual721f682772279902ef2d4a3a1b40fddb8e93aa46.

- Combined focused backend26tests/333assertions PASS (workload membership/date/authorization, actual outcome scale, batch parity, existing Treatment records/history, foundation warning and unchanged query ceilings).
- Malformed search validation1test/13assertions PASS: list/map/short alphabetic rejected422; numeric-short and normal text remain valid.
- Scoped PHP static analysis0 errors, Pint and diff-check PASS.
- Full frontend2293tests PASS; types, changed-file ESLint/Prettier, production build PASS.
- Billing independently cleared exact patch/all12 hashes and bounded backend contract; client review separately requested from Staff.

The actual outcome scale test respects the three-held cap: own doctor has1→3 held patients; branch supervisor reads1→26 across distinct doctors. Existing role permissions govern outcomes; no test-only visibility grant. Director sees workload membership with outcome text redacted. The new historical clone parity is a policy-path unit boundary check, not proof of a completed-record workflow; existing Treatment tests retain that coverage.

A real N+1 was reproduced before repair: own outcome hydration27→29 queries from1→3 rows due to repeated effective-branch checks. The additive batch API evaluates current branch authority once and reuses the same current-record predicate. Historical/single-view entry points remain intact; permission and branch revocation are tested. No ceiling was raised.

No workload tabs are integrated in this slice. Exact recovered UI Reset regression remains preserved as QUEUE-WORKLOAD-RESET-FOLLOWUP.test.mjs.txt SHA dfe6b35bddaf3e97b753c0c76fd2fda3bbaf04e9402fdab171544f5ebe22a15e, and must run when the tab transport integration follows. No full-demo parity claim.

Published endpoint/client preservation draft #1215 at630ea3d2627f5610392c79c2eab835411b71a1c4 after independent client review (three tests independently rerun). UI work continues in separate recovery/queue-workload-ui; endpoint/client source remains frozen.

# Queue workload integration — bounded ready candidate

Frozen16-file patch QUEUE-WORKLOAD-INTEGRATION-v2.patch SHA256 8d4e2a2f532a0928fd30832e4eeb18321547f4bb9e818e28ec9e422b9c0295cb. Base is draft1153 commit5330f42d0 (full SHA in manifest-v2.json), stacked on backend1151, visual1145 and archive1142. Never merge the archive wholesale. This patch contains only the new integration delta, no underlying dependencies or synthetic fixture files.

My patients/Queue now uses real independently paginated Seeing now, On hold, Treatment updates, Done today and own Medicine checks under Needs me. Seeing now excludes active consultation holds. Done today counts consultation checkout to Dispensary/Billing within branch-local midnight inclusive to next midnight exclusive, converted UTC; it is not a billing-settlement claim. Treatment updates includes unacknowledged done Q2 steps, including not-successful outcomes, and explicitly does not confirm physical return. Reads do not resume or acknowledge anything. Existing row mapping, action versions, private outcome policy, branch scope and emergency lane remain intact. No migration or grant.

Workload requests are optional/nonawaited in the ordinary refresh loop. Branch/section/page/filter changes abort replaced reads; generation/scope checks reject stale publication. All list pages are at most25, full counts remain independent, server clamps pages after shrink. Reset always uses valid Waiting transport for the existing main search; all six tabs have an actual-handler regression.

Validation on this source:
- Related PostgreSQL workload/Q2/Q13/Q15:27tests285assertions pass; new workload7tests50assertions includes MYT day boundaries, not-held seeing membership, query scaling,26-page clamp, own/branch and real MA outcome privacy.
- Full frontend2283tests pass. Scoped PHPStan0, TypeScript, production build, lint (including every changed test), Prettier and diff whitespace pass.
- Actual browser24checks/8captures: doctor+supervisor states at390/1440 light/dark, truthful treatment text, real completion, zero medicine checks, reset and simulated503 recovery; no overflow or JS errors. Three original fixture visit/queue versions and hold states unchanged; Q2 remains unacknowledged (state-proof.json).
- Additional actual paging: dedicated fictional doctor26completed→25+1; branch28→25+3. No overlap, stable Previous, applied search preserved on page2, Reset returns page1; four captures. All26 created through real service workflows in isolated synthetic preview, no direct clinical-state fabrication.
- Independent Staff source review cleared membership/reset and the five initial exact expectation groups. Final visual and additional heading/column expectation closure passed; see INDEPENDENT-REVIEW.md.

Existing assertion replacements are explicit in ASSERTION-REPLACEMENTS.md/.patch under owner's26November delegated technical decision. They retain old utility boundary tests, shared accessibility checks, Registration behavior and backend payload contracts. No test weakening is used to accept a runtime failure.

Preview3068 uses only kpone_queue_workload_preview_test; original3054/3067 state untouched. Test DBkpone_queue_workload_test. No .env access, real data, production activation, deployment or merge. TV next-page work remains held; no claim of complete demo parity beyond this Queue slice.

Published as draft#1156 at 38e1937f499c1bdf46e24ceb37174277536cddbe against#1153. CI on the new head remains separate from the passed local gates. No merge.

# Queue convergence: reviewed dependency foundation, then bounded visual/workload slices

Prepared26November2026 after scoped TRI2 lane UI publication and Q16f recovery. This is a source reconciliation plan, not a ready-to-merge visual PR. Archive#1142 and its descendants are evidence sources only; none will become ancestors of the candidate.

## Exact source parents

- Current observed main:9764037af1bfcb24ebad3102a124ff3f6cd7acd2, including deterministic TriageDoctorView fixture fix#1194.
- TRI2 lane UI#1199:229522c8d88209cf247c771f5e159e96de4ea253. This carries scoped Treatment-call#1169→record backend#1172→record UI#1180→lifecycle#1182→lane#1185→presence#1188→shared UI#1193. Keep every existing version/lock/privacy/clinical transition contract.
- Q16d#1161:8c9cc5f8a282cfb563a91e828096f7ac21b1821f, already ancestor of AUTO1c#1164:b6b63512b3af9f02eec631ed0ecf606bfa970ad6. Merge the latter once, not both independently.
- Q16e#1157:39db40991b7f5d2cb749f37ac8188898cf675cef, independent average-wait read and optional poll.
- Family#1148:d3f814f1c595ddb14d86037e1bd93c902a7ddbc2 remains an external release prerequisite, not permission to import archive Family code. Its own both-endpoint policy and waiting-today Clinical projection must survive final serial integration. If root needs it in a validation foundation, use this exact reviewed branch, label that dependency explicitly and review Clinical/Show union separately.
- Q16f is independent TV-settings-only work and is not required by Queue/Clinical convergence.

## Phase1: isolated dependency foundation only

Create a disposable branch from exact1199 and normally reconcile current main, exact1164 (including1161), and exact1157. Preserve histories and parent metadata. No archive ancestry and no rebasing/force push. Do not present this multi-parent foundation as a visual-only PR. If parent PRs merge while preparing, rebuild/reconcile from actual current main and verify containment/source parity before publication; do not blindly replay old full files.

Shared-file union checklist:

- QueueController/routes: retain lifecycle Taken/Seen, lane and presence contracts, Treatment routes and all existing middleware/no-store/throttling. Add only rooms-today and average-wait imports/endpoints from scoped reviewed parents. Later workload is a separate read endpoint.
- QueueDirectoryService: retain lane full-set scoped SQL exclusion, independent25-page lane, combined batch hydration, authorized existing row mapper, treatment outcomes/read policy and call capabilities. Add AUTO warning once per bounded Waiting row set, including emergency rows; preserve update-authorized identity and branch/own scope. Never derive full membership from visible ordinary pages.
- VisitDirectoryService/Registration mapper: preserve branch-authorized ordinary identity behavior, other-doctor queue/triage/action privacy, full-red exclusion and dispensary early-return payload. Add only authorized AUTO warning; do not reintroduce unscoped clinical details.
- Queue/Index: preserve one shared emergency host (no duplicate page banner), persistent lane-focus/error region,25-page cursor/filter carry-through, dedicated Treatment action/modal and read-only detail behavior. Union optional nonawaited average/rooms polls with abort/generation/reset/hidden/unmount handling. Keep existing source row mapper fields when adding doctorWarning.
- PatientBoard/types: union dedicated canCallTreatment, server-supplied doctorWarning with scoped Reassign targets, emergency metadata and existing Q2 outcome fields. Preserve default Registration layout and own-layout warning visibility. Do not replace whole components from the archived baseline.
- RosterOnDuty/BranchAccess: preserve additive scoped batching from Q16d/AUTO/presence, exact current-shift/leave/room/effective-branch semantics; no global API policy changes.
- phpunit.xml: retain full Contention union, shard assignments and Feature exclusions from scoped parents and current main. No duplicate discovery or lost Treatment/TRI2/STK tests.

Foundation verification: explicit parent/merge-base graph with archive ancestry-negative check, shared-file diff review, scoped Queue/Registration/DoctorWarning/Treatment/Emergency tests,1-vs26 query proofs, actual row privacy and own/branch tests. Existing failing historical parent heads remain historical; normal main fixture inclusion does not make their prior CI green. No production activation.

## Phase2: smallest new publication slice — recovered Queue workspace presentation

Apply only the five-file immutable visualv4 overlay `queue-demo-makeover/QUEUE-DEMO-FIRST-CANDIDATE-v4.patch` against the proven foundation, reconciling hunks rather than copying full files: Queue/Index, PatientBoard, QueueWorkspaceFrame, EmergencyLane, actual workspace SSR tests. Do not invent card numbers or present unavailable workload sections as working. Preserve latest lane paging/focus and corrected red canSet semantics. Keep branch Doctor pill bounded, usable Waiting/Status widths, own server warning/Reassign and responsive targets.

Compare the actual uploaded runtime MyQueue/Queue role routes and screenshots, not design-note WfQueue mocks. Canonical content is a mapping reference; approved later emergency/privacy behavior takes precedence. This bounded presentation candidate must clearly disclose any data sections still awaiting Phase3 and cannot claim whole-demo parity.

## Phase3: recovered independent workload read+client, then UI integration

Inputs remain immutable: `queue-workload/QUEUE-WORKLOAD-BACKEND-v1.patch`, `QUEUE-WORKLOAD-CLIENT-PREP-v1.patch`, and `queue-workload-ui/QUEUE-WORKLOAD-INTEGRATION-v2.patch` (SHA8d4e2a2f532a0928fd30832e4eeb18321547f4bb9e818e28ec9e422b9c0295cb). Their old complete shared files contain unrelated aggregate state; extract only described additions.

Backend contract: existing scoped baseQuery plus current effective branch; each section independently count/clamp/page25. Held is registered Serving with active consultation hold; Seeing now excludes active holds. Treatment updates are existing DONE steps with closed_at null and authorized outcome projection, including unsuccessful outcomes; neither name nor view implies physical return/resume/acknowledgement. Medicine checks require assigned+attending resident doctor and existing encounters/check permissions/open handoff. Done today uses branch-local day boundaries converted to UTC on removal time, not all-time removed count. No new permissions/migrations/clinical transitions.

Client/UI: optional nonawaited workload requests, independent section/page/filter/generation, response branch/section checks, abort on replacement/context/unmount, unavailable distinct from zero. Reset always transports ordinary statusWaiting rather than new workload tokens. Preserve all command cancel/version/idempotency semantics and manual Call/Recall. Reconcile exact recorded assertion replacements against actual current tests; no broad replacement of published test files. Add query-bound, local-midnight, context/reset/error and real own/branch tests before a ready claim.

Final release evidence must bind the exact extracted candidate, independent Staff/Billing review, source hashes, full required gates, browser actual-data states at390/768/1440lightdark and CI. No mixed foundation will be labelled a visual-only change. Root owns serial merge decisions; this agent does not merge PRs.

## Read-only merge preview and review qualification

`git merge-tree --write-tree --messages` (no worktree/index changes) identified actual TRI2+AUTO conflicts in QueueDirectoryService, VisitDirectoryService, Queue/Index, Registration/Index and QueuePerformanceTest. TRI2+average-wait conflicts only in Queue/Index. The automatic merges of PatientBoard/types/controller/routes still require semantic review; textual cleanliness is not proof of preserved contract. Saved merge preview logs bind exact parent refs above. `convergence-parent-graph.json` independently confirms archive#1142's published commit is not an ancestor of any proposed parent.

Staff Design plan review is clear, with explicit checks that own-layout authorized AUTO warnings survive emergency rows; lane CSS applies only to the legacy nine-column branch layout; pager focus survives reconciliation; and Done today follows actual finalized checkout/removal semantics inside branch-local day bounds. No source implementation or publication approval is inferred from plan review. Billing plan review is pending at this entry.

Conflict-level findings: QueueDirectoryService's textual conflict is only adjacent Visit/DoctorWarningProjection imports; keep both. VisitDirectoryService requires computing AUTO warnings from the merged `$allVisits` set (ordinary page plus authorized lane page), then retaining its merged hydration loop, rather than AUTO's old ordinary-paginator-only loop. Existing visibleQueueEntry and update-policy gates remain mandatory. QueuePerformanceTest's already-approved TRI2 ceilings24GET/21poll/21filtered explicitly include the separate AUTO GET read; preserve those values and the stricter historical hard bounds, then execute the combined fixture. There is no justification to raise ceilings speculatively.

Coordinator decision after plan review: include the exact reviewed Family1148 parent in the disposable validation foundation, so the Clinical/Show union is actually exercised. It remains a separately labelled dependency, not a visual change or archive import. Preserve both-endpoint policy, waiting-today family projection and all shared-host/Hold/draft behavior, with independent semantic review of Clinical/Show. Phase1 implementation waits only for Billing's requested independent plan review; its source worktree is prepared clean at exact1199.

Family semantic tests requested by independent Staff review: absent/null projections hide each card independently; both authorized projections render each exactly once; narrow-width order/scroll remains usable; exactly one emergency host/Clinical bridge and no duplicate Hold/unsaved callbacks. Do not infer family eligibility from treatment data or the reverse. Current main dry merge is textually clean (routes only automatic union), but its prescribing phrase behavior and deterministic fixture must remain intact.

## Phase1 implemented checkpoint

Local branch `integration/queue-reviewed-foundation`, worktree `/workspace/KPOne-queue-reviewed-foundation`, current candidate `bf6d6e760578fe4acea6f3edd6f46e29f7709928`. Normal merges preserve all exact reviewed parent histories, plus new main `375f56c39a4a492016cf8c9a9b3472904a1b03f5` after root's BIL38 release. No push/PR and no archive ancestor. Shared18-file manifest `QUEUE-FOUNDATION-v2.manifest.json`; independent Billing source review clear, Staff preliminary semantic source review clear.

Before latest main: focused combined backend45/491 plus supplemental20/181 =65tests/672assertions; suite integrity2/15; frontend scoped81; PHPStan0. After main: focused new combined warning/privacy+exact performance ceilings+Family+Director publication7/66; fullfrontend2283/2283, types/build pass. Shared reviewed source hashes remain unchanged by BIL38 merge. All test databases isolated/synthetic. No full backend-suite claim.

New regression creates an actual approved roster and actual red/normal Waiting visits, proving AUTO warnings survive both Queue and Registration emergency hydration while own read scope still hides unauthorized warnings/other-doctor rows. New actual-template SSR proves Clinical Family and Treatment independent absent/single/both guard combinations. Browser3155 has own synthetic cloned lane fixtures and one service-created Family link; ten actual Queue/Clinical states pass singleton host, both panels once, authorized linked Waiting patient and overflow checks. Screenshot framing is qualified separately; no clinical command was sent during these read-only combined browser checks.

# Queue screen acceptance — historical checkpoints and latest-stack review

This is a screen-level checklist, not a PR-count completion claim. Read-only review; no builder source changed.

## Inspected anchors

Locked full-wrapper canonical MyQueue runtime: `queue-demo-makeover/canonical-runtime.json`, doctor/supervisor390/1440 captures; supervisor1440 image re-inspected. This is top group navigation, task tabs, metric cards, a central list and optional right help. WfQueue design notes are not production pages. Dark app checks are semantic readability checks, not pixel equality to the light-only wrapper.

Inspected foundation `bf6d6e760578fe4acea6f3edd6f46e29f7709928`; published visual head `721f682772279902ef2d4a3a1b40fddb8e93aa46`, patch `a3a5e1a6a0ee47b98e28406ef2e7b39340cf03975404c327f8bab54cec493d20`; endpoint/client head `630ea3d2627f5610392c79c2eab835411b71a1c4`, patch `438cb661821365752aac518dc85bdbc28914cabc2584496e2da140da6b3ee35a`. Workload UI is now published as draft #1220 at `efe71415580ac71b15507522dd62308981c4c884`, patch `8ed6fbc5a2da4052489f1ee4d766dc45972451b1289bec66fbc7b34099b78bf5`. Local HEAD and clean status independently checked; all ten manifest source hashes were independently verified at final review. Publication is not merged-main acceptance.

## Implemented and boundedly evidenced

- [x] Visible scope-derived My patients/Queue heading; existing Call next moved into header, branch/actual room controls retained.
- [x] Initial Waiting; actual count cards, selected state and help toggle/disclosures. Missing numbers are not fabricated. Queue at a glance correctly avoids claiming every count is today-only.
- [x] Branch Queue/Doctors grouping uses bounded real Rooms read; own-only users receive no branch-directory capability.
- [x] Opt-in Queue columns/card presentation preserves default Registration, own authorized warning/Reassign and emergency lane width rules.
- [x] Latest reviewed TRI2 host, independent red lane paging/focus/privacy, Treatment and Family guards retained in explicit multi-parent foundation. This is a dependency validation state, not merged-main screen acceptance.
- [x] Actual own/branch390/768/1440 light/dark evidence;390x640 Apply and emergency Next/Previous44px centers hit-test below persistent host. Desktop controls36px. This proves those sampled actions, not every page control.
- [x] Independent full-set workload endpoint/client exists with bounded paging, policy projection, response cancellation/generation checks. It is now connected through separately reviewed #1220; the original visual-only slice remains distinct.

## Tab integration acceptance at #1220

- [x] Own Waiting/Seeing now/Treatment updates/On hold/Done today and Medicine checks grouping uses actual independently queried sections; Seeing/Serving workload excludes active Held in both own and branch scope. Cards/sidebar must use matching full-set counts, never visible25-row counts.
- [x] All tab/filter/page switches reset the correct cursor; explicit Reset returns ordinary Waiting transport and clears prior filters/errors. Preserve exact Reset regression.
- [x] Unavailable differs from zero; late success/error/finally cannot repopulate wrong branch/scope/section. Optional requests stay nonawaited; hidden/unmount/reset abort and context guards are tested.
- [x] Explicit page change focuses stable named workload region before controls disappear; error/retry retains focus, ordinary polls never steal focus. Emergency pager remains independent.
- [ ] Actual own/branch26+ rows, no duplicate page membership, search/doctor/urgency, local-midnight Done boundary, unsuccessful treatment outcome, authorized medicine checks and negative roles are demonstrated on the final hash.
- [x] Existing command Cancel/refusal/exact retry, hold state and assignments remain unchanged merely by opening tabs. Short-phone actions remain reachable below persistent host.

## Remaining contracts and deliberate adaptations

Treatment updates is the truthful replacement for demo Back from treatment: done/unacknowledged work is not proof of physical return or automatic resume. Done today means branch-local completed consultation routing, not paid billing. These labels are clinical safeguards, not visual defects to “fix” by copying demo prose.

At the earlier #1220 checkpoint, per-task comparison was pending; the latest-stack section below supersedes that gap. capability-aware My patients/All doctors switching must not be fabricated for own-only actors. Clinician/room/next-patient context and capability-gated TV link were subsequently mapped and reviewed in #1223; this is distinct from TV screen completion. Exact per-tab badges, group selection and help content need final rendered review after integration. Existing host visibility/privacy decisions take precedence over older demo red-alert proposals.

TV display/settings full composition, Malay/name modes and public-feed changes remain separate reviewed contracts; Q16f statistics alone is not TV completion. No new backend contract should be invented merely to match illustrative demo numbers.

Current evidence includes 22 independently rerun visual tests, three client tests and 12 final workload UI/race/focus/Reset tests. Builder final workload gate reports 2,298 frontend tests plus types/build/lint/format. Actual own/branch paging/filter/Waiting transport and short-phone pager hit testing were read; 28 synthetic state records independently compare byte-identical before/after. These were open at this historical checkpoint; latest-stack coverage and its limits are recorded below. No current-main end-to-end release or full-screen parity claim is made.

## Next bounded context plan

Reviewed QUEUE-NEXT-CONTEXT-SLICE-PLAN.md against actual DoctorRoomService choice, shared queueDisplayScreen capability and Q4 call-next wording helpers. Clear to implement read-only current room provenance, separately authorized ready-candidate context and capability-gated TV link. Current room and candidate destination must not be conflated; null/absent data remains explicit. Keep steady Call next label and all existing confirmation/stale-candidate rules. This plan is not implementation acceptance.

Correction: the final workload seeing query excludes active consultation_holds in both scopes (QueueWorkloadQuery seeing branch independently inspected). Earlier held-inclusive branch wording described obsolete presentation assumptions and is superseded; no application behavior changed.

## Populated Medicine checks evidence

At reviewed context #1223, real-service synthetic fixture now yields own1/other doctor0/CA0/Director0. Independent review read fixture/results and compared seven before/after clinical/stock row sets structurally equal. Populated390dark/1440light images show the count, row and check warning; existing Open consultation was not invoked because it posts encounter entry. This closes populated membership/minimized-list evidence, not Items/Sent by DTO parity. Settled action-position visual follow-up requested because overview1440 captured Applying transiently.

Settled Medicine-check action-position desktop follow-up inspected with Apply ready; populated membership/representative visual evidence now closed. Items/Sent by contract remains open. Next own Waiting/Seeing extraction plan is cleared: exact internal Status renderer retains all safety content/events and parent-owned dialog state, with dedicated authorized Zone only in opt-in ordinary own tasks. Default Registration/emergency layouts and mobile full-width treatment behavior remain required.


## Latest reviewed stack — 27 November

Exact final source #1249 `013ac9d9d22bcd9cff3f0825118eef396efd0d34`. Read `queue-final-screen/PUBLISHED-HEADS.json` and `VALIDATION.md`, both final browser result files, and content reconciliation. Latest read-only integration is not merged-main release approval. Historical packet hashes above remain their own scoped evidence.

Implemented content now includes own Waiting/Seeing (Reason/Zone), Held (single actual step badge), Treatment (paired planned step and recorded procedure/result), Done (Seen for/branch-local consultation checkout), Medicine checks (all review Item lines/current sender), branch Waiting/Serving (Status before duration), Held (step/duration, retained safety), Done (historical Sent to/checkout) and optional Doctors four-column table. Context provenance, separately authorized ready-candidate context and capability-filtered TV related link are implemented. None grants new actions or scope.

Read latest 18 own/branch task states at1440light/390x640dark plus two populated Medicine states on identical source. Independently compared both complete before/after snapshot pairs byte-identical. Inspected latest own Treatment mobile representative, preserving explicit unsuccessful result. Earlier final component reviews supply branch Held and Doctors representative inspection. Waiting JSON total0/rows0 belongs to the optional summary response, not the ordinary main-snapshot list. Follow-up source/own-waiting1440 inspection confirms a populated ordinary row and region-scoped menu hit; builder is adding explicit rendered row/identity metadata to remove that ambiguity. Do not interpret summary counts as empty Waiting. This does not claim every predicate, timezone boundary or negative role was rerun on the final stack. Doctors25+2/error recovery/stable summary focus is same-source separate evidence; existing emergency commands were not reinvoked.

Remaining contract gaps: authorized dual-scope switching, current downstream Now at (historical Sent to is deliberately narrower), full TV composition/feed/name/language behavior, and any new illustrative mutation actions. Item lines is not supplied quantity; Doctors eligibility/Free is not staffing availability. Branch Treatment/check tasks are not invented outside existing authority. Full keyboard/screen-reader audit and all page target compliance are not inferred from sampled hit tests.

Release boundary: recorded snapshot #1242 shard3 and aggregator failed; #1247/#1249 still had running checks. That snapshot is not all-green CI and diagnosis belongs to coordinator/builder. No archive1142 merge, no final main integration acceptance, no PR-count completion claim.

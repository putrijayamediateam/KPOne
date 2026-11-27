# Integrated CA candidate — 26 November 2026

Status: validation in progress; not release-ready. Source tree `/workspace/KPOne-ca-integration`; immutable input patches and reconciliations in `source-inputs.json`, current source hashes in `validated-source-sha256.json`. All database/browser data are synthetic.

| Gate | Result | Evidence |
| --- | --- | --- |
| Frontend | 2235 passed, zero skips | /tmp/kpone-ca-integrated-frontend-final.log |
| TypeScript | Passed | /tmp/kpone-ca-integrated-types-final.log |
| Production build | Passed, including final Treatment dialog target fix | /tmp/kpone-ca-integrated-build-hitboxes.log |
| ESLint | Passed | /tmp/kpone-ca-integrated-lint.log |
| Prettier | One interpolation corrected; focused recheck passed | /tmp/kpone-ca-integrated-format.log; Q16b-formatting-followup.patch |
| Pint | One import order corrected; focused recheck passed | /tmp/kpone-ca-integrated-pint.log |
| PHPStan | Passed after complete AUTO1c result-row initialization | /tmp/kpone-ca-integrated-phpstan-fixed.log |
| Unit/Feature | Incomplete: leaked PNL30 execution timer killed a CLI worker | /tmp/kpone-ca-integrated-full-backend.log; bounded fix applied;14 focused tests62assertions and independent review passed; full rerun running |
| PostgreSQL Contention | Running separately after fresh synthetic seed | /tmp/kpone-ca-integrated-contention.log |
| Puchong actual UI | Registration through invoice/payment succeeded; final closure evidence pending | ../ca-smoke/REVIEW.md |
| Treatment held call | Real UI and state/audit proof passed; targets44px390/768 and36px1440 | ../treatment-call/INTEGRATED-UI-REVIEW.md |

The first frontend attempt omitted PHP from PATH and failed one PHP-backed test process; corrected full invocation passed2235. Do not conflate that harness failure with the separate PNL30 timer defect found by the backend aggregate. No assertion was weakened or skipped for either.

All PHP commands use PATH=/workspace/kpone-tools/bin:$PATH. Backend commands use APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55439 DB_SSLMODE=disable DB_USERNAME=agent DB_PASSWORD= and explicitly named task test databases. Never apply migrate:fresh to preview or non-task data. Full Unit/Feature uses --parallel --processes=4 --testsuite=Unit,Feature; real races use --testsuite=Contention on a separate database. Static commands follow composer lint:check/types:check and npm lint:check/format:check/types:check/build.

Remaining integrated features (STK46 entry/check UI, Treatment records, Dispensary case restyle, TRI2) are not silently included in this gate. Each needs its own reviewed patch and approved changes before inclusion; final validation must identify the precise included source set.

PNL30 timer fix: three new regression tests failed before the correction and passed afterwards. Unlimited CLI workers remain unlimited; existing bounded request allowance is restored in finally on success or exception. Full backend rerun uses fresh task-owned parallel databases.

## Baseline aggregate completion and next overlays

Serial PostgreSQL Contention completed:299passed/4139assertions, no skips, exit0. Full Unit/Feature after timer repair completed4028cases:4022passed,4failures,2existing disabled-registration skips;76654assertions. Failures are two older Director-without-publication fixture assumptions and two Store Admin exact-table expectations, all linked to already owner-approved role grants. Exact proposed fixture/table diff awaits explicit approval; no tests weakened. New positive Director-path coverage is being prepared.

After that aggregate completed, STK46 entry/check UI and Dispensary case restyle were integrated. Source-before-stk-ui-dsp-case.json retains the preceding source set. Contentions' mutation services/configuration are unchanged by these two UI/read-controller overlays; new Inventory HTTP/read behavior and broader inventory tests, frontend/types/build are running separately. Treatment-record and TRI2 implementation remain outside this snapshot.

## Current integrated checkpoint after Treatment records

This section supersedes the earlier incomplete status table; previous logs remain historical evidence.

| Gate | Current result | Evidence |
| --- | --- | --- |
| Frontend | 2242 passed, zero failures/skips | /tmp/kpone-ca-treatment-frontend-approved.log |
| TypeScript | Passed | /tmp/kpone-ca-treatment-types.log |
| Build | Passed | /tmp/kpone-ca-treatment-build.log |
| Inventory feature suite | 503 passed / 6728 assertions | /tmp/kpone-ca-stkui-inventory.log |
| Treatment record feature/HTTP | 38 passed / 442 assertions | treatment-integration.json |
| Treatment record real contention | 1 passed / 44 assertions, six scenarios | treatment-integration.json |
| Grant fixture corrections | Owner approved and applied; 23 passed / 499 assertions | /tmp/kpone-ca-approved-grant-tests.log |
| Baseline full backend | Four earlier grant failures resolved in focused tests; full post-integration rerun pending | /tmp/kpone-ca-integrated-full-backend-fixed.log |
| Baseline full contention | 299 passed / 4139 assertions | /tmp/kpone-ca-integrated-contention.log |
| Puchong synthetic CA UI | Registration through completed visit, paid invoice and balanced cash count passed | ../ca-smoke/REVIEW.md |

STK46 UI, Dispensary Show/partials and Treatment record backend/UI are now included. Approved additive Treatment migration applied only to kpone_ca_preview_test. TRI2 backend, shared alert UI and emergency lane remain isolated and are not covered by this integrated gate. Full backend rerun follows their final reviewed integration; no claim of complete release readiness.

Owner approved the single additional Invoice prop assertion on26Nov: retain CompletedInvoicePage and add exact optional nullable treatmentRoomUrl. No financial/permission assertions removed or weakened.

## Final TRI2 integration checkpoint — 13:25 MYT
 approved three aggregate query ceilings (Pilot32, Registration20, Queue18), emergency payload keys and complete empty25/page metadata, CombinedQueueSummary red lane and no-scope empty lane, Registration publication, and one-line verification fixture publication. Applied aggregate tests passed24tests/355assertions. Earlier authority budget2→3 and independent emergency pagination assertion were also approved and applied.

Latest integrated frontend run:2272passed/1failed out of2273. The sole remaining old source assertion pins the former inline Cancel binding; its explicit cancellation handler preserves drafts and cancels an unsubmitted emergency Take. Exact assertion approval is pending; no weakening or skipping. Types/build passed. Full prior backend:4070passed/5failed/2existing disabled-registration skips; all five causes corrected with owner-approved expectations/fixture. Final rerun now running, not yet green.

Actual synthetic browser flow passed: cancel preserves draft and sends no Take; Save and hold preserves the original consultation; dropped response retries the identical key/payload; Seen does not Take. Database evidence shows exactly one emergency claim, both red cases still Waiting and called_at NULL. Navigation preserves the emergency title and exactly one shared host. No production activation or deployment.

Independent review cleared the bounded alarm followup90eae5fd: effectively untaken emergencies rank before the25-row limit, preserving alarms when25older taken cases exist. Three new regressions plus unchanged lifecycle tests passed30/155; PHPStan zero. Applied to integration before the final backend rerun. No existing assertions changed by this fix.

User confirmed switching reasoning after the coordinator requested High for critical TRI2 review. Keep High until critical validation closes, then notify that Medium is sufficient for routine restyle work.

### Publication and browser checkpoint — 26 November 2026

Owner approved separate Consultation, Triage and Homes publications after reviewing exact packets; independent source review cleared all three. Published Triage PR1134 heada30d09277, Homes PR1135 head247f97ddd, Consultation PR1136 head911664f04. All contain main67fcc; CI pending, no merges. Registration1132 routine two-blank-line lint correction pushed asae2ff98c8; full static checks passed locally, assertions/application unchanged. BIL38 refreshed normally to main67fcc asheada8f38817e; all11approved file hashes exact,53tests1291assertions/Pint/PHPStan passed; new-head CI pending. Dialog1130 post-merge CI is green.

Full integrated frontend passed2273/2273 with no skips. Subsequent lint identified only formatting/bracing errors in5files; fixed without changing assertions or behavior, full lint/format and affected tests rerunning. Types/build passed before these non-semantic formatting edits. Registration scope new regression passed1test26assertions separately; final backend aggregate remains running.

Actual integrated readonly responsive check covered16states (Queue/Registration, doctor/CA,390/1440,light/dark). Defined policy passed: doctor emergency lane1, CA lane2, one number-only shared host, no page overflow/JavaScript errors. Registration intentionally retains branch-authorized identities but hides another doctor's queue/triage/action metadata; comparison against main confirmed this is pre-existing authorization. Initial overly broad no-other-identity expectation is preserved in raw evidence and corrected in the policy assessment. See tri2-integrated-ui/responsive-REVIEW.md.

New emergency lane/host touch controls meet44mobile/36desktop. Existing Queue mobile filters remain36px and some Queue/Registration desktop buttons32px; a separate minimal restyle followup is being prepared. No unsafe scope expansion, production activity or database reset occurred.

### Coordinator checkpoint — 26 November 2026, 13:46 MYT

Owner publication batch pending for four concrete completed candidates: PNL30 PR1124 continuation (10files SHA6df04cfddf06d6c504ae414fecbaf4153a6a2f922c66590f838bd0d73af6b3ef), filter targets (2files SHA4f80418aaad5c5ae87d112fd4773cfc9d8ced6f1f68ff68a0a702b130fb1d672), pure Inventory (11files SHA8c728072e0295b13cd64bdda13fb220a2aadac005d5027302d2d65fdbadc3813), pure Dispensary (9files SHA562eb9cebe5751386a66be39a86c5e052d6d3a6b27022338b642bedc4fcd0fe1). All exact-candidate focused/static/types/build gates and independent scoped reviews passed. No publication authorized yet for this batch.

PNL30 fresh browser proves4approved1skipped; nine screenshots independently clear for bulk scope. All new bulk targets explicitly44px. Existing Panel styling debt is documented separately. Inventory original STK6 assertion approval verified in uploaded25Nov:121 and full26Nov:235. Dispensary partial scripts identicalmain; Show changes only presentation class strings/comment. Carried visual evidence is not represented as fresh main browser verification.

Full integrated Unit/Feature is still RUNNING: tool session52066, /tmp/kpone-ca-tri2-full-backend-final.log, requestedJUnit /workspace/kpone-validation/backend/ca-tri2-unit-feature-final.xml, DBkpone_ca_tri2_full_test. Started05:25UTC serial; no final outcome yet. Full Contentions RUNNING session88340, /tmp/kpone-ca-tri2-contention-final.log, requestedJUnitca-tri2-contention-final.xml, separateDBkpone_ca_contention_test; started05:35UTC. Do not reset either database or claim green before completion. New test-only Registration scope1/26 ran separately after full-suite discovery. Full frontend2273passed; formatting-only followup31passed; integrated fullESLint/Prettier/Pintpassed. Exact two-page filter overlay80868bb applied after those frontend gates; sameoverlay243existingtests+30actualbrowserstates/types/build passed in isolatedCA clone; no backend behavior change. Source snapshot current-tri2-candidate-sha256.json predates filter overlay and needs final refresh.

Q16c1126 refreshed/pushed headefd5c84a46556ad3bd47d1a34ed4975275a3ac44 containsmainbd9a423; adjacent conflicts preserve both slices, exactoverlayparity,23backend126assertions/9frontend/staticpassed. Await exact-headCI; then serialmerge freshmain gate. Currentmainbd9a42331d72cf1d2b54ad72b5671e8913d0a483. Q16a postmergegreen; Q16b postmergepending. BIL38head a8f38817 containsprevious67fcc and will need normalrefresh before eventualmerge. Registrationhead ae2ff98c8 staticgreen backendpending. Triage1134, Homes1135, Consultation1136 allpublishedandrootattached. Authfixture1133 all substantivechecks passed; finalaggregatecheckpending atlastobservation.

Continue High reasoning until critical TRI2 aggregate checks close; notify owner before suggesting Medium. No production operation, kpjaya.com change or realdata access.

### Owner publication decisions and aggregate diagnosis — 26 November 2026, 14:22 MYT

Owner explicitly approved publication of all four final packets: PNL30 ten files, filter targets two files, Inventory eleven files and Dispensary nine files. No repeat approval is required for those exact scoped publications. PNL30 published PR1124 headf2974b3d; Inventory PR1137 head814c738a; filter targets PR1138 head5d178658. Root attached all three. Dispensary publication in progress.

Q16c PR1126 merged as c938a9724a0de82d3bc08700442cffe7cff58f8c after all six exacthead efd5c84a checks passed, independent source review and fresh mainbd9 ancestry verification. PNL30 normal refresh onto c938 assigned to Billing before its serial merge gate. No production deployment.

Final serial integrated backend completed4080cases:4067pass,5fail,6errors,2existing skips,75930assertions. All11 failures/errors are WebsiteArtifactBuildTest memory_limit/derivative failures. Initial diagnosis: production guard checks whole-process allocated memory>512MiB; root ran all4080 serially unlike the established four-worker CI gate. Isolated unchanged18-case class with correct generated32-byte synthetic key is under diagnosis; do not claim resolved until evidence. No assertion changes authorized or made.

Contention completed314cases:248pass,8fail,58errors,3041assertions. Most errors derive from the coordinator's malformed synthetic APP_KEY command, not a real.env change. Queue is correcting harness configuration and investigating one distinct historical-schema fixture failure (queue_emergency_alerts missing during inventory rollback proof). Preserve assertions and rollback proof; no production missing-table bypass. Full reruns remain required. Keep High reasoning while these blockers are resolved.

### Approved publications completed — 26 November 2026

All four approved publications completed and root-attached: PNL30 PR1124 now head81973a719b7085cacf96583f7e4e3cfd92067528 after normalrefresh currentmainc938; Inventory PR1137 head814c738ab336b5e4372bc86ef09450aa8c531d43; filtertargets PR1138 head5d178658236bb89a4d61b68c0092b28fae802ce8; Dispensary PR1139 head6de9c339f4dcb3af747a0d3bd801640a8d158885. Only Q16c merged in this turn. New PRs await exact-headCI and current-main containment before merging. PNL30 fresh39/280+5frontend/Pint/PHPStanpassed; routes retains upstreamQ16c plus exactapprovedbulk endpoint.

Root verified original test-command placeholder decoded26bytes, explaining invalid cipher failures; this is a coordinator harness error, no real.env/key change. Correct generated32byte key proof44tests/400assertions passed (Billing+Attendance), including both previously opaque worker errors. Independent Patients review cleared a historical-fixture-only proxy for EmergencyAlertService::closeLocked once during intentional pre-STK3 setup. Real queue/clinical/inventory fixture and all old rollback assertions/cutoff remain unchanged; no production missing-table guard. Queue applies exact fixture correction, runs historicalfocused proof, then fullContention withcorrectkey.

WebsiteArtifactBuild isolated18-case diagnostic and subsequent establishedparallel4 fullUnitFeature gate assigned Patients. Preserve failedserial/fullcontention logs; do not replace them with misleading all-green claims. Current fullaggregate status remains NOT GREEN pending corrected reruns. High reasoning remains appropriate.

### Corrected aggregate reruns launched — 26 November 2026

Unchanged isolated WebsiteArtifactBuildTest passed18tests1431assertions in148s, childRSS~124MiB versus512MiB guard. This supports whole-process serial-test memory accumulation; no Website application fix/assertion change. Full establishedparallel4 Unit,Feature now running session82218 via /workspace/kpone-validation/backend/run-tri2-parallel4.py, log /tmp/kpone-ca-tri2-full-backend-parallel4-final.log, JUnitca-tri2-unit-feature-parallel4-final.xml, dedicatedbaseDBkpone_tri2_full_parallel_test, verified32byte synthetickey, before/after sourcehashes. Findings /workspace/kpone-validation/website-budget-diagnostic/FINDING.md.

Historical inventory rollback fixture focused proof passed1test169assertions unchanged; full Contentions correctkey now running session87945, /workspace/kpone-validation/contention-recovery/full-contention.log and.xml, own DBkpone_ca_contention_test. Exact appliedfixturepatch at contention-recovery/historical-fixture-applied.patch. No runtime source changes.

Dispensary1139 initialattestfailed and skippedappchecks; detailedlogs403, exactcauseunconfirmed. Main advanced duringpublication; normalrefresh toc938 and freshCI assignedDispensary, no workflow modification. Rootalreadyattached1139; no need childattach retry.

### Aggregate gates passed — 26 November 2026

Verified corrected fullUnitFeature:4081cases,4079passed,2existing skips,77282assertions,zero failures/errors;4024sourcehashes unchanged. FullContention314/314passed,4271assertions,zero skips/failures/errors;3406sourcefiles unchanged. Logs retained alongside prior failedharness runs. No assertion/memoryguard weakened. Critical aggregate validation is closed; remaining publication/extraction/CI gates are not implied complete.

PR1124 all6 exacthead81973a7 checks passed; fresh mainc938 containment and independentreview verified before normalmerge. Toast1109/1111 andBIL361112 refreshed/pushed with originalapprovedscope; rootattached. BIL37 targetfollowup stillawaiting finalcandidate review/publicationdecision.

# Queue release dependency map — actual main, 26 November 2026

Inspected current remote main e5e72f69b (full SHA in main-sha.txt), not archive contents. Exact1156 head38e1937f499c1bdf46e24ceb37174277536cddbe has all six CI checks SUCCESS: attest, static, backend shards1/2/3, aggregate ci (run45847264569). This validates the preservation stack, not a main-based extraction.

## Existing preservation chain

main → archive-only1142 → visual1145 → workload backend1151 → fetch client1153 → integrated workload1156.

Archive1142 must NEVER merge. The descendants currently inherit its entire mixed integration tree, so changing their base to main would expose unrelated work. Preserve their reviewed commits and immutable overlays; rebuild scoped candidates on published dependencies, then retarget/replace drafts deliberately with explicit review.

## Already on actual main

- Q16a1121, Q16b1123, Q16c1126 are merged. Main has QueueRowDetailsService/controller details route, RedZoneAlertBanner and canonical claims/order. Do not republish these whole slices.
- Existing Q2 progress, ConsultationHold, DSP23/Q13/Q15 checks and approved TreatmentRoomPermissions exist. No new permission is needed for the workload read/UI.
- Clean Look shared baseline exists; individual current page-restyle PRs remain separate.

## Missing scoped dependencies and extraction order

| Slice | Main evidence / consumers | Exact existing inputs | Reconciliation / ordering |
|---|---|---|---|
| Q16e average wait | QueueWaitStatsService absent;1156 imports summary/composable | queue/q16e-standalone.patch +manifest (9files) | First blocking read-only dependency. Newfiles exact; reconcile only QueueController import/method, routes entry, Queue/Index import/poll/reset/render against mergedQ16c. No permission/schema. |
| Q16d rooms today | RoomsTodayService absent;1156 Doctors group consumes it | queue/q16d-standalone.patch +manifest (9files) | Stack after e for sharedIndex/controller/routes, or independently reconcile; preserve own-only refusal/nonawaited polling. No permission/schema. |
| AUTO1c warning | DoctorWarningProjection absent;1156 mapper/types retain warning | AUTO-1c-current-main.patch, on-combinedQueue overlay and shape followup | Batched HR current-shift/leave precedence + Queue row/type warning. Recover approved21→22 historical assertion only; do not import unrelated HR work. |
| Treatment call | Permission catalogue exists, held narrow call behavior/projection missing | treatment-call/TREATMENT-call-current-main.patch, ui-overlay, dialog-hitboxes-overlay | Service/route + canCallTreatment mapper/types + roomvisibility. Union Contention and its shard with currentmain, preserving others. No new grant/schema. |
| Treatment records | Models/service/policy/projection and approved record migration absent from main | treatment-record/TREATMENT-record-backend-current-main.patch; TREATMENT-RECORD-UI.patch | Separate backend then UI; exact approved a39e12e7 migration payload, strict clinical read policy and six real race scenarios. Preserve Q2 outcome projection consumed by workload. Do not copy unrelated Clinical Show changes. |
| TRI2 lifecycle/lane/shared host | EmergencyAlertService absent;1156 imports EmergencyLane/emergency metadata and filtered lists | tri2/publication/INPUTS-AND-SOURCE-HASHES.json and PUBLICATION-PLAN.md + frozen followups | After Treatment/Family handoff order. Exact approved dfff7e9 migration + resident emergency response grant only; bounded untaken-first alarm,25-page lane, full scoped exclusion, Clinical Hold bridge. Union races. Clinical/Registration/sharedlayouts by hunk, never whole archive files. No production activation. |
| Queue visual/workload | Existing draft overlays1145/1151/1153/1156 | queue-demo v4; workload backend/clientv1; workload integrationv2 | Replay only after required imports/types/row contracts exist. Preserve branch/clinical boundaries and recorded faithful UI assertion replacements. Full local/CI/browser on exact reconstructed candidate. |

External sequence/dependency coordination: Registration restyle1132/1146, emergency contacts1147 and Family1148 belong to Patients. Family is a handoff-order prerequisite for TRI2 and shares Clinical Show, not permission to bundle Family code into Queue. Other teams' inventory/billing/dispensary changes stay excluded. Sharedphpunit must retain STK46 and other existing races.

Q16f Calls today is an independent parallel release candidate, not a blocker for1156. Its9-file frozen q16f-standalone.patch touches TV Settings/controller plus its own stats/components/tests and does not touch QueueIndex or Clinical. This is existing reviewed work, not a new TV makeover. It can be extracted separately after the blocking read dependency is concrete.

## Next minimal blocking PR

Q16e average wait on isolated release/q16e-average-wait at currentmain. Original patch checks fail only on three shared files (QueueController, Queue/Index.vue, routes/web.php) because Q16c is now merged; six newfiles can apply unchanged. Reconcile those three insertion points and preserve exact service/component/composable/tests. Validate scopedPG, frontend behavior, static and required frontend gates, get independent review, then publish a main-based draft. No application policy, grants, migration or old assertion change is needed. No merge is authorized here.

## Q16e main-based release — 26 November 2026

Draft PR https://github.com/putrijayamediateam/KPOne/pull/1157, branch `release/q16e-average-wait`, commit `39db40991b7f5d2cb749f37ac8188898cf675cef`, base main `e5e72f69b79e68cbb57f72d2a7ad974f19aaa048`. Independent nine-file extraction, no archive ancestry or dependency; three shared files are insertion-only. Exact precommit artifact `queue-release-path/Q16E-CURRENT-MAIN-v1.patch` SHA256 `d8c36bd44e79b581eb19ecba9fb08501ceaf4e330d4c520f83805ab6df5e53d1`. SQL scalar aggregate preserves original scoped average/count semantics.

Validation: backend 3/15, all frontend 2207/2207, scoped PHPStan/lint/format, types/build, whitespace and reverse patch checks passed. Actual preview 3073 eight states own/branch × 390/1440 × light/dark matched API counts/date with no overflow or JavaScript errors; two synthetic calls correctly render insufficient data. Numeric mean cases remain automated coverage. Independent source review clear, frontend4 independently passed. Evidence in `/workspace/kpone-validation/queue-release-path/`. No merge; CI pending at publication. Next minimal dependency extraction is Q16d Rooms today, preserving Q16e hook union and current main Q16c.

## Q16d main-based release — final checkpoint

Draft PR https://github.com/putrijayamediateam/KPOne/pull/1161, branch `release/q16d-rooms-today`, exact commit `8c9cc5f8a282cfb563a91e828096f7ac21b1821f`, base main `83c5999bb6658c9009c81bc70cb444b8a8e0a9c4`. Clean tree. Fourteen-file patch SHA256 `be50360d9716d049f43ffd27b3ed41eca3f04768f1139465fea7a85417ae3973`, manifest and independent review in `/workspace/kpone-validation/queue-release-path/`. No archive ancestry/dependency.

Final current-main checks: PostgreSQL18/246, allfrontend2210/2210, scopedstatic0/lint/format, fulltypes/build, reversepatch/whitespace PASS. Browser10checks/8captures own/branch390/1440lightdark, actual27eligible doctors25+2pages, keyboardfocus+Previous+nonoverlap, nooverflow/JSerror. Staff independent review CLEAR; frontend7independently passed. Pagination/batch APIs are faithful performance adaptations; old single APIs and all existing assertions unchanged. No grant/schema/patient-state change. CI pending at publication.

Q16e1157 CI update: static/backend1/backend3 passed, backend2 failed only TrialDataCommandTest phase-five fixture419; root owns diagnosis. Evidence q16e-ci-failure-summary.txt. Next dependency AUTO1c recovery started isolated `/workspace/KPOne-auto1c-release` on83c5999; original18-file patch+shape followup, QueueDirectory import/context needs reconciliation. Will stack on Q16d batch-room API to eliminate repeated room lookups while preserving approved warnings. No merge/productionactivation.

## AUTO1c recovered release — final checkpoint

Draft https://github.com/putrijayamediateam/KPOne/pull/1164, branch `release/auto1c-doctor-warning`, head `b6b63512b3af9f02eec631ed0ecf606bfa970ad6`, explicit base `release/q16d-rooms-today` (#1161@8c9cc5f8). Eighteen-file exact stacked artifact `/workspace/kpone-validation/queue-release-path/AUTO1C-ON-Q16D-v2.patch` SHA256 `d9c9e6d8a1524dc465082331fcc3b5c1e76615060be611778c5a8384f63c3046`, manifestv2; v1 preserved. No archive ancestor. Recovered approved temporal warnings + complete-array follow-up; corrected alternative-without-room mismatch against approved Q2 and batched room/busy reads. Existing recommendation/assignment APIs unchanged. Only old assertion replacement remains owner-approved21→22 GET budget.

Final15/172 PostgreSQL, allfrontend2214/2214, static0/lint/format/types/build PASS.20browserchecks16captures Queue/Registration own/branch390/1440lightdark; no unauthorized warnings or Reassign, nooverflow/JSerror, allvisit/queue assignment/status/version hashes unchanged. Final local link target fix independently closed at36px desktop/44px tablet/mobile. Exactv2 independent source+visual review CLEAR in AUTO1C-BATCH-INDEPENDENT-REVIEW.md. No merge/production. CI pending at publication.

Automatic follow-on: next blocking Treatment-call extraction isolated `/workspace/KPOne-treatment-call-release`, branch `release/treatment-call`, starts actualmain83c5999. Frozenbackendpatch applies clean in preflight. Plan in TREATMENT-CALL-NEXT-SLICE.md. No migration/grant/oldassertion change expected; preserve phpunit race union.

## Treatment-call release — final checkpoint

Draft https://github.com/putrijayamediateam/KPOne/pull/1169, branch `release/treatment-call`, head `5025cf3e4b0e36aa455f1323affdc271926f2963`, actual main base `eec5d5444bd14b0358e0383c1ea5d427e4d33f11`. Independent main-based extraction; archive1142 is not an ancestor. The13-file patch `TREATMENT-CALL-CURRENT-MAIN-v1.patch` SHA256 `4411a15df92145e7f5f03690391747798c9731fc3a5ef4143f313677f210f9e2` and manifest are in queue-release-path. No grants, migration or existing assertion changes. Dedicated capability enables only the existing approved narrow held-treatment announcement; Call In/Recall behavior stays unchanged.

Final gates: ordinary15 tests/230 assertions, registered observed Resume/Call race1/14, all2,207 frontend tests, types/build/static/lint/format and reverse-patch/whitespace passed. Independent exact13-file source+visual review CLEAR in TREATMENT-CALL-INDEPENDENT-RELEASE-REVIEW.md. Actual MA proof9checks/6captures390/768/1440lightdark: controls44/44/36, Cancel no request, one real synthetic announcement, correct actor/room. Same doctor assignment, visit/encounter versions and hold; still Registered/Serving. No merge/production. CI pending at publication.

Automatic next prerequisite: approved Treatment-record backend, separate branch stacked on1169, recovering frozen14-file backend patch with exact owner-approved a39e12e7 migration payload and preserving Contentions union. UI remains a subsequent separate slice; no new clinical policy/grant.

## Treatment-record backend published — 26 November 2026

Draft https://github.com/putrijayamediateam/KPOne/pull/1172, branch `release/treatment-record-backend`, head `7741ab448dff2453241258baa7c3d4e6aadf6b76`, explicit base `release/treatment-call` (#1169 at5025cf3e4). No archive merge. Frozen16-file artifact `TREATMENT-RECORD-ON-CALL-v1.patch` SHA256d4205fa7169d51b14daaee0f6c7a830ecc6193a990e75e382a6f6d36e2a50285; manifest in queue-release-path. Exact owner-approved migrationa39 unchanged. Independent final source review clear in TREATMENT-RECORD-INDEPENDENT-RELEASE-REVIEW.md.

Current-parent gates ordinary39/410 (record/pagination17/136, budgets/integrity3/51,Q2existing19/223), actual registered six-scenario race1/44, PHPStan0,Pint,diff/reverseapply pass. Frontend unchanged from1169; no duplicate frontend gates. CI pending. New pagination adapts unbounded reads to25perpage with full-set evidence/status and hasStartedStep. Existing assertions unchanged; all test/migration execution isolatedsynthetic. Later UI must implement TREATMENT-RECORD-PAGINATION-CONTRACT.md before deployment. Next: scoped22-file UI recovery, Clinical/Show existing-anchor reconciliation without Family/archive import, explicit pages and uncertainty/draft guards. No production or merge action.

### Exact-head CI checkpoint supplied by coordinator (26 Nov)

- #1161 Q16d and #1164 AUTO1c: all six checks SUCCESS at their published exact heads. They replace the corresponding archive-only recovery dependencies; they do **not** replace Queue workload/visual client work.
- #1157 Q16e remains its current scoped recovery, with the previously diagnosed TrialData fixture failure. Root owns #1163 fixture resolution; no cherry-pick or unrelated repair in Treatment UI.
- #1145 visual, #1151 workload backend, #1153 workload client and #1156 integration remain preserved original stack artifacts. #1151/#1153 historical failures are not claimed fixed or superseded by #1161/#1164/#1169/#1172. Their later main-based scoped recovery remains pending the dependency chain; retain all original branches/patches.
- #1169 replaces the archive-only Treatment-call slice; #1172 replaces the archive-only Treatment-record backend slice plus bounded read adaptation. Neither supersedes the Treatment UI, TRI2, Family or full Queue makeover. Upcoming Treatment UI recovery stays scoped and does not attempt historical stack CI repairs.

## Treatment-record UI published — 26 November 2026

Draft https://github.com/putrijayamediateam/KPOne/pull/1180, branch `release/treatment-record-ui`, head `fcfe5f30287cefdd613873aab7c64650ecfed1e2`, explicit base #1172 at7741ab448. Frozen25-file patch `TREATMENT-RECORD-UI-ON-BACKEND-v1.patch` SHA05eee5f0a464ccc27cae11982c488b255d3f5446c6ed70c715d917b3a7523237; manifest/review in queue-release-path. Independent final source/visual review CLEAR with15 current focused tests independently rerun.

HTTP5/102, controllerPHPStan0, Pint/allchangedlint/format/whitespace/reversecheck pass. Fullfrontend2221/2221 passed; final small identical-props navigation fix then passed7actualfocusedregressions+finaltypes/build. New final focused case makes current discovery2222; no claim a second full2222 run. Browser22completedchecks plus9DBinvariants pass across390/768/1440lightdark,paging,draftCancel,errorfocus,committedresponseloss/retry,correction/void. Explicit failed harness timing/shortened-label attempts are preserved and not counted; finalfollowupactualUI waits passed. Evidence treatment-ui-browser-summary.json and treatment-ui-final-state-proof.json. CI pending. Preview3078 ownDBkpone_treatment_record_ui_release_preview_test stays synthetic.

Sole existing assertion update is previously owner-approved exact Invoice props signature; financial/access assertions unchanged. No production/merge/archive action. Next dependency: TRI2 scoped extraction; Family scoped draft1148 branchfeature/patient-family-links-publication head d3f814f1c595ddb14d86037e1bd93c902a7ddbc2 reported all6green by Patients, not merged at their last checkpoint. Preserve explicit policy/Clinical overlaps; do not import archive wholesale.

### TRI2 lifecycle → lane regression dependency

Lifecycle-only recovery replaces the NEW alarm test's dependency on absent EmergencyLaneProjection with an explicit26openWaitingepisodes count, retaining bounded25alerts/untaken priority. The exact original lane assertion is preserved in `TRI2-LANE-ALARM-ASSERTION-FOLLOWUP.patch`, SHA256 `4f1babfbd2bfd91e9806d1c1efc15ed76a3b84554b6637860bcd14ec547566f8`. Apply this follow-up once the next lane projection slice is recovered and execute the restored assertion before claiming lane integration clear. Original reviewed90eae5 alarm artifact remains immutable. No old main assertion is deleted or weakened.

## TRI2 lifecycle published — 26 November 2026

Draft https://github.com/putrijayamediateam/KPOne/pull/1182, branch `release/tri2-lifecycle`, head `8e640c0b43188e550d1faa20d67d98eea6884070`, base #1180 fcfe5f302. Family1148 explicit release prerequisite.25-file frozen artifact TRI2-LIFECYCLE-ON-TREATMENT-v1.patch SHA7d8a55232350a0b3025645baf74dae047d06ed0921da7fc55cd89488525081b8; manifest blobs match committed source. Exactdfff migration unchanged; no productionactivation.

Final focused55/386, actualPGContention15/255 (14races86 +historicalrollback1/169), PHPStan0,Pint,diff/reverseapplyPASS. Independent Billing25-file source/parityreviewCLEAR, finalgateattributed. Frontend unchanged from1180; compatibleTakeclient/sharedhost/lane stillrequired beforedeployment. Original laneassertion preserved followup4f1babfb; nextlaneextractionmustrestore/run it, no laneintegrationclaimyet. CI pending. No merge/archive1142.

## TRI2 lane backend published — 26 November 2026

Draft https://github.com/putrijayamediateam/KPOne/pull/1185, `release/tri2-lane` head `ab044ba15e67b024cab745f6267819785ea757d3`, base1182 at8e640c0b4.14-file frozen patch TRI2-LANE-ON-LIFECYCLE-v1.patch SHAf2dcfe03c9bf680a6b34fc74a7d064cd823e4a43170380d61e84c9d487a5cc5a; manifest and independent Billing reviewCLEAR.31/475 focusedPASS, PHPStan0,Pint,diff/reversecheckPASS. Restored exactdeferredlaneassertion nowexecutedPASS;1vs26 constantquerycount extends fullQueue/Registration hydration. Existingapprovedquery/payloadadaptations retained; actualGET23 without separateAUTO dependency. Newprivacytest acceptsabsent/null optionalAUTO hint, strictremainingmetadata.

No laneUI/presence/sharedhost yet, so backendnotdeployablealone; Family1148 explicitreleaseprerequisite. CombinedQueueSummaryTest adaptation deferredwithimmutableoriginalaggregatepatch becauseQ16d/e absent inthisstack. Frontend unchanged. No migration/grant/productionactivation/merge/archive1142. CI pending. Next minimal existingdependency: cache-only doctor-presence service before sharedalertUI; inspect batching before readiness.

## Presence recovery published

Draft #1188 (`release/tri2-presence`, head0475a77df) stacks on lane #1185. Six-file frozen patch86b6e8d7 preserves count-only/unknown cache semantics and batches branch authority;20/135 focused tests, static0/Pint, independent Billing review clear. Shared alert UI/Hold bridge is next, then lane UI. No archive merge, production activation or full workflow readiness claim.

## Shared emergency UI recovery published

Draft #1193 (`release/tri2-shared-ui`) head a83e9e8f3e0cd35b027a55f8fe5bb3f10687699a stacks on #1188. Frozen18-file patch5eb0103b, independent Staff review clear, full frontend2247/types/build/lint pass. Synthetic browser Save/Cancel/retry/Discard/Seen/mute/recorder proof and stage-specific9+7 DB invariants pass. Lane UI is next; no full Queue parity or production activation claim.

CI blocker #1180 at fcfe5f30 was independently diagnosed as randomized recorder 'Lockman' colliding with the unchanged JSON forbidden-token assertion. Separate main-based draft #1194 (`fix/triage-view-fixture-name`) head92aa0c8eda849fe0777bab6b0209a29011140e57 normal-merges mainf8dd19cb8; one fixture-only file, all assertions unchanged, final11/103 PASS and Billing review clear. This is not the TrialData clock issue. Serial main refresh should incorporate this fix normally once merged; do not pretend the original failed head is green.

## Emergency lane UI published

Draft https://github.com/putrijayamediateam/KPOne/pull/1199 (`release/tri2-lane-ui`) head229522c8d, explicit base1193. Distinct first commit94de6b223 aligns only readiness canSet with the existing red refusal; second commit recovers lane UI. Frozen17-file patch1ee88356, Staff final visual/source review and Billing capability review clear. Backend21/424/static0, focused frontend73/full2257/types/build/lint pass. Browser18 lane checks plus explicit Call/Cancel and5 DB invariants pass. Settled390dark captures separately position heading/card and pager below shared host, recording scroll coordinates; an additional synthetic service-created visit restores26 after the explicit Call reduced count to25. No data reset or production activation.

Fixture PR1194 is now merged to main as9764037af1bfcb24ebad3102a124ff3f6cd7acd2 after six exact-head checks; root owns merge evidence. Stacked Treatment/TRI2 ancestors retain original heads until coordinated normal main refresh. Next independent existing work: Q16f Calls today recovery on fresh main9764037af; original9-file patch applies cleanly. Add bounded room pagination with complete totals, no TV-feed changes. Blocking Queue visual/workload convergence still requires scoped Q16d/e/AUTO and Family release dependencies; archive1142 remains forbidden as merge source.

## Q16f Calls today recovered and published

Draft https://github.com/putrijayamediateam/KPOne/pull/1204, branch release/q16f-calls-today, head4a97c54bc, base currentmain9764037af. Twelve-file frozen Q16F-CURRENT-MAIN-v1.patch SHAbd1c2fe571362f686295554827fb0627cf1767c3fa77dedb5e68533f003642ab; manifest and both independent reviews clear. One bounded SQL statement preserves concurrent snapshot consistency, full totals/recalls and null-room merge across25-label pages. No TV-feed/permission/workflow changes or archive dependency.

Backend9/87, fullfrontend2217/2217, final types/build/static0/Pint/lint/format pass. Browser10checks and read-only26calls/25recalls/26historical-labels/1physical-room proof pass. Actual service fixture renames a room between calls; first physical-room-cap attempt fully rolled back and is documented. CO8 exact URL occurrence adds only a duplicate existing GET URL, independently reviewed under delegated authority; other mutation/privacy assertions unchanged. Preview3154/DBkpone_q16f_release_preview_test remains synthetic. CI pending; no merge.

Next authorized work is QUEUE-CONVERGENCE-PLAN.md: explicit scoped1199+1164(includes1161)+1157+currentmain disposable foundation, Family1148 external prerequisite, followed by bounded visualv4 and workload recovery. This multi-parent foundation must never be presented as a visual-only PR; no archive1142 ancestry. Staff plan review clear, Billing pending at this checkpoint.

## Queue validation foundation and visual recovery

Local foundation `integration/queue-reviewed-foundation` at bf6d6e760578fe4acea6f3edd6f46e29f7709928 normally combines exact scoped TRI2/Treatment1199, Q16d/AUTO1164, Q16e1157, Family1148 and current main375f56. No archive ancestry or push. Shared18-file manifest/reviews clear. Broader backend65/672 before latest main; post-main7/66 plus fullfrontend2283/types/build, suite integrity2/15. Actual combined browser10states verifies singlehost, own/branch Queue, both Clinical cards and authorized linked Waiting relative.

Local visual `recovery/queue-workspace-v4` at 721f682772279902ef2d4a3a1b40fddb8e93aa46, seven-file frozen patch a3a5e1a6a0ee47b98e28406ef2e7b39340cf03975404c327f8bab54cec493d20. Staff finalboundedclear; fullfrontend2290/types/build/lint,22independentfocused, browser15states with real shortphone hit-tests below persistenthost. Latest lane focus/pagination/error and Rooms paging preserved. Only script changes are frame import, approved initialWaiting and workspace section/reset; commands/filters/poll bodies unchanged. No new workload counts yet. Both branches are explicit validation-dependent local checkpoints, not main-ready visual-only PRs. Next: scoped workload read/client recovery, then UI on these verified contracts; no new clinical policy.

### Published convergence validation branches
#1213 integration/queue-reviewed-foundation@bf6d6e760 → #1214 recovery/queue-workspace-v4@721f68277. These are remote preservation/review drafts, not substitutes for their scoped parent release chain, and contain no archive1142 ancestry. Workload read/client438cb661 is the next separate candidate; no tab UI or full-demo parity is claimed.
#1215 recovery/queue-workload-read@630ea3d2627f5610392c79c2eab835411b71a1c4 follows #1214; exact12file bounded endpoint/client policy recovery438cb661. UI integration is next isolated branch recovery/queue-workload-ui, not included in1215.
#1220 recovery/queue-workload-ui@efe71415580ac71b15507522dd62308981c4c884 follows #1215.10file8ed6fbc5 exact reviewed UI integration;2298frontend/types/build and real bounded browser proof pass. Exact Reset restored. Still a preservation/validation stack, not permission to merge foundation/archive.
#1223 recovery/queue-context@cc73b15203f774c25a48f9a6a5af2eed8b12b015 follows #1220.3filef10fe358 room/source/next-context/TVcapabilitylink,2302frontend gates+boundedactualbrowser+peer clear. No backend/command/permission changes. Underlying foundation remains validation-only.

- #1229 own Waiting/Seeing presentation `recovery/queue-waiting-seeing-columns` `18c9edc46166b7a15f4bcd0fd0b75dcf9e19e550` → #1223.10files,8d31a264,2308frontend/final54+types/build+independentreview+8browserstates. No merge; not archive replacement. Next own Held plan separate.

- #1233 own Held columns `d99e5c4ae61070e723ea066d60fcc218a9d3963f` → #1229 updated efa4eb000.5filesc885b9a9,2311frontend+types/build+27focused+independentreview+4browserstates. No merge. #1229 test-only CI followup8a33b968 passes1/21; freshremote gate pending.

- #1234 Treatment/Result `b0ed07f2771533b743a7e30e8226cce5a7625bcb` → #1233.6files58b6ad71;2314fullfrontend/types/build/30focused/12independent+4browserstates. ParentremoteCI remains independent; no merge.

- #1238 `recovery/queue-done-checkout-time` @26b838d3390fda27a5b151b2186f5d469ee2a562 → #1234. Seven-file23ed6cfa completed-only checkout timestamp + own Done branch-time column. Full2316/backend11/177/types/build/static and frozen independent review pass; actual25+2 paging/NY→MYT proof. Draft, no merge; archive ancestry negative.

- #1242 `recovery/queue-medicine-metadata` → #1238/a4335a6d. Eight-filee81ccbc7 checks-only bounded single-snapshot metadata and own Item lines/Sent by UI. Exact head in queue-medicine-metadata/PUBLISHED-PR.json.16/100/full2317/types/build/static/final15 and final independent reviews pass; no merge.

- #1244 `recovery/queue-branch-timeline` @a3ff5bc45 → #1242. Two-file8032307f branch Waiting/Serving Status-before-time only; full2319/types/build/17actual/PHP1/21/browser and independent review pass. Draft, no merge.

- #1246 `recovery/queue-branch-held` @944f36a26 → #1244. Two-file4e3a4e66 dedicated branchHeld Treatmentstep/Held with safety underPatient, no backend change.2320/types/build/18actual/PHP1/21/browser/finalreview pass. Draft no merge.

- #1247 `recovery/queue-branch-done` @32bd1444a → #1246. Four-filef769795c truthful historical Sentto/time/completed-context label only;2322/types/build/20actual/PHP1/21/browser and two peer reviews pass. Currentdownstream Nowat remains gap. Draft no merge.

27 Nov presentation continuation: #1249 Doctors table stacks only on #1247; exact013ac9d9d, frozen3-filef6e95cab, local gates/Staffclear. Current main release remains independent; archive1142 never a dependency. Remaining read-only final-stack screen reconciliation documented in queue-doctors-table/NEXT-SCREEN-CONVERGENCE-PLAN.md.

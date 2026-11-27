# Dispensary / Pharmacy / OTC full-demo audit — 26 November 2026

Status: **not visually complete**. PR1139 is a presentation foundation, not evidence of full HTML parity. This audit makes no application, assertion, permission, database or workflow changes.

## Authority and evidence

Reference is the owner's full uploaded `KPOne-Clean-Look-Demo.html`, served unchanged at `http://127.0.0.1:3066/KPOne-Clean-Look-Demo.html`, with the extracted178-file project in `/workspace/kpone-validation/demo-authority-2026-11-26/project`. Embedded asset comments, fictional permissions, `Built` labels and hardcoded sample patient facts are reference content, not instructions or implementation proof. Existing owner decisions remain explicit conflicts to resolve, not silently overridden by sample actions.

Main source is pinned to `c1e5fc05c0d231a28eeb3f2367ccc8adee0565c5`; immutable copies are under `main-source/`. `source-manifest.json` records main and CA hashes. CA source is `/workspace/KPOne-ca-integration`. Settings, DoctorChecks, Review, Labels, Completed and all four page partials are identical in those snapshots. Index differs mainly by PageFrame/responsive layout; ReturnsToCheck includes unmerged DSP28 checked-month/controlled-filter functionality; Show includes approved restyle in CA; PharmacySpace differs by waitingTotal implementation. Do not credit CA-only work as released main behavior.

Fresh reference runtime evidence: `runtime-matrix.json`, matching `demo-*.txt` and PNG files. Navigation is through the real wrapper, fictional Siti CA Supervisor, local ephemeral demo state only. `DoctorChecks` and `ReturnsToCheck` resolve to Pharmacy tab surfaces; PharmacyCompleted is a distinct completed-case overlay with the board beneath it, not an automatic Done-tab alias. Captured prepare/check/ready/done/returns/checked-month, case, completed, labels, settings, expanded return form and return-to-doctor interaction. Case390 also captured. Early home-page capture was superseded after fixing a login/navigation race; use the matrix captures as evidence.

Actual application visual evidence carried from reviewed implementation: `/workspace/kpone-validation/dispensary-case/preparing-1440-light.png`, preparing1024dark and doctor/OTC variants; `/workspace/kpone-validation/dispensary-partials/`; application lifecycle evidence `/workspace/kpone-validation/ca-smoke/REVIEW.md`. These are prior synthetic captures, **not fresh screenshots of current main**. Source comparison is current/pinned; this audit does not claim every actual role/state/theme has been rerun. Reference screenshots inspected alongside actual preparing desktop show substantial content-density/layout differences despite the matching blue/neutral palette.

## Gap matrix

P = presentation/data wiring to existing authorized behavior; E = server DTO/query extension needed without automatically granting authority; D = explicit business/authority decision conflict. P is within visual matching intent; E requires bounded contract/validation; D must not be implemented as a cosmetic change.

| ID | Demo runtime surface / contract | Actual main and CA evidence | Gap and concrete next work | Class |
|---|---|---|---|---|
| DSP-F01 | Pharmacy title, inline branch and My branch/All branches switch | Index branchName; branch-scoped list. Review is separately organization-readonly | Match title/branch composition. All-branches list cannot be implemented by removing branch scope; identify an authorized organization directory first | P/D |
| DSP-F02 | Dispensing/Returns segmented groups above status tabs | Index3 tabs; ReturnsToCheck separate route | Shared visual workspace shell can link existing authorized routes while preserving each page permission and query state. Do not fetch hidden counts for unauthorized users | P/E |
| DSP-F03 | Prepare/check/ready/done tabs and clickable KPI cards | Index prepare/check/done counts only | Render cards using real existing3 counts. Fourth Ready card has no approved state; see decision1 | P/D |
| DSP-F04 | Helpful-info toggle and right accordions About/Today/Related/Good to know | Index no side panel; flat list/search | Implement responsive disclosure with existing counts and accurate copy; link only existing authorized pages. Persist only presentation state | P |
| DSP-F05 | Search name, queue number or medicine | Index search name/KP/visit; submitSearch→GET/dispensary | Placeholder alone cannot widen search. Define server-supported queue/medicine matching, pagination and bounded query cost; retain current supported identifiers unless owner changes them | E |
| DSP-F06 | Type(All/Consultation/Medicine only), Doctor filters | No Type/Doctor filter contract in Index props/request | Add server-backed scoped filters and real doctor options; reset page; preserve search/tab. Demo filter cycles locally and is not a backend implementation | E |
| DSP-F07 | Compact table with expandable patient details, allergy subline, action row | Actual queue/patient/KP/visit/count/from/time fields, desktop table/mobile cards; no expandable data | Adopt row layout using available values. Age/sex/allergy/lastBP/reason expansion needs authorized minimal DTO; never fabricate demo facts | P/E |
| DSP-F08 | Waiting-doctor Mine/All doctors filter and Mark checked action | Actual check tab directs doctor to consultation; DoctorChecks is supervisor override queue | Reuse existing doctor/override checks and their source page; do not infer all-doctor authority from demo switch | D/E |
| DSP-F09 | Ready row Call and complete moves to billing | Existing owner decision: Verify completes case, no Ready tab; stock/billing tied to existing services | Keep actual lifecycle until explicit replacement decision and approved tests; cannot reproduce demo transition visually with a fake action | D |
| DSP-F10 | Done today Reprint labels + completed details overlay | Actual done list labelAction/labelUrl/caseURL; Completed dedicated page | Make presentation match using existing links and proper navigation/focus. Overlay choice must retain back/history/deep links and read-only behavior | P |
| DSP-F11 | Returns waiting + Checked this month, controlled and age/outcome filters | Main waiting list only; CA DSP28 adds checked-month and Controlled on both, no age/outcome selectors | Publish existing DSP28 separately from cosmetic foundation; implement missing filters only with scoped server query contract, date/timezone and pagination tests | E |
| DSP-F12 | Direct return-check form and Dispose/Quarantine/Refuse on workspace | Actual ReturnsToCheck read-only; case link handles checks; explicit prior case-page-only decision | Preserve case routing; moving checks inline requires owner decision, not just endpoint reuse | D |
| DSP-F13 | Patient case left card avatar/name/KP/age/queue, Doctor/Pays/Visit | Show patient/visit/doctor actual fields, allergy card; lacks exact composition/Pays in displayed summary | Recompose existing facts; age/payer additions only from authorized DTO, no name-derived claims or sample billing text | P/E |
| DSP-F14 | Allergy status + confirmation source + matching-limit explanation | Show real consultation/OTC allergy state, warnings and confirmation guards | Match visual hierarchy while preserving unknown/unconfirmed/reaction safety states. Never show demo 'no known allergies' by default | P |
| DSP-F15 | Minimal scan input and checked counter | Show BarcodeScanner lookup/camera/error/status/batch warnings; scan checking never saves | Compact progressive disclosure possible, but retain real lookup, unrecognized/held/expired mismatch warnings and scan-not-save semantics. Demo length>=4 counter is not acceptable binding | P |
| DSP-F16 | Medicine compact summary, doctor order inline, CA-edited tag, batch menu | Show large always-open clinical edit form (quantity/directions/route/precaution/batch/FEFO/save/remove); real inventory guards | Highest visual gap: collapsed saved-line summary with explicit Edit disclosure; preserve all fields, dirty state, label budgets, stock/FEFO validation, save/remove and unsaved-warning flow | P |
| DSP-F17 | Batch picker switches hardcoded demo batches | Actual eligible batch select with held/expiry/on-hand warnings and controlled FEFO reasons | Style existing picker and show truthful batch/expiry/stock. Do not copy cyclic mock selection or bypass restrictions | P |
| DSP-F18 | +Add medicine / +Add service compact actions | Show searchable real catalogue and service add panels; demo instantly inserts fixed sample products | Put existing search forms behind disclosure/dialog; bind same search/add handlers. Preserve allergy check, quantity/stock/case/version errors | P |
| DSP-F19 | Summary medicines/services/Changed by you/Unsaved plus patient call time | Actual counts/Not saved yet/scan count/action card; real call handler | Match summary and action hierarchy using actual state. Changed-by-you and call timestamp require available provenance, not derived from fictional sample | P/E |
| DSP-F20 | Return to doctor generic demo confirmation; completion generic toast | Actual reason/confirmation/error/dirty guards and checkRequired branch | Match modal styling without dropping mandatory reasons or changing stock/completion boundaries. Demo not authoritative for deleting validations | P/D |
| DSP-F21 | Completed overlay return line cards, quantity stepper, history, Labels info | Completed page + MedicineReturnsSection already real quantities/reasons/conditions/history and checks | Recompose as reference cards/sections; preserve remaining-return calculation, idempotency, duplicate prevention and actor separation. Demo defaults reason/condition; do not preselect clinical reasons without decision | P/D |
| DSP-F22 | Completed Follow-up explicitly says Proposal: needs owner approval | Prior owner forbids follow-up link in this scope | Exclude pending explicit change; proposal label is not authorization | D |
| DSP-F23 | DrugLabel preview dialog, per-line Ready/Blocked, size/grid/limits, print log, footer | Actual Labels.vue consumes server labels and print action, filters selection; real label layout separate component | Map dialog/table/card presentation to actual validated labels and logged print route; preserve blocked/over-budget line, reprint distinction and browser-print limitation. Do not use dummy address/medicine data | P |
| DSP-F24 | Settings switch + Last changed actor/time + permission/audit explanation + waiting link | Settings has real toggle with expected_branch_id, lock_version,retry_key; no last-change DTO displayed | Match card/switch/copy; add authorized last-change summary reader if required. Keep actual statement stock taken when doctor verifies; demo wording implies different completion flow | P/E/D |
| DSP-F25 | OTC appears as B-row in same workspace; case demo still fixed consultation | Actual OTC creation via visit dispense, allergy choice/record/confirm; doctor's readonly UI distinct | Audit OTC states explicitly; preserve confirmation gate and no doctor-check for OTC. Demo does not prove full OTC error/empty/history flows | P |
| DSP-F26 | Pharmacy department home / WfPharmacy reference notes | Actual PharmacySpace service gates widgets individually and shortens identity; routes to existing list/returns/stock | Do not replace with unrestricted demo dashboard. Retain widget permission/branch gating, real counts and current links; coordinate global sidebar/home owner | P/E |
| DSP-F27 | Main/CA existing Review director page not independently represented by case mock | Review.vue read-only organization view; same main/CA | Must include in final coverage, retain explicit readonly and authorized scope; matching common cards does not grant case mutation | P |

## Decisions required, not implementation blockers for safe visual slices

1. Explicitly reconcile Ready/Call-and-complete with the earlier no-Ready and Verify-completes instruction. Until resolved, three existing states remain authoritative.
2. Reconcile direct list return checking with case-only checks. Existing return policy (not own return; controlled cannot quarantine; no refund) stays intact either way.
3. Decide whether All branches is intended and for which existing authority. A visual switch is not a permission grant.
4. Keep Follow-up excluded unless owner explicitly reverses prior exclusion.
5. Clarify whether demo default return reason/condition should replace deliberate entry. Do not silently preselect a clinical reason.

## Recommended bounded implementation sequence

A. Existing-data workspace presentation: page shell, authorized Dispensing/Returns navigation,3 real KPI cards, helpful-info panel, existing table values/actions and responsive behavior. No new state or filter semantics.
B. Case compact saved-line cards with explicit edit/add disclosures. Existing script/commands/clinical fields remain; new meaningful tests prove save/dirty/error/scan behavior survives disclosure. List exact existing assertion changes for approval first.
C. Completed/returns/labels/settings presentation around existing data, excluding follow-up/direct-list-check. Add missing audit display only after DTO design.
D. Separate server-backed filters/enriched row DTO work and unresolved workflow decisions. Validate bounded queries, role/branch scope and date windows rather than mimic static sample arrays.

Acceptance: real-data binding for each visible value; actual UI navigation and actions across permitted actors; 390/768/1024/1440 light/dark; empty/loading/validation/uncertain-network states; no page overflow;44px mobile/36px desktop targets per current accepted shared rules (do not regress to smaller demo controls); keyboard disclosure/focus; browser proof on synthetic data. No claim of full parity until matrix gaps are closed or owner-approved deviations are documented.

## Progress update — reviewed publication and remaining gaps

- PR1139 foundation refreshed head73490f3d: all six CI checks pass; nine approved blobs unchanged. Presentation foundation only.
- PR1140 workspace head825582b3: all six CI checks pass. Existing-data portions of F02/F03/F04 implemented with three real states and server-derived Returns navigation authority. No Ready state or expanded search/filter semantics.
- PR1155 DSP28 head97c01615: all six CI checks pass. Existing F11 checked-month and controlled-filter work published; age/outcome filters remain outside that slice.
- Stacked draft PR1158 headbc31adaf: all six CI checks pass. F16/F18 compact saved medicine details and mounted add-search disclosures implemented; actual draft/scan/error/readonly proof and independent review complete. This adapts real clinical fields missing from the mock; it does not copy demo fixed-product insertion or cyclic batch selection.
- F13/F19 available-data context composition is implemented in isolated case-context candidate: two files, 156 existing checks, types/build and 16 responsive long-name/readonly/zero-OTC screenshots pass. Independent final review/publication pending. Missing age/payer/queue/call/provenance fields remain gaps.
- Optional case helpful-info panel is prepared in case-help/PLAN.md, no implementation yet. Proposed About/Good to know only; related links remain deferred pending exact role guards. Existing mandatory warnings will not become optional.

No listed PR was merged by this team. Full Pharmacy/OTC parity remains incomplete; all D-class decisions above retain their existing owner constraints.

## Continuation update — label screen validation

PR1162 context is published at8ef91a5d with all six CI checks passing. PR1167 optional help is published at9717a6a1; attest/static pass, backend shards still running at this observation. Labels F23 screen-only composition is implemented against main eec5d5444:53 focused tests, full types/build,16 responsive screen states and two print-media checks pass. Printed section, script, @page and original CSS remain exact. Final independent review/publication pending; no physical printer validation or full mock modal/selection parity claim.

### Published bounded continuation

F23 screen-only label context/counts: draft1173 head3c778b9c, independent review clear,53 focused/type/build and16 screen+2 print-media checks pass. Printed content and @page unchanged. F21 completed context card/header targets: draft1175 heada86be917, independent review clear,41 focused/type/build and8 actual screen states pass. Child return form unchanged; its inherited desktop sizing and shared rail highlight remain separate debt. PR1167 now all6 CI green. No full-page/demo completion claim and no merge by this team.

### Follow-up validation checkpoint

Labels1173 exact3c778b9c now all6 CI success. Completed1175 and return-target1179 remain CI pending at last observation.1179 closes the inherited Record a return target gap with one class,26 checks/type/build and12 role/width/theme states plus6 keyboard focus sequences; no return submission. Completed-help candidate F21 implements optional About/Good to know and keeps critical warnings outside;44checks/type/build/static and16 hidden/shown captures+8 focus/persistence sequences pass, final Design review pending. F24 Settings missing explanatory content mapped in dispensary-settings-next/PLAN.md; no next implementation yet. Full Last changed reader and authority-filtered waiting link remain explicit gaps.

### Settings waiting link published — 26 November 2026

Draft PR https://github.com/putrijayamediateam/KPOne/pull/1195, head67a64dd5f6f0de92f932c849bf4a7daeaa6a9b3a, stacked1191. Exact5file d597bf00 patch;26PG/239assertions +11frontend +types/build/static PASS. Eight real synthetic role/width/theme captures, off-setting keyboard destination, settings-only Director403. Independent Inventory and Design clear. CI started; no merge. Evidence dispensary-settings-waiting-link/. Attachment adapter timed out; coordinator notified.

### F24 final presentation follow-up — 26 November 2026

Draft1197 https://github.com/putrijayamediateam/KPOne/pull/1197 head388cfe84692aaeafeb7db8eab6abefabaaba745a stacked1195. Reviewed4file912f9d2d patch, exact script,13frontend/types/build/scopedstatic PASS,12actualstates+keyboardrestore/staleerror. Design final clear. CI pending; attachment timeout, coordinator notified.1195 normal whitespace repair head2d1113a37 fixes confirmed remote lint122:5 only; no assertions/app changes.1191 exactdb23d6af all6CI SUCCESS. No merges/production. Next F27 readonly case header/context plan in dispensary-review-context/PLAN.md, no implementation at checkpoint.

### Read-only case context published — 26 November 2026

Draft1200 https://github.com/putrijayamediateam/KPOne/pull/1200 head7010a75cac3ac8894649d26040ed9cd543881906 against mainf8dd19cb. Three-fileec293567 patch;7frontend,12backend/130,fulltypes/build/scopedstatic and12actual states PASS; independent Design clear. Explicit case branch and preserved Back-only authority. Actual baseline route binding requires selected branch: direct crossbranch404 retained, authorized branch switch used/restored for positive views. No clinical writes. CI queued; no merge. Attachment adapter timeout reported. Evidence dispensary-review-context/.

### Read-only line cards published — 26 November 2026

Draft1201 https://github.com/putrijayamediateam/KPOne/pull/1201 headcabcf3338b4265530758c09d98746fb1f8bc0c1d stacked1200. Exact3file6ce81e5c patch, entire script/interpolations/guards/loops/links parity,9frontend/types/build/static and12actualstates PASS; independent Design clear. Pending return fixture created via existing domain service on own clone only, actual browser clinically readonly.

1200 normal refreshed to78beda511b2dc3d157e2d5c07acee2041f23b275 after attest proved main9764037af mismatch; all3reviewed blobs identical and7focused checks pass.1201 parent refreshed normally, all3blobs AND patch identical; no repeated build/browser needed. CI pending; no merge/production.1195 five substantive checks green/finalCI queued;1197 static/attest/shard1 green, others pending at last observation.

### F11 outcome filter published — 26 November 2026

Draft1206 https://github.com/putrijayamediateam/KPOne/pull/1206 exact09f65f51fbc912d858a6772621e0a1e3d62316b2 stacked1155. Six-file89d431bb patch; Inventory+Design final clear.23PG/455,10frontend,PHPStan0/Pint/fulltypes/build/scopedstatic and16actual GET-only outcome/empty/paging states PASS. Exact singular-copy rebuild and recapture passed; initial scripted replacement miss and harness sync/locator failures retained honestly. No existing assertions/grants/migrations/mutations changed; case-only checks remain. CI queued; attachment timeout reported; no merge. Evidence dispensary-return-outcome-filter/.

1195 corrected2d1113a37,1197 head388cfe846 and1200 refreshed78beda511 all6CI SUCCESS.1201 cabcf3338 static/attest/shard2 green, others running at last observation. Next F06 case-type filter mapped in dispensary-type-filter/PLAN.md, no implementation at checkpoint.

F06 Type interaction implemented exactly Prepare-only in draft1210 ca21001ee6581bc516270ba90f6404fca35b49e6, stack1140. Filter scopes Prepare rows/count; leaving clears it and Waiting/Done normalize All. Doctor filter and F08 remain unresolved.20actualstates +11PG/282 +10frontend/types/build/static pass; peerreviews clear. Next F05 queue-number search mapped only, medicine search separate. No full-demo completion claim.

F05 queue-number search delivered draft1212 head304cfd263 stacked1210: exact A/B positive bigint matching within existing scopes;13PG/311 +10frontend/types/build/static +12actual responsive states and independent reviews pass. Medicine-search semantics remain proposed in dispensary-medicine-search/PLAN.md; no implementation yet. No full parity claim.

F05 medicine-name search built/reviewed draft1222 headf2d64c224 on separateorderingfix1217: generic patient-name OR currentlatest immutablemedicine names, exclude not_dispensed/history/currentcatalogue; exactKP/KPV/A/Bprecedence preserved.19PG/349 +10front/types/build/static +16actualstates and independentSQL/Design clear. This closes the bounded name/queue/medicine search mapping across draft1212/1222, not overall page parity. F06 Doctor andF08 remain open; Waiting-return age semantics proposed only, no threshold invented.


### Return-age filter published — 27 November 2026

Draft PR https://github.com/putrijayamediateam/KPOne/pull/1225, remote head cf3adbb7f584fc3aad016ebcfdf9c884c6f76f17, stacked on1206 (09f65f51). Exact seven-file patch61b18971cc8179a7030f62a606146021a64420206bea7d53ee9421b18fe7507d; all source manifest hashes reverified. Literal branch-local Today/Yesterday/2 days ago/3 days ago, All includes older returns; no clinical urgency interpretation.26 backend tests/518 assertions,12 frontend tests, types/build/static and24 actual responsive GET states passed; Inventory and Design independent reviews CLEAR. All six remote CI checks SUCCESS at this checkpoint; draft open, mergeable clean, no merge.

Publication used the connected GitHub Git-data API after CLI401/auth failure. Remote tree dc15e0b5e00ffe24aee1ab8a7a98cba29196bc96 exactly equals local staged tree. Local worktree still has seven staged files and HEAD at parent09f65; do not claim local clean or create an alternate commit. Normalized remote commit metadata was insufficient for exact commit-object reconstruction; no ref was moved. Fetch the authoritative remote commit when normal Git authentication is restored. No secrets or generated assets included. Evidence: /workspace/kpone-validation/dispensary-return-age-filter/. Attachment attempt timed out; coordinator has URL.


### Prepare Doctor filter published — 27 November2026

Draft https://github.com/putrijayamediateam/KPOne/pull/1231 head4a88fb3cb64abaf9258a2f26b772c101fefd2c24 stacked1222. Exact8-file4cae39820a2c4d6592b948d1decf11ec2431fb645201d8b8bb0c0a37c27affc1 patch and clean local branch; normal commit/push.24PG/486 assertions plus14frontend, frozen types, final production build, PHPStan/Pint/lint/format and24actual GET-only responsive states pass. Both independent reviews CLEAR. Case-derived25-option selector, server-normalized pinned selection and stale/foreign reset; no new staff directory/grant/schema/clinical action. Design polling-race finding fixed with true parent replacement test. Existing schema rejects unassigned consultation fixture; failed new-test evidence retained and speculative test withdrawn without constraint bypass. CI attest in progress at publication; no merge/production. Attachment adapter timed out; root has URL. Evidence: dispensary-doctor-filter/.

Next F07 existing reason/coverage row disclosure proposal in dispensary-row-context/PLAN.md; Prepare/Waiting only using existing authorized board facts, age/sex/allergy/lastBP excluded pending their own contract. No implementation at this checkpoint.


### F07 row context published — 27 November2026

Draft https://github.com/putrijayamediateam/KPOne/pull/1235 head8f145d8da2c6438e5e690f0a1296c38ef6afed55 stacked1231. Exact9-filecbb34921c165d85056a2cbc9d2c332ec37318fdc58bdcf80e762fcfdc58c0f56 patch, normal commit/push, clean branch. Both peer final reviews CLEAR;11PG/200 +16frontend +types/build/static +14actualresponsive states PASS. Real15s polling proves same-case disclosure/focus persistence, removedfocusedrow fallback via separately recorded synthetic sendForDoctorCheck transition, and outside-focus nonsteal. Browser GET-only; no Verify/stockdecrement. No demographics/private note/history expansion. Three harness importstub additions only, existing assertions unchanged. CIattest started; no merge/production. Attachment adapter timeout, coordinator informed. Evidence dispensary-row-context/.

Next F02 reciprocal Returns navigation mapped on separate1225 base. Actual successfulReturns-page gate already impliesDispensing canView; peerconfirmation pending beforepureUIlink, no backendDTO/query/grant needed. Plan dispensary-returns-navigation/PLAN.md.

## Returns navigation F02 — draft1239

Published https://github.com/putrijayamediateam/KPOne/pull/1239 at b7fe12d13f8215a3fba68366b8a280bf33481bed, stacked on1225. Exact3file patch9e99caca06baf64c8c0ee80356816e92ffbea9d3168b6fb1948d932d3c03ef35, clean local tree. Script unchanged;16frontend/types/build/scopedstatic and8browserstates+2keyboard/back+CA403 pass. Design final clear; Inventory authority implication clear. CI attest in progress at publication, not yet green. Attachment adapter10s timeout, PR verified remotely. No merge. Evidence /workspace/kpone-validation/dispensary-returns-navigation; older1225 destination presentation explicitly qualified. Next Returns help F04 plan reviewed by Design, count/copy backend review pending.

## Returns help F04 — draft1241

Published https://github.com/putrijayamediateam/KPOne/pull/1241 at95adf4b326fb1ad134c4149bc42f36b67f998f1f, stacked on1239; exact4file patch3e191644a4c3cf3e4225d179cab9a1ae57138c0fbaa934d167f6e20e4599b9e7, cleanlocal. Design and Inventory finalclear.19focused/types/build/static+20actualbrowserstates+fivewidth44/36target measurements PASS. Required instructions remain outside optionalpanel, exactfilteredcountersemantics, existing960table preserved with content-queryrail. Two old assertions normalize whitespace only, exactregex unchanged andpeerreviewed. CIattest started, notyetgreen. No merge. Attachadapter10s timeout. Evidence /workspace/kpone-validation/dispensary-returns-help. Initial previewcwd and formatterpin failures retained with finalpassinglogs.

Shared inode exhaustion was recovered by removing only reproducible vendor directories in inactive old DSP queue-search/type-filter trees; their source/evidence remain, dependency restoration required before futurePHPchecks there. F08 ownership plan now needs coordinator audience/default disposition beforeimplementation; /workspace/kpone-validation/dispensary-waiting-ownership/PLAN.md. No Mine/Ready/Markchecked inference.

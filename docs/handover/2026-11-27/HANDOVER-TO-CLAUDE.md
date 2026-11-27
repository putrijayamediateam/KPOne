# KPOne — comprehensive continuation handover to Claude

**Checkpoint:** 27 November 2026, approximately 11:45 MYT (03:45 UTC). This is a point-in-time transfer record. Re-query GitHub before acting: status checks and branch heads can change. The GitHub listing succeeded for the PR snapshot, but a later detail query hit the GitHub API rate limit.

**Purpose:** let a new Claude session understand the actual goal, design authority, implemented work, pending PR stack, known failures, in-progress local slices, full roadmap and the exact safe next actions without guessing. This is an engineering/status handover, not a claim that the makeover is complete.

## 1. Start here — the user’s goal and the truth about progress

The user wants the **entire KPOne frontend to become the new Clean Look experience**, matching the supplied interactive demo in page content, information hierarchy, actions, role-specific views, navigation, animations and responsive behavior—not just colors, fonts or a few polished screens. Backend behavior and displayed data must remain aligned with existing real KPOne contracts. The demo is a visual/interaction reference, not permission to invent clinical, financial or authorization behavior.

The application is **not 100% complete and is not yet proven to match the demo across all pages**. Many narrow screen and backend slices have been drafted and validated. Several are open draft PRs stacked on other draft PRs; passing their local checks or CI does not mean they are merged or that the whole app is complete. Some workflows have unresolved product-owner decisions. A substantial part of the full demo census remains unimplemented or only partially implemented.

The most accurate status language is:

- **Implemented and evidenced:** name the exact screen/contract, exact revision, and evidence.
- **Draft PR / open:** published to GitHub but not merged.
- **CI green:** all required checks on that exact immutable head succeeded. Recheck before merging.
- **In progress locally:** identify the exact worktree/branch and unfinished gate; do not imply remote publication.
- **Planned / not built:** point to a plan or gap matrix.
- **Blocked / decision needed:** state the specific unresolved decision or dependency.
- Do not say “the whole screen is done” based on token changes, static source checks, a few screenshots, a successful build or a bounded feature.

The user authorized broad continuation and previously approved publication of the exact three-file Registration Settings slice. This does not turn fictional demo behavior into clinical authorization, change the existing rules for permission/schema work, or make an unreviewed merge safe. Be candid about what this session itself can observe; do not imply background progress unless it has actually been observed.

## 2. Canonical demo and design authority

### Primary artifact

- Claude artifact link shared by the user: <https://claude.ai/artifact/KUkn7hc8xW9k2REtRUCFbG>
- Uploaded interactive HTML: `docs/handover/2026-11-27/older/docs/handover/2026-11-26/KPOne-Clean-Look-Demo.html`
- Uploaded ZIP: `docs/handover/2026-11-27/older/docs/handover/2026-11-26/KPOne Clean Look Demo.zip`
- HTML SHA-256: `1633ed31900f6ce49097332ad668d77fe8a7601215695b3d01419e29305753f9`
- Audit and authority index: `docs/handover/2026-11-27/validation/demo-authority-2026-11-26/CLEAN-LOOK-DEMO-COVERAGE.md`
- Page-level implementation census: `docs/handover/2026-11-27/validation/demo-authority-2026-11-26/IMPLEMENTATION-PROGRESS.md`
- Older normative design spec: `docs/design/CLEAN-LOOK-SPEC.md` in the KPOne repo. Read both the spec and the live artifact; the page census says what is still not proven.

The artifact contains a runtime wrapper, role/account switching, redirected route assets, tab guards, hidden design notes, role-specific content patches, action guards, and entrance/dialog animation scheduling. The HTML ZIP’s embedded asset hashes matched the earlier extraction, but that does **not** make a static screenshot or extracted asset equivalent to the interactive runtime. Read the wrapper and audit before comparing a screen.

Important runtime behaviors to validate per relevant route and role:

- 17 fictional accounts. Director opens Modern, TV opens WaitingRoomTv, other demo accounts open Me. A demo account cannot simply switch to another identity by remembering a different role.
- Redirected assets choose their correct tab/view. HR aliases select own/team tabs by persona. Design notes and `NOTES` are hidden reference content, not application product pages.
- Runtime adds role-specific home summaries, own-HR tabs, Admin connector tools, invoice-void explanation and Ask KPOne drawer; navigation links may be rewritten to their actual matching section.
- Page entrance plays once per arrival, not on every rerender; leaving and returning restarts it. Dialog entrance is independent. Respect reduced-motion preference and keep focus, selection and scroll stable across updates.
- Demo persistence and inventory changes are fictional runtime behavior. They are not authority for application architecture, a real transaction, new grants, or automatic clinical choices.

### Whole-demo completion gate

For each real role × route × tab that maps to a product screen, compare **real content and hierarchy**, layout at 390/768/1440 in light/dark, real actions and disclosures, loading/empty/error/partial/read-only states, keyboard/focus/touch targets, page/dialog animations and reduced motion. Record source/demo screenshots and app screenshots with the exact demo SHA. A CSS-only diff, static test, source resemblance or green build does not close this gate. Preserve approved minimum targets: 44 px below 1024 px, 36 px desktop. Any documented deviation must be explicit.

The audit is a census, not a claim of completion. Use its full asset inventory and route map; it contains many product routes beyond the current work. Some assets are dialogs, aliases, design notes, or hidden references, not separate pages. Confirm the correct classification before making a new page.

## 3. Guardrails and workflow

1. **One bounded implementation slice at a time per owner/review stream.** A slice is one screen or one backend change with a complete, reviewable contract. Multiple independent people can work concurrently only where the active session actually supports it; never report imaginary teams/background tasks.
2. **Inspect before coding.** List exact repository paths, inspect current main and the chosen branch/parent, read the corresponding plan/gap matrix, then write. Never modify an unseen file from a filename guess.
3. **Use fresh exact heads.** Before updating/publishing a stacked PR, verify parent head and ancestry; never silently rebase away a reviewed dependency. Pin patch SHA and source/blob hashes.
4. **Existing assertions:** identify any changed existing assertion and why. Prior exact owner-approved changes stay limited to the specific named assertion/purpose. Do not broaden approval to neighboring tests.
5. **Backend contracts:** actor/organisation/branch checks must be server-side and current at command/query entry. Preserve transaction, locks, optimistic `lock_version`, idempotency/retry and audit semantics where present. Use synthetic fixtures only. A UI filter must be applied before count/pagination and must not leak cross-branch values.
6. **No invented permissions, migrations, workflow, patient context, financial values, labels, clinical defaults or helper data.** If the planned behavior requires a new permission/schema change or conflicts with an existing owner decision, surface the exact decision; do not derive it from the demo.
7. **No real patient data, production DB/service, `.env` or secrets.** Do not access `kpjaya.com`. Browser proof must use isolated synthetic accounts/data. Do not mutate shared or production clinical/financial records to stage a screenshot.
8. **Evidence:** report exact command, exact test count/assertions and exit code; exact head; browser role/route/width/theme and inspected states; data invariance before/after; independent source/design review; CI run/check result. Keep failed diagnostic attempts and explain corrected harness/environment errors rather than hiding them.
9. **Resource safety:** an inode exhaustion incident was resolved by removing only known regenerable PHPStan cache and retired vendor copies. Do not clean folders based on names alone; preserve source, fixtures, logs, screenshots and current dependencies.
10. **Publication and merge:** open PRs remain drafts unless deliberately reviewed. No stack merge while any required check is pending/failing. Serial stack merge only; verify the exact head and parent relationship, wait for green CI for that exact revision, then refresh/re-run downstream heads after parent merge. Do not merge archived PR #1142 or use it as ancestry.

## 4. Repository and local evidence locations

The active app code is in separate local KPOne worktrees, which are intentionally not included in this docs-only branch. Do not assume any worktree is current main. Always run `git status`, `git branch --show-current`, `git rev-parse HEAD`, `git remote -v` and compare against `origin/main` before using a tree.

Evidence/plans live under `docs/handover/2026-11-27/validation/`. This directory is not the application source repository. It contains immutable review packets, logs, browser captures, plans and diffs. Link to those packets rather than copying or altering evidence. Examples:

- Broad handover/evidence index: `docs/handover/2026-11-27/validation/README.md`
- Current Queue status ledger: `docs/handover/2026-11-27/validation/REVIEW-QUEUE-LATEST-CHECKPOINT.md`
- Exact demo census and team gap matrices: `docs/handover/2026-11-27/validation/demo-authority-2026-11-26/`
- Queue demo comparison: `docs/handover/2026-11-27/validation/queue-demo-makeover/GAP-MATRIX.md`
- Inventory demo audit: `docs/handover/2026-11-27/validation/demo-authority-2026-11-26/inventory-audit/INVENTORY-GAP-MATRIX.md`
- Dispensary demo audit: `docs/handover/2026-11-27/validation/dispensary-demo-audit/GAP-MATRIX.md`
- Billing/Panel demo audit: `docs/handover/2026-11-27/validation/demo-authority-2026-11-26/BILLING-PANEL-GAP-MATRIX.md`
- Patient/registration demo audit: `docs/handover/2026-11-27/validation/demo-authority-2026-11-26/REGISTRATION-PATIENT-GAP-MATRIX.md`
- Phase-specific plans are named in sections 7–9 below.

The handover snapshot included a successful open-PR listing through #1251 around 27 Nov 03:42 UTC. During this documentation transfer, current repository API calls returned 404/unresolvable for the connected GitHub identity. Therefore the table is a best-effort point-in-time inventory assembled from that listing and saved local status files; Claude must re-query open/closed state, heads, parents and CI using an identity with repository access before acting. Never label a PR merged based only on a local branch, a previous session’s report or a merge-ready state.

## 5. Current high-priority blocker: PR #1132 Trial Data fixture

**User explicitly asked about this blocker.** It has a narrow fix published, but the fix still needs exact CI and merge sequencing.

- Original PR #1132 at head `7457a39…` failed backend shard 1 in `TrialDataCommandTest`: Phase 1 refused before a later Phase 5 assertion because its synthetic Director had no valid assignment at CHERAS for the fixture’s previous-day test clock.
- Diagnosis is in `docs/handover/2026-11-27/validation/trial-director-clock/REVIEW.md`. Frozen application/test context recorded UTC 2026-11-27 03:20:53, then the test time was KL 2026-11-26 12:00. Primary assignment had `valid_from=2026-11-27`; secondary assignment fixture was already valid from 2026-11-26. Actual refusal: `The Director has no current assignment at CHERAS. Run php artisan kpone:director-branches first, then try again.`
- Fix is **fixture-only** in `tests/Feature/TrialDataCommandTest.php`: set the synthetic primary assignment date consistently for the prior-day configured fixture, add a positive previous-day Phase 1 regression, and add a negative future-assignment refusal regression with table-count invariance. Production branch guard unchanged; original assertions retained.
- Full class passed **24 tests / 564 assertions**, including the midnight provider cases; syntax/Pint/diff and independent Staff review passed.
- Draft PR #1248: <https://github.com/putrijayamediateam/KPOne/pull/1248>, branch `fix/trial-data-director-fixture-clock`, head `305c2033682d00926dfe97a188f74291dd594117`, based on main `d0f37b5c1864b796fc7b4224996bd917551af3e7`. CI run `45934479513` was queued/running when last observed. Latest PR listing showed attest/static green and three backend shards in progress; **do not claim green yet**.
- Once #1248 CI is green and its exact branch is merged according to repository policy, refresh #1132 onto the new main, rerun the six required checks on #1132’s exact updated head, then consider it. #1248 does not itself prove #1132 is fixed/green until this integration sequence completes.

Packet: `docs/handover/2026-11-27/validation/trial-director-clock/` (`COMMANDS.md`, `REVIEW.md`, `INDEPENDENT-REVIEW.md`, `PROPOSED-FIX.patch`, `publication.json`).

## 6. Open PR stack snapshot and exact next issues

All links below point to public draft PRs unless explicitly stated. Most are deliberately stacked. **A green child PR is not independently mergeable until the parent is present on main and the child is refreshed/validated against it.** CI values below are the latest successful `gh pr list` snapshot around 03:42 UTC on 27 Nov; detail queries may be stale or rate-limited.

### Newest Queue, Inventory and Billing drafts

| PR | Purpose / head at snapshot | Parent | Status and next action |
|---|---|---|---|
| [#1251](https://github.com/putrijaymediateam/KPOne/pull/1251) | Billing: search Finance/Panel work lists; head `31b1b76a77…`, branch `feature/billing-work-search` | #1243? Open-PR snapshot says `feature/billing-work-mobile-cards`; verify exact ancestry | Draft; independent Design clearance pinned at `66d86bb2`; full frontend 2,211, types/build/static, backend new 3/120 and full existing+new 9/423; 40 light/dark captures (390/768/1440), 50 JSON records, financial-row hashes unchanged. At snapshot backend CI shards were still running. Read `docs/handover/2026-11-27/validation/billing-work-search/REVIEW.md`; recheck PR head/parent and exact CI. No migration/grant/financial writes. |
| [#1250](https://github.com/putrijaymediateam/KPOne/pull/1250) | Inventory low-stock reorder workspace; head `4f6f60cc0b…`, branch `feature/stock-reorder-workspace-demo` | #1245 | Draft. Source/Design clear for frozen six-file candidate. A separate 7-file local Location filter extension on this tree is now fully frozen as `9e1338a5…`; expanded backend17/133 + final4/34, frontend focused19, full frontend2299, types/build/PHPStan/Pint, lint/format all passed. Actual select+Submit and all12 browser states passed; 29 stock movements/16 balances/68 actions unchanged. Final independent hash reviews requested; extension remains uncommitted/unpublished. No permission/schema changes. See `docs/handover/2026-11-27/validation/stock-reorder-location-filter/PLAN.md` and `CHECKPOINT.md`. |
| [#1249](https://github.com/putrijaymediateam/KPOne/pull/1249) | Queue Doctors table; head `013ac9d9d22b…`, branch `recovery/queue-doctors-table` | #1247 | Draft. Final Staff source review clear; frontend 2,327, types/build, backend3/27 and 20 final-stack browser states passed; actual list 27 doctors paginates 25+2, mobile focus/error recovery checked and eight source tables unchanged. CI run `45934566521` was still running backend shards 2–3 at latest agent checkpoint. Recheck exact CI and stack ancestry. Queue documented content reconciliation and gaps in `queue-final-screen/` (locate current folder; agent named evidence folder `queue-final-screen`). |
| [#1248](https://github.com/putrijaymediateam/KPOne/pull/1248) | Fixture correction for #1132; head `305c2033682d…` | main | Draft; local all-class 24/564 and independent review clear, CI run `45934479513` backend shards were in progress. Priority blocker; see §5. |

### Recent open PRs #1242–#1247

| PR | Scope / exact head | Parent | Latest observed CI / disposition |
|---|---|---|---|
| [#1247](https://github.com/putrijaymediateam/KPOne/pull/1247) | Queue branch Done destination, `32bd1444afd1…` | #1246 | All six CI checks succeeded in latest snapshot. Draft, not merged. |
| [#1246](https://github.com/putrijaymediateam/KPOne/pull/1246) | Queue branch Held treatment step, `944f36a26a…` | #1244 | All six CI checks succeeded. Draft, not merged. |
| [#1245](https://github.com/putrijaymediateam/KPOne/pull/1245) | Inventory Supplier Return request worklists, `c3a3dc0057…` | #1236 | All six CI checks succeeded. Bounded groups of 10 and individual page 25; refusal preserves draft and no stock mutation. Draft. |
| [#1244](https://github.com/putrijaymediateam/KPOne/pull/1244) | Queue Waiting/Serving columns, `a3ff5bc45d…` | #1242 | All six CI checks succeeded. Draft. |
| [#1243](https://github.com/putrijaymediateam/KPOne/pull/1243) | Billing Panel price decision status filter, `6809d19be3…` | #1237 | All six CI checks succeeded. Filter visibility issue was found/fixed; draft. |
| [#1242](https://github.com/putrijaymediateam/KPOne/pull/1242) | Queue Medicine-check count/sender, `2b3f2cd83e…` | #1238 | CI failure, reproducible test clock boundary; do not alter query budget/assertions. A tiny fixture-only clock freeze candidate is prepared locally; see §6.1. |

#### #1242 precise diagnosis and test-only candidate

The Registration tab-count performance test warms the `DoctorWarningProjection` cache and then measures queries across a real clock minute boundary. Its cache key includes the minute, so if the clock crosses after warm-up, one cache miss produces **21 vs the expected maximum 20**, without a per-row/N+1 regression. Same unchanged tests passed serial/parallel on the main stack (five tests/190 assertions) and on exact #1242 copy (five/191 assertions). A deliberate minute-crossing diagnostic reproduces 21. This is a **flaky measurement fixture**, not evidence that the queue implementation should loosen its query cap.

Isolated worktree: local worktree `KPOne-tabcounts-clock-fix` (application source not copied), branch `fix/tabcounts-stable-measurement-clock`, fresh main at `d0f37b5c1…`; uncommitted patch `5b3f88da…`, two added fixture lines only, assertions/application cache/query budgets unchanged. Main five/190 and copied-stack five/191 pass; Pint passes; exact diagnostic retained under `docs/handover/2026-11-27/validation/queue-release-path/tabcounts-clock`. Final Billing source pin/review was pending when handed over. Next: inspect the exact two-line diff and tests, obtain/reconfirm independent Billing review, then publish a separate, tightly scoped draft if still appropriate. Do not modify #1242 or its query ceiling to hide this test race. After the fix merges, refresh #1242 and rerun exact CI.

### Open stacked PRs #1220–#1241

The table is a navigation ledger, not a request to merge the stack all at once. Each PR’s current parent and current head must be rechecked. At the listing snapshot, every row except #1240, #1241 and #1242 had `ci=SUCCESS`; #1240/#1241 failure reason was not fetched due API rate limit; #1242 is the explained failure above. Most stacks also still need refreshed CI after their parents merge.

| PR | Work (bounded; the full page/domain remains incomplete) | Head prefix | Parent branch/PR at snapshot | CI snapshot |
|---|---|---|---|---|
| [#1241](https://github.com/putrijaymediateam/KPOne/pull/1241) | Dispensary Returns help panel; 19 frontend/type/build/static, 20 actual read-only states, five widths; Design clear | `95adf4b326` | #1239 | Failure; inspect failing job before any repair |
| [#1240](https://github.com/putrijaymediateam/KPOne/pull/1240) | Registration Settings composition, exact 3-file approved slice | `d2d13fdc24` | main | Failure in latest listing; fetch job/log and resolve. The original local full run passed; do not assume remote failure is harmless |
| [#1239](https://github.com/putrijaymediateam/KPOne/pull/1239) | Dispensary Returns navigation | `b7fe12d13f` | #1225 | Success |
| [#1238](https://github.com/putrijaymediateam/KPOne/pull/1238) | Queue checkout time in own Done | `a4335a6d97` | #1234 | Success; current head includes test/lint follow-up |
| [#1237](https://github.com/putrijaymediateam/KPOne/pull/1237) | Panel remittance payment workspace | `88d21dfe23` | #1232 | Success |
| [#1236](https://github.com/putrijaymediateam/KPOne/pull/1236) | Inventory Supplier Returns Open/Done filter | `0ff4e00120` | #1230 | Success |
| [#1235](https://github.com/putrijaymediateam/KPOne/pull/1235) | Dispensary case-row existing visit-context disclosure (F07 bounded) | `8f145d8da2` | #1231 | Success |
| [#1234](https://github.com/putrijaymediateam/KPOne/pull/1234) | Queue treatment steps with recorded result columns | `b0ed07f277` | #1233 | Success |
| [#1233](https://github.com/putrijaymediateam/KPOne/pull/1233) | Queue Held treatment steps in separate columns | `d99e5c4ae6` | #1229 | Success |
| [#1232](https://github.com/putrijaymediateam/KPOne/pull/1232) | Panel follow-up claims exact status filter | `ff3d942d54` | #1227 | Success |
| [#1231](https://github.com/putrijaymediateam/KPOne/pull/1231) | Dispensary preparation-case Doctor filter | `4a88fb3cb6` | #1222 | Success |
| [#1230](https://github.com/putrijaymediateam/KPOne/pull/1230) | Inventory Supplier Invoice match worklist with scoped amounts | `3fb034ad44` | #1226 | Success |
| [#1229](https://github.com/putrijaymediateam/KPOne/pull/1229) | Queue own Waiting/Seeing columns; test adapter follow-up included | `efa4eb0005` | #1223 | Success |
| [#1228](https://github.com/putrijaymediateam/KPOne/pull/1228) | Registration Contact/Address headings | `359e9bbd13` | #1224 | Success |
| [#1227](https://github.com/putrijaymediateam/KPOne/pull/1227) | Panel claims awaiting first submission | `76330a1923` | #1218 | Success in listing; exact fresh checks still need recheck |
| [#1226](https://github.com/putrijaymediateam/KPOne/pull/1226) | Inventory Purchase Order worklist with persistent drafts | `fc98024ec2` | #1216 | Success |
| [#1225](https://github.com/putrijaymediateam/KPOne/pull/1225) | Dispensary waiting returns exact calendar-day age filter | `cf3adbb7f5` | #1221-ish; verify exact parent | Success |
| [#1224](https://github.com/putrijaymediateam/KPOne/pull/1224) | Registration quick patient field groups/dialog visibility | `e8d3769e85` | #1221 | Success |
| [#1223](https://github.com/putrijaymediateam/KPOne/pull/1223) | Queue authorized room provenance/next patient context | `cc73b15203` | #1220 | Success |
| [#1222](https://github.com/putrijaymediateam/KPOne/pull/1222) | Dispensary search current saved Medicine names | `f2d64c2247` | main/latest DSP foundation; verify | Success |
| [#1221](https://github.com/putrijaymediateam/KPOne/pull/1221) | Registration guarded Find/New patient controls | `9ef687db40` | #1219 | Success |
| [#1220](https://github.com/putrijaymediateam/KPOne/pull/1220) | Queue workload tabs, safe paging/context reset | `efe7141558` | #1215 | Success |

For exact seven/eight-file scope, backend/test counts, screenshot names, permission boundaries and assertion adapters, read that PR’s linked review packet under `docs/handover/2026-11-27/validation/`; do not infer these from titles. PR #1142 is archived and must not be merged or used as a parent.

## 7. Per-domain detailed roadmap (done vs. remaining)

### 7.0 Previously delivered foundation and older approved slices (before #1220)

The user pasted a successful local fast-forward/build/migration update to `main` at `953e9d75f` (merge of #1119) and said they had merged the PRs locally. That output showed 156 files changed from `14701efae` and included the following real application foundations. This list describes that observed local update, **not** a guarantee that every later open PR has merged to GitHub or that any listed route has achieved whole-demo parity:

- **Clean Look base:** design token/CSS refresh, Geist + Inter fonts, shared Card/Sheet/Dialog/Button/Input/Status/Badge/Table/Tabs/PageHeader/Sonner refinements, modern shell `WorkspaceRail`, workspace/branch switcher and Clinic/Admin layout wiring. This changes the shared visual base and some nav, but pages that were not individually remade remain legacy or mixed UI.
- **Core shell/access/navigation:** new ShellMenu and ClinicManagementNavigation/StaffKind/PermissionCatalogue menu contracts; role-aware shell navigation tests and frontend shell/token coverage. This enables role menus; it is not a wholesale access-control rewrite.
- **Inventory/stock backend and screens:** Catalogue import service and command; dry-run/refusal/difference report services and report migration/permission; governed stock and inventory/stock health/review/valuation wording; Inventory Index, Stock Review, Stock Value, Opening Import, Medicine Sales pages and report sheets. These are discrete real workflows with tests, not proof that all inventory demo tabs match.
- **Dispensary/clinical:** real Dispensary list service/controller/page, diagnosis preset review/settings work, suggestion review page, supporting permission/picker work. Clinical safety guards remain authoritative.
- **HR:** real locum availability and roster/month planner/free-times flows and Open Shifts adjustments. Work remains across other HR/own/team/admin screens and runtime-specific tabs.
- **Admin/other real product:** user role modeling, registration/supplier/medicine option settings, consultation settings, patient-board and layout integration; additional domain test coverage.
- **Previous parallel authorized slices:** STK-46 inventory cost entry/check behavior and role expectations; BIL-38 Director publication separation and positive/negative fixtures; Clean Look shared shell/Card/Sheet/Registration/Consultation/Homes/Inventory/Dispensary/Queue slices; Q16 billing refinements; TRI-2 emergency lane and its append-only audit/migration/polling UI; PNL-30; treatment-room invoice prop; Dialog target/heading; email-verification fixture; and numerous PRs in the #1119–#1219 range.

For that older work, use the saved evidence rather than claiming all are merged from memory: `docs/handover/2026-11-27/older/` (older continuation bundle), `docs/handover/2026-11-27/validation/handover-2026-11-26/`, `README.md`, `stock-pr*.json`, `patient-publications.json`, `billing-release-map.md`, `dispensary.md`, `inventory.md`, `patients-registration.md`, `queue-*.md`, and exact PR history on GitHub. Some are historical, some were drafts, and some were merged; verify each against current main before editing. The user’s local `953e9d75` pull output is the primary evidence for that local checkpoint.

The user’s original full-system plan has much more than UI restyling. Backend-first work such as registration/clinical settings, pharmacy/inventory/HR, safe queue lifecycle and auditable permissions must remain coupled to the frontend screens that consume it. The main is now substantially richer than the earlier screenshots. Clean Look completion still requires translating every actual demo page/role/state to real KPOne routes and real server data.

### 7.1 Queue / waiting room / TV

**Current trajectory:** real workload read and workload tabs are already represented in drafts #1215/#1220; contextual room/provenance and own Waiting/Seeing are #1223/#1229; Held/treatment/result/done timeline is #1233/#1234/#1238; branch Waiting/Serving/Held/Done/Doctors are #1244/#1246/#1247/#1249. The recent work is product/content composition on real bounded backend projections, not merely styling. All recent Queue PRs remain drafts and stack on each other. #1242 currently has the test cache-minute failure described above; fixing that correctly is the immediate Queue CI priority.

**Reference discrepancy:** canonical demo page is **MyQueue runtime**. `QueueBoard` redirects to `MyQueue`; `WfQueueBoard` is a hidden design note. Current integrated Queue still differs in grouped views/metrics/top pills or tabs/central list/right contextual disclosure and contextual Call next. Do not compare against static `QueueBoard` or claim parity because several columns now resemble screenshots. Inspect:

- `docs/handover/2026-11-27/validation/queue-demo-makeover/GAP-MATRIX.md`
- `docs/handover/2026-11-27/validation/queue-demo-makeover/canonical-runtime.json` and role screenshots (`demo-doctor`/`demo-supervisor`, 1440/390)
- `docs/handover/2026-11-27/validation/queue-release-path/queue-final-screen/`; Staff ledger says the zero Waiting count was summary-response metadata, not an empty list. In verified state `KPV00000060` is a populated Waiting row while the optional summary count can be zero. Do not show a false empty screen.
- `docs/handover/2026-11-27/validation/handover-2026-11-26/queue-tv.md`

**Remaining after current drafts:** final exact CI/stack release; compare the live artifact’s doctor/supervisor/TV content and actions; verify every real Held/Back/Check/Done dataset and empty/error state; responsive/animation parity; actionable Call next placement; role scoping; use evidence-based counts. Treat summary counts and row data as separate contracts. No full Queue/TV parity claim yet.

### 7.2 Inventory / Stock / Purchase Orders / Supplier Returns

**Current trajectory:** purchase-order delivery metadata #1216; PO worklist #1226; supplier-invoice matching #1230; returns Open/Done #1236; supplier return request worklists #1245; low-stock reorder workspace #1250. This is a series of bounded real-workflow screens. Do not imply all Inventory tabs or reports have been rebuilt.

**Active local Location-filter continuation:** local worktree `KPOne-stock-reorder-location-filter` (application source not copied); plan/evidence `docs/handover/2026-11-27/validation/stock-reorder-location-filter/PLAN.md`, latest checkpoint `docs/handover/2026-11-27/validation/stock-reorder-location-filter/CHECKPOINT.md`. It extends low Stock/Reorder with an optional authorized Location filter, preserving empty=All and existing list/request behavior. Only active locations in the current authorized branch/org; reject malformed, foreign, inactive and returned-medicine locations rather than broadening silently. Apply before calculation/count/page. Keep SKU/quantity/cost eligibility and current request mutation semantics. Reuse `InventoryPickerService` via narrow `reorder` context only: active branch/current actor/effective assignment checks, active branch-owned non-returns location options, limits DEFAULT25/MAX100. Existing picker contexts retain policy. No migration/grant. Frozen seven-file patch `9e1338a553c9fd3ddb502441aa6fa943c2d83a878ad4ccdec5ed1cab73abb106`; backend17/133 plus final4/34, frontend19 focused, full2299, types/build/PHPStan/Pint/lint/format pass. Real selection+Submit and all12 browser states pass; stock data unchanged (29 movements/16 balances/68 actions). This continuation is still uncommitted/unpublished; final independent hash reviews requested. Next: inspect latest checkpoint and get the precise reviewer dispositions; if clear, publish as a separate child draft, then run its exact CI. Do not fold it into the existing six-file #1250 without explicit ancestry/source review.

Known unresolved product content: demo `Kind=Supplies` cannot be inferred from absence of a medicine mapping because current Item/SKU model has no explicit kind. Keep this as an explicit contract question; do not invent a category or filter.

Read `docs/handover/2026-11-27/validation/demo-authority-2026-11-26/inventory-audit/INVENTORY-GAP-MATRIX.md` and local team `docs/teams/inventory/TEAM.md`, plus each PR review packet. Remaining: broader stock on hand, valuation/reports, batch/controlled register, receiving, stock movement/adjustment, low-stock actions and return lifecycle must each be mapped to real routes/contracts. Preserve transaction, row locks, lock_version, retry/idempotency and audit behavior. Reorder Request these remains unchanged in current local slice.

### 7.3 Billing / Panel / payments / claims

**Current trajectory:** bounded Panel waiting payment #1218, first submission #1227, status filter #1232, remittance workspace #1237, decision status filter #1243; Invoice/PO and Billing worklist search #1251. The newest search covers Finance and Panel responsibility worklists only; it is not the complete patient Billing view or full Billing/Panel demo.

**#1251 evidence:** `docs/handover/2026-11-27/validation/billing-work-search/REVIEW.md` is the detailed evidence. Search remains inside existing actor/org/branch/invoice/Panel predicates and applies before the unchanged 25-row pagination; financial rows, amounts, Review destinations and labels unchanged. No writes, grants or migrations. Final independent Design pin `66d86bb2`; full frontend 2,211, types/build/static, backend 9/423 and final added cases 3/120, 40 responsive light/dark captures and 50 JSON records passed locally; financial before/after hashes identical. Draft PR published; at snapshot CI backend shards were in progress. Existing viewport/table scroll affordance remains; do not claim all inherited controls have target-size compliance.

**Full-page gap:** billing patient detail/history/documents/actions and richer Finance/Panel overview are not certified. Preserve official monetary snapshots, versioned commands, permissions and approved family-billing limits. Reconcile data meanings before presenting demo totals. The panel first-submission packet explicitly says it does not create a Claim submission action, alter paid-date/all-branch semantics, or assert claim readiness. See `docs/handover/2026-11-27/validation/demo-authority-2026-11-26/BILLING-PANEL-GAP-MATRIX.md` and each `panel-*` review packet.

### 7.4 Registration / Patients / Patient overview

**Published draft:** #1240 is the exact user-approved three-file Registration Settings composition. Branch `feature/registration-settings-composition`, head `d2d13fdc24…`, based on main. Exact changed files:

1. `resources/js/pages/RegistrationSettings/Index.vue`
2. `tests/Frontend/registration-settings-composition.test.mjs`
3. `tests/Frontend/registration-settings.test.mjs`

Owner explicitly approved publishing those three files. Local packet reports all 2,212 frontend tests, 8 backend tests/173 assertions, types/build/static and 20 browser captures, with settings unchanged and no successful save during capture. Latest GitHub open-PR list showed `ci=FAILURE`; reason not yet collected because GitHub API rate limit occurred. **Next action is fetch exact failed job/run logs and classify infrastructure vs real failure**, rerun only as appropriate, no new assertion changes without exact review. Packet `docs/handover/2026-11-27/validation/registration-settings-composition/REVIEW.md`.

Other Registration drafts include #1221 Find/New, #1224 field groups, #1228 Contact/Address headings. These are bounded form/navigation refinements; they do not complete the demo’s full Registration and Patient overview hierarchy. Read `docs/handover/2026-11-27/validation/demo-authority-2026-11-26/REGISTRATION-PATIENT-GAP-MATRIX.md` and `docs/handover/2026-11-27/validation/demo-authority-2026-11-26/IMPLEMENTATION-PROGRESS.md`.

**Already resolved blocker in this session:** #1248 exact fixture-only TrialData fix as §5. It is not a Registration UI change. Do not combine unrelated CI repair with a Registration screen PR.

**Remaining phases:** compare Today/Patients/QR and role-specific top content against runtime; patient registration full-page/dialog states; QR consent/policy snapshot and validation; registration review; patient overview (history/billing/clinical panels) only with existing authorized source data. No extra cross-branch aggregate, emergency/family relationship inference, duplicate phone family, fake scanner, or new clinical context to fill a demo card. Existing atomic commands, unsaved protections, idempotency, validation and optional emergency/manual-family choices remain authoritative. Settings contract explains separate auto-assignment permission and settings; keep forms and save semantics separate.

### 7.5 Dispensary / Pharmacy / OTC

Recent drafts: #1222 current medicine-name search; #1231 Doctor filter; #1235 existing visit-context disclosure; #1239 Returns navigation; #1241 optional Returns help. #1241 has local final proof (19 frontend/types/build/static, 20 browser states, five width checks) and independent review clear; latest GitHub listing said CI failure, exact cause unknown at handover. Inspect job logs before any modification. #1240 unrelated.

Critical demo mismatches/decisions in `docs/handover/2026-11-27/validation/dispensary-demo-audit/GAP-MATRIX.md` and `docs/handover/2026-11-27/validation/dispensary-waiting-ownership/PLAN.md`:

- Keep real case states, checks, allergy/stock/FEFO warnings, dirty-state protection, quantities/reasons, idempotency, actor separation and return limitations. Demo mock inventory/cyclic batches/fixed products are not behavior.
- Compact saved-line cards and help/return views have bounded evidence; they do not complete the entire Dispensary list/case/OTC/labels/settings surface.
- F08 “Mine” ownership meaning is unresolved. Do **not** silently map preparer to attending Doctor. Do not implement/change ownership filter until exact role/current branch semantics are agreed in the decision plan.
- No new Follow-up link in this scope: prior owner decision excludes it unless explicitly reversed. Keep Ready/Verify and “verify completes” contract; unresolved direct-list returns vs case-only, All branches, default return reason/condition decisions stay documented. Do not infer permission from demo role labels.
- Current demo gap matrix lists 27 scoped gaps and five decision conflicts; the team plan gives recommended sequence: (A) existing-data list/workspace presentation, (B) saved-line card/edit/add disclosure while preserving form/commands, (C) completed/returns/labels/settings composition around existing data, (D) separately approved server-backed filters/enriched DTOs. Only implement after exact source/owner-decision review.

### 7.6 Shared shell and design system

The user said the classic UI will ultimately be replaced. A Clean Look shell, card/sheet tokens, shared filter targets, dialog/spacing and home components have been published/drafted in older PRs, and the local main snapshot already contains the major shell/token changes. This is **foundation work**, not a wholesale route replacement. Keep all legacy pages operable until each real screen is rebuilt, mapped and accepted. Do not toggle a global V2 feature gate that sends unconverted screens to an empty/new shell.

Reference `docs/design/CLEAN-LOOK-SPEC.md`, `docs/design/CLASSIC-ONLY-PLAN.md`, old tracked source/design review docs and exact open PRs. Avoid broad shared CSS/primitive changes in a page slice. Ensure tokens rather than raw palette classes where the spec requires it; light/dark, focus, reduced motion and responsive states are part of the contract.

### 7.7 Staff / HR / Admin and other routes

Read the asset census in `CLEAN-LOOK-DEMO-COVERAGE.md` and `docs/teams/hr/HR-PLAN.md` (if present on current main) route by route. Current uploaded demo includes Staff/HR/Admin/approvals/audit/branches and role-specific tabs. The old handover has HR plans, schedules/roster/open-shifts and team status. Do not call these finished based on legacy partials. Confirm whether each reference asset is a page, tab, dialog, alias or hidden design note, then compare exact actor/permission-filtered content and commands. Avoid adding external messaging or unapproved grants.

## 8. Other planned/not-built work and explicit decisions

The rows here matter: user asked for work already planned as well as what has been built. Treat these as the roadmap inventory, not as completed tasks or an implicit approval to implement risky behavior.

| Area/phase | State at handover | How to continue |
|---|---|---|
| Artifact-wide route/asset census | Audit/index exists; many assets marked unverified | Finish one product area at a time using `CLEAN-LOOK-DEMO-COVERAGE.md`; classify aliases/dialogs/hidden notes; attach real route, actor, tab and evidence. |
| Queue full parity | Multiple functional and content drafts; grouped runtime/role coverage incomplete | Finish open draft stack and compare doctor/supervisor/TV roles; do not substitute static asset; establish real data for metrics and actions. |
| Inventory reports/stock | Reorder and supplier workflows being composed; broad on-hand/report parity incomplete | Map all Inventory tabs to current service/query contracts. Do not fabricate `Kind`, balances, expiry/KPI aggregates. Preserve authorization and accounting invariants. |
| Billing patient workspace | bounded responsibility worklists/search and claims slices exist | Continue with exact patient-detail screen and evidence matrix; preserve financial snapshots/permissions and do not infer demo total semantics. |
| Registration & patient overview | Settings and contact/grouping/navigation drafts exist | Rebuild route-specific content progressively; family/emergency links are bounded separate flows; patient clinical summaries require authorized backend projection review. |
| Dispensary case/list | bounded search, doctor filter, disclosures, returns support | Complete evidence against all case states and OTC/labels. Resolve workflow conflicts before changing behavior. |
| HR/Admin full runtime tabs | existing team plans/assets; not full parity | Inspect each role-specific alias/tab and verify real projection/actions; no blanket claim. |
| Animation system | demo runtime behavior documented; whole-app equivalence not certified | For each page/dialog implement once-per-arrival behavior, preserve focus/scroll/rerender, reduced motion, no replays; add runtime proofs. |
| Responsive/accessibility | several slices measured at 390/768/1440; not all pages/controls | Measure actual interactive targets, not only outer row; retain 44 px below1024 and 36 px desktop; keyboard/visible focus; no overflow. |
| Backend-to-frontend alignment | bounded slices have service-backed props/filter/pagination | Inventory every visible value/action; tie it to a server projection and actor scope. Mark absent data honestly; request contract review before adding aggregates or permissions. |
| PR release integration | dozens of open stacked drafts | Decide dependency order from actual GitHub graph; merge serially only when parent and exact CI green, then refresh children. Never infer from numerical PR order alone. |

### Known decisions/limits that must remain visible

- Demo account/permission switches cannot authorize new backend abilities. `TAB_GUARD`, hidden proposal flags and demo comments are evidence about the reference only.
- Do not treat a disabled control, hidden section, or label in the demo as a real access policy without verifying KPOne permission service and tests.
- Clinical returns/follow-up/Ready/Verify/OTC and family/emergency semantics have explicit owner constraints in the referenced plans. Preserve them unless the owner updates those exact decisions.
- `All branches` requires a source authorization contract, not just a toggle.
- Search and filters must preserve real server scopes and have explicit applied vs draft query behavior.
- An empty list is not inferred from summary count zero. Use the actual rows/metadata distinction.
- A browser test harness fix is not an application change. Record it clearly.
- User’s approval of the #1240 three-file publish was scoped to that packet; avoid using it as implicit approval for unrelated assertion changes, grants or migrations.

## 9. Recommended exact resumption order

1. **Establish current truth:** from fresh main clone/worktree run status, exact HEAD, `git fetch`, current main SHA; query open draft PR heads/parents/checks. Do not operate in a stale worktree.
2. **Resolve PR #1248:** check run `45934479513`, confirm all six checks on exact `305c203…`. If green, merge only under normal repo policy, then refresh #1132 and verify all six new checks. This closes the user-highlighted CI blocker only after #1132 exact green.
3. **Resolve #1242 flaky measurement safely:** inspect 2-line `5b3f88da` patch; get final Billing peer pin; publish separate minimal fixture PR; CI green; refresh #1242; do not change production cache or query threshold.
4. **Repair known remote failures:** #1240 and #1241 list as CI failure. Fetch workflow/job logs and diagnose exact failure. Do not reflexively rerun or change source before establishing whether code/test/environment failed.
5. **Finish local Inventory Location filter:** both final browser/format sessions now exited 0; inspect `docs/handover/2026-11-27/validation/stock-reorder-location-filter/CHECKPOINT.md`, frozen seven-file manifest and final peer-review responses. If exact source/authority reviews clear, publish as a separate child draft and run its exact CI.
6. **Finish Queue #1249/#1247/#1246/#1244 stack checks:** current content and boundary evidence are strong, but recheck exact CI and hidden gaps. Refresh each descendant after the parent merge rather than merging an old base.
7. **Proceed with #1251 Billing** only after its current running CI completes and exact parent/stack relationship is reconciled. Retain the financial hash/invariance proof.
8. **After the urgent CI/release work, select one new screen from the canonical demo gap matrix** (highest value, no unresolved behavior decision). Write a one-screen implementation plan with exact files, backend fields/authority, design reference, tests and browser states. Complete and review it before starting another overlapping UI slice.
9. **Maintain the route parity ledger:** update “done / in progress / planned / blocked” only with evidence and exact SHA. Any page without demo-vs-app captures remains “unverified.”

Do not say “all teams working” unless Claude actually starts concurrent subagents in that new environment and sees their activity. This handover contains observed end states from agents who reported to the present session; their completion is not a guarantee they continue in the next session.

## 10. Verifiable current facts vs. unknowns

### Verified in this session’s evidence

- Uploaded demo source exists locally and its audit gives exact SHA.
- Main baseline stated by active draft packets is `d0f37b5c1864b796fc7b4224996bd917551af3e7`; re-fetch before using.
- PR #1248 fixture correction is published as a draft at head `305c203…`; full test class 24/564 and review clear; CI was running.
- PR #1249 Doctors table is published as a draft at head `013ac9d…`; local gates/review clear; backend CI shards pending.
- PR #1250 reorder workspace is published as a draft at `4f6f60…`; its Location filter is a separate uncommitted continuation, frozen with all local gates/browser proof green; final independent hash reviews pending.
- PR #1251 Billing search is published as a draft at `31b1b76…`; local gates and Design review clear, CI backend shards running.
- PR #1242 query-count failure was reproduced as minute-boundary cache-key instability; two-line local fixture-only fix exists, peer pin/publish pending.
- Latest GitHub list snapshot reported #1247/#1246/#1245/#1244/#1243/#1239/#1238/#1237/#1236/#1235/#1234/#1233/#1232/#1231/#1230/#1229/#1228/#1227/#1226/#1225/#1224/#1223/#1222/#1221/#1220 CI success, but all remain open drafts unless GitHub says otherwise.
- Latest GitHub list reported #1240 and #1241 CI failure, with cause not yet fetched; #1242 CI failure diagnosed above.

### Must be rechecked before action

- Current `origin/main` hash and exact open PR graph. It may have advanced.
- Whether any PR merged/closed after the captured list.
- Current CI for all PRs, in particular #1248/#1249/#1250/#1251.
- Exact reason for #1240 and #1241 CI failure.
- Billing peer review for the #1242 test-only patch.
- Final independent peer-review disposition for frozen Inventory Location-filter patch `9e1338a5…`.
- Whether the current demo HTML has a newer version than uploaded SHA.
- Current availability of any Claude/browser session. The artifact link was provided by the user, but this handover itself does not claim that Claude can access their authenticated account.

## 11. Suggested opening prompt for the next Claude session

> Read `docs/handover/2026-11-27/validation/handover-2026-11-27/HANDOVER-TO-CLAUDE.md` completely, then read its linked demo authority/census, the exact relevant gap matrix and the review packet for the highest-priority open slice. First verify fresh `origin/main`, all open PR heads/parents and exact CI. Do not assume the status snapshot remains current. Continue with the §9 order: #1248/#1132 blocker, #1242 fixture-only clock diagnosis, exact #1240/#1241 failures, then finish the already-running Inventory gate. Keep slices bounded, preserve owner-approved behavior and assertion scope, use synthetic data only, and report facts with exact hashes/results. The application-wide Clean Look makeover is incomplete; maintain the full page/phase ledger and do not claim demo parity without route/role/state screenshots and a recorded comparison.

## 12. Handover completeness note

This is intentionally paired with the source records and plans rather than flattening every old session transcript into one guessed narrative. Before any product change, read the exact linked plan and current code. If the source conflicts with this dated snapshot, current source + newest owner decision + current CI are authoritative, and the handover should be corrected with evidence.

## 13. Owner decisions 26-27 Nov

This is a transcript-derived decision register for 26–27 Nov 2026. The chat export available to this task contains the exact answer text but does not expose a timestamp for every earlier reply. Accordingly, dates are the day recorded by the contemporaneous handover/publication packet; where the exact day could not be independently tied to a packet, the entry is explicitly marked **26–27 Nov (day not separately timestamped)**. No time-of-day is invented. Exact wording below preserves the owner’s answer; the linked PR/work packet supplies the object and limits of that decision.

| Date | Exact owner wording | Recorded scope / boundary |
|---|---|---|
| 26 Nov | “Lulus commit, push dan PR token (disyorkan)” | Publish the already reviewed Clean Look Card/Sheet token slice; three files; no authorization/assertion/schema change. |
| 26 Nov | “Lulus pengasingan pautan berizin dan ujian baharu (disyorkan)” | STK-46: separate the cost-entry link from the general link list and assert `referenceData.canFillCosts`; retain expected 16 general links. |
| 26 Nov | “Lulus token warna dan expectation tersebut (disyorkan)” | Dispensary/DD57 + DARK1 S1-007 semantic colors for warning/allergy/success/error/info. |
| 26 Nov | “Lulus gaya scan dan sent-back (disyorkan)” | Dispensary DSP17/DSP23: blue primary/ring scan hint and semantic sent-back wash; keep focus/status/accessibility and behavior. |
| 26 Nov | “Lulus breakpoint dan kad servis (disyorkan)” | Dispensary layout: three columns from xl only for Dispensary (Invoices stays lg); service card styling; retain non-OTC and three-section behavior. |
| 26 Nov | “Lulus penyelarasan ini (disyorkan)” | TRI-2: keep existing Not ready flag; red Waiting stays in emergency lane; reject new flag; Take neither calls nor removes flag; alarm quiet only while assigned doctor remains; preserve history. |
| 26 Nov | “Lulus pelaksanaan aktivasi dan ujian sintetik (disyorkan)” | Build staged activation instructions and test only synthetic data; label system observation time distinctly from clinical onset; Seen waits until all branch alerts verified; no production activation approved. |
| 26 Nov | “Lulus migrasi tepat dan pelaksanaan izin sedia diluluskan (disyorkan)” | TRI-2 exact `EXACT-MIGRATION.php.txt` hash `dfff7e9c…ab6`; append-only lifecycle/event history, versions/retries/claim protection, exact already-approved `queue.emergency.respond.branch` resident_doctor grant; `down()` preserves data/grant; migration alone does not activate old alerts. |
| 26 Nov | “Lulus tiga kumpulan backend A–C (disyorkan)” | TRI-2 backend assertions: red carry-over priority; reject new flag while red and preserve alert; add seven state/version fields to list payload with no patient detail. |
| 26 Nov | “Lulus dua fixture dan ujian positif (disyorkan)” | BIL-38 two named test fixtures; remove `prices.publish.organisation` only from synthetic Director in those tests, keep 403/rollback assertions and add positive Director cases. |
| 26 Nov | “Lulus expected table untuk kedua-dua ujian (disyorkan)” | Add the two previously approved STK-46 cost permissions to Store Admin expected-role table; do not loosen staff/access limits. |
| 26 Nov | “Lulus satu assertion token semantik (disyorkan)” | One Dispensary attention panel semantic-color expectation; status/message/action/dark-mode checks retained. |
| 26 Nov | “Lulus commit dan push spec PR #1119 (disyorkan)” | Publish the reconstructed/reviewed design spec on PR #1119; no application, permission or baseline changes. |
| 26 Nov | “Lulus pembetulan dan perlindungan tambahan (disyorkan)” | TRI-2 `TriageServiceTest`: UPDATE/DELETE remain P0001; ordinary TRUNCATE gets 0A000 from new FK; CASCADE refusal explicitly tests append-only message/history with synthetic data. |
| 26 Nov | “Lulus empat kumpulan frontend (disyorkan)” | TRI-2: shared-layout banner; 5s poll/10s sound; “I’ll see this patient”/Seen; visible persistent alert instead of Minimise and max 2-minute device mute; preserve no identity, no Call In, errors and return to consult. |
| 26 Nov | “Lulus commit, push dan PR Dialog (disyorkan)” | Separate Dialog one-file target/title spacing repair; 44px close target; no assertion/schema/grant change. |
| 26 Nov | “Lulus satu assertion prop Invoice (disyorkan)” | Treatment-room completed invoice retains page and may receive `treatmentRoomUrl?: string | null`; permission-bound link only. |
| 26 Nov | “Lulus penerbitan Q16a (disyorkan)” | Commit/push Q16a continuation to PR #1121, preserving merge order; TRI-2 excluded. |
| 26 Nov | “Lulus penerbitan Q16b (disyorkan)” | Commit/push Q16b continuation to PR #1123, including `<1 min`; TRI-2 remains separate. |
| 26 Nov | “Lulus penerbitan Q16c (disyorkan)” | Commit/push Q16c continuation to PR #1126 with patient header, units, allergy warning and late-response guard; no new permission/migration. |
| 26 Nov | “Lulus assertion lane red (disyorkan)” | QueueWaitingOrderTest expects red in `emergency.data`; other order and Call next priority retained. |
| 26 Nov | “Lulus tiga had dengan ujian N+1 (disyorkan)” | Queue performance query limits 22→24, 19→21, 19→21 with bounded lane/scope cost, hard `<26/<25` limits and explicit 1-vs-26 regression. |
| 26 Nov | “Lulus dua expected markup (disyorkan)” | Add `<EmergencyAlertHost />` to Clinic/Admin layout expectations; keep one main, one Toaster and no V2 assertions. |
| 26 Nov | “Lulus satu had autoriti (disyorkan)” | QueueDirectoryTest staffing-branch assignment query bound 2→3 for one constant lane authority check; no permission relaxation and N+1 test retained. |
| 26 Nov | “Lulus assertion pagination bebas (disyorkan)” | Queue frontend payload assertion accepts multiline `appliedFilters`/`page` and adds `emergency_page: emergencyPage`; search/date/draft protections stay. |
| 26 Nov | “Lulus penerbitan BIL-38 (disyorkan)” | Publish the reviewed 11-file BIL-38 Director-publication draft on its feature branch; no staff authority change; merge only with exact green CI/main ancestry. |
| 26 Nov | “Lulus tiga had query (disyorkan)” | TRI-2 tab-count performance expectations 30→32, Registration 18→20, Queue 16→18; no per-row query growth, data mutations or weakened hard cap. |
| 26 Nov | “Lulus kontrak payload lane (disyorkan)” | Add Emergency to top-level expected keys and complete empty metadata including `perPage=25`; preserve other counts/scope. |
| 26 Nov | “Lulus pemisahan lane dan skop (disyorkan)” | CombinedQueueSummaryTest expects red in emergency lane and empty lane for doctor without scope; other privacy/summary assertions stay. |
| 26 Nov | “Lulus penerbitan Registration (disyorkan)” | Publish nine-file Registration Clean Look restyle/assertion packet to its existing feature branch; no Emergency Contact, Family or TRI-2 bundling. |
| 26 Nov | “Lulus penerbitan fixture e-mel (disyorkan)” | Publish one-line synthetic email-verification fixture correction (user ID + 1 instead of fixed 123); authentication logic unchanged. |
| 26 Nov | “Lulus assertion dan semakan Cancel (disyorkan)” | TRI-2 unsaved-work Cancel handler closes dialog/cancels unsent Take, keeps draft; test must prove no reset/request. |
| 26 Nov | “Lulus penerbitan Consultation (disyorkan)” | Publish three-file Consultation presentation slice with previously approved style expectations; no TRI-2/Treatment bundling. |
| 26 Nov | “Lulus penerbitan Triage (disyorkan)” | Publish one-file Triage PageFrame/token slice; submit logic, authorization and assertions unchanged. |
| 26 Nov | “Lulus penerbitan Homes (disyorkan)” | Publish three-file Homes slice; shared CSS may affect other Spaces; no assertion/permission/migration change; exact CI required. |
| 26 Nov | “Lulus penerbitan PNL-30 (disyorkan)” | Publish PNL-30 continuation to #1124 with reviewed partial-success wording/timeout handling; no permission changes. |
| 26 Nov | “Lulus PR saiz kawalan (disyorkan)” | Publish two-page 44px mobile/tablet, 36px desktop filter-control size fix; script/assertions unchanged. |
| 26 Nov | “Lulus penerbitan Inventory (disyorkan)” | Publish 11-file Inventory Clean Look restyle with the previously exact-approved STK6 assertion; STK-46 excluded. |
| 26 Nov | “Lulus penerbitan Dispensary (disyorkan)” | Publish nine-file Dispensary Clean Look presentation packet with approved Show/partials/style assertions; no clinical logic change. |
| 26 Nov | “ya saya authorize semuanya to you and also be me until i say im back, for now please continue everything that we already start and approve all for me, make sure we have the backend and frontend for our system align with the artifact as well and the design that already lock.if want to speed up and add more agent pun you can add yourself” | Broad continuation/approval delegation and artifact-alignment goal. This does not let an assistant operate a Claude session or bypass tooling/repository controls; explicit safety/product constraints remain. |
| 26 Nov | “tolong make sure semua bekerja ya supaya cepat siap, saya bagi awak approve semua” | Continue authorized work and report progress; no hidden background work may be claimed. |
| 26 Nov | “saya nak awak sambung je terus tanpa saya bagitau, jadi agihkan je bila task mereka settle, jadi kerja akan terus je berjalan walaupun saya tak bagi sambung” | Request for continuous continuation/delegation. Session/tool limits mean work cannot run after the turn ends unless a real automation/task exists. |
| 26 Nov | “saya dah allowkan awak, jadi reset je bila dah abis limit. teruskan je kerja tanpa henti tanpa sambung dari saya” | Requested usage-limit reset/continuous work. No reset capability was available; the settings shown by the owner state resets are never used automatically and require explicit in-conversation request when the limit is ≤10%. No reset is claimed here. |
| 26 Nov | “teruskan lagi dan jalan tanpa approval saya, bagi updates sekali dan progress.” | Continue within approved scope and provide progress. Exact assertion changes/new permissions/migrations remain subject to their recorded bounded approvals. |
| 26 Nov | “Stop. Correct how you work, before anything else.” | Require honesty about one assistant/one chat; Malay replies; one screen/backend slice at a time; inspect paths before editing; plan/files/code/tests/commands/self-review/commit message; factual statuses; stop on specified risky changes. This remains a governing workflow instruction. |
| 26 Nov | “setiap kali nak tukar reasoning high ke apa bagitau ya saya tukarkan” | Tell the owner before changing the reasoning setting; no autonomous model/usage-limit reset claim. |
| 27 Nov | “ya saya luluskan, sambung semua” | Explicitly authorizes publication of the exact three-file Registration Settings packet reviewed in `registration-settings-composition/REVIEW.md` (commit `3bf91447`); it does not authorize unrelated files or merging. PR #1240 is a draft; current CI must be diagnosed/rechecked. |
| 27 Nov | “saya nak awak makesure buat handover yang detail seperti mana awak dapat handover haritu, jadi pastikan ianya sangat2 detail dan terperinci. supaya claude dapat faham semuanya terus dan boleh teruskan tanpa perlu mengagak apa2 phase” | Produce a detailed phase/status/evidence handover for Claude. |
| 27 Nov | “Push the handover and its evidence to GitHub so a Claude session can read them.” “Create branch docs/handover-2026-11-27 from origin/main.” “Commit, push the branch, and reply with the branch name and commit SHA.” “Do not open a PR.” | Current exact docs-only publishing instruction. Paths are recorded in repository-relative form to follow the owner’s path-rewrite requirement. This row records the instruction itself, not a product feature decision. |

## 14. Open PR inventory #1150–#1251 and scope provenance

**State basis:** open-PR checkpoint file at `validation/open-pr-status-latest.json` (26 Nov 22:47 UTC), publication/checkpoint packets dated through 27 Nov, main ancestry at `d0f37b5c…`, and a successful listing through #1251 captured around 27 Nov 03:42 UTC in the prior handover work. Current repository API access was unavailable in this transfer task. Main shows #1163 and #1194 are merged, so they are excluded from the “open” table even though an older cached open snapshot still listed them. #1194 is fixture-only but merged; #1163 is a test-clock fix and merged. Exact current state/checks must be refreshed before any release work.

**Classification meaning:** “Owner-approved scope” means a contemporaneous explicit owner decision applies to that named behavior/scope. “My own initiative” means the bounded slice was selected under the owner’s broad continuation delegation, but no exact scope approval was found in the captured decision record. It is not a claim that the feature was unauthorized. “Fixture-only” means the PR changes synthetic test/fixture behavior only, not production behavior. These labels describe scope provenance, not merge readiness.

| PR | Scope | Classification |
|---:|---|---|
| #1150 | STK-46 retrospective valuation and report notices | Owner-approved scope |
| #1151 | Queue bounded Held/attention workload reads | My own initiative |
| #1152 | STK-46 cost entry/checking interface | Owner-approved scope |
| #1153 | Queue guarded workload fetch client | My own initiative |
| #1154 | STK-50/51 purchase and invoice approver narrowing | Owner-approved scope |
| #1155 | DSP-28 checked returns/month and controlled filters | Owner-approved scope |
| #1156 | Queue scoped workload tabs and bounded pagination | My own initiative |
| #1157 | Q16e scoped daily average wait | My own initiative |
| #1158 | Dispensary compact medicine details while preserving drafts | My own initiative |
| #1159 | Preserve active implementation checkpoint/delegated decisions | Owner-approved scope |
| #1160 | Billing collection search and due-date filters | My own initiative |
| #1161 | Q16d bounded Rooms today view | My own initiative |
| #1162 | Dispensary patient context and summary rows | My own initiative |
| #1164 | AUTO1c scoped doctor-availability warnings | Owner-approved scope |
| #1165 | Connect closing pages to Billing workspace | My own initiative |
| #1166 | Registration workspace context/accessibility help | My own initiative |
| #1167 | Dispensary case help with safety warnings retained | My own initiative |
| #1168 | Stock Batch & Expiry mobile readability | My own initiative |
| #1169 | Queue authorized treatment calls while consultation held | Owner-approved scope |
| #1170 | Billing workspace presentation recovery | My own initiative |
| #1171 | Patient directory search pills/safe clear | My own initiative |
| #1172 | Treatment-room approved-record read projections | Owner-approved scope |
| #1173 | Dispensary medicine-label preview context | My own initiative |
| #1174 | Patient Edit unsaved-change review/undo | My own initiative |
| #1175 | Completed Dispensary case context | My own initiative |
| #1176 | Stock transfer requests and persistent details | Owner-approved scope |
| #1177 | Patient Create actual requirements/guardian guidance | My own initiative |
| #1178 | Billing mobile work readability | My own initiative |
| #1179 | Dispensary return disclosure target sizing | Owner-approved scope |
| #1180 | Treatment-room evidence recording/review and bounded pages | My own initiative |
| #1181 | Optional completed Dispensary guidance | My own initiative |
| #1182 | TRI-2 emergency lifecycle and bounded alarm ranking | Owner-approved scope |
| #1183 | Stock Counts-due worklist with blind-count safeguards | My own initiative |
| #1184 | Explain branch Dispensary settings | My own initiative |
| #1185 | TRI-2 bounded emergency lane/board exclusion | Owner-approved scope |
| #1186 | Registration review of current choices | My own initiative |
| #1187 | Billing loaded-month cash-closing filter | My own initiative |
| #1188 | TRI-2 bounded emergency doctor poll presence | Owner-approved scope |
| #1189 | Stock count case worklist/approval context | My own initiative |
| #1190 | Registration single form-owner extraction | My own initiative |
| #1191 | Dispensary settings change attribution | My own initiative |
| #1192 | Panel proposal review context/responsive actions | My own initiative |
| #1193 | TRI-2 shared emergency alerts/safe Hold continuation | Owner-approved scope |
| #1195 | Authorized users link to waiting Dispensary cases | My own initiative |
| #1196 | Stock batch safety-holds worklist | My own initiative |
| #1197 | Accessible Dispensary settings switch/branch context | My own initiative |
| #1198 | Panel follow-up scope/responsive claim actions | Owner-approved scope |
| #1199 | Paginated emergency lanes with existing board actions | Owner-approved scope |
| #1200 | Dispensary review patient/case context | My own initiative |
| #1201 | Dispensary Review medicine/service/return cards | My own initiative |
| #1202 | Stock write-off cases worklist | My own initiative |
| #1203 | Panel claims/follow-up/payment page navigation | My own initiative |
| #1204 | Q16f bounded Calls-today TV statistics | My own initiative |
| #1205 | Registration guarded board dialog | My own initiative |
| #1206 | Dispensary checked-return outcome filter | My own initiative |
| #1207 | Verified receiving/refused-draft preservation | Owner-approved scope |
| #1208 | Panel Queried claims view | My own initiative |
| #1209 | Registration existing fields in four demo sections | My own initiative |
| #1210 | Dispensary To-prepare case-type filter | My own initiative |
| #1211 | Registration unconfirmed summary state | My own initiative |
| #1212 | Dispensary A/B queue-number search | My own initiative |
| #1213 | Queue reviewed-foundation integration validation only | My own initiative |
| #1214 | Queue reviewed workspace presentation on validation foundation | My own initiative |
| #1215 | Queue bounded workload reads/outcome policy at scale | Owner-approved scope |
| #1216 | Purchase-order delivery date/supplier reference | Owner-approved scope |
| #1217 | Dispensary list handoff ordering correction | My own initiative |
| #1218 | Panel waiting-payment read view | My own initiative |
| #1219 | Registration pending/stale search response state | My own initiative |
| #1220 | Queue workload tabs/safe paging/context resets | Owner-approved scope |
| #1221 | Registration guarded Find/New patient controls | My own initiative |
| #1222 | Dispensary current saved-medicine-name search | My own initiative |
| #1223 | Queue authorized room provenance/next-patient context | Owner-approved scope |
| #1224 | Registration quick-patient field groups/dialog visibility | My own initiative |
| #1225 | Dispensary waiting returns calendar-day age filter | My own initiative |
| #1226 | Inventory purchase-order worklist/persistent drafts | Owner-approved scope |
| #1227 | Panel claims awaiting first submission | My own initiative |
| #1228 | Registration Contact/Address headings | My own initiative |
| #1229 | Queue own Waiting/Seeing columns | Owner-approved scope |
| #1230 | Supplier invoice matching/scoped amounts | Owner-approved scope |
| #1231 | Dispensary preparation-case Doctor filter | My own initiative |
| #1232 | Panel follow-up exact-status filter | My own initiative |
| #1233 | Queue Held treatment-step columns | Owner-approved scope |
| #1234 | Queue treatment steps with recorded outcomes | Owner-approved scope |
| #1235 | Dispensary existing visit-context disclosure | My own initiative |
| #1236 | Supplier Returns Open/Done filter | Owner-approved scope |
| #1237 | Panel remittance payment workspace | My own initiative |
| #1238 | Queue checkout time in own Done | My own initiative |
| #1239 | Dispensary Returns workspace navigation | My own initiative |
| #1240 | Registration Settings composition, exact three files | Owner-approved scope |
| #1241 | Dispensary Returns help panel | My own initiative |
| #1242 | Queue Medicine-check line count/sender | My own initiative |
| #1243 | Panel recorded-price decision status filter | My own initiative |
| #1244 | Queue branch Waiting/Serving columns | My own initiative |
| #1245 | Supplier Return request worklists | Owner-approved scope |
| #1246 | Queue branch Held treatment step | My own initiative |
| #1247 | Queue branch Done checkout destination | My own initiative |
| #1248 | Trial-data Director fixture for past-day test clock | Fixture-only |
| #1249 | Queue branch Doctors table/room/waiting context | My own initiative |
| #1250 | Inventory low-stock reorder workspace | My own initiative |
| #1251 | Billing Finance/Panel responsibility-list search | My own initiative |

PR gaps are intentional where the range has no open PR: #1163 and #1194 are merged on the `origin/main` ancestry captured for this branch. The standalone #1242 **test-only clock-freeze candidate** is not yet a PR at this checkpoint, so it is not falsely counted as an open PR.

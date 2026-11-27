# Dispensary workspace slice1 — review packet

Implemented the uploaded Pharmacy workspace's count-card and contextual-panel layout around the existing Dispensary list. The cards display real server counts and use the existing tab GET; Returns links to its existing protected route only for the existing branch-view permission. The help panel can be hidden and has native keyboard disclosures. Narrow screens show patient cards; desktop has the work column and280px context column. No app mutation, new filter, clinical state or authority was introduced.

Worktree `/workspace/KPOne-dsp-workspace`, branch `feature/dsp-workspace-demo-parity`, pinned main `c1e5fc05c0d231a28eeb3f2367ccc8adee0565c5`. Three-file package: Index.vue, new component-runtime tests and TEAM.md delivery entry. Patch `DSP-WORKSPACE-SLICE1.patch`, SHA256 `ca246c5935cdde2280d2c975eab5463f6df57fc1c1fad896b46eb2f39d18a7b0`; exact source hashes in `source-manifest.json`. No commit/push/PR.

## Scope and authority

Read `PLAN.md` and full audit `/workspace/kpone-validation/dispensary-demo-audit/GAP-MATRIX.md`. Owner/coordinator authorized implementation26Nov2026. Staff Design accepted bounded plan. Existing three real states remain; Verify completes case, return checking stays on case; no all-branch grant, Ready state, follow-up or preselected reasons. Source comparison confirms the original script is whitespace-identical after removing the new presentation-only usePage/Link imports and computed/ref block; existing search/tab/page/poll/row helpers are untouched. Existing test assertions were not edited.

Partial parity is explicit: existing supported search only; no demo Type/Doctor filters or invented clinical row metadata. Native/shared tabs and44px mobile controls follow accepted application accessibility rules rather than copying undersized demo controls.

## Validation

- New Vue custom-renderer tests3/3: count changes and selected state follow fresh props; actual card click invokes existing GET retaining search/resetting page; readonly viewer gets no Returns link; help hide removes aside and restore issues no navigation.
- Existing DSP27 assertions5/5 unchanged. `focused.log`.
- Full frontend final2206/2206, correct PHP PATH and bounded concurrency4. `frontend-final.log`. Initial run retained in `frontend.log`: missing PHP executable PATH and an unrelated Visit Reason picker timing failure; both passed focused rerun (`frontend-followup.log`) and two subsequent full runs. No tests weakened.
- Full `npm run types:check` pass (`types.log`); final production build pass (`build-final.log`). Local ignored font assets copied for browser review; initial build notes these runtime font paths are not bundled. No dependency/source asset changes.
- Scoped ESLint, Prettier and `git diff --check` pass; `lint.log`, `format.log`.
- Actual Laravel preview3069, own synthetic `kpone_dsp_workspace_test` clone only. Effective DB verified127.0.0.1:55439 before HTTP. No shared DB writes, migrations, seed reset or clinical state-changing actions. Seven browser states pass with no page errors/no page overflow:390/1440 light/dark workspace, helper hidden, doctor-check tab, empty search. Mobile visible controls≥44px; desktop≥36px. `browser-results.json` and matching PNGs. Actual GET card selection/check note/search empty and helper restore exercised.
- PHP/backend/migration suites not rerun for this three-file frontend/docs slice; no backend or schema changed. Full integrated release gates remain coordinator-owned.

## Self-review and remaining shared issue

First capture exposed inherited32px desktop Open case and pagination controls; scoped minimum36px fixed them. KPI grid now matches demo auto-fit150px minimum rather than stacking three full-width cards on390. Scope stays within Index.

Desktop rail highlights Today/Home on `/dispensary`. This is inherited main behavior: `WorkspaceRail.vue` currentKey selects longest matching menu link, defaults to today; `ShellMenu.php` Dispensing link is `/registration?tab=dispensary`, so `/dispensary` has no matching menu link. Both files are unchanged in this packet. Reported to Design/coordinator as shared navigation debt; no local workaround or route change.

The synthetic populated fixture has one OTC pending case and zero items, so captures prove rendering and true counts, not every case status with populated rows. Prior list tests/actual source preserve those row paths; full role/lifecycle behavior is outside this readonly presentation slice.

## Suggested commit / PR

`style(dispensary): compose workspace counts and contextual help`

> Compose the Dispensary workspace with live count cards, permission-gated Returns navigation and responsive contextual help. Card selection uses the existing list query; search, polling, case/label actions and the three established states stay intact.
>
> Add component-runtime coverage for count updates, real navigation, permission visibility and help disclosure. Validation:2206 frontend checks, full types/build, scoped lint/format and seven actual synthetic browser states at390/1440 in light/dark. Existing assertions unchanged. This is a bounded full-demo presentation slice; broader filters and workflow differences remain separate.

Independent Design review: `INDEPENDENT-REVIEW.md` records actual source and seven screenshots inspected plus new runtime tests independently3/3. No local blocker; inherited shell selection issue remains separately recorded. Package reverse-apply check and final exact three-file git status pass. `NEXT-CASE-SLICE.md` is preparation only, outside this patch.

Publication follow-up: draftPR1140 exact head `bd6997e9d9a77928d2635f315eade4b8886f3123`. Initial CI static lint failure reproduced as13 style errors only in the new test harness; braces/spacing corrected without assertion/application changes. Full repository lint exit0, resource format pass, all2,206 frontend tests pass, focused8 pass. Normal push, no merge. Original patch plus `CI-LINT-FOLLOWUP.patch` describes final source; fresh exact-head CI is pending.

## Returns navigation authority correction

Reviewing the actual return directory service found a defect in the initial1140 link guard: `dispensary.view.branch` alone permits the CA list but does not permit the return directory. The latter also uses existing `canCheckReturns` (CA Supervisor plus inventory.variances.approve.branch). The first presentation review did not detect that mismatch; its permission claim is superseded by this section.

The controller now emits `canViewReturns` by reusing that existing policy plus branch-view permission after the list service validates the request. Vue consumes the server boolean and no longer infers access from shared permissions. Added two HTTP regressions: CA receives false and endpoint403; CA Supervisor receives true and endpoint200. New component harness tests consume the server boolean. No legacy assertions or permissions change. Actual browser confirms the same two actors and successful supervisor link navigation (`navigation-browser.json`), and all seven responsive captures were refreshed.

Scope is now five files: existing Index/new runtime/TEAM plus controller capability projection and new HTTP test. This is a navigation correctness fix within the same slice, not a new return workflow. Types/build, controller PHPStan, scoped lint/Pint and focused frontend pass; focused PHP18/334 assertions pass. Filter-only PHPUnit collection triggers unrelated duplicate Contention-suite warnings and exits1 despite passing assertions; final gate uses the intended `--testsuite Feature` selection, with event log retained. Independent authority review is required before push.

Final publishedhead825582b34536071e7ace1e9e545b02c61d49f341 passes all six CIchecks (fresh API `ci-final.json`, watcherexit0). Independent authority review and exactheadlocal checks are clear. Draft remains unmerged.

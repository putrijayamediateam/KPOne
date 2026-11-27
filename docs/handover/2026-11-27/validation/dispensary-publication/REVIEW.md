# Pure Stage 1 Dispensary publication candidate

Candidate: `/workspace/KPOne-dispensary-publication`, branch `feature/clean-look-dispensary-publication`, based exactly on current-main snapshot `bd9a42331d72cf1d2b54ad72b5671e8913d0a483`. No commit, push or PR has been created. This packet is for owner review before publication authorization.

## Problem and resulting behavior

The existing Dispensary case and shared doctor-check/attention partials retain legacy palette/field styles and reduce some tablet targets below44px. This candidate applies the approved Clean Look surfaces, blue scan hints, semantic warning/error/info states and36px desktop/44px below1024 controls. The case keeps its existing1500px frame, stacking patient → medicines/services → actions below1280; three columns start at1280 as explicitly permitted in current main's `docs/design/CLEAN-LOOK-SPEC.md:87`. Existing clinical form grouping/action order, routes, permissions, requests, reason rules and verification behavior remain intact.

Spec: main `docs/design/CLEAN-LOOK-SPEC.md` sections2–5, especially line87 case-layout exception and line93 controls. Original mapping and actual synthetic visual evidence: `/workspace/kpone-validation/dispensary-case/DESIGN-MAPPING.md` and `REVIEW.md`; partial mapping: `/workspace/kpone-validation/dispensary-partials/PLAN-AND-REVIEW.md`.

## Exact source scope

`DISPENSARY-STAGE1-publication.patch` SHA256 `562eb9cebe5751386a66be39a86c5e052d6d3a6b27022338b642bedc4fcd0fe1`: nine tracked files,74 additions/96 deletions.

- `resources/js/pages/Dispensary/Show.vue`.
- `resources/js/components/dispensary/DoctorCheckActions.vue`.
- `resources/js/pages/Clinical/Partials/DispensaryAttentionPanel.vue`.
- Six previously approved appearance tests: `dark-1`, `dd-57-dispensary-dark`, `ds-01b-3-otc-dispense`, `ds-01d-dispensary-layout`, `dsp-17-scan-pick-batch`, `dsp-23-doctor-check`.

No Dispensary functional feature, backend, type, route, role, migration, treatment-room, TRI2, shared shell or asset/token addition is included. The original DSP Index restyle and DSP28 changes are not part of this candidate. No whole integration snapshot replacement occurred. The reviewed Show patch applied cleanly to main; the two approved partial components also applied cleanly. DD57 is the exact union of the already approved Show expectations and the separately approved AttentionPanel surface assertion.

All three Vue file SHA256 values exactly equal their prior reviewed manifests. `source-manifest.json` records candidate/main hashes. `script-parity.json` and `normalized-script-parity.txt` prove both partial scripts byte-identical to main and the Show script byte-identical after normalizing only `inputClass`, `batchHintClass` and their adjacent explanatory comment. These two presentation strings are the sole script differences; all clinical behavior stays main's.

## Approval provenance

Coordinator relayed owner approval of the six Show assertion groups/ten style expectations before their original application; exact record: `/workspace/kpone-validation/dispensary-case/PROPOSED-ASSERTION-BATCH.patch`, original `REVIEW.md` and manifest. The later AttentionPanel-only DD57 assertion was separately approved; exact record: `/workspace/kpone-validation/dispensary-partials/PROPOSED-DD57-ASSERTION.patch` and final approval paragraph of `PLAN-AND-REVIEW.md`. This publication extraction changes no additional assertion. Existing unrelated main assertions remain intact.

DD57's earlier approved test title still says the attention panel retains its current colours; its assertion now uses the separately approved semantic warning tokens. Kept the approved title rather than introducing an unrequested test change.

## Candidate validation

-118/118 case-focused tests and28/28 partial-focused tests passed (overlapping suites, not146 unique tests) on this exact main-based candidate; exact command inherited from `/workspace/kpone-validation/dispensary-case/focused-command.txt`, result `focused.log`.
- Scoped Vue ESLint and Prettier passed (`lint.log`, `format.log`). `git diff --check` passed.
- Main behavior script parity passed, as above. Reviewed Vue source hash parity passed.
- Full typecheck and production build PASS on this exact candidate (`types.log`, `build.log`; Vite10.46s), run serially after Billing, Staff and Inventory. Local generated Wayfinder actions/routes created in this worktree; vendor is a local copy with no-script autoload regeneration. No `.env` used.
- Original reviewed source has32 case screenshots and25 partial screenshots with actual synthetic flows and measured36/44px targets. These are source-identical historical visual evidence, not a claim of new main-based browser execution. Original independent visual review found no blocking issue.

## Remaining visual risks and limits

Inherited inner four-column medicine fields remain dense at1440; the approved slice does not redesign clinical field grouping. Partial evidence originally had a baseline shared-dialog close-target gap outside its scope; current main's independently published dialog changes remain untouched. No feature/UI-state changes justify repeating clinical mutations merely for this extraction. Full backend/migration checks are not rerun for a source-identical presentation-only candidate with no PHP/schema changes; coordinator publication gates remain separate.

Independent main-extraction review assigned to Patients & Registration; pending closure. No dependency makes this pure extraction unsafe: the required semantic tokens and approved case-layout spec are already main. The nine-file diff is ready for owner review; publication remains unperformed.

## Suggested commit and PR

Commit: `style(dispensary): apply approved Clean Look case and partials`

PR title: `Restyle Dispensary case and doctor-check partials`

PR body:

> Apply the approved Clean Look case surfaces, field/focus tokens and semantic warning states. Keep tablet controls44px and desktop controls36px; start the existing three-column case layout at1280px. Doctor-check reasons, permissions, actions and clinical behavior remain unchanged.
>
> Includes only three Vue files and six previously approved appearance-test updates. Both partial scripts match main byte-for-byte; the case script differs only in two presentation class strings and a comment. Validation:118 focused tests, scoped lint/format and script/source parity; full typecheck and production build passed. No backend, permission, migration or unrelated feature changes.


## Owner-authorized publication update

Owner explicitly approved commit/push/new PR for the exact nine-file packet. Fetched origin/main before commit: still `bd9a42331d72cf1d2b54ad72b5671e8913d0a483`. Staged and committed diff SHA256 remained `562eb9cebe5751386a66be39a86c5e052d6d3a6b27022338b642bedc4fcd0fe1`.

Normal push succeeded. PR: https://github.com/putrijayamediateam/KPOne/pull/1139. Head: `6de9c339f4dcb3af747a0d3bd801640a8d158885`. Worktree clean. No force push or merge. Independent closure is `INDEPENDENT-REVIEW.md`: no scoped blocker,24 changed-test checks independently pass, approval union/script/source parity verified.

Initial CI run45767690367 failed at “Attest pull-request checkout and integration”; static/backend jobs were skipped and aggregate CI failed. Job API confirms the failing step; annotation gives only exit1. Downloading detailed logs returned403Forbidden, so the precise cause is not established. This is not reported as an application test failure or a green CI run. Coordinator notified; no workflow or source change made in response.


## Normal main refresh and attestation recovery

Coordinator authorized a normal refresh after main advanced. Merged `c938a9724a0de82d3bc08700442cffe7cff58f8c` without conflict and pushed normally, no force. New PR head: `30ce29ad0a315b58f8495393737af6e5296b913f`. All nine approved file hashes and main-relative patch SHA256 `562eb9cebe5751386a66be39a86c5e052d6d3a6b27022338b642bedc4fcd0fe1` remain unchanged. Reran118 case-focused and28 partial-focused tests: all pass. Diff check passes; no unchanged full build repeated.

Fresh CI run45768175955: attestation **SUCCESS**, including “Attest pull-request checkout and integration”. `refresh-attest.json` records the exact job evidence. Downstream static/backend checks remain separate; no merge performed. The original attestation failure's exact cause remains unproven, despite the successful normal refresh.

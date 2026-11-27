# Panel follow-up context — bounded review

Two-file candidate: resources/js/pages/PanelClaims/FollowUp.vue and new tests/Frontend/panel-follow-up-context.test.mjs. Original base d783621e38b3de57c11620526e7a8e5f1a68548d. Frozen patch SHA256 30c911ecfe4ea7c5e8e17b1e4c011bac4e479b151bbb7a7d3517e73d5bc90d54; manifest.json pins both files. Full Vue script is byte-identical to main. No backend, route, policy, permission, migration, monetary rule or existing assertion changed.

The existing paginated list gains a responsive context column, explicit filtered total versus loaded page count, local 36/44px action targets and native guidance. Existing patient/claim/invoice identity, supplementary relationship, masked actor display, last action, per-Panel threshold, status and exact amountLabel/amountSen remain intact. Claimed-awaiting-decision and unpaid are not summed or relabelled. The filtered empty message now names the selected Panel, while the unfiltered empty copy remains unchanged. No new Overdue link or inferred navigation authority is added.

Corrected analysis: the OPEN constant includes prepared, but the complete PNL-28a query excludes prepared claims that have never been submitted. The initial OPEN-only inference was incomplete and superseded before final publication. Final product copy plainly sends never-submitted prepared claims to Overdue and describes this list as following previous submission. The new rendered fixture is Submitted, not an impossible never-submitted prepared row. Eligibility remains entirely unchanged.

Validation: 7 focused new+existing tests passed; no existing assertions changed. Full 2,208 frontend tests, types and build passed before the final copy/NEW-fixture correction; final 7 focused, scoped lint/format and rebuilt production assets passed after that correction. New rendered tests cover exact claimed/unpaid labels including zero, truthful filtered/unfiltered empties, loaded-versus-total scope, long identifiers, supplementary identity and focus classes. No new full backend suite or migration/seed run: no backend/schema source changed.

Actual browser at 3148 uses the Billing-owned synthetic database and existing domain services. One pre-existing synthetic Panel responsibility was approved within a synthetic configured limit and its claim submitted; no roles or permissions were changed. A local external router simulates 45 elapsed days so the real follow-up service selects the claim without rewriting immutable event timestamps. Database sessions are used because file-session mtimes follow the real host clock and expired under this simulated clock; this was a fixture/runtime issue, not a product fix. Branch setup uses the existing CSRF-protected branch-context endpoint; this does not certify the shell's branch-switch UI.

Submitted-state evidence covers 390/768/1440 light/dark, actual Panel filter navigation to a scoped empty state and reset, focus on the claim link, no document overflow, and measured 36px desktop / 44px below 1024 for back link, native filter, claim link, pagination and help summary. One real claim/page means multi-page navigation was not exercised. Long identifiers and unpaid-zero are rendered-test coverage, not claimed as actual database scenarios. Accepted-state evidence is captured separately after the existing decision service, retaining the same monetary amount with the authoritative Unpaid label.

This is a bounded follow-up composition, not full six-tab Panel demo parity. Shared shell, claims eligibility, mutation flows and operational rollout remain unchanged. Captures, fixture scripts and local clock router stay outside the commit. Root owns attachment and serial merge.

Current-main preparation: candidate fast-forwarded normally to f8dd19cb83a6129479246db1c62d0af12cd28fb5 before publication. Upstream adds SUG settings only, with no owned-file overlap. Exact patch and both file hashes remain unchanged (current-main-parity.json). Fresh 14 focused tests, including both upstream SUG test files, pass on this actual candidate. Browser/full-suite evidence remains attributed to the preceding d783 runtime; the identical owned page was not recaptured after that unrelated main refresh. Accepted-state browser completed at 390/1440 light/dark, with Unpaid RM 10 and the newer action's 44 elapsed days. Total 20 captures; no document overflow.

Commands (working directory `/workspace/KPOne-panel-follow-up-context`):

- `PATH=/workspace/kpone-tools/bin:$PATH npm run types:check`
- `PATH=/workspace/kpone-tools/bin:$PATH node --test tests/Frontend/*.test.mjs`
- `node --test tests/Frontend/panel-follow-up-context.test.mjs tests/Frontend/pnl-4-follow-up-list.test.mjs`
- Current-main focused run adds `tests/Frontend/sug-1a-diagnosis-review.test.mjs tests/Frontend/sug-1b-prescribing-phrases.test.mjs` to that command.
- `npx eslint resources/js/pages/PanelClaims/FollowUp.vue tests/Frontend/panel-follow-up-context.test.mjs`
- `npx prettier --check resources/js/pages/PanelClaims/FollowUp.vue tests/Frontend/panel-follow-up-context.test.mjs`
- `PATH=/workspace/kpone-tools/bin:$PATH npm run build`
- `git diff --check`
- External synthetic capture commands: `node /workspace/kpone-validation/panel-follow-up-context/capture.mjs` and `capture-accepted.mjs` in the same directory. The submitted capture is not blindly rerunnable now: the fixture was subsequently accepted through the domain service. Reuse recorded artifacts or construct a separate synthetic fixture.

Published draft PR1198: https://github.com/putrijayamediateam/KPOne/pull/1198. Head6f941096c861e724d73fbd26561c0a2ffd3193e8, branch feature/panel-follow-up-context, base main f8dd19cb83a6129479246db1c62d0af12cd28fb5. Final independent Design source/visual review CLEAR at unchanged30c911ec; three new rendered tests independently rerun. Final accepted browser also proves real Tab from Panel filter to claim link and Enter navigation at1440/390. Root notified to attach; CI/serial merge remain separate.

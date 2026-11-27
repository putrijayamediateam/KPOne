# Queue and Registration filter targets — scoped followup

## Outcome/spec
Clean Look spec section4 Controls requires minimum36px from1024 and minimum44px below1024, including tablets (published reconstructed spec line93). Actual integrated smoke showed gaps. This minimal followup uses explicit h-9 and max-lg:min-h-11 on only the affected local controls; no shared token widening or workflow redesign.

Exact overlay FILTER-TARGETS.patch contains only Queue/Index.vue and Registration/Index.vue template class additions. Queue: room picker, search input, Doctor/Urgency selects, Apply/Reset. Registration: Apply/More. Both scripts byte-identical to baseline; listeners, values, permissions and conditions unchanged. No existing test assertions changed. No next reviewed Queue feature patch in the frozen integrated snapshot already fixes these targets: the measured snapshot includes Q16/TRI2 lane and still has the old h8/default/select/size-sm classes.

Isolated /workspace/KPOne-filter-targets copies the frozen CA snapshot as a validation dependency. Do NOT export its whole git diff: it contains the other integration features relative to main. Only the exact two-file overlay and before/after manifest are this slice. No edits to integration or publication trees, no commit/push.

## Before versus after
Before measurements come from actual integrated /workspace/kpone-validation/tri2-integrated-ui/responsive-policy-results.json, extracted in before-measurements.json. After measurements are own live app3057, browser-results.json and room-results.json.

| Control | Before390 | Before1440 | After390/768 | After1440 |
|---|---:|---:|---:|---:|
| Queue search / Doctor / Urgency |36|36|44|36|
| Queue My room today |36|36|44|36|
| Queue Apply / Reset |44|32|44|36|
| Registration Apply / More |44|32|44|36|

24 actual doctor/CA×Queue/Registration×390/768/1440×light/dark states passed size/overflow checks. Additional6 doctor-room states explicitly measure selected-room trigger. Focus on Apply and Queue search succeeds; settled shots show ring in light/dark. Zero JS errors in both runs. No clinical mutations issued; harness blocks non-login/search POST/PATCH/DELETE. Synthetic ownDB clone only; source kpone_ca_preview_test was read-only dumped to new kpone_filter_targets_preview_test, verified DB/port before HTTP. File cache is task-local; no .env used.

## Gates/self-review
243 existing tests across37 direct page consumers PASS; scoped Vue ESLint/Prettier PASS; full types/build PASS. No assertion updates needed. Source scripts retain byte parity and two-file patch is independently portable to matching integrated files. Visual review: mobile dark filter fields align and fill their row; desktop Registration actions align with36px fields; focus remains visible. Shared shell/emergency lane and unrelated board controls visible in screenshots are validation context, not new source scope or blanket certification.

Risk: future page merges must retain both current functional code and these local classes; whole snapshot replacement is prohibited. No backend regression expected from class-only changes; no new full backend suite run. Root integration gate remains separate.

Proposed commit: fix(ui): meet Queue and Registration filter target sizes
Proposed PR: Queue filters now retain44px targets through tablet widths, while Queue and Registration filter actions meet36px desktop minimum. Keep all handlers and permissions unchanged. Validation:243 focused tests, full types/build, lint/format and actual30 responsive/theme checks with measured targets and focus. No existing assertion changes.

Evidence note: room-check.mjs reuses the main harness and its console summary still says24; room-results.json is the authoritative six-state room-specific result. Combined total is24main+6room=30, not48. Reverse apply-check PASS and final source hashes unchanged. Patch SHA25680868bb20c92be443ac17189a634adb9a0fc01e58a6768a1c3ad0296b5086f7b.

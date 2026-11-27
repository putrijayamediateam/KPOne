# Dispensary case Clean Look — bounded review packet

## Outcome and scope
Restyles the existing Dispensary case in place using shared surface, field, status and focus tokens. Medicines/services/cards use quiet surfaces; confirmation uses informational blue; allergy and error states retain semantic meaning. Mobile/tablet controls remain at least44px, desktop36px. Outer three-column layout starts at1280px so1024–1279 stays readable. No route, permission, workflow, clinical field, action ordering or backend changes.

Plan/spec mapping: DESIGN-MAPPING.md records the actual Clean Look spec/demo mapping. Root accepted that mapping and owner approved all six existing assertion groups/ten style expectations in PROPOSED-ASSERTION-BATCH.patch. Those exact changes are applied. The DARK-1 OTC hunk was applied by exact replacements because this isolated base lacks unrelated Registration edits; no unrelated assertions were copied.

## Deliverable
DISPENSARY-CASE-CL1.patch contains Show.vue plus six approved test files only. manifest.json records base, SHA256 for every file and patch. Three already reviewed Surface token files are applied locally solely as preview/build dependencies and excluded from this patch; integration must contain that separately owned token slice. No package/lock/dependency changes, commit or push.

## Validation
-118/118 existing focused tests PASS. Exact25-file command is focused-command.txt; focused-approved.log records result.
- Full vue-tsc and production build PASS (types.log/build.log). Short production rebuild with exact Surface token dependency PASS (build-surfaces.log).
- Show ESLint, Prettier check and git diff --check PASS.
- Script parity PASS against original Show, normalizing only inputClass, batchHintClass and their explanatory comment. All clinical script bytes otherwise identical.
-32 settled screenshots: preparing at390/768/1024/1440 light/dark; unsaved, confirmation, doctor-check waiting, doctor view-only, OTC unconfirmed/confirmed at390/1440 light/dark. Actual isolated-DB send-for-doctor-check and OTC confirmation succeeded. Unsaved state disables send; doctor has no edit actions. Layout/overflow assertions passed; visible controls measured44px below1024 and36px desktop.

Browser harness correction: original run reached28 captures and all preceding assertions, then failed because harness searched for Record instead of actual Confirm. Resume used existing data-action, refreshed unconfirmed and captured confirmed (8 captures, zero JS errors). No repeat send was issued. browser-otc-results.json describes this resumed scope; its console summary inherited broader text and is not the evidence for earlier stages. Original run's global JS errors array was not serialized, so a whole-run zero-JS-error claim is deliberately not made. manifest.json records this limitation.

Database source was authorized synthetic kpone_ca_preview_test, read-only dumped to new kpone_dsp_case_preview_test. Actual database/port preflight passed before HTTP. Fixtures and mutations are confined to clone; root active cases untouched. No .env used.

## Self-review and risks
Reviewed mobile dark confirmation and desktop light preparation: labels, warning semantics, action wrapping, page reading order and required fields remain visible without horizontal overflow. Existing inner desktop clinical form density remains; this slice does not redesign field grouping. Clinical/Partials/DispensaryAttentionPanel.vue raw amber and DoctorCheckActions are deliberately outside this patch (existing DD57 pin retained). Separate independent reviewer requested before final acceptance.

No full backend rerun for this appearance-only patch: clinical script parity and existing focused assertions bound the risk, plus real browser state transitions. Root integration full gates remain integration evidence, not claimed as this isolated tree's full-suite run.

Proposed commit: Restyle Dispensary case with shared Clean Look tokens

Proposed PR: Dispensary case still used legacy palette and squeezed its centre form at tablet widths. Apply shared card/field/status tokens in place, preserve clinical actions and permissions, and start three-column layout at1280px. Owner-approved appearance expectations updated in six test groups. Validation:118 focused tests, full type/build, lint/format, script parity and32 synthetic preview screenshots with real send/check/allergy interactions.

Independent review: Dispensary agent reviewed preparing1440light, confirmation390dark, doctor-view-only390light and OTC-unconfirmed1440dark. No blocker; confirmed readable confirmation/action, readonly absence of edit controls, and understandable OTC completion block. Inherited inner4-column fields remain narrow on1440 (wrapping labels and input text clipped within normal single-line inputs); recorded as subsequent layout consideration, not expanded into this approved slice.

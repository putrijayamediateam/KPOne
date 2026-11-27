# Registration native dialog — bounded review packet

Base: PR #1190 (`adef80a5cabf188c6a4b9af1afdfec4278e155cd`), branch `feature/registration-native-dialog`. Twelve source/test files. Exact patch and SHA are in `source-manifest.json`.

## Result

Register Visit opens one existing extracted form over the real Registration board. The original `/registration/create` href supports direct navigation and modified/new-tab activation. The existing create policy and branch-scoped services return only their four existing form props through an explicit private/no-store JSON representation. No permission, migration, clinical rule, registration payload or persistence format changes.

The existing atomic POST and command remain authoritative. The modal captures an immutable dispatched payload and its display draft; controls pause during submission/uncertainty, original details are restored after loss, and Retry original registration sends that exact captured command even if local state is changed. Actual validation and stale-branch refusals retain the same form. An unconfirmed network/HTTP outcome keeps the draft and key, with explicit wording that discard cannot undo a possibly saved registration. Only a successful expected result closes the dialog. Processing prevents dismissal; dirty closing asks before local discard. The owned confirmation uses shared Dialog primitives with deterministic autofocus restoration; no shared UI file changes.

Existing board refresh guards remain intact. Closing waits for typing/menu/visibility/in-flight protections; success alone resets an exhausted poll-error budget. Applied filters/page are retained. QR uses its existing dedicated reload and never sends `qr-intake` to visit search. Back uses ordinary navigation/unmount semantics; no history trap, draft recovery or PHI persistence.

## Evidence and limits

- PHP: new projection coverage plus existing VisitAuthorization tests, **16 tests / 96 assertions passed**. Includes authorized exact four-key JSON/no-store, doctor/admin refusal, fullpage/Inertia representation, and validation redirect to Index.
- Controller PHPStan: zero errors. Changed PHP Pint: pass.
- Final full frontend **2227/2227 passed** after the immutable retry and focus corrections; **38 affected runtime checks passed** beforehand, including exact payload equality despite pending/post-loss mutation, connected/disconnected focus restoration, all 23 actual Index polling/refresh tests and existing extraction/sticky checks.
- Final types and production build passed. Full ESLint/resources Prettier passed before final scoped changes; changed-file lint/format and diff check passed after them.
- Actual browser: 18 modal captures at390/768/1440, light/dark; actual302 validation and stale-branch refusal retain the same form; nested select Escape; processing dismissal refusal; deliberately lost committed response and same-key retry; successful refresh retains `Demo Patient 03`; fresh explicit discard and fullpage fallback. See `browser-lifecycle.json`.
- Three isolated synthetic success/retry flows created exactly one visit each: initial database5 visits, final8; one visit each for synthetic KP-00000001, KP-00000002 and KP-00000003. First flow's only harness failure was an incorrect zero-form assumption on the original fullpage; corrected rerun passed. No application defect was attributed to that harness assumption. `visit-count-proof.txt` records counts.
- Supplementary final browser proves Keep editing restores the prior field, disconnected-result fallback stays inside form, Ctrl/new-tab uses fullpage, Back/Forward does not resurrect the draft, and Close is44px at390/768 and36px at1440. Four confirmation captures use400ms theme settling.
- Browser uses a task-owned clone `kpone_registration_modal_preview_test`; no shared preview/production data writes. No real-device or screen-reader validation claimed.

## Independent findings resolved

The initial Inertia Link composed navigation handler still opened the fullpage; an actual native href anchor fixes interception without breaking fallback. Root caught an overbroad forced-refresh flag, QR-invalid-search edge and paused-success refresh edge: forced bypass was removed, generic guards preserved and QR/success-specific runtime regressions added. Actual Keep editing focus exposed shared-confirmation restoration overriding nextTick: local Dialog close-auto-focus handlers fix it without sleeps/shared changes.

Staff approved the exact sticky source assertion adaptation in `PROPOSED-sticky-test.patch` under delegated authority. Three existing Index harnesses only gain a null mapping for the new component; existing assertions remain intact. REG-LIVE adds lifecycle cases and event-forwarding support without changing its existing assertions.

This is the native route-host slice. It does not claim complete demo parity or introduce the demo's separate four-step validation/business model.

Staff also identified an editable-uncertain-command edge before publication. Immutable captured transport data, restored displayed draft, disabled pending/uncertain fieldsets, and an explicit retry action resolve it; new actual-useForm mutation regression and actual lost-response browser proof cover the correction.

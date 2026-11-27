# Coordinator review of native Registration dialog work in progress

Reviewed the proposed RegistrationController JSON representation, RegistrationCreateDialog, form lifecycle changes, Registration Index integration and new projection tests. This is an intermediate review, not final approval or independently executed test evidence.

## Findings sent to the builder

1. The existing generic `refreshAppliedBoard` had changed to forced refresh, bypassing typing, menu, hidden-page and in-flight protections for unrelated mutations/readiness events. Restore its original guarded automatic search and keep dialog-specific behavior separate.
2. Forced dialog-close search bypassed the QR Intake guard, although SearchVisitsRequest rejects `qr-intake` as a visit board status. Use the existing QR projection refresh path and preserve its current page.
3. A previously paused poll budget could prevent a successful registration from refreshing local board rows. Success may reset that budget for a guarded retry; Cancel must preserve the paused state. Retain editing/menu/visibility/request protections and dispose owned timers.

The first two corrections were subsequently read in source: generic refresh restored, broad forced-search bypass removed, guarded delayed dialog refresh and dedicated QR path present. The third correction and 23 runtime cases were reported passing by the builder and sent to the independent reviewer for exact-source verification. The builder also reported 16 backend tests / 96 assertions and ongoing actual-browser lifecycle checks. These are attributed reports, not coordinator reruns.

The JSON path retains the existing create authorization and same four form props, adds private/no-store caching, and retains full-page Inertia behavior. No new permission or migration is introduced. Final approval still requires frozen hashes, independent review and completed actual-browser success/refusal/uncertainty/focus evidence.

## Independent regression closure

The independent Staff reviewer subsequently inspected the corrected Index and ran all 23 reg-live/runtime tests successfully. Generic refresh retains its guards, QR close uses the QR projection, delayed refresh respects editing/menu/visibility/in-flight state, and only successful registration resets a paused poll budget. The three findings are closed for source/regression behavior; final frozen modal lifecycle/browser approval remains a separate gate.

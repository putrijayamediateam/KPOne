# Next bounded screen: Registration settings composition

Source ledger still marks RegistrationSettings:requirements incomplete; completed Registration modal/search/grouping drafts are not repeated. Actual demo RegistrationSettings.dc.html lines42–84 separates New-patient details, fixed system requirements, four configurable switch rows, and Where these apply. Current source already supplies all settings, exact save/reload/idempotency behavior and separately authorized branch auto-assignment.

## Base and ownership

Use fresh independent branch on recorded origin/main `d0f37b5c1864b796fc7b4224996bd917551af3e7`; the settings page is byte-identical to that main in the current Registration stack. No dependency on #1228 is needed. Own only `resources/js/pages/RegistrationSettings/Index.vue` presentation and new tests. No shared settings sidebar/header edits. No backend/route/grant/schema changes.

## Proposed exact contract

Keep the entire script byte-identical, including both separate useForm owners, post endpoints, idempotency key renewal, lock/expected-branch errors, reload behavior, autoDirty navigation guard and success handling. Keep requirement form id and submit/reload buttons inside their existing form. Auto-assignment stays a separately labelled branch section under `autoAssign?.canManage`; never merge its Save with organisation requirements.

Recompose existing template into bounded readable width with visible page heading/organisation scope, System requirements card, New-patient details switch rows, and Where these apply card. Use semantic theme tokens and current shell; no demo hard-coded branch count or expanded navigation. Existing breadcrumb/controls get44px mobile and36px desktop targets where appropriate.

Preserve existing disabled fixed checkboxes/labels and Gender Unknown allowed. Correct only the misleading phone copy with an explicit known-minor exception supported by current Guardian/quick-patient policy; do not call every phone universally mandatory. Heading says System requirements rather than Always required. No new rule.

Each of four current native checkbox bindings stays unchanged and gains role=switch, stable id+label association, existing aria-describedby help, and an adjacent Required/Optional status derived from that same form boolean. Native Space/label activation preserved; decorative track/thumb is aria-hidden. Entire labelled row is at least44px and focus-visible. No autosave/request on toggle. Existing error/status/reload block remains visible near Save. Preserve exact optional line2, mandatory public QR DOB exception and per-setting copy.

Where these apply is a plain section (not a new sidebar/aside): staff Patient creation, Registration Quick create, and public QR new submissions. State existing records/visits unchanged. Existing PublicPatientIntakeService::exchange stores registration_policy and validator uses that snapshot, so in-progress QR forms keep their captured rules. Do not claim legal notice approval or change consent text.

## Verification

Exact script parity and all original form bindings/handler/permission conditions. New actual-SFC native checkbox/switch tests: state+status without request, native keyboard behavior in browser, exact requirements submit payload/key and independent auto-assignment submit, conflict/reload/error retention and canManage omission. Existing source assertions remain unless precise actual failure requires reviewed adaptation.

Browser synthetic Director full settings at390/1440both themes; keyboard switch+dirty state; separate Save scopes; controlled server refusal/reload and processing disabled controls without successful policy mutation; lower-authority autoAssign omission via existing fixture/authorized projection. Do not mutate real or shared policy merely to stage screenshots; controlled Inertia props/errors must be labelled. Any required real save proof uses task-owned cloned synthetic DB only.

Focused frontend/backend settings authorization as appropriate, types/build/fullfrontend/static, independent final hash/visual review, then standalone draft publication. No merge. Missing clinical/patient overview aggregates remain separate functional projection work; this supported settings screen does not fill them with invented data.

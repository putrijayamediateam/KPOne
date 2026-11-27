# Registration form extraction — proposed bounded scope

Purpose: establish one reusable form surface as the next dependency toward the authoritative demo dialog, while retaining the full-page route. Stack on #1186 after its frozen head. This proposal does not add a modal or navigation interception.

## Proposed ownership and files

- New `components/registration/RegistrationCreateForm.vue`: move the existing Create script state/computeds/watchers/search/submit and form template together, keeping exactly one useForm instance. Accept the same typed server props. No independent copies of form/quick-patient state.
- Existing `pages/Registration/Create.vue`: retain Head, layout/breadcrumb metadata and full-page outer main/header; forward the same props to the form component. Keep effective DOM and existing return-to-console link unchanged.
- New runtime extraction tests; existing source-reading tests require an enumerated exact delegated review batch before edits. No test may lose its submit/error/retry/phone/identity/guardian checks just because the code moves.

## Behavior invariants

Preserve form content, field IDs, label associations, direct URL, browser Back/Forward behavior, errors and focus. Preserve mounted/recommendation/abort lifecycle and their component lifetime (page navigation still unmounts the same single form). All submit/payload transformation, idempotency keys, branch guard, duplicate/repeat confirmation, adult/guardian rules and processing guard must remain text-identical where possible. No server action, fetch timing, validation or successful redirect changes. Preserve the exact #1132 touch fixes and #1186 summary.

No new dirty-close confirmation is added in this extraction, because it would change current full-page navigation behavior. The later modal host needs explicit separate review of dirty/back/close/focus return and processing-close policy, with the same form owner. Existing Emergency contact staging and Family policies are independent; future integration must reconcile props/template rather than duplicate owners.

## Validation gates

Record normalized script/template move parity and exact existing-test impact before implementation. Exercise actual Vue/Inertia useForm and mount/unmount lifetime: selection/quick-mode, field edits, error focus, repeat validation, cancellation of pending searches, no duplicate recommendation requests and no duplicate submission. Run affected existing tests and full frontend/types/build/lint/format. Compare actual full-page screenshots with frozen #1186 at390/1440 both themes; prove same direct URL and browser Back/Forward behavior, no new navigation requests or patient/visit writes in read-only scenarios. Independent source/visual review required before draft publication.

## Explicit deferrals

No four-step validation machine, modal host, fake scanner, queue number, readiness/panel inference, clinical/history projection, permission, migration or production action. If the current component lifecycle cannot be moved without changing semantics, stop this extraction and report the exact dependency rather than expanding scope.

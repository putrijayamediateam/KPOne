# TRI2 shared emergency UI recovery

This slice follows presence #1188 (`0475a77df618ef751a70637aa06fba3e20822b6f`). It recovers the reviewed shared alert host, composable, banner and Clinical Hold bridge without importing archive #1142. Lane presentation is a separate next slice; Family #1148 remains a final workflow dependency. Existing Treatment read panel and form behavior remain intact.

The shared host replaces duplicate page-local banners across authorized layouts. Alerts remain bounded by the backend. Seen and Take are separate, Take does not call a patient, and a successful Hold does not imply automatic Resume. Sound requires a user gesture, repeats only for untaken alerts, and device mute is capped at120 seconds. Recent poll presence is not clinical availability. User/branch changes abort stale requests and invalidate callbacks. Uncertain commands retain their exact payload/key for retry.

Recovered follow-ups preserve the current page title, wait for Vue's successful Hold props before Take, and cancel a pending emergency continuation when the unsaved-work dialog is dismissed without resetting drafts or sending requests. Manual shared-file reconciliation removes only old banner mounts and adds the bridge; Queue retains usePage for its existing Treatment-room link.

Independent review found a host-only teardown gap. The host now invalidates its continuation and cancels the bridge on unmount, while the composable refuses retained read/command/retry callbacks after disposal. Two new runtime regressions prove that deferred successful Hold callbacks cannot start Take after teardown. No clinical rollback or state clearing was introduced.

Existing assertion adaptations are the previously owner-approved shared-layout host markup, Q16b shared-host expectations, and OH06c Cancel handler with explicit no-reset/no-discard/no-request checks. No permission, migration, clinical policy or backend changes are included.

Validation: full frontend2247/2247, TypeScript and production build pass; changed-source and new-test lint/format pass. Independent teardown tests11/11 pass. Actual synthetic browser proof covers Cancel preserving draft, Save and Hold, committed Take response loss and unchanged retry, return to original consultation, separate Seen, one host,390/768/1440 light/dark layouts, device mute and recorder action/sound restrictions. Nine database invariants confirm one retry claim, red patients still Waiting and uncalled, current consultation held and saved note retained. A recorder harness initially used nonexistent /visits (404); rerunning with the actual /registration route passed. No application change was made for that harness error.

This is a bounded shared-alert recovery, not full Queue/demo parity or a deployment/activation approval. The emergency lane UI still must be recovered before the combined workflow is ready.

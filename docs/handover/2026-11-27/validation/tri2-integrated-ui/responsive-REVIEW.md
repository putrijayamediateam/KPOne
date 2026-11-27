# Integrated emergency lane responsive and policy smoke

Read-only browser proof against the actual Laravel application at3054, using the existing synthetic doctor session and synthetic CA login. No Hold, Take, Seen, Call In, readiness mutation, DB reset or application edit occurred. The harness allowed authentication and read-only Queue/Registration search POSTs only; no disallowed state-changing request was attempted. Session/cookie values are not part of this report.

## Outcome

**Emergency lane/host responsive and defined authorization checks pass.** All16 actor/page/width/theme combinations returned200, one shared emergency host, correct lane count (doctor1, CA2), no page-level horizontal overflow, no JavaScript errors. Actual screenshot coverage: Doctor and CA × Queue and Registration ×390/1440 × light/dark. `responsive-screenshot-manifest.json` lists all16 images.

New lane and shared-host visible button targets measured at least44px on390px screens and at least36px on1440px screens. The scope does not imply that every existing page control meets the design target; see gaps below. Existing internal board scrolling must be distinguished from page overflow.

## Authorization finding and resolution

The initial whole-page expectation that a doctor would never see another doctor's red patient's identity failed in all four doctor Registration captures. That failure is preserved unchanged in `responsive-results.json` and `responsive-smoke.log`: the other doctor’s patient appears in the ordinary Registration list, not the emergency lane. It is not silently converted into a successful initial test.

Source and main comparison explains this as established authorization rather than a new TRI2 disclosure:

- `VisitPolicy::viewAny` requires `visits.view.branch` and an active branch. `resident_doctor` holds that permission on both main and the integrated source.
- `VisitDirectoryService::search` on main and integrated source scopes registration identities to organisation/active branch/date; doctor selection is an optional filter, not an own-patient policy.
- Queue visibility is separately restricted by `visibleQueueEntry`/queue policy. The other doctor’s ordinary Registration row therefore shows queue “—”, duration “—”, Registered/Urgent and authorized visit identity/summary, with no red-zone triage chip, emergency timestamp/taken metadata, waiting duration or Call In. Its actual opened menu contains exactly “View Visit” and “View Patient”. The menu was inspected, neither link followed.
- Doctor Queue exposes only the owned red patient. Both doctor emergency lanes contain only that one owned patient. The shared cross-doctor host contains queue numbers and allowed operational alerts, not patient names/numbers. CA lanes each show both branch red patients.

Queue independently confirmed this policy/main comparison and is adding a backend regression documenting the distinction. No permission or scope modification was made. `responsive-policy-results.json` records the extra real menu/row/target checks; `responsive-policy-assessment.json` records the policy-specific pass while referencing the initial stricter failed expectation.

## Existing target-size gaps outside the new lane/host

These are observed, not fixed in this read-only task:

- Queue at390px: room chooser (doctor), patient search, doctor filter (CA) and urgency filter are36px high rather than44px.
- Queue at1440px: Apply/Reset are32px rather than36px.
- Registration at1440px: Apply/More are32px rather than36px.
- Registration at390px: inspected visible main controls meet44px.

Evidence: `responsive-policy-results.json` includes measured names/heights. These pre-existing controls mean the smoke does not claim whole-page target-size compliance. Root/coordinator notified for separate scoped follow-up.

## Limits

No clinical actions or permission-denial writes were exercised. This is responsive/read-only privacy evidence, not a substitute for backend role/concurrency tests or full end-to-end clinical validation. Synthetic guideline/fixture warnings in this directory still apply. Review visually inspected doctor Queue390dark and CA Registration1440light in addition to automated geometry/content checks across all16 screenshots.

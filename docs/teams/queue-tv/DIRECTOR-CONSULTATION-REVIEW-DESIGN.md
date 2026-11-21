# Director's read-only consultation view: design note (item 4, owner decision P4, 21 Nov 2026)

Owner decisions: the Director may look at any consultation, at any branch, read only; every view is recorded (15 = A); audit log only, no page of views (16 = A); permission `consultations.view_readonly.organisation`, Director only (P4). Built on Aisyah's DSP-25 (PR #1016, on main): `ReadOnlyReviewAudit` and `ReadOnlyBanner.vue`.

## What the Director sees (explicit field list, like `DispensaryReviewService`)
Header: branch name, patient number and name, visit number, attending doctor, consultation status, started and completed times (branch time zone).
Body: vitals (latest observation, with BMI), clinical note, diagnoses (text, code, primary), allergies (profile status and active records), treatment plan orders (active medicine lines: name, strength, quantity, dosage, frequency, duration, route, instruction; active service lines: name, quantity, instruction; withdrawn lines in a quiet group).
Not included: lock versions, idempotency keys, any URL except Back, any `can` flag, hold/queue controls, problem list edits, history of other visits, MC / referral / attendance documents, billing, dispensary content (that is DSP-25's own page), date of birth and contact details (age band only if needed later).
The data is an explicit list for every status; it does not reuse `detail()` (which carries lock versions, tokens and idempotency keys). A test pins the keys.

## Backend
- `App\Domain\Clinical\Services\ConsultationReviewService::open(User $actor, Visit $visit): array` with `PERMISSION = 'consultations.view_readonly.organisation'`. Fresh `$actor->can()` check, organisation scope (404 for another organisation), no branch-assignment requirement (any branch).
- First `ReadOnlyReviewAudit::record($actor, 'consultation.reviewed', $encounter, ['visit' => ..., 'patient' => ..., 'encounter' => ...], $branch)`. The audit row commits on its own before anything is built; a failed write throws and shows nothing (500, no patient name). `ReadOnlyReviewAudit::EVENTS` gains `'consultation.reviewed'` and `ID_KEYS` gains `'encounter'` (my PR changes Aisyah's allowlist: ids only, nothing else).
- Route `GET visits/{visit}/encounter/review`, name `encounters.review`, middleware `permission:consultations.view_readonly.organisation`, `sensitive.no-store`, `throttle:60,1`. GET only (a source test refuses any other verb under it). A HEAD request returns no content and records nothing.
- Permission: additive migration (`firstOrCreate` + grant to `director` only, missing role skipped, `down()` a no-op), added to `PermissionCatalogue` for the Director only. Not people or access authority (test, as in DSP-25). The Director holds no clinical write permission by this (a test lists all consultation, treatment-plan, encounter, queue-call and hold write routes and asserts a 403 and no row written).
- Entry link: `visit.consultationReview` on the visit page (VisitDirectoryService, beside `dispensaryReview`): `{url, status}` for the Director when the visit has a consultation, else `null`; showing the link records nothing.

## Frontend
- `Clinical/Review.vue`: `ReadOnlyBanner` at the top (Back as the slot), then plain read-only sections. No form, no input, no button except Back. Shared tokens, dark mode, 320 to 1440 px.
- Entry: a plain `<a>` (no Inertia prefetch, no partial reloads) "Open consultation (read only)" in its own card on the visit page (`Registration/Show.vue`), beside DSP-25's Dispensary card, Director only.

## Tests
- Only the Director holds the permission; not people or access authority.
- A Director at another branch opens a consultation; one audit row `consultation.reviewed` with ids only, recorded first.
- Every open is recorded; HEAD records nothing; showing the link records nothing.
- Pinned keys: nothing a form could post (no lockVersion, token, idempotency, `can`).
- Every other role gets 403; another organisation gets 404; the TV account gets 403.
- Forced audit failure: 500 and the response contains no patient name.
- GET-only path; the Director is refused on every consultation write route and nothing is written.
- The helper refuses an event or id key that is not allowlisted (existing test extended).
- Frontend: banner present, no form or input elements, no prefetch on the entry link.

## Existing assertions that will change (listed for the owner; left failing until approved)
To be confirmed by grep at build time: the Director's permission list in `tests/Frontend/fixtures/access-control-roles.json` and backend pins of the Director's exact permission set; the `ReadOnlyReviewAudit` EVENTS and ID_KEYS pin in `DispensaryReviewTest` (if it pins the exact lists).

## Not in this slice
No page of recorded views (16 = A, audit log only); no export; no change to the doctor's own consultation page; no Registration board change; Q-12 and the Complete Consultation guard are separate.

## Open questions for the owner
None blocking. For Daniel: the layout of Review.vue (sections order: header, vitals, note, diagnoses, allergies, orders).

# Family links F1–F3 — implementation review, 26 November 2026

Local branch `feature/patient-family-links`, `/workspace/KPOne-family`, based on `origin/main`23639679e. No commit, push, PR, merge or deployment. Owner approved all three concrete design/schema choices on26Nov; preserved F1 existing permissions, F2 no shared-phone suggestions, F3 only authorised direct relatives waiting today and Patient links with no conditions. Guardian/emergency remain independent. Exact approval recorded in scoped TEAM/design.

## Implementation

- Current direct pair + immutable command history; fixed five labels/inverse, ordered IDs, composite organisation FKs, one pair, optimistic versions, inactive tombstone and non-destructive rollback. No role grant or inferred group.
- Actor authority then sorted patient locks then pair lock. Both endpoint view/update checks before writes; current merge checks before replay. Actor/key HMAC canonicalises orientation and binds operation/relation/version; exact retry returns current authorised state without mutation. Unlink original merged pair remains permitted under both endpoint policies.
- Directory filters both endpoint policies; merged originals only show generic removal affordance to authorised updaters. No automatic retarget on merge, original active relation visible on unmerge unless explicitly unlinked.
- Consultation projection is current authorised live encounter only, active branch/local-date waiting consultations, existing own/branch queue policy and patient policy; bounded allowlist, no clinical/contact/billing fields, no hidden counts, no transitive relatives.
- Patient UI and separate read-only Consultation card implemented by Staff/Design agent; manual blank existing-patient search, explicit relationship direction, branch/request cancellation, standard confirmation and44px mobile controls. Types/build passed; actual browser28screenshots plus final error4views passed, with no overflow/JS errors. Keyboard/focus and44px Family control checks passed. Full UI evidence at `/workspace/kpone-validation/family/UI-REVIEW.md`.

## Evidence

- New family tests + existing PatientMerge/ClinicalEncounterDirectory/ConsultationHold:48tests253assertionsPASS (`backend-48-tests.log`). Additional timezone boundary/other-branch case passed with all3projectiontests/10assertions (`projection-final.log`);48-test broader run predates this one new case.
- Actual PostgreSQL races:5tests32assertionsPASS (`postgres-races.log`). First creation, reciprocal creation, same-key replay, correction versus unlink, merge versus link. READY/GO plus observed PG blocker chains; distinct own testDB.
- Final current core11tests52assertionsPASS after upfront current-patient view check before target lookup (`core-final.log`); both endpoint checks still repeated under locks.
- Focused PHPStan0errors (`phpstan.log`). Pint on all new PHP files, migration and tests passed; existing ClinicalDirectory source deliberately limited to import/projection field to avoid unrelated formatting.
- Approved migration token parity with owner-reviewed draft PASS (excluding formatting/comments). Approved migration executed successfully on own synthetic test/preview databases; seeded reference/presentation and synthetic family fixture. Schema immutability, self-pair refusal and non-destructive down covered.
- No existing assertion modified. New race suite registered in Contention/Contention3 and excluded Feature.

## Independent review

Codex Billing/Panel reviewer reviewed actual backend source and tests/harness (did not rerun). Reported no authority/replay/locking blocker: both endpoint policy, inverse canonical HMAC, current merge checks, original unlink preservation, sorted patient locks, F3 policy/allowlist all coherent. UI direction must be explicit (directory current→relative; waiting card relative→current); Staff/Design implements/tests that distinction. This is agent review, not a claim of historical human signoff.

Coordinator integration must preserve emergency/Registration/Stage0 changes when merging Patient/Clinical pages, routes, phpunit.xml and TEAM. Feature patch `family-feature.patch` is exported against clean main; hashes are in `source-manifest.json`. Combined integration remains a separate gate, with three known context conflicts documented in `INTEGRATION-NOTES.md`.

## Coordinator-authorised source integration

Applied exact frozen Family patch to `/workspace/KPOne-ca-integration`. All13newfiles match source hashes. Reconciled the three context-conflict files surgically; removing only Family additions reproduces each captured pre-integration file byte-for-byte, preserving emergency routes/card and all Emergency/Treatment/HandCost suite registrations. Updated `/workspace/kpone-validation/ca-integration/source-inputs.json` with the Family patch hash. Evidence: `integration-result.json`.

Integrated focused frontend31/31PASS; ContentionSuite2tests15assertionsPASS; `git diff --check`PASS. No database, preview, build or broad gate run during this source-integration task. Root owns final gates; Queue owner notified exact approved migration is ready for additive application to coordinator-owned synthetic preview DB.

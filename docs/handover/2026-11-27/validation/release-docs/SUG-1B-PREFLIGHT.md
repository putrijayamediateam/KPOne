# SUG-1b release preflight

Read-only review of PR1117 at 6f68902e80ff3e9424e7cfa2b4f15e7f459e3544. The handoff records owner ship-it and the combined waiting-count assertion approval. This note is not a fresh runtime, independent-review, or merge claim.

## Preserved scope

Reviewed the phrase model/service/controller, review controller/page, migration, settings page, routes and eight feature tests. Existing settings and clinical approval permissions are reused. Service writes re-check actor and branch, lock organisation and rows in a transaction, validate lock_version, bind retry hashes to actor and payload, and emit identifier-only audit context. The database rejects self/editor approvals, incomplete approval metadata and invalid kinds. Down refuses when either table has records. Search escapes wildcard characters; settings and review lists are bounded.

The later consultation popup and usage ranking are separate slices. No new authority, production data or deployment is authorized by this review. Original PR prose says dosage/frequency/instruction, whereas the implementation and approved design use frequency/instruction/precaution/duration; refresh the description to match the actual four kinds without changing scope.

## Checks needed on its serial release turn

- Refresh against the then-current main after1113/1128/1118; preserve approved migration and existing assertion semantics.
- Run focused PrescribingPhraseTest and DiagnosisReviewTest, the two suggestion frontend suites, scoped formatting/static checks and the fresh exact-head CI.
- Independently verify the current page composition and responsive interaction. New phrase Archive/Restore switches use h-7 (28px) without an evident larger hit area; search shrinks at md and several buttons apply a 44px minimum only below sm. These need the agreed below1024px target review before claiming the quality bar.
- The switch thumb uses bg-white rather than a semantic token; evaluate a narrow token replacement alongside target fixes. Do not claim full Clean Look parity for this reference-settings implementation.
- Check actual main-landmark behavior before alleging a duplicate: AdminLayout dynamically sets its role via its shared landmark helper, so a page-level main alone is insufficient evidence of a bug.

No application or test files were changed in this preflight.

## Independent source follow-up

Staff independently confirmed the native switch has a 28px element and no larger hitbox. Proposed bounded repair: a focus-visible 44px button below1024 / 36px desktop with a separate 28px visual track and semantic thumb token. The native search uses md:h-9 and should use lg:h-9. Correction to the initial concern: current shared Button already supplies max-lg:h-11, so its local max-sm:min-h-11 does not by itself establish a tablet failure. Size=sm is 32px on desktop, so check the applicable 36px requirement with a local min-h-9. Runtime measurements are still needed after the refresh. No duplicate-main claim was made and no code was changed.

# DSP28 clean publication

Recovered the already-approved checked-this-month returns list and controlled-medicine filters from its reviewed patch. Applied cleanly on main and refreshed to `e5e72f69b` before final checks. Isolated worktree `/workspace/KPOne-dsp28-publication`, branch `feature/dsp28-checked-returns-publication`.

Eight-file scoped patch `DSP28-PUBLICATION.patch` SHA256 `9b208c66ff79e1ba4f683a01183522ab383dd47457166a643c953538eba9896b`; exact hashes in `DSP28-PUBLICATION-manifest.json`. No migrations, permission grants, existing assertion edits, inline checks or Ready lifecycle. Waiting and checked rows use the same current branch/organization/policy gate; checked rows use branch-local month boundaries and omit private notes. Checking remains on the existing case page. Controlled status reflects the current catalogue flag, not a historical snapshot.

The only publication presentation correction beyond the reviewed recovery is scoped36px desktop targets after browser measurements found32px filter/pagination controls. Mobile remains44px. Recovery history and current publication evidence are both recorded in the existing review document; no unrelated shared preview overlays were included.

## Validation

- Final explicit Feature-suite run:30 tests,452 assertions, exit0; `backend-final.log`, `final-junit.xml`, `final-events.log`. Covers new history5/113 plus existing returns/home/Ask. Initial unscoped-filter runs passed assertions but exited1 because existing phpunit.xml registers Contention files again in Contention1/2; raw event/JUnit evidence retained. Selecting the intended Feature suite resolves runner collection warnings without altering configuration, suppressing warnings or changing assertions.
- Existing frontend13 checks pass, unchanged (`frontend-focused.log`).
- Full PHPStan0 (`phpstan.log`), full TypeScript (`types.log`), production build (`build-final.log`), scoped Pint/lint and diff check pass.
- Actual Laravel3072 with isolated synthetic `kpone_dsp28_publication_preview_test`. Eight waiting/checked-month390/1440 light/dark states pass; no page overflow/page errors;44px mobile and36px desktop targets. Controlled filter round trips and filtered checked-empty state exercised (`browser-results.json`). Synthetic fixture uses existing domain/test fixture methods and is local only. No real data, clinical mutation through browser, shared DB reset or .env change.
- Independent Inventory source review found no blocker in authority/branch/month/query/privacy scope; see independent note. It did not independently rerun heavy tests. Design visual closure recorded separately.

## Honest limits

The generic empty-filter copy is inherited: a zero filtered result can say no returns checked/waiting even if another controlled category has returns. It is recorded as copy debt, not changed in this publication. Shared PageHeader repeats the branch when the legacy description already includes it; shared navigation highlight debt is outside this slice. No claim of complete Pharmacy-demo parity. Full combined backend/contention/migration gates remain CI/coordinator scope; this read-only feature adds no schema or transactional mutation.

Publication is a scoped draft PR; no merge or production action. A new publication URL/head will be appended after creation.

Published scoped draftPR1155: https://github.com/putrijayamediateam/KPOne/pull/1155. Head `97c01615d5a31652ac3df647a9f183daef0bfd4e`. All eight reviewed source hashes match; clean worktree, no PRmerge. Independent source and Design notes clear. Fresh exact-head CI pending in `ci-head.json`.

# DSP-28 recovery review package — 26 Nov 2026

Status: local, uncommitted recovery in `/workspace/KPOne-dsp28`, branch `feature/dsp-28-continuation`, based on `23639679e`. Source recovered from `origin/review-dsp28` (`51d316cf`). No commit, push, pull request or merge has been made.

## Problem and behavior

The Returns to check list previously showed only unchecked returns. A supervisor can now switch to Checked this month to see the active branch's checks within its local calendar month, including outcome, checker and checked time. Both views can be narrowed to controlled or not-controlled medicines. The list remains read only: Open case links to the medicine returns section, where existing checking rules apply.

## Plan and scope

1. Recover the authorized B3/B5 implementation without changing migrations, roles or existing assertions.
2. Validate the list response, branch-local month, controlled filtering, privacy exclusions and existing home count behavior.
3. Correct query-array normalization and the quarantined chip's status tone.
4. Record real merge status and evidence-backed follow-on candidates separately from this implementation.

No Ready tab, doctor handover step, inline return check, Follow-up link, staff management change or permission grant is included. The doctor Verify transition remains unchanged.

## Files and specification

| File | Change |
|---|---|
| `app/Domain/Clinical/Dispensary/Services/DispensaryReturnService.php` | Add normalized view/controlled arguments; checked-month query; outcomes/checker/time; tab counts and paging; `waitingTotal` count entry point. Existing access gate and visible-staff name lookup retained. |
| `app/Http/Controllers/DispensaryController.php` | Read view/controlled query values; non-string inputs fall back safely. |
| `app/Domain/DepartmentSpace/PharmacySpace.php` | Use waitingTotal for home count. Coordinator separately corrects both navigation links to `/dispensary`. |
| `resources/js/pages/Dispensary/ReturnsToCheck.vue` | Tabs, controlled pills, history outcome and checker/time; filters retained when paging. Quarantine uses existing info tokens. |
| `tests/Feature/Clinical/DispensaryReturnsHistoryTest.php` | New feature tests for month boundary, checked transition, history privacy, controlled filters, forbidden CA access and array-valued query fallback. Existing test files untouched. |
| `docs/teams/dispensary-otc/TEAM.md` | Correct DSP-27 merge record; explicitly label DSP-28 local recovery. |
| `docs/teams/dispensary-otc/CLEAN-LOOK-GAPS-2026-11-26.md` | Demo/source comparison and scoped candidate filters; no invented original B numbering. |
| This review package | Review evidence, limitations and proposed delivery text. |

## Risk and self-review

- Active user, organisation, active branch assignment and CA Supervisor return-check permission remain enforced server-side for both views. History uses the same branch-scoped return query.
- Month bounds are calculated in branch timezone, then queried as UTC half-open bounds; histories sort newest first and waiting rows remain oldest first.
- Free-text patient return and refusal notes are never selected for the list. Staff names use the existing visibility query and fall back to Staff member.
- Controlled status reflects the current catalogue flag, consistent with the existing returns screen; this is not a historical snapshot.
- The list does not call any record/check/stock/refund mutation. Quarantine and second-person checking rules remain on the case path.
- Unknown filter strings and array-valued filters fall back safely. New test covers the array case; no existing assertion was rewritten.
- Home waitingTotal suppresses other-tab counting but still uses the list query and row projection. This preserves the recovered scope; a count-only optimization can be reviewed separately.
- Empty-state copy is generic when the controlled filter produces no results; no matching rows is not proof the branch has no returns at all.
- No browser visual review has yet been performed. Aggregate full-suite, production-build and dummy migration/seed gates remain coordinator work; this package does not claim release readiness.

## Validation

Passed:

- Focused PHP: 30 tests, 452 assertions, 147.654 seconds.
- Focused frontend: 13 tests passed, zero failures.
- Changed-file ESLint, Prettier and Pint.
- Full frontend types (`vue-tsc --noEmit`, exit 0).
- Full PHPStan (exit 0).
- `git diff --check`.

Exact focused commands, run from the recovery worktree:

```sh
PATH=/workspace/kpone-tools/bin:$PATH APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55439 DB_SSLMODE=disable DB_DATABASE=kpone_dsp28_test DB_USERNAME=agent DB_PASSWORD= php artisan test --compact --filter='DispensaryReturnsHistoryTest|DispensaryReturnsToCheckTest|PharmacySpaceTest|SpaceAskTest'
node --test tests/Frontend/dsp-7b-returns-to-check.test.mjs tests/Frontend/dsp-27-dispensary-list.test.mjs tests/Frontend/classic-only-co10-kept-returns-to-check.test.mjs tests/Frontend/classic-only-co5-kept-v2-dispensary-returns-to-check.test.mjs
npx eslint resources/js/pages/Dispensary/ReturnsToCheck.vue
npx prettier --check resources/js/pages/Dispensary/ReturnsToCheck.vue
PATH=/workspace/kpone-tools/bin:$PATH php vendor/bin/pint --test app/Domain/Clinical/Dispensary/Services/DispensaryReturnService.php app/Domain/DepartmentSpace/PharmacySpace.php app/Http/Controllers/DispensaryController.php tests/Feature/Clinical/DispensaryReturnsHistoryTest.php
PATH=/workspace/kpone-tools/bin:$PATH php vendor/bin/phpstan analyse --memory-limit=1G --no-progress
npm run types:check
git diff --check
```

Changed-file formatting/lint, full PHPStan and frontend types passed. No additional heavyweight suite is being started while other team suites run.

## Exact diff

The coordinator can inspect `git -C /workspace/KPOne-dsp28 diff` for tracked changes and the new Markdown/test files listed above. A complete patch, including new files, is supplied at `/tmp/kpone-dsp28-review.patch` ; its digest is supplied alongside the patch.

## Suggested commit and PR

Suggested commit: `feat(dispensary): show checked returns and controlled filters`

Suggested PR title: `DSP-28: Show checked returns this month and filter controlled medicines`

Suggested body:

> Supervisors can review returns checked during the active branch's local month and filter both waiting and checked returns by controlled status. The history shows outcome, checker and time without exposing free-text notes; checking remains on the existing case page.
>
> Recovers authorized Clean Look B3/B5, normalizes malformed filter parameters, and records the remaining demo gaps separately. No migrations, permissions or existing test assertions change. Integrate with the coordinator's separate PharmacySpace navigation correction.
>
> Validation: focused PHP 30 tests / 452 assertions and focused frontend 13 tests passed. Changed-file formatting/lint, full PHPStan and frontend types passed. Aggregate full-suite/build/seed gate status must be appended before PR creation. Browser review remains pending.

## Clean publication preparation — 26 November 2026

The reviewed patch applied cleanly to current main in `/workspace/KPOne-dsp28-publication`, then fast-forwarded to `e5e72f69b` before final validation. No role, migration, existing assertion or business behavior was added. Browser review measured inherited32px desktop filter/pagination targets; scoped presentation classes raise them to36px while keeping44px below1024px. Eight real synthetic browser states now cover waiting and checked-month at390/1440 in light/dark, with controlled-filter round trips, no page overflow/errors, and return checks still on the case page.

Publication evidence is `/workspace/kpone-validation/dsp28-publication/`. Full types/build, PHPStan, scoped Pint/lint and13 existing frontend checks pass. The30 focused backend tests pass452 assertions; final command explicitly selects Feature because collecting every configured suite before a filter triggers baseline duplicate Contention-suite warnings. Raw events/JUnit retained; no warnings or assertions were suppressed. Independent Inventory source review found no authority/branch/month/query/privacy blocker. Final Design review and exact-head CI remain separately recorded by coordinator. This section supersedes the earlier missing-browser limitation; the earlier recovery history is retained.

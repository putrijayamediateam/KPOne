# W1e-1b — private release-page and Little Shield follow-ups

Owner-authorised 2026-10-23. Branch `feature/w1e-1b-followups`, base `21bb2ec` (contains required PR #165 `ef79ea6` and PR #164 `21bb2ec`). **Uncommitted review candidate**: the owner's latest instruction requires a green gate and review before commit. No push or PR. Served tree remains unchanged on main; no other worktree touched. No .env or .env.preview opened.

## What changed

1. **Presentation-time staff names.** `WebsiteReleaseService::page` gathers the distinct creator and approver ids in the displayed release list plus actors in the displayed detail history. At most one batched `users` query selects **name and id only**, with both the acting organisation filter and an id IN list. No lookup by email, phone, role or any other field. Empty pages need no name query. The missing-in-scope fallback remains `Staff #id`. Names are resolved at display time, so a later rename appears without editing historical evidence. Names are never stored in the release evidence or audit. Existing authority-only staff reads remain unchanged; this explicitly authorised display lookup is the only additional staff read.
2. **Current approved baseline.** The comparison is the greatest release id **strictly less than the viewed id**, in the same organisation, whose **current control state is exactly approved**. Prepared, refused, withdrawn, the viewed release itself and all newer releases are excluded. `comparedTo` is that release's number; if none exists the unchanged label is **First release**. This applies even when viewing old approved or withdrawn releases. It changes only the read-time comparison; stored manifests, hashes, actions and release evidence are untouched.
3. **Little Shield cosmetics.** EN/BM buttons use the same default/outline selected variants as other website editors; existing aria-pressed, keyboard behaviour, dirty-form protection and branch clearing remain. Title is a normal text input, still bound to the same field and the same **120-character** limit/backend validation. All other text fields retain their textarea behaviour. No saved shape or wording change.
4. **Baseline documentation.** Section 6 item 14 marks these release and Little Shield items fixed in W1e-1b using **PR #TBD**, to fill only after an authorised PR opening. Item 15 records that runs for #164 (418) and #165 (417) passed, as directed by the owner. The W1e-1 known limits retain the full encrypted image-copy and complete-bilingual-active-branch-profile limits, removing the two fixed presentation/comparison limits. No AGENTS.md or PROJECT.md edit.

## Files, permissions and safety

- app/Domain/Organisation/Website/WebsiteReleaseService.php
- resources/js/pages/Website/LittleShield.vue
- tests/Feature/Website/WebsiteReleaseTest.php
- tests/Frontend/website-little-shield.test.mjs
- docs/BASELINE.md and this report

**No migration, table, column, new permission or dependency.** No public route/API, output, renderer, deployment, job, application network call or import. Existing release-page permission/organisation/effective-branch checks, no-store/noindex headers, append-only evidence and values-free audit remain. No clinical, billing or other feature change. Only git fetch origin main used external network.

## Every changed existing assertion

**Exactly one existing query-allowlist entry changed**, with narrow owner authorisation on 2026-10-23:

`WebsiteReleaseTest::test_all_unrelated_rows_queries_http_jobs_scheduler_and_audit_are_values_free`, original line 463.

- **Old:** every query against users, staff_profiles or staff_branch_assignments had to match exactly a query and bindings captured from `WebsiteAccess::lock` for the acting user: `$this->assertContains([$q['query'], $q['bindings']], $authority)`.
- **New:** retain that exact assertion for every other staff/identity query, and admit only this additional fully anchored, case-exact query shape:

```sql
select "name", "id" from "users" where "organisation_id" = ? and "id" in (?, ...)
```

The regex accepts one or more `?` placeholders separated by comma+space, no extra clauses, columns or whitespace. It does not admit select *, email/phone/role/password/remember_token, an absent organisation predicate, or a lookup by any key other than id. Added checks require the first binding to equal the acting organisation, every id to come from that organisation's release creator/action rows, and exactly one such query in the page-containing action. New tests additionally prove the exact displayed ids, constant one-query batching across multiple releases/actions, zero such reads in direct prepare/approve/refuse/withdraw/media calls, and no foreign-row retrieval. The original helper for decisions reads a page before the mutation; the new direct-operation test separates and checks the actual mutation reads.

**Reason:** the owner authorised staff display names on **2026-10-23**, read at display time and never stored in evidence. The earlier unchanged authority-only boundary failed on that deliberate new query (1 failed / 71 assertions); work stopped. The owner then authorised only this precise allowlist extension. All forbidden-table checks, remaining identity authority checks, HTTP/jobs/scheduler assertions, audit-value checks and full-row comparisons stay unchanged. No other existing assertion changed, weakened, removed or skipped. Existing frontend assertions are untouched; one new test was appended.

## Added regression coverage

Three new backend tests:

- `test_release_page_names_are_batched_scoped_display_only_with_fallback`: creator/approver/every history actor display, a single query before and after more actions/releases, exact columns/filter and expected distinct ids, no unused/foreign ids, display-time rename, fallback for a referenced row no longer in the scoped organisation, and unchanged release records/audit after page reads. Existing restrictive foreign keys mean deleting a referenced user is not a valid fixture; the scoped-missing case proves the same fallback without weakening any constraint.
- `test_change_baseline_is_currently_approved_lower_id_only`: older approved release selected after a newer withdrawal; prepared/refused excluded; only withdrawn candidates gives First release; viewing an older release never uses a newer approval.
- `test_display_name_query_is_absent_from_direct_mutations_and_media`: all four mutations and the retained-media read issue no display-name query.

One appended frontend test pins both selected variants/aria-pressed bindings and the Title input with unchanged maxlength; other fields remain bounded textareas. Existing compile, safety, saved-preview escaping and branch-switch tests remain intact.

## Environment and full gate

Task-owned PostgreSQL **18.6**, cluster `/private/tmp/kpone-w1e1b-pg`, **127.0.0.1:58507**, user **followup**. Effective Laravel host/port/database were printed and verified before the first connection, then actual server/database identity checked.

- Backend: `kpone_w1e1b_testing`
- Separate fresh seed gate: `kpone_w1e1b_seed_testing`
- Synthetic Chrome only: `kpone_w1e1b_preview_testing`, server **127.0.0.1:54507**

No other database connected. Dependencies copied from the served tree without installing anything. Ignored Wayfinder route types generated for the fresh worktree. Local PG runner explicitly disables SSL for its loopback disposable server; no production configuration changed.

| Gate | Result |
|---|---|
| Full backend, child process memory override 1G | **1,379 passed / 27,197 assertions / the same two existing skips**; 1,381 total, 587.271 seconds, exit 0 |
| Release regression suite | **22 passed / 849 assertions** |
| Full frontend | **482 passed**, zero failures/skips |
| Pint | Passed |
| PHPStan level 7 | Passed |
| npm types:check | Passed |
| npm lint:check | Passed |
| npm format:check | Passed |
| npm run build | Passed |
| Separate migrate:fresh --seed | Passed |
| git diff --check against fetched origin/main | Passed |

Full backend command: `storage/app/private/followup-tools/run php -d memory_limit=1G artisan test --compact -d memory_limit=1G --stop-on-failure`. The forwarded override covers PHPUnit's child process. No test skips added. Initial setup/type/lint/new-test issues were corrected only in ignored task setup or new code: loopback SSL mode, generated route types, the textarea's unreachable title comparison, a blank line in the appended frontend test, and the new controls-table comparison using release_id rather than id. No further existing assertion failure occurred in the selected or full run. The backend gain is three passing tests; frontend gain is one passing test. No test was weakened, removed or newly skipped.

## Chrome evidence

**24 synthetic-only private local screenshots**, ignored and uncommitted: `storage/app/private/followup-evidence/manifest.json`. Six families, each at **1440 and 390 CSS px**, light and dark, actual widths checked with devicePixelRatio 1, no horizontal overflow:

- release-list: creator and approver names; currently approved/withdrawn/prepared states together.
- release-detail: named list plus viewed release.
- release-history: prepared/approved/withdrawn actors all named.
- change-after-withdrawal: release 3 compares with approved release 1, skipping withdrawn release 2.
- little-shield-en: English selected, single-line Title.
- little-shield-bm: Bahasa Melayu selected, single-line Title.

Chrome DOM additionally confirms Title is INPUT, maxlength 120, height 38 px. The first capture inherited system dark mode; it was correctly renamed dark and a separate actual-light capture taken. All final capture names match observed themes. Temporary viewport override reset. No real staff/patient data, screenshots or credentials are committed.

## Open questions

- PR number remains **#TBD** until the owner authorises opening it.
- No new functional decision is needed. Unchanged W1e-1 image-copy and complete-branch-profile limits remain deferred; no work on them in this slice.

No commit, push or PR before the owner's review. Nothing public or production-approved.

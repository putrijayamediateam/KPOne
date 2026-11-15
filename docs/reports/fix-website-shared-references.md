# Website shared references — local candidate

Owner-authorised build, 2026-10-23. Branch `fix/website-shared-references`, base `origin/main` b49a96d. No push, PR or merge. Served checkout untouched. No permission, public route, dependency, publishing, outbound application call, import or staff-authority change.

## What changed

A coordinated append now treats branch availability, service/category parent and FAQ service link as item-wide references. It appends the requested language and, only when needed, a reference-only revision of the other saved language. All other JSON fields and the portrait/cover asset id are preserved. The existing service transaction advances the item version once, writes one command and one values-free audit, and returns retries without another write. Ordinary non-shared saves retain their previous audit contract; coordinated audits additionally name both languages. Existing branch validation, fresh authority, organisation/effective-branch checks, locking, optimistic version and payload-conflict rules stay in their original services.

The helper applies to doctor profiles, careers, categories, services, promotions, activities and FAQs. Branch profiles remain unchanged and are excluded from deferred trigger event queuing by its WHEN predicate. Missing service translations preselect shared references only, never translated text. Legacy inconsistent parents are not rewritten: a visible editor notice explains that the next save converges onto the edited language's choices. Existing dangling FAQ links have a separate notice and can be cleared. Services with active linked FAQs cannot be archived; the refusal counts distinct FAQs, not translations, within the organisation. Archive/unarchive of people content redirects explicitly to its item page. SEO labels use uppercase SEO.

Changed runtime files: `WebsiteSharedReferences`, the People/Services/Editorial services, `WebsitePeopleController`, their three Vue editors and corresponding TypeScript payload types. `bootstrap/app.php` is unchanged: consolidating its route-specific trim exceptions is possible, but not necessary for this fix and would require a separate behavioural equivalence check. The safest narrow choice is to retain its explicit list.

## Migration and database mechanism

`2026_10_25_000100_coordinate_website_shared_references.php` adds functions/guards only; no table, grant or legacy-data rewrite. Its down method retains evidence and protections.

The coordinated path is an atomic pair of append-only inserts under the existing organisation/item locks. A `DEFERRABLE INITIALLY DEFERRED` PostgreSQL constraint trigger checks the **latest saved EN and BM values at commit**. A divergent single insert fails and rolls back; a matching two-language transaction succeeds. The application also explicitly checks consistency before returning, including within a caller's outer transaction. Existing update/delete/truncate protections remain intact. Reference comparison normalises branch ordering and reference case.

This chooses a database invariant rather than a writable bypass flag: no marker can disable the check. Temporary divergence is possible inside the writing transaction, never in its committed output. The constraint check also takes the item row lock, serialising direct writers before comparison. An authorised direct SQL client can insert a consistent pair too; the database is not an application-permission boundary. Privileged clients able to disable triggers remain trusted operational actors. Deferring the check changes failure timing from insert to transaction end, so direct-write tests explicitly force the constraint inside their savepoint. The organisation lock serialises saves and service/FAQ archive checks in normal application paths.

A narrowly recognised reference-only copy can retain already attached retired media in the other language: identical non-shared JSON and identical hero id are required, and shared references must actually change. This prevents coordination from editing or losing the other language's media. The current user-selected revision still passes the original active-media validation; newly attaching a retired asset is refused. Portrait selection rules are unchanged. The deferred equality check still applies to the pair. There is no application bypass parameter.

## Tests and every changed existing assertion

Seven new backend tests in `WebsiteSharedReferencesTest` cover all seven types, unchanged translated text/media and old rows, one version/audit, stale versions, retry/conflict, non-shared saves, direct SQL refusal, matching pairs, legacy notices/convergence, service FAQ counts, clearing legacy links, rollback on audit failure, explicit redirects and retained retired media. Full ID-ordered comparison of unrelated tables uses a populated clinical/billing fixture. Two new frontend tests pin notices, SEO labels, coordinated service entry points and explicit archive redirects. Existing permission/provisioning assertions and hub maps are untouched.

Every changed existing assertion is below; no other existing test file changes:

| File / test | Old expectation | New expectation and reason |
|---|---|---|
| `WebsiteServicesTest::test_language_snapshots_retry_versions_archives_history_filters_and_stable_slug` | EN branch remains original after BM chooses another branch | EN branch equals BM's branch. Shared references now converge; EN name assertion remains unchanged. Added both-parent-null assertions. |
| Same | Category-filtered search count 1 after BM clears parent | Count 0 under the old parent, plus count 1 without that category filter. Parent clearing is now item-wide. |
| Same | Revision count 2 through archive/unarchive | Exact count 3, including the coordinated EN revision; archive still appends no content revision. |
| Same | Second history page count 3 | Exact count 5: the initial BM reference change adds EN and the later EN reference change adds BM. Page size 12 remains exact. |
| `WebsiteEditorialTest::test_item_wide_branch_and_faq_service_references_refuse_language_divergence`, renamed `...coordinate_language_changes` | Refuse BM branch change and subsequent EN clearing; BM preselection original branch | Save succeeds; exact EN/BM branch equality to the selected branch, then both empty. Intentional coordinated-save rule replaces the old refusal. |
| Same | Refuse BM clearing FAQ link and subsequent EN clearing | BM clearing succeeds and both links are null; restoring it with fresh version/key makes both equal the service. Old retained-history link assertion stays. |
| Same fixture | Archive a service while its active FAQ remains | Archive that FAQ first, then archive service, retaining the existing refusal for a new FAQ linking an archived service. New archive invariant is separately tested with exact active counts. No original foreign/type/branch denial was removed. |
| `WebsiteEditorialTest::test_database_guards_refuse_evidence_edits_divergent_references_and_faq_branches` | Guard failure expected at insert inside an outer test transaction | Same failures/assertions, with `SET CONSTRAINTS website_shared_references_consistent IMMEDIATE` after the attempted write. This explicitly tests commit-time protection inside a savepoint rather than accidentally allowing the deferred event to remain pending. |
| `tests/Frontend/website-editorial.test.mjs`, `Editorial source adds no public delivery, scheduler, integration or permission` | Service source contains `other language` (old inline refusal) | Service source calls `WebsiteSharedReferences::append`; the coordinating rule now lives in that helper. Every negative integration/public-delivery assertion remains unchanged. |

No test was removed, weakened or newly skipped. Synthetic legacy fixtures temporarily disable only the relevant new guard on the **task-owned test database**, immediately re-enable it, and never add a production bypass.

## Validation

Task-owned PostgreSQL **18.6**, effective and actual target verified before requests: `127.0.0.1:58471`, user `sharedref`, database `kpone_shared_ref_testing`. Separate successful fresh/seed check: `kpone_shared_ref_seed_testing`; synthetic Chrome preview: `kpone_shared_ref_preview_testing`, application `127.0.0.1:54411`. No other database was used and no `.env` was opened.

The first completed full run found one failure: 1,346 passes, 25,767 assertions, two existing skips; the untouched W1a truncate assertion expected P0001 but got 55006 from unnecessary pending deferred events. The trigger now queues checks only for types with shared references; no W1a assertion changed. Final results:

| Gate | Result |
|---|---|
| Full backend, memory override, PG18 | **1,347 passed; 25,773 assertions; two existing public-registration skips** (1,349 total), 566.655 seconds, exit 0 |
| Focused final website suites | **55 passed; 3,879 assertions**, exit 0; W1a assertions untouched |
| Full frontend | **474 passed**, zero failures or skips, exit 0 |
| Pint | Passed, exit 0 |
| PHPStan level 7 | Passed, zero errors, exit 0 |
| Types / lint / format | All passed, exit 0 |
| Production build | Passed, exit 0 |
| Separate migrate:fresh --seed | Passed, exit 0 |
| git diff --check | Passed, exit 0 |

The final full suite ran alone. All 17 changed runtime/test/migration files have the same SHA-256 hashes as at the start of that run. The two existing skips remain disabled public-registration tests; no new skip. Logs are local under `/private/tmp/shared-ref-*.log`. Preliminary interrupted runs are not validation results: review retained strict FAQ-target validation and added item locking to the deferred check before the final full run. A briefly overlapping focused runner was stopped; the final full gate ran alone.

## Chrome evidence

Twenty JPEGs are local ignored synthetic review artifacts, not committed. Directory: `storage/app/private/shared-ref-evidence/` in this worktree. Each scenario has `-1440-light`, `-1440-dark`, `-390-light`, `-390-dark` suffixes:

- `promotion`: saved EN addition of Puchong is present in BM revision 2, whose BM wording remains intact; history retains the preceding revisions.
- `parent`: saved service parent change is present in BM, with its original text.
- `faq`: changed service link is present in BM with unchanged answer.
- `legacy`: visible divergent-parent notice before the converging save.
- `archive`: active FAQ count and actionable refusal before any archive occurs.

Chrome viewport geometry measured exactly 1440 or 390 CSS px, with document scroll width equal to viewport width in all captures; no horizontal overflow. Both themes checked explicitly; temporary viewport override reset. Preview fixtures include only synthetic content; the deliberate legacy case was injected in the task-owned preview database only. The fixture's legacy timestamp is synthetic and is not evidence of a production timeline.

## Open questions and deferred scope

None needed to ship this private-draft fix. Review the deferred-constraint transaction boundary described above. Consolidation of trim exceptions remains optional future work. Publishing must validate frozen references again; no publishing or public behaviour is added here. All other website programme and clinical/financial work remains outside this change.

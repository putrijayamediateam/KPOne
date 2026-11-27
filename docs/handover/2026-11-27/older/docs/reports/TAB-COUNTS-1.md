# TAB-COUNTS-1 — Registration and Consultation tab counts

Owner authorisation: 2026-11-04. Not production-approved. Synthetic data only.

## Scope and decisions

Surveyed and built from `7de0a307acf0165c289c4481cf9144ad46a0aa14` in the isolated `feature/tab-counts-1` worktree. Registration and Consultation receive a single additive `tabCounts` map of aggregate integers. Existing row fields, request fields, routes, permissions, migrations and stored data are unchanged. Counts mean list entries, not distinct patients: repeat visits count separately.

Registration's ordinary tabs include the applied patient search, doctor, visit type, priority, coverage, status and branch-local date range, ignoring only the selected tab and pagination. The existing date-range limit remains 31 days. Unsubmitted draft text does not change the applied counts.

**Owner decision, Option B:** the Dispensary list is unchanged. Its badge intentionally ignores the date range, visit type, priority, coverage and status filters, just like its list. Both use `DispensaryDirectoryService::boardQuery`, scoped to the same organisation, active branch, pending/dispensing cases, patient search and doctor. The exception is also recorded in the query's code comment. Returned and completed cases are excluded. No extra date/filter condition was added to that query.

Awaiting Billing is omitted from the count map when the actor lacks `billing.view.branch`; no billing badge is then shown. Dispensary counts are omitted unless the actor satisfies the existing Dispensary board's permission and CA/CA Supervisor role check. Existing tab-label visibility is unchanged; this slice does not grant access to a tab that previously returned 404.

Consultation uses the existing actor/branch scope, applied patient or queue-number search, doctor and priority filters, ignoring tab status and pagination. Waiting combines today's waiting entries and carry-over; future waiting entries are excluded. All active combines Waiting and Serving. Consultation Completed follows existing `removed` queue status, not payment completion. Serving and removed retain their existing date scope and 25-row cap; their badges count the full matching set, including entries beyond the displayed cap.

## Implementation

- `app/Domain/Visit/Services/VisitDirectoryService.php`: one conditional aggregate with shared tab predicates; use its selected-tab total for pagination instead of a second count. Include the same counts when Dispensary is selected.
- `app/Domain/Clinical/Dispensary/Services/DispensaryDirectoryService.php`: extract the unchanged board query for reuse by list and count; retain the existing authority and filters.
- `app/Domain/Queue/Services/QueueDirectoryService.php`: one conditional aggregate, including exact today/carry-over totals reused for pagination. Return `tabCounts` in both full and polling snapshots.
- `resources/js/pages/Registration/Index.vue`: badge values from initial/search counts; accept them only after existing generation, lifecycle and typing checks. Automatic failures retain the previous counts with the previous results. Manual clearing hides unavailable counts until a successful response.
- `resources/js/pages/Queue/Index.vue`: reactive badge values from the accepted live snapshot.
- `resources/js/types/queue.ts`: additive typed count map, optional for compatibility with older in-memory fixtures; current server responses supply it.
- `tests/Feature/Queue/TabCountsTest.php`: five new regression tests.
- `tests/Frontend/tab-counts-1.test.mjs`: four new frontend tests.
- `docs/reports/TAB-COUNTS-1.md`: this report.

`OperationalTabs.vue` already renders zero as `0` and hides only undefined counts. It is unchanged. QR Intake continues to read `props.qrIntakes.pendingCount` independently. The Registration 10-second and Consultation 3-second polling schedules, protections and back-off are unchanged. No new polling, public payload, TV field or consultation-dropdown field is added.

## Every changed existing assertion

**None.** No existing test file, fixture, expected value, test name or skip is edited. The only test files added are `tests/Feature/Queue/TabCountsTest.php` and `tests/Frontend/tab-counts-1.test.mjs`. Existing exact-key dropdown, TV and Patient-detail contracts are untouched. New tests pin the additive Registration response shape and integer-only count map.

The first existing-board regression replay exposed the new aggregate exceeding `QueuePerformanceTest::test_queue_poll_omits_stable_metadata_and_reduces_the_representative_query_graph`: 21 GET queries exceeded its unchanged ceiling of 20 (105 passed, one failed, 968 assertions). The fix reused the new aggregate's exact today/carry-over totals for pagination, removing redundant count queries. No budget or assertion was raised. The corrected targeted run passed six tests / 209 assertions, including that unchanged performance test.

## Query evidence

The before measurement ran the existing 400-visit pilot-scale fixture on the unmodified source, through an external measurement harness. Its repeated entries deliberately share a patient, proving these are entry counts.

| Source, 400 entries | Before | After |
| --- | ---: | ---: |
| Registration directory | 16 | 17 |
| Consultation full snapshot | 15 | 14 |
| TV feed, unchanged control | 2 | 2 |

There is no query per tab or per row. Registration adds one visit aggregate and one permission-gated Dispensary count, and removes its old paginator count. Consultation adds one aggregate and removes up to two old paginator counts. A new one-versus-400 test keeps fixed query ceilings and proves query count does not grow with the fixture size. Full ID-ordered comparisons of visits, queue entries, patients and audit rows are unchanged after reads. These are query-count checks, not a production load-test claim.

## New coverage

- Registration counts match each tab's list total under applied name/KP search, doctor, priority, visit type, coverage and status filters; non-zero waiting, serving, billing, completed, cancelled and Dispensary fixtures.
- An older normal OTC case remains in Dispensary despite a date/urgent/consultation/Panel filter combination that empties the other tabs; its count matches the unchanged Dispensary list, and a returned case disappears.
- Additive exact response keys and integer map; an actor without billing authority gets no billing count and still receives 404 for billing search. An actor without Dispensary role authority gets no Dispensary count.
- Branch and organisation isolation; status changes update both boards' counts.
- Carry-over plus today, 27 serving and 27 removed entries despite 25-row caps, all tab selections, applied doctor/search/priority, and forced doctor-own scope.
- Bounded 400-entry queries and unchanged source rows.
- Frontend badges use the actual computed tab declarations reactively, retain QR Intake's independent count, render zero with the real shared component, omit unavailable counts, and update through guarded existing responses.

## Environment and final gate

Task-owned PostgreSQL 18.6: host `127.0.0.1`, port `58823`, cluster `/private/tmp/kpone-tab-counts-1-pg18`. Databases `kpone_tab_counts_1_testing`, `kpone_tab_counts_1_seed`, `kpone_tab_counts_1_preview`, and the separate measurement database `kpone_tab_counts_1_measure_testing` were created UTF8 from template0. Environment and effective PostgreSQL database/encoding/cluster were verified before use; Laravel's effective preview configuration was also checked before HTTP requests. Explicit placeholder environment, task-only PHP/Composer 1 GiB setting, separate test/seed/preview storage roots. No real `.env` or `.env.preview` was opened or created.

Tested HEAD: `7de0a307acf0165c289c4481cf9144ad46a0aa14` plus this candidate's listed changes; application and test source stayed unchanged throughout the final gate.

| Check | Final result |
| --- | --- |
| `php -l`, three changed services and the new PHP test | Passed |
| Pint, PHPStan level 7 before the long suite | Passed, no PHPStan errors |
| Exact `composer ci:check` sequence | Passed; 1,586 tests reached: **1,584 passed, 34,150 assertions, two existing skips, zero failures/errors** |
| Frontend types, lint, format | Passed, both in CI sequence and separate frontend gate |
| `npm run build` | Passed |
| `node --test tests/Frontend/*.mjs` | **611 passed, zero failed/skipped** |
| Explicit PostgreSQL replay, all 18 `Postgres*Test.php` classes | **120 passed, 1,202 assertions, zero skipped/failed** |
| Separate `php artisan migrate:fresh --seed --force` | Passed on `kpone_tab_counts_1_seed`, dummy data only |
| Tracked and new-file whitespace checks | Passed |
| Existing tests compared against `origin/main` | No existing test file changed |

The final CI sequence took 879.138 seconds, including its backend run; the explicit PostgreSQL replay took 98.261 seconds. Raw logs are in the private evidence directory. The two full-suite skips are pre-existing; none of the new tests or PostgreSQL contention tests skipped. A preliminary CI attempt stopped at a blank-line lint error in the new frontend test before running backend tests; the new test formatting was corrected and the entire final sequence was replayed successfully.

## Headless evidence

Captured with **headless Chromium through Playwright**, using a task-local browser binary and synthetic data. Eight page/width/theme states and 12 PNGs: each page at 1440/390 px in light/dark, plus mobile captures scrolled to the last tabs. DOM checks verify every operational badge, the unchanged QR Intake zero, correct theme and no whole-page horizontal overflow. No browser page errors. Tabs retain their existing horizontal scrolling at 390 px; remaining badges are reachable by scrolling.

Registration fixture counts: All 8, Waiting 3, Serving 1, Dispensary 1, Billing 1, Completed 1, Cancelled 1; QR Intake 0. Consultation: All active 4, Waiting 3, Serving 1, Completed 1. These are independent list scopes, not a claim that the tab counts partition Registration All.

A real-page poll rehearsal added one synthetic waiting entry while both boards stayed open. Registration changed 8→9 and Consultation 4→5 without reload, both observed within 9.689 seconds. Only the existing `/registration/search` and `/queue/search` requests delivered updates. The fixture mutation was a task-only synthetic bootstrap, not a new application endpoint.

Private evidence and scripts are outside the repository at `/private/tmp/kpone-tab-counts-1/evidence`; screenshots are not committed. Manifest: `screenshots.json`. Images: `screenshots/{ca,doctor}-{1440,390}-{light,dark}.png`, plus `screenshots/{ca,doctor}-390-{light,dark}-last-tabs.png`. Rehearsal: `poll-rehearsal.json`; request statuses: `requests.jsonl`; query measurements: `baseline-queries.jsonl` and `after-queries.jsonl`.

## Handoff

Final fetch found `origin/main` unchanged at `7de0a307acf0165c289c4481cf9144ad46a0aa14`; the branch already contains it, so no merge was needed. All four named disposable task databases were dropped after validation and the task PostgreSQL cluster stopped. The preview server was also stopped. Private screenshots and logs were retained. The served tree remains clean on `main` and was not modified.

## Limits

- Badges refresh at the existing polling cadence and pause under the existing visibility, typing, menu and dialog protections; they are not push updates.
- Dispensary intentionally has different filter semantics, by owner decision.
- Consultation Serving and Completed remain capped at 25 displayed rows, even when the exact badge is larger. No history/pagination redesign is included.
- Existing tab visibility and authorisation refusals are unchanged.
- No permission, migration, dependency or operational-data change. Nothing here approves production use.

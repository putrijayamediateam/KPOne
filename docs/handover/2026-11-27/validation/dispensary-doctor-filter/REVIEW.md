# Prepare Doctor filter — review packet

Base: medicine-search1222, f2d64c2247737232329ced2e053b65c4c11bea99. Candidate: feature/dispensary-doctor-filter in /workspace/KPOne-dispensary-doctor-filter. Publication and final source hashes remain pending at this checkpoint.

The locked Pharmacy Prepare tab includes a Doctor selector that was missing in the real workspace. This slice adds All, OTC counter and only doctors represented by existing eligible cases at the actor's active branch. It does not add a staff directory, clinical state, grant or migration. Prepare rows/count apply the same Doctor and Type predicates; text search still does not affect status counts. Waiting/Done ignore and clear Doctor. All retains unassigned consultation cases; OTC uses immutable case type, not missing doctor identity.

Choices are loaded separately in bounded25-row pages, ordered by name then ID, with escaped literal search. Fresh active actor, existing canView, active organisation/branch and effective assignment checks precede lookup. Users have no public identifier, so authenticated encrypted organisation/branch/doctor selector tokens are used instead of a migration or raw ID. They are always resolved through the same eligible-case relation. Invalid, stale and foreign selectors normalize explicitly to All without returning a rejected label. Responses retain sensitive no-store and existing route permissions.

The UI keeps the server-selected label pinned, never selects automatically after option paging/search, and focuses a persistent named picker region synchronously before replacing choices. Failed reads clear loaded identities. A scalar-source context/tab/selection watcher and abort+sequence guard reject old branch responses while leaving ordinary Inertia list replacement alone. This is an explicit bounded overflow adaptation to the static demo.

## Verification and honest intermediate failures

- Backend final10 tests/267 assertions passed (new Doctor tests plus Type and medicine-search regressions). Additional legacy list/navigation/queue/latest-handoff14/219 passed. A proposed synthetic unassigned-consultation edge fixture was rejected by the existing visits_consultation_fields_check database constraint. Its failing log is retained; the new test was withdrawn rather than bypassing the constraint. No application assertion or schema was changed.
- Frontend14 focused actual-SFC/list/workspace tests passed, including a true reactive parent-list replacement during pending option search.
- PHPStan0; Pint, scoped ESLint and Prettier logs retained. Initial types failed because local Wayfinder generation omitted --with-form; generated artifacts corrected and full types/build passed. Generated files are excluded from publication.
- Design review caught that an array-returning watch getter still fired on entire props replacement. It was replaced with Vue's array of scalar sources, and the regression now replaces parent state.list, rather than mutating counts in place. Final corrected rebuild/browser remains pending at this checkpoint.
- Initial browser assertion ran while option choices were still loading. Evidence is preserved in browser-initial-search.log/search-debug.json; final harness waits the picker aria-busy=false. No false pass was recorded.

Synthetic fixture uses an isolated cloned *_test database. Existing test reference/actor setup and real clinical/handoff/dispensary services create26 prepared cases. Browser work is GET-only after login, not production activity. No reachable unassigned consultation UI state is claimed; the existing schema forbids that fixture. No real staff/patient data or secrets are included.

Independent Inventory source review is clear; Design closed the polling source finding and independently reran4 runtime tests. Final exact hash and settled browser review remain required. No merge and no full Pharmacy parity claim. Existing shell active-navigation/duplicate-branch presentation debts remain outside this slice; Whose cases remains separate.


Final browser checkpoint: corrected watcher bundle passed24 actual GET-only states at390/1440 light/dark, including25-option first page and visible second page, long selected label, option-search empty state with pinned selection, contradictory Type/Doctor empty cases, invalid-token reset, and403 clearing choices. No page overflow, browser exceptions or post-login mutation requests. New select/search targets measured44mobile/36desktop. Complete list-object replacement and branch late-response rejection are actual-SFC runtime evidence, not a real branch-switch browser claim. Frozen exact-source TypeScript rerun is tracked in types-frozen.log; final hash in source-manifest.json.

Frozen types-frozen.log PASS exit0. Both final peer reviews CLEAR at4cae3982. Published draft1231 head4a88fb3cb; clean branch, normal push, CI pending, no merge.

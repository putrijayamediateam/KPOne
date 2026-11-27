# F07 existing row context — frozen review packet

Base1231 head4a88fb3cb64abaf9258a2f26b772c101fefd2c24; isolated feature/dispensary-row-context. Exact nine-file patch cbb34921c165d85056a2cbc9d2c332ec37318fdc58bdcf80e762fcfdc58c0f56 and source hashes in source-manifest.json.

The locked demo's expandable row context was absent from the real workspace. This bounded slice exposes only existing Dispensary-board-authorized reason excerpt and saved coverage label on Prepare/Waiting. Visit/From remain in the surrounding row. Done does not receive context. No new demographics, allergy/history, private panel note, phone, patient link or clinical command is added.

The reader reuses VisitReasonService summary and exact Str::limit80 behavior. Both assignments and reasons are eagerly loaded, avoiding nested lazy queries. The existing unique visit-position plus position1..5 constraints bound25 cases to125 assignments. Snapshot labels and legacy fallback survive catalogue renames; coverage uses the saved name, never the current panel name. A defensive missing panel label remains unknown. Current PostgreSQL schema forbids panel-null visits, so that fallback is an actual-template test only, not a reachable HTTP fixture claim.

Native details retain open state and keyboard focus through same-case polling. Existing actions remain outside the disclosure. A focused removed row restores focus to the stable cases region only when its old element disconnected and document focus fell to body. Ordinary polling does not autofocus. Three old runtime harnesses add only the new component import stub; all existing assertions remain unchanged.

## Verification

- 11 PostgreSQL tests/200 assertions PASS, including three new tests for snapshot/privacy, legacy/null/Waiting/Done/scope and1-versus25-row constant queries with five reasons per visit.
- 16 focused frontend tests PASS, including two actual partial SSR tests for escaped text, absent values, labels and absence of actions. Initial new Reason-label regex assumed no template whitespace; corrected the new assertion only, failure log retained.
- PHPStan0, Pint, scoped ESLint/Prettier, full types and production build PASS. No schema or permission changes.
- 14 actual responsive role/theme states at390/1440, measured44/36 summary targets, keyboard open/close and zero page overflow. Actual15-second Inertia polling preserves the same open disclosure/focus. A separate guarded synthetic domain sendForDoctorCheck call moves the prepared case; the actual polling response removes its focused row and restores focus correctly. An additional actual polling run preserves focus outside rows in the search input.
- Browser requests are GET-only after login; the synthetic domain fixture transition is explicitly separate, using the existing service with no Verify or stock decrement. The clone and seeded references are synthetic. Readonly doctor rendering has no mutation controls, Done has no disclosure and the private panel note is absent.

Initial screenshot harness captured one Done state at the prior viewport width and showed the fixed shell header mid-document after focus scrolling. Corrected viewport/top-scroll capture helper and refreshed all14 static images without repeating the one-shot domain transition. Original lifecycle assertions remain valid and separately logged. No application change was needed for these evidence corrections.

Independent Inventory source review clear; final hash/Design visual closure pending at packet creation. Shared shell Home highlight remains separate inherited debt. No full Pharmacy parity, merge or production activation claim.

Final both peer reviews CLEAR atcbb34921. Published draft1235 head8f145d8da, clean normal push, CIpending. No merge.

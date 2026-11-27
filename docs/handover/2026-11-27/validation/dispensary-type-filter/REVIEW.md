# F06 Prepare-only Type filter review

Six-file candidate stacked on workspace PR1140, base825582b34536071e7ace1e9e545b02c61d49f341. Frozen patch1069fab922209645b533eccb34b64bff47d40a2a2eb9bbfb9ea7455028782e63; exact hashes in source-manifest.json.

The locked Pharmacy demo exposes Type only in To prepare. Existing immutable consultation/OTC values filter that tab's rows and count in the unchanged branch scope. Waiting/Done counts and branch-local Done-today window stay unchanged; Type normalizes to All outside Prepare. Submitted search, bounded25-row paging, case/label capabilities and Returns navigation remain intact. No mutation, grant, schema, clinical state or existing assertion change.

Validation:11 PostgreSQL tests/282 assertions (new3 plus existing list8),10 frontend tests (new2 plus existing8), PHPStan zero, Pint, ESLint and Prettier passed. Full types and production build passed. browser-results.json records20 actual page states across390/1440 light/dark: both selected types, filtered empty, Waiting, Done; additional assertions cover page2 retention, reset to page1, search-independent counts, clear-on-leave/return-All and array normalization. Browser requests after login were GET-only. Synthetic own cloned DB contains26 new OTC and one consultation created by existing domain fixture methods; one pre-existing OTC makes filtered total27. No original database changes.

Inventory independently reviewed actual backend source/tests and cleared the bounded change. Design final review is recorded separately. No full-demo parity claim: F08 Whose cases, Doctor filter, medicine/queue search and inherited shell presentation remain separate. No Ready lifecycle is inferred.

Initial new runtime harness lint found one missing blank line before a loop; corrected before final lint/tests and freeze. Pint formatted the inserted controller statement. No failed app behavior was observed. Build emitted existing font-resolution warnings; font files are present in served public/fonts and actual page captures rendered normally.

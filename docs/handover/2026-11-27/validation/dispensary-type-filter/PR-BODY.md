To prepare now supports the demo's Type filter using existing consultation and medicine-only cases. Its rows and count share the filter; Waiting and Done keep their existing scope. Leaving Prepare clears Type, pagination retains it within Prepare, and empty results explain how to reset the filter.

Stacked on #1140. No permission, schema, clinical lifecycle or existing assertion changes. Verify still completes the case; this does not introduce Ready or complete all Pharmacy demo gaps.

Validation:11 focused PostgreSQL tests/282 assertions,10 frontend tests, changed-path PHPStan/Pint/ESLint/Prettier, full types and production build passed. Twenty actual synthetic browser states cover390/1440 light/dark, both types, empty results, paging/search and tab reset; browser interactions were GET-only. Independent backend and Design review recorded in the review package.

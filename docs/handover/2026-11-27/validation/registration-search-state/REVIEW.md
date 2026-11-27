# Registration patient-search state

Bounded prerequisite before the separately planned Find/New composition. Base: `f8e45b027a9842728440eff19d818e866927e6d2` (draft #1211). Exact two-file patch: `909c6bdb0ae777b7affff444acf9181820d99114d3cc7e1e0fa78e95b4b0d29e`.

The empty-result message now distinguishes pending, failed, and accepted-empty responses. Each request captures its query and generation; abort, query change, clear, and unmount invalidate older work. Both asynchronous boundaries, errors, and final cleanup verify identity. Enter cancels queued debounce and avoids an overlapping request for the same current query.

Quick create conditions are unchanged, including short nonempty queries with no rows and an empty recent list. No successful-search prerequisite, Find/New mode, backend duplicate rule, authority, migration, or submitted registration command changes. The existing registration immutable-retry logic is unchanged. Existing assertions were not edited. This is not full demo parity; the inherited general guidance about searching first remains for the upcoming explicit mode composition assessment.

## Validation

- 15 focused tests pass: six new actual-SFC controlled request tests plus existing extraction and immutable-dialog command cases.
- Full frontend: 2,237/2,237 pass; types and production build pass.
- Full ESLint, resources Prettier, scoped checks and diff check pass.
- Actual browser: 16 captures at 390/1440, light/dark; eight recorded proof flags; no JavaScript errors or registration POSTs. Synthetic visit count remains nine.
- Browser HTTP 500, empty response, empty-recent projection, and response delay are controlled fixtures. Real authorized patient search supplies the delayed/current patient data. Chromium aborted old requests; abort-ignoring late completion is proven only by separately controlled promises against the compiled actual SFC, not claimed as a demonstrated production browser incident.
- New tests cover A→B→A, stale HTTP and delayed JSON failures, stale cleanup, clear/unmount, repeated Enter, and truthful accepted-empty state. Existing backend is untouched; no new backend suite was warranted.

Independent final review is recorded separately. No merge or deployment.

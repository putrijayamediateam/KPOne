Panel price review now keeps organisation-wide proposal context beside the existing workflow on desktop and below it on mobile. Counts explicitly distinguish total proposals, the loaded waiting subset and the current decision page. Mandatory effective-time, four-eyes and refusal explanations remain beside the existing actions.

The Vue script is unchanged from main: request payloads, selection, versions, draft handling, permissions and financial rules are preserved. Local action targets are at least 36px on desktop and 44px below 1024px. Three new rendered tests cover the composition; reviewed existing assertions only accommodate formatter whitespace.

Validation: 14 focused and 2,208 full frontend tests; types, scoped lint/format and production build pass. Actual synthetic browser checks cover 390/1440 light/dark, both roles, long names, draft retention across tabs, focus return and a real partial bulk result (four approved, one refused). No backend/schema source changes; no new full backend/migration run. Multi-page draft retention was not exercised.

This remains a bounded presentation slice, not full Panel demo parity. Separate global Approved/Rejected views are deferred. No grants, migrations, production data or deployment.

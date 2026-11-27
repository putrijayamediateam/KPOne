Queue and Registration now render the authorized emergency lane independently of ordinary filters, with25 rows per page and server-clamped pagination. They reuse existing board row projections and action handlers, preserving Call In, readiness, versions and identity scope. Keyboard paging moves focus to a persistent named region that survives a failed request and describes its error.

A distinct first commit corrects an existing capability mismatch found in the real browser: `canSet` no longer advertises Mark not ready for the latest red triage, matching the already-approved service refusal. Existing flags, clear/override authority and two-query batch cost remain unchanged. No new clinical policy, grant or migration.

**Stack:** #1193 shared UI → #1188 presence → #1185 lane backend → #1182 lifecycle → Treatment stack. Family #1148 remains a final workflow prerequisite. No archive #1142 merge or production activation; this is not full Queue/demo parity.

Validation: focused frontend73; full frontend2257/2257; types/build/lint/format; backend21/424, PHPStan0/Pint; independent capability review. Actual browser18 checks cover25+1 pagination, own scope, filters, keyboard error/retry, responsive light/dark and red readiness restriction. Manual Call In via the existing dialog proves Cancel sends nothing and explicit Call removes the row;5 DB invariants pass. Strict filtered HTTP/DOM proof after that Call shows0ordinary rows while all25remaining emergencies remain. Stage-specific counts and excluded harness-only errors are documented in the recovery note.

Frozen17-file patch SHA256: `1ee88356ed7646d643d247c6f18aa3574dbb7c67bdbcd3d673ac8d940135aa82`. Exact owner-approved assertion provenance is recorded in `docs/reviews/TRI2-LANE-UI-RECOVERY-2026-11-26.md`.

Parent #1180's independently diagnosed random-name fixture failure is fixed on main by #1194 (merge9764037a). This stack keeps its reviewed base; incorporate main normally at the coordinated release refresh rather than treating the old failed parent head as green.

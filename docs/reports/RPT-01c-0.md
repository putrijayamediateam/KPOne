# RPT-01c-0 — payment-method classification

Owner authorised the five sequential reporting slices on 2026-10-20. This first slice is based on canonical main `571dbbf`; subsequent slices must start only after its full gate passes. No push, PR or merge is authorised.

## Change

Adds nullable Cash / Non-cash classification and a classification-specific `lock_version` to Payment Methods. A separate action on the existing screen uses the existing `payment_methods.manage.organisation` permission (Director, Finance Officer, Administrator). No new permission or patient access. Organisation-owned methods remain shared across its branches; an active assigned branch and fresh actor/grants are required for the mutation. Foreign organisation methods are refused.

The action validates input, opens a transaction, locks authority and the method, checks the optimistic version, increments it on change, and records only the method identifier and `classification` field name. Audit failure rolls back. A same-value request is a no-op; a stale changed request must reload, so retry cannot duplicate a mutation. Ordinary name/status settings retain their existing contracts and do not use this classification-specific version.

Migration `2026_10_21_010000_add_payment_method_classification.php` adds columns and a PostgreSQL allowlist check. Retained case-insensitive CASH codes become Cash; other retained methods become Non-cash. Replay never overwrites later choices, and down does not delete columns or decisions. Newly created methods can remain Not classified; reports count that as Non-cash with a visible note. Classification is current reference data: changes restate later-generated reports of historical periods, never stored payments or receipts.

## Tests and assertions

Three new backend tests cover all catalogue roles, foreign organisation, input/stale version, fresh revocation, audit rollback, actual migration backfill/replay and full ordered source-row comparison before/after each Cash, Non-cash and cleared action. The comparison includes all other database tables, including populated invoice/payment/allocation/clinical rows; stock tables in that fixture are empty. No old audit or source row may change. One new frontend test pins version binding, fallback and retrospective-report wording and compilation.

**Changed existing assertion input:** `MedicalCertificateTest::test_payload_conflicts_stale_versions_duration_and_reason_consent_are_refused` derives its intended invalid 31-day end date from the same branch-local start date, rather than UTC `now()`. Adds an exact 31-day assertion; all existing refusal/count assertions remain. The first full gate exposed the existing test accepting a valid 30-day interval after Malaysia midnight. No MC runtime, clinical behaviour or financial rule changes. Existing payment method creation, name/status, payment snapshots and operational method projections remain unchanged. CSV encoding/cap and export throttle are not applicable to this settings-only slice: it adds no export. Its mutation keeps the existing settings route's 30/minute limit.

## Browser evidence

Real Chrome authenticated with a fictional `.test` Finance identity, separate disposable seed database. Exact measured CSS widths 1440 and 390, light and dark, no page overflow; the wide catalogue table scrolls internally. Saved a synthetic classification through the UI. Evidence directory: `/private/tmp/rpt-overnight-evidence/rpt01c0/`, screenshots `1440-light.jpg`, `1440-dark.jpg`, `390-light.jpg`, `390-dark.jpg`, `dialog-390-dark.jpg`. Appearance restored and viewport override removed.

## Validation

Full PostgreSQL 18.6 gate: 1,191 tests, 1,189 passed, two existing disabled-public-registration skips, 18,193 assertions. Pint, PHPStan level 7, TypeScript, ESLint, format check, production build, all 381 frontend tests, separate-DB fresh migration/seeding and git diff --check passed. Full backend uses PHPUnit `-d memory_limit=1G`. Task cluster is 127.0.0.1:58426; databases kpone_rpt01c0_testing and kpone_rpt01c0_seed_testing. Initial worktree bootstrap lacked generated Wayfinder files/Vite manifest; production build generated them. Initial new-fixture factory and cached-revocation issues were corrected, retaining every existing test. Frontend lint formatting was corrected without changing behaviour. No real `.env`, external database or production request was used.

## Open questions

None blocking. Not classified remains available for new methods and is counted as Non-cash until explicitly set, per the owner's fallback rule. Existing aggregate exports remain unchanged; the following authorised report slices consume the classification.

## Files changed

PaymentMethod model, PaymentMethodClassificationService, PaymentMethodController, routes/web.php, PaymentMethods/Index.vue, the additive classification migration, PaymentMethodClassificationTest, payment-method-classification.test.mjs, the MC invalid-duration fixture, docs/BASELINE.md, docs/INSIGHTS.md and this handoff.

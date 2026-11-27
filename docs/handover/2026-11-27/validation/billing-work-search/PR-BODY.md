Finance and Panel responsibility work lists can now search their existing records by patient name, invoice number or visit number. Literal matching applies inside the existing branch and authority scope before pagination; money, Panel responsibility and secured Review destinations are unchanged.

Search uses GET only. Paging retains the applied query while unsent text survives a same-context response. Branch/mode changes reset to loaded context; empty-page and zero-match messages are distinct. The existing financial safeguard tests now permit exactly one named search form while retaining all financial-action denials and amount assertions, following independent delegated review.

Stacked draft on #1178 (`feature/billing-work-mobile-cards`), seven files only. No financial writes, permission/migration changes, all-branch scope or Paid-date policy. Parent and integration release gates remain separate; this does not claim full Billing demo parity.

Validation:
- Existing + new backend: 9 tests / 423 assertions; final new class 3 / 120 after additional out-of-range coverage.
- Scoped PHPStan/Pint, full frontend 2,211 and types pass. Final presentation-only empty-page adjustment followed by 7 focused tests, lint/format and production build.
- Independent backend/design review and40 actual synthetic browser captures cover both modes at390/768/1440, failed reads and delayed-response branch replacement. Invoice/payment/coverage full-row hashes are unchanged before/after.

Runtime artifacts remain outside the repository at `/workspace/kpone-validation/billing-work-search/`. Initial fixture/harness and environment failures are retained and qualified in REVIEW.md; no failed gate was relaxed.

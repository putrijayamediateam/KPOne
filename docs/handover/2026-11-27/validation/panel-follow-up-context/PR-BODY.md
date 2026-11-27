Panel follow-up now keeps branch/Panel-filtered totals and page context beside the existing list on desktop and below it on mobile. The empty state names an applied Panel filter, and guidance distinguishes never-submitted prepared claims from follow-up after submission. Claimed-awaiting-decision and unpaid amounts retain their existing labels.

The complete page script, filters, claim URLs, money, eligibility and permissions are unchanged. Local back/claim/filter/pagination/help controls measure36px desktop and44px below 1024. Three new rendered tests cover scoped totals, both amount meanings, zero amounts, long identifiers and empty states; no existing assertions changed.

Validation: 7 focused tests; full 2,208 frontend tests/types/build before the final copy-only correction, followed by final focused/lint/format/build. Actual synthetic browser checks at 390/768/1440 light/dark cover filter roundtrip, scoped empty state, focus and target sizes. An isolated preview clock simulates 45 elapsed days over a real service-created claim without altering ledger timestamps. Multi-page navigation was not exercised. No backend/schema changes or new full backend/migration run.

Bounded presentation slice; no new financial actions, grants, migration or production deployment. Full Panel demo parity remains separate.

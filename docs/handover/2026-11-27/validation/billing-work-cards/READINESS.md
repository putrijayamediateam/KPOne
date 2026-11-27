# FinancialWork responsive cards

Candidate: `/workspace/KPOne-billing-work-cards`, branch `feature/billing-work-mobile-cards`, base `eec5d5444bd14b0358e0383c1ea5d427e4d33f11`.

Two-file presentation slice. Finance cards preserve six distinct amounts, invoice/patient/visit/branch/status and the existing authorised Review URL. Panel cards preserve responsibility and requested timestamp, explicitly separate from claims submission. Desktop tables and real pagination remain. Page-local review/header/pagination targets use 36px desktop and 44px below1024. Native explanatory disclosure describes actual readonly scope. No backend, money calculation, authority, schema, grant, existing assertion or shared primitive change.

Frozen patch SHA256: `51aad9576f8ca87607303dfe8ab5adfcb591d8951c1b738ddf1071badf9dd4bd`; see manifest.json for source hashes.

Validation: full frontend2208/2208, types and production build passed before final page-local pagination minimum; final production build and6 focused actual-render/existing financial-page tests passed afterwards. Final scoped ESLint/Prettier and diff checks pass. Backend not rerun because no backend changes. Browser evidence is synthetic, using existing role authority and guarded own task database. Browser harness initially selected a hidden My Day branch control and raced Review navigation; corrected harness uses the real list page and waits for destination. These were harness failures, not product authority workarounds. Final browser PASS:12 populated Finance/Panel states at390/768/1440 light/dark, zero document overflow and mutation forms; Review/pagination measured36px desktop/44px mobile. Both real synthetic roles received403 on the opposite list and navigated their existing Review URL at all three widths. Independent final review CLEAR; see INDEPENDENT-REVIEW.md.

Limits: desktop broad table retained; no full Billing demo parity claim. No production data, payments or roles changed. Existing tests are unchanged; new tests cover both modes, zero/empty/long identity and exact money semantics.

Suggested commit: `feat(billing): make financial work readable on mobile`

Independent review identified desktop Review discoverability beyond the existing horizontal table. Added visible desktop scroll guidance and aria-describedby/focus ring on the existing labelled tabindex0 internal scroller, without changing the shared table. Six focused tests, scoped lint and format pass after this addition. Final production rebuild passed after scroll guidance. Final browser evidence PASS on final source: both modes Tab reaches the actual scroller and then the visible Review link. Finance ArrowRight changes scrollLeft; Panel table fits at1440 and needs no scrolling.12 main screenshots plus2 keyboard screenshots; all previous role/target/no-overflow checks remain green. Independent final closure CLEAR: exact patch/hash verified and3 rendered tests independently passed.

Published draft PR1178: https://github.com/putrijayamediateam/KPOne/pull/1178. Branch feature/billing-work-mobile-cards, head aa3613c29bb241153f22d5406c8f0865ca65e2bb, clean tree. Initial exact-head CI snapshot: attestation queued; no green CI or merge claim. Coordinator notified for attachment and serial release reconciliation.

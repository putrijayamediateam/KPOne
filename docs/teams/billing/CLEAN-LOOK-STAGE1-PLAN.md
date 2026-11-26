# Billing and daily close — Stage 1 bounded restyle

26 November 2026, Codex billing_panel under B.E.R.N.I.S.S. Branch `feature/clean-look-billing-stage1`, main base `23639679e`. Recovered source is authoritative; the former container-only Billing restyle is unavailable.

## Source and plan

Compared current `BillingWorkspace.vue` and `Billing/CashClosings.vue` with uploaded demo `project/PatientBilling.dc.html` and `project/CashClosing.dc.html`, and coordinator's reconstructed Clean Look specification. Apply the look in place: quieter page surface, larger heading, readable card spacing, semantic warning/success tokens and blue focus styling. Keep the existing Billing columns and all role/state-controlled actions, financial computations, routes and request payloads. Remove the duplicate branch prefix in the daily-close description because the shared header supplies it. Daily cash closing retains count/recount, four-eyes approval, locked-day/refund rules and negative cash support. No family billing, new permission, migration or API change.

Files: the two existing Vue components; this plan; optional new frontend regression coverage. Shared Stage 0 tokens/components are supplied separately by coordinator's shell candidate. No parallel layout/version.

## Explicit assertion decision — approved

Billing overrides shared controls to 30px desktop height; Clean Look specifies36px desktop/44px touch. Existing `tests/Frontend/dd-29-billing-touch-targets.test.mjs` pins the compact desktop override. Proposed single assertion change:

```diff
- assert.match(workspace, /min-height: 28px;\s*height: 30px;/);
+ assert.match(workspace, /min-height: 36px;\s*height: 36px;/);
```

Corresponding source block changes min-height28/height30 to36/36; desktop font becomes14px, inline padding14px. Existing below1024px44px rule and its assertion stay identical. Owner explicitly approved this exact one-assertion and desktop36px change on26November2026, relayed by B.E.R.N.I.S.S.; applied as approved.

## Risk and verification

Pure presentation changes can still cause overflow/hidden actions. Preserve existing class-sensitive assertions unless owner approves a concrete change. Run focused Billing/daily-close frontend tests, scoped lint/format and diff checks; compare script blocks to main for exact parity. Browser review at390/1440 light/dark and the coordinated full gate remain required before release. No backend suite needed for unchanged PHP/business logic. Proposed commit: `style(billing): apply Clean Look to billing and daily close`; proposed PR describes in-place surfaces/spacing/tokens and unchanged financial rules with validation evidence.

## Initial implementation and validation

Applied the bounded presentation slice in the two existing components. Billing's title is26px, header/columns have calmer spacing, page surface uses background, focus uses ring rather than brand, fields inherit shared radius/fill. Both pages use semantic warning/success tokens; cash-closing status gains a decorative dot while its existing text and conditions remain. The date form wraps on narrow screens. Desktop buttons now use the owner-approved36px height,14px font/padding; existing44px touch override is unchanged.

Both `<script setup>` blocks compare byte-for-byte identical with main. No PHP, API, permission, migration, request handler or financial calculation changed. Exactly one existing assertion changed with explicit owner approval, as listed above. Existing Billing/daily-close frontend coverage: **126/126 passed** (`/tmp/kpone-billing-cl1-focused.log`). Scoped ESLint and Prettier pass; diff-check clean. A final token-only banner correction reran daily-close/status tests separately (`/tmp/kpone-billing-cl1-status-recheck.log`).

Commands: `node --test tests/Frontend/bil-*.test.mjs tests/Frontend/*billing*.test.mjs`; `node_modules/.bin/eslint resources/js/components/billing/BillingWorkspace.vue resources/js/pages/Billing/CashClosings.vue`; scoped `prettier --check`; `git diff --check`. Script parity checked against `git show HEAD:<path>`.

Self-review: no business-rule change; conditional action visibility/copy retained. Dependency: coordinator's Stage0 tokens supply success/warning washes and card surfaces. Independent Design review and combined390/1440 light/dark screenshots remain pending. This is an initial visual slice, not whole Billing-family-flow acceptance. Exact patch: `/workspace/kpone-review-bundles/BILLING-CL1-current-main.patch`.

Independent source review by Codex staff_design found no new behavior blocker; combined visual review must check header wrap and larger desktop button font/padding at390px. Documentation reconciled to the approved36px decision.

Final focused run after approved desktop size/assertion change: **126/126 passed** (`/tmp/kpone-billing-cl1-final-focused.log`), scoped ESLint/Prettier/diff checks pass. The final banner-token spot check was11/11 passed. No runtime test process remains. Combined Stage0 + Billing types and build passed; final CSS-only visual follow-up build also passed. Browser evidence is tracked below.


## Combined preview and independent visual follow-up

Disposable combined tree `/workspace/KPOne-billing-preview` uses only its own synthetic database `kpone_billing_preview_test` and preview3035. Registration, consultation, invoice finalization, payment and issued documents were produced through existing domain services: invoice RM40, paid RM15, due RM25; daily closing expected cash RM15. No financial math or real data changed. Combined types/build passed (`/tmp/kpone-billing-preview-types.log`, `/tmp/kpone-billing-preview-final-build.log`).

Independent Dispensary and Design review found no behavioral blocker; both script blocks remain byte-identical to main. Review caught preexisting34px payment input targets on mobile. Local input CSS now enforces36px desktop/44px touch, without changing any further assertion. Duplicate daily-close branch prefix removed; explanatory wording retained. Focused126 tests, scoped ESLint/Prettier and diff checks passed after those fixes. Final screenshots and runtime dimensions are in `/workspace/kpone-validation/billing-cl1/`. Dark captures wait for theme transitions to settle; early captures from the wrong occupied port were explicitly rejected and overwritten.

Risk/limit: this bounded restyle does not constitute acceptance of every payment, reversal, refund, closing approval or family-billing workflow. No new assertion beyond the explicitly approved DD29 change, no permissions or migration introduced.

Final browser result:16 main-state captures (Billing, daily cash closing, issued invoice and issued receipt at390/1440 light/dark) plus4 payment-dialog captures passed exact-route and horizontal-overflow checks. Payment amount/reference and method trigger measured36px desktop and44px mobile. The shared shell branch selector remains36px and is outside this Billing slice. Screenshots are presentation evidence, not an assertion that all financial workflows were executed. Independent Design re-review cleared settled main-state colors, header/action wrapping and duplicate branch text. Evidence: `/workspace/kpone-validation/billing-cl1/browser-results.json`, `payment-results.json`, and `/workspace/kpone-validation/billing-cl1-independent-review.md`.


### Add Payment follow-up

Independent dialog review measured desktop Add Payment at32px due to its local `size="sm"`. Replace that single prop with `class="min-h-11 lg:min-h-9"`; default Button size supplies36px desktop and the explicit minimum preserves44px below1024. Scripts, handlers and existing assertions unchanged. Focused126 tests, scoped lint/format and script-parity/diff checks passed (`/tmp/kpone-billing-cl1-addpayment-tests.log`). Independent review separately identified the shared dialog close hit area; Design owns that separate slice, so this Billing package does not claim every shared dialog control is compliant.

Final runtime measurement passed at390/768/1440 light and dark: Add Payment44/44/36px respectively, no horizontal overflow. Build passes in `/tmp/kpone-billing-preview-addpayment-build.log`; refreshed6 dialog screenshots and `payment-results.json` are in the existing evidence directory. Shared close remains16px in this Billing-only preview, explicitly tracked by Design separately.

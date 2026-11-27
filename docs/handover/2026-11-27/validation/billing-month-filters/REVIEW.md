# Monthly cash-closing status/flag filters

Authorized continuation of existing Billing demo work. Isolated branch feature/billing-month-filters, stacked parent PR1165 at114fd56decdbb4b709c6fa083464a5d1bd1df6d4. Two files: CashClosingMonth.vue and NEW cash-month-filters.test.mjs. Frozen patchc13f7634a43242544a66ed197f2aad67f9b157b4079905889fd9c62bef96e8a5; exact hashes in manifest.json.

Native Status/Flag selectors intersect exact loaded DTO fields, preserving row order and immutable approved money snapshots. Today uses the supplied business date. All summary/count/money expressions are unchanged and remain full-period, with explicit filters-do-not-change-totals copy and matching/loaded day coverage. No-match state retains start-date/month warnings and offers Clear. Filters reset only when loaded month/branch identity changes (including shared active branch ID); same-month DTO refresh and failed navigation retain current criteria. No backend, permissions, migrations, financial logic or existing assertions changed.

Validation:21 focused tests pass, full frontend2212/2212 pass, types/build and scoped ESLint pass. New actual-page-script tests cover ordered intersection, exact Today, zero results/reset, negative approved snapshot identity/full-summary invariance, same-name branch identity reset, same-month refresh retention and failed month navigation. Final browser PASS; independent Design final closure CLEAR; Inventory visual observations also clear.

Preview is synthetic-only3143 using existing own billing preview DB. Initial3142 launcher used an invalid local-only synthetic key and its child retained the port after launcher stop; no product source change was needed.3143 uses the correctly sized fixed testing key. No real .env or production data involved.

Suggested commit: feat(billing): filter loaded cash-closing month days

Browser found wrapped-label accessible names included option text; repaired new controls with explicit separate label for/id. Final21 focused tests and production rebuild pass after this presentation-only fix. Design independently reran4 actual-script tests and found source clear; final browser passed; visual closure CLEAR.

Final browser3143:24 actual synthetic CA/BranchManager month views (all/waiting/empty at390/1440 lightdark) plus2 real aborted-request error states. Exact summary text stays unchanged, no overflow, filter/Clear targets36desktop44mobile, native ArrowDown selection and Tab focus pass. Failed month request retains criteria; actual retry to another month and return resets to All. Final captures scroll to top before full-page screenshots to avoid a sticky-header stitching artifact. No monetary mutations were performed by this slice.

Published draft PR1187 https://github.com/putrijayamediateam/KPOne/pull/1187, branch feature/billing-month-filters, head5aa3b959147620ca29a5d28082b5a1c414b47a28, stacked1165. Root notified to attach. No merge/deployment.

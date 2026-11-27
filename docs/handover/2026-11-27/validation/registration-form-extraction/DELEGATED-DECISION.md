# Exact test relocation decision

2026-11-26. Owner standing delegation permits faithful technical test maintenance after independent review; this is not a fabricated direct owner answer.

Staff Design reviewed the exact PROPOSED-source-test-relocation.patch. Initial review identified the dynamic adult-confirmation loop, then caught an overbroad public path replacement in the proposal. Neither defective proposal was applied. Final coherent diff was regenerated and git apply --check passed; reviewer cleared it before application. Existing public QR/Billing adult-confirmation exclusions remain unchanged. No-V2 and no-native-select guards include both wrapper and extracted form.

Nineteen files retain all 551 existing assertion call sites. Source locations move from Registration/Create.vue to RegistrationCreateForm.vue where the behavior moved. Test names, payload/value/privacy/phone/guardian/duplicate/retry assertions are preserved. Counts are lexical assert.method call-site counts, not the number of loop iterations or a claimed semantic proof. The exact inventory records per-file counts; runtime and source parity provide the complementary evidence.

Follow-up independently cleared: PROPOSED-dynamic-path-relocation.patch moves three assembled Registration paths in guardian, Gender and MY country-default checks; effective Patient and PublicCheckIn paths unchanged. The first focused run identified these omissions; no assertions were weakened. New runtime wrapper fixture was corrected to read the actual page, then all four runtime cases passed.

Full-suite follow-up independently cleared: PROPOSED-final-dynamic-paths.patch moves DARK-1 and nationality dynamic paths. Final scope21 existing test files, 581 existing lexical assertion call sites retained; no expected values changed.

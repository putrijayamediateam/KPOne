# Independent formatting follow-up

CLEAR the three uncommitted test formatting changes on base16aeb2c52 in KPOne-dispensary-publication. Independently parsed before/after dark-1, ds-01b-3-otc-dispense and ds-01d-dispensary-layout with Babel and compared ASTs after removing only location/start/end/extra raw-format metadata. All three are identical, including literal values, regexes, assertions and control flow. Git diff scope contains exactly these three test files; no application source delta.

This is formatting repair of previously reviewed assertions, not a new assertion approval. Root's focused24 and formatting gate results remain separately recorded; no need repeat unchanged heavy gates. The forthcoming current-main refresh still requires exact-head parity review before publication.

## Final main refresh

CLEAR exact headf7c729b2f09a7c9e9f387333bf75d894a4698994. Independently verified mainf8dd19cb83a6129479246db1c62d0af12cd28fb5 is an ancestor, exact nine-file scoped diff, and all nine blobs identical pre-refresh6e181b715. The three formatted tests retain the independently verified AST parity above. Root final24 focused checks are a separate gate; full2205/type/build/lint evidence predates formatting and SUG main merge and is not represented as a new full-suite run on this head. Remote CI remains publication validation.

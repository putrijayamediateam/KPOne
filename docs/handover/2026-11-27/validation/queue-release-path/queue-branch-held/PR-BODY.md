Branch On hold now shows **Treatment step** and **Held** as dedicated columns. The original status, triage, warnings and controls remain under Patient; the real treatment badge appears once. Own Held and all other task/default/emergency layouts remain unchanged.

Stacked on #1244. Two files only, with no new data, endpoint, permission or clinical action. Mutually exclusive renderer placement preserves the single parent-owned treatment dialog and readiness forwarding.

Validation: full frontend2320, types/build,18 actual component/lifecycle tests, PHP UI contract1/21 and lint/format pass. Four actual own/branch desktop/mobile states verify badge/status placement, unsuccessful outcome and44px actions below the persistent host; eight synthetic clinical/stock tables unchanged. Two faithful existing layout expectation adaptations independently reviewed; unrelated/default assertions retained.

Review draft only; no merge/full-demo parity/production activation claim. Archive #1142 must never be merged.

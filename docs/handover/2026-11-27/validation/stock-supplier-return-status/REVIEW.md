# Supplier Return Show filter

Frozen source is in SUPPLIER-RETURN-STATUS.patch and source-manifest.json, stacked on PR1230. Exact existing state mapping and representative group semantics are retained; page sizes25/10, scoped authority, mutation paths and lot availability are unchanged. No existing assertions changed.

Validation: backend28/338, focused frontend24, full frontend2279, types, scoped lint, full resources formatting, Pint/PHPStan and production build pass. See individual logs. Initial new fixture failures corrected only invalid fixture command payloads/reference reuse; initial browser harness failures corrected Inertia v3 bootstrap parsing.

Actual own preview3186 confirms All31/16→Open28/13→Done3/3; group page2 has3 rows, changing Show resets both request pagers while retaining delivery. Individual-only no-match search leaves3 groups. Native keyboard selection with intentionally aborted GET retains loaded Done. browser/results.json retains the expected injected Network error; see NETWORK-FAILURE-NOTE.md. Six390/768/1440 light/dark states have44/36 Show targets and no overflow. Builder inspected390dark and1440light. No whole-page target audit claimed.

Read workflows preserve all balance quantities,28 movements and63 actions from the baseline captured after normal domain fixture setup. Browser uses CA Supervisor; role/branch denials are backend evidence, not additional browser actor claims. Independent backend source review is clear; frozen visual/hash closure pending.

Final independent Billing and Design clear; published draft1236 at0ff4e0012083fabb1a066d4ac0f91dd605916ba8. No merge.

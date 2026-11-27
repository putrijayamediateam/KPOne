# Location filter handover checkpoint

Tree `/workspace/KPOne-stock-reorder-location-filter`, branch `feature/stock-reorder-location-filter`, HEAD/base `4f6f60cc0b209eee7fb0a5f2b14c8105e96d06e2` (#1250). Seven scoped files uncommitted. Frozen patch `9e1338a553c9fd3ddb502441aa6fa943c2d83a878ad4ccdec5ed1cab73abb106` and source-manifest.json adjacent.

Implemented optional strict location UUID before existing low-stock calculations/pagination, same current branch/org/active/non-returns source scope. New locations-only picker context refreshes actor and effective branch at both public entries; old contexts unchanged. Optional value-matched initialSelected preserves old callers and applied label after omitted match; page keys by branch/appliedUUID and paging uses applied rather than unsent selection. Existing Request these unchanged. No grants/schema/stock mutation or old assertion change.

Passed: existing+new PG17/133 (`backend.log`), final new4/34 (`backend-final.log`), full2299frontend, types-final, build, PHPStan0, Pint, final lint/format, focused19. Initial nullability/static findings fixed; retained first logs. Last optional error-label markup was added during full gate, before build; no business logic changed after successful types.

Final actual browser own3211/DBkpone_reorder_location_preview_test:12states CA/Finance390/768/1440lightdark; min44/36, noerrors/overflow. Real select+submit, initially applied selection survives first lookup omitting it, cost gate, GET-only downstream request prefill (not submitted). before/after parsed stock snapshots identical29movements16balances68returnactions. Browser final log exit0. Server session25522 active. No heavy pipeline active.

Both plan reviews clear; Billing preliminary source clear. Final Design/backend hash reviews requested, pending. Next: independent closure, final REVIEW/PR body, commit/push scoped draft stacked1250 and attach. No merge. Root requested checkpoint and no new work until report; report sent.

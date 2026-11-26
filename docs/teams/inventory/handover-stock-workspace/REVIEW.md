> Current status: superseded by RESUMED-DECISION.md. Owner resumed this existing slice and delegated technical decisions; the six proposed groups are now applied after independent review. Full frontend now passes2208/2208. Desktop target correction is implemented; final measured validation is recorded in the PR. The text below is the historical preservation record.

# Stock workspace / On-hand — first implementation review

26 November 2026. Candidate /workspace/KPOne-stock-workspace, branch feature/stock-workspace-demo, base c1e5fc05c0d231a28eeb3f2367ccc8adee0565c5. Uncommitted, unpublished. No migrations, grants, backend, shared primitives or dependency changes. Existing assertions untouched pending exact owner batch.

The Stock landing page now uses the demo's composition: compact group navigation, underlined directory views, real summary cards, filter toolbar with an expandable location picker, expandable stock rows, and a collapsible helpful-info rail. Each row explicitly represents one batch/location balance; original quantity/unit, date, hold reason, availability and count state remain server-owned. No partial-page aggregation or invented metrics. Original report capability and focus restoration remain. The old task-link directory remains in Tasks with all links/guards/order intact. Stock operations and authorized reference forms remain mounted behind workspace navigation so drafts survive switches.

This is a bounded first slice, not full Inventory completion. Demo's other Inventory sub-tabs, six domain group arrangement, HQ context, scanner/global report actions and other workflows are not claimed complete. Actual existing Batch & Expiry and Movements remain operational in their current table layout. Reports are a separate track. See PLAN.md and inventory-audit/INVENTORY-GAP-MATRIX.md for scope/bindings.

## Validation
- New actual Vue renderer tests:5/5 pass (runtime.log): exact fractional unit/expiry/hold display, independent expanded rows and reset on replacement, zero held quantity, dual authority plus original row/trigger forwarding, and loading hides stale row actions.
- Full frontend discovery:2203 tests,2196pass,7 failures. Six source-pinned groups affected by extraction/layout are proposed in ASSERTION-BATCH.md and ASSERTION-PROPOSAL.patch; no edit applied. Seventh was missing PHP PATH; isolated nav rerun5/5 passes. Therefore overall frontend gate remains pending owner assertion approval, not green.
- Full vue-tsc pass (types-final.log); production build pass (build-final.log); scoped ESLint, Prettier and diff whitespace pass. ESLint prints existing duplicate-plugin warnings only.
- Own preview http://127.0.0.1:3071, own synthetic DB kpone_stock_workspace_test cloned from earlier Inventory synthetic fixture. Added expired opening balance and branch hold via existing domain services (seed-review.php); no ledger/permission bypass. No .env. Port3054 was occupied by existing TRI2 preview and our server refused; switched to3071 before candidate evidence.
- Actual browser390/1440 light/dark:13 rows, held/expired fixture present, open detail, zero document overflow, no browser exceptions. Keyboard Enter expands, helpful info toggles, Tasks switches, server search excludes other SKU, Report difference opens and Escape restores original menu focus. results.json records these checks.
- Additional actual browser: unsaved reference quantity preserved across workspace switches; Batch & Expiry shows expired; Movements opens; selected Store filter excludes Dispensary (additional-results.json).
- Independent Staff review identified36px status selector; fixed page-local Status/Movement trigger minimum44px belowlg. Own help/location summaries also44px. Refreshed results measure all new visible mobile filter/help targets44px in both themes. No shared component change.
- Actual delayed directory response: loading hides old rows/menus and correct filtered rows return after response; no errors (loading-parity.json). Settled screenshots refreshed on final build.
- Actual Finance Officer login: same13 stock rows visible, zero Report difference menus and zero Reference data group; no browser exceptions (finance-readonly.json).
- Existing pagination renders13 records/page1of1; actual multi-page traversal not exercised because fixture has13 rows. Server pagination handler remains unchanged; runtime projection replacement resets open rows.
- No new backend tests/full suites run for frontend-only source. Earlier coordinator baseline backend validation is not presented as a rerun here. Synthetic fixture services and real authenticated app provide scoped integration evidence.

## Review / approvals
Independent Staff composition/source review pending final confirmation. Owner exact existing-assertion batch pending. Commit/push not authorized for this new candidate. Source patch/manifest adjacent; proposal assertion patch is separate and NOT part of source patch.

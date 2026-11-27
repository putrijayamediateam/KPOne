# Stock workspace: demo composition, bounded first slice

26 November 2026. Base main c1e5fc05c0d231a28eeb3f2367ccc8adee0565c5; isolated /workspace/KPOne-stock-workspace. Owner directed full demo content/layout/interaction work; coordinator assigned Stock shell and real On-hand first. No commit/push until reviewed approval.

## Files and ownership
- resources/js/pages/Inventory/Index.vue: context header, workspace group navigation, existing directory filters/views, actual summary, collapsible helpful-info rail, retain all task links and mounted reference/operations forms.
- resources/js/components/inventory/StockOnHandList.vue: responsive expandable balance rows with original status, units, hold reasons, open-count state and original DifferenceRowMenu event/authority.
- New focused runtime tests and implementation/validation documentation only. No shared primitive, backend, routes, schema, grant, dependency or existing assertion changes without exact approval.

## Binding and decisions
The demo shows aggregated item quantities and nearest expiry. Existing server contract is paginated batch/location balances. This slice explicitly calls each row a batch/location balance and displays its actual quantity, unit, batch expiry and availability. Never sum a partial page or claim a nearest expiry. Summary uses server-provided SKUs with stock / Total batches / Expired batches, with branch scope explained. Report difference remains row-specific and gated by canReport AND row.difference. Filtering/pagination preserve existing server requests and validation; row expansion is display state only. Expanded details disclose existing authorized projection, not new data.

Reuse demo composition (compact context header; pill group navigation then underlined directory views; summary cards; compact toolbar; readable expandable list; helpful-info side cards). Group navigation exposes implemented Inventory, Tasks, Stock operations and authorized Reference data. Existing task links remain exact and visible in Tasks; no invented HQ, scan, counts, permission gates or unsupported demo routes. Operations/reference panels remain mounted so switching display groups cannot discard drafts. No Classic/V2 toggle.

Other demo tabs and workflows remain gaps documented in INVENTORY-GAP-MATRIX.md; this slice is not whole Inventory completion. Actual Batch & expiry and Movements views retained functional, not redesigned in full here.

## Risks and validation
Inspect existing source-pinned assertions; report exact old/new batch before any test edit. New runtime cases must exercise row expansion, display fidelity (held, expired, zero/fractional units), projection changes, report authority and event forwarding. Focused original tests then broad frontend scan to capture all affected pins. Full type/lint/format/build serially coordinated. No backend change; focused existing directory tests if preview setup uncovers concerns.

Actual app browser evidence at390/1440 light/dark with synthetic nonempty stock, hold and expiry, open row, keyboard expand/collapse, filters and pagination, preserved difference authority/action and helper/group navigation. Check overflow, focus and no fabricated metrics. Independent Staff design/source review required; carry no historical approval as approval of this new diff. Separate own synthetic DB, no .env, no production.

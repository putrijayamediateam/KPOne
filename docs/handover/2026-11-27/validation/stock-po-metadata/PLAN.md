# PO optional delivery date and supplier reference — contract proposal

Source read: locked InvCreatePO.dc.html SPEC; 2026_09_11_000100_create_inventory_operations; PurchaseOrder model; ProcurementService create/updateDraft/submit/approve/lockOrder/transition; InventoryOperationsController; InventoryOperationsDirectoryService; PurchaseOrderPrintService; independent approval/short-close trigger migrations and PurchaseOrderApprovalTest. Base is Receiving1207 head12b79b6f6. No implementation or migration execution yet.

## Smallest contract

Add nullable `expected_delivery_date` SQL DATE and nullable `supplier_reference` bounded business string (proposed120characters). A date is a calendar expectation, not an arrival timestamp, overdue blocker or guarantee. Both optional for new and historical POs; no backfill inferred from created/received time or supplier lead days. No uniqueness constraint: quotation references may be reused. Past dates remain valid historical expectations; no new future-only business rule. No pack conversion, total, staff metadata, routing or approval transition.

Create accepts omitted/null/blank as null. Draft update distinguishes omitted keys (preserve existing values for old clients) from explicit null/blank (clear). Validate scalar strings before trimming; reject arrays, invalid exact YYYY-MM-DD and overlong/control-character references. Format date as date-only without timezone shifts. Reference remains ordinary escaped supplier business metadata and never enters audit values. Existing PO visibility already exposes supplier/location context; project these fields only with the same scoped PO DTO/print authority. No new name/directory lookup.

Only existing CREATE_PERMISSION users can set these during create or updateDraft. Existing active branch/location/org checks, locked fresh authority, order version lock, draft-only status and line update remain. One successful update increments the existing lock_version once. Submitted/approved/received/closed/cancelled orders cannot edit metadata via draft endpoint; submit/approve/receipt/close payload allowlists must not accept metadata. Existing independent approver rule stays intact. Purchase-request conversion callers omitting fields remain compatible.

## Proposed migration, preservation and audit

One additive migration with two nullable columns, no data rewrite/index/grant. No-op down preserves newly entered business history, matching recent approved additive migrations. Up guards existing columns for rollback/reapply compatibility. A separate metadata-only PostgreSQL trigger may enforce the already-existing draft edit boundary: changed values require OLD.status and NEW.status both draft and version+1; inserts at normal initial draft are permitted. It must not replace or modify independent-approval/short-close guards. Exact trigger inclusion is a technical review decision before patch execution, not a new lifecycle. Existing raw create/update audit records gain only field-presence/change booleans or field-name list; never reference/date values. Existing event/action identity and transaction retained; no unrelated global audit changes.

## Bounded implementation files and UI

Migration; PurchaseOrder date cast; ProcurementService metadata validation/preserving merge; controller create/update allowlists; InventoryOperationsDirectoryService two selected aliases; PurchaseOrderPrintService two read fields and print template labels; InventoryOperationsPanel create/edit state/payload and optional DateField/reference controls plus readonly context; existing DocumentRow type. Keep existing commands and price visibility. Display unknown as Not specified, not fabricated date/reference; no lead-time suggestion until a separately reviewed existing terms contract supports it.

New tests: omitted legacy create/update compatibility; explicit set/clear and trim; leap/invalid dates/arrays/overlength; null/historical rows migration preservation and rollback/reapply; stale version and all non-draft transitions refuse modification; revoked/inactive/org/branch authority; independent approval retains metadata; query/print scope and non-cost viewer unchanged; raw reference absent audit; no stock/money movement. Trigger tests if included. New actual-SFC tests cover create/edit exact payload, error draft retention and no state reset on harmless refresh. Existing procurement/approval/print/receiving tests unchanged unless exact faithful source pin review is needed. Real synthetic create→edit→submit→independent approve→print shows saved metadata and stale/refused paths at390/768/1440 light/dark.

## Technical choices needing peer/coordinator review

120-character reference bound and nullable preservation semantics are conservative implementation defaults, not owner-attributed policy. Separate metadata trigger is proposed as defense of existing draft/version boundaries; reviewers may prefer service-only consistency with current PO columns. No genuine business decision blocks optional metadata; do not defer it as an unspecified full-demo feature.

## Delegated coordinator approval —26 November

B.E.R.N.I.S.S explicitly approved the exact migration76d874383747b49f34bc94e87a9ca90e63f57ae6899a4236a224e4adb0ac96c7 and reviewed optional contract: UPDATE-only changed-metadata draft→draft/version+1; no replacement of approval/short-close guards, no INSERT restriction; nullable/no backfill/omitted preserve/explicit clear/no-op down. Execution is isolated synthetic only. This is delegated coordinator approval, not a fresh owner reply. Billing independently read exact migration clear; Staff optional input/read/print placement clear.

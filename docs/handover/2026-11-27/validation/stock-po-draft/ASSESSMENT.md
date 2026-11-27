# Purchase-order draft: next bounded assessment

Read actual InvCreatePO.dc.html SPEC and current InventoryOperationsPanel.vue after Receiving frozen30e9741d. No implementation begun.

Current create command posts expected_branch_id, supplier_public_id, destination_location_public_id and lines of sku_public_id, quantity and rmToSen(estimatedUnitCostRm). Success resets supplier/destination and one blank line; existing send controls busy/errors/idempotency. The create permission remains the outer gate. Existing add/remove helpers and selected lookup options remain authoritative.

Proposed presentation-only boundary: compose the existing form into clear Order / Lines / Before saving groups, with container-aware line layout inside the actual narrow Operations parent, visible quantity and optional per-stock-unit estimate labels, original supplier/destination lookup, explicit Create PO draft action, and manual line focus. Preserve exact command and subsequent existing submit/review actions; no invented one-click submit.

Demo facts not supplied by this command: expected delivery/lead-time, supplier quotation reference, pack conversion, calculated estimate total and preparer attribution. These must remain explicit contract gaps pending separate actual-service/read-model assessment. Demo approval-role wording must not override current governed policy. No new fields, totals, grants or approval rules are proposed in the bounded form slice.

Before implementation: Staff plan review; inspect exact source-pinned tests and old add/remove/error/reset behavior; decide whether local composition can retain every script handler. Acceptance would include actual synthetic draft save/refusal, unchanged retry semantics, dynamic line Add/Remove focus, null versus zero cost, no accidental submit and390/768/1440 light/dark at real parent width. This is assessment only, not completed work or full demo parity.

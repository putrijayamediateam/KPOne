# Independent Billing backend contract review

Plan CLEAR for bounded implementation, 27 November 2026. Reviewed the corrected PLAN and actual InventoryPickerService search/choices, ReorderSuggestionService page and InventoryStockReviewService low-stock query. No application edits or independent runtime tests performed.

The existing picker default 25/max 100 is preserved. A locations-only reorder context is necessary because directory includes returns and reference/warehouse contexts allow wider locations. Fresh active actor/permission/effective active branch checks must apply at both public entries; the supplied Branch must equal the freshly selected branch. Keep expected_branch_id validation at search and reject all non-location kinds in the new context. Old contexts remain unchanged.

Resolve optional scalar UUID under the same organisation, active branch, active location and non-dispensary-returns constraints as lowStockQuery, then apply l.id before the existing calculation and pagination. Invalid or unauthorized UUID must not broaden to All. A valid location with no low rows may truthfully return empty. Preserve cost redaction, quantity algorithm, row grain and Request these semantics. New applied label/branch identity is display context, not authority.

Final review should verify fresh cached-actor revocation, both public entry points, malformed/foreign/inactive/returns UUID refusals, same-SKU two-location isolation and unchanged default/cost DTOs. Kind classification remains explicitly unresolved; do not infer it from medicine mappings. This is plan clearance, not implemented-source or publication clearance.

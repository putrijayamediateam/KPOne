# Phase 3A-Core — Dispensary and Minimal Inventory

Phase 3A-Core adds an explicit, auditable handoff from a doctor-owned Treatment Plan to branch Dispensary fulfilment. It excludes Quick Treatment Sets, procurement, receiving, stocktake, billing, payment, claims, and production stock migration.

## Lifecycle and safety

An attending resident doctor may send an `in_progress` Treatment Plan only when current care, the exact Plan version, active Medicine Orders, Allergy Profile, Encounter Allergy Review, and every Medicine Order safety version are current. Sending changes the Plan to `ready_for_dispensing`, increments it once, records that post-transition version in a new immutable handoff attempt, snapshots active Medicine Orders, and moves the serving Queue entry to the structural `sent_to_dispensary` removed state. No stock moves on send.

Branch CA and CA Supervisor users with explicit permissions may fulfil the open case. A `patient_declined` partial or zero result creates version-bound evidence requiring the attending doctor's acknowledgement. `out_of_stock`, `clarification_required`, and `other` require Return to Doctor.

Return to Doctor is the only service allowed to move this removed Queue entry back to serving. It closes the handoff as returned, unlocks the Plan to `in_progress`, increments it, records the Returned marker, and never changes stock. Re-send is explicit and creates a new immutable attempt.

Completion repeats Allergy and Plan-version checks under lock. It requires finalized items, exact batch allocations, current acknowledgements, eligible unexpired batches, and sufficient balances. Stock deductions, one immutable movement per allocation, workflow completion, and structural audit commit atomically. Physical handover follows successful commit.

## Inventory foundation

`InventoryItem` is product identity; `InventorySku` is the governed stockable unit. Medicine mappings are explicit and never imply clinical equivalence. Aliases improve search only. Locations are hierarchical and balances remain distinct: HQ Medical Stock, branch Store, and branch Dispensary never silently share quantity.

Batches carry status and expiry. FEFO accepts mapped SKU, permitted location, positive balance, `available` status, and expiry after the branch-local current date, ordered by expiry, receipt date, then ID. Cold-chain fields are schema-ready only.

The materialized balance has no generic edit route. Only Opening Balance, Transfer, and Complete Dispensary change it, paired with immutable `StockMovement` evidence. Availability shown in the browser is advisory; completion rechecks under database locks.

## Permissions, privacy, and locks

Doctors receive send and own acknowledgement only. CA and CA Supervisor receive branch Dispensary operations; organisation/HQ transfer is a separate explicit Supervisor permission. Director-only and Technical Admin roles receive no medication fulfilment authority.

The branch Dispensary board is structural. Medicine values, Allergy values, notes, and batches appear only in the private/no-store workspace. Routes use public identifiers, audits are structural, and sensitive request roots are excluded from old-input flashing.

Clinical lock order is actor security, Patient, Visit, Queue, Encounter, Allergy Profile/rows/Review, Plan/orders, Case/Handoff/items, ordered Locations/SKUs/Batches/Balances, movements, then audit. Inventory-only operations never acquire clinical locks afterward.

PostgreSQL constraints enforce tenant ownership, one case per Plan, one open handoff, quantity/status shape, nonnegative balances, unique dispense evidence, and deferred completed-case allocation reconciliation. The separate-process PostgreSQL race gate remains mandatory before release; a local skip is not release evidence.

## Non-goals

Quick Treatment Sets are deferred to Phase 3A's second milestone after independent review, dynamic PostgreSQL validation, and browser UAT of Core. Procurement, receiving, stocktake, substitution, billing/payment, claims, Visit completion, real Drug Master import, and production catalogue governance are not implemented.

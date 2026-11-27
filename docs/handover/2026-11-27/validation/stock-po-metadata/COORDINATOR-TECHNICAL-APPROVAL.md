# Delegated technical approval — PO delivery metadata

The owner authorized continued implementation and delegated technical approvals in this conversation. This records a coordinator decision under that standing authorization, not a new individual owner reply.

Approved draft migration SHA-256: `76d874383747b49f34bc94e87a9ca90e63f57ae6899a4236a224e4adb0ac96c7`.

The coordinator read the complete migration, the current ProcurementService create/update/transition paths, and the original purchase-order schema. Status and lock_version are non-null, with the existing status/positive-version constraint. The two optional fields remain nullable; historical records are not backfilled. Omitted draft-update keys preserve values, explicit empty values clear them, and reference/date values stay out of audit payloads.

The separate UPDATE-only guard is approved: only actual metadata changes require both old/new status draft and exactly one version increment. It does not modify the independent-approval or short-close guards, add an INSERT restriction, widen authority, or change the receipt command/hash. No-op down preserves the added data and guard; repeated up/down/up must be tested.

Implementation and migration execution are authorized only in isolated synthetic validation environments. Before draft publication, verify stale/non-draft/authority refusal, legacy omitted-field compatibility, preservation, scoped projections/print, transaction/audit behavior, and actual UI flows with independent final review. No production execution is authorized by this record.

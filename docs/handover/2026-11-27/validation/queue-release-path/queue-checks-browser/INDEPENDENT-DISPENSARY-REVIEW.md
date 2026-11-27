# Synthetic checks fixture — bounded domain-path review

Reviewed prepare.php against the actual queue-context-recovery service implementations on27 November2026. Read-only review; I did not execute the fixture or modify application source.

No blocking API/version mismatch found. The guarded testing database and one-shot batch marker constrain provisioning to the separate synthetic clone. createBatch and CA Supervisor openingBalance use existing authorization. The setting payload matches current()/setDoctorCheck version and retry contracts. Queue call then refreshed Visit/returned Queue versions feed encounter start. Allergy declaration/review, plan save and handoff use the existing consultation sequence. Dispensary start supplies the next case version; updateItem uses the item version and explicit new-batch allocation; refreshing the case before sendForDoctorCheck avoids a stale post-edit version. The send operation validates current allergy/plan/items and allocations but does not execute Verify or stock completion. No grants or direct clinical-state/ledger writes were introduced.

Runtime authority, existing source-clone readiness and exact membership remain to be proved by execution. The draft's earlier fixture establishes assigned-doctor readiness, but service guards must remain authoritative if it changed. Capture baseline stock and record versions after preparation and compare before/after browser reads; preparation itself intentionally writes an opening movement and clinical state.

Operational caveat: membership assertions currently run after the provisioning transaction commits. If an assertion fails, fixture.json will not exist even though preparation committed. The reserved batch guard prevents duplicate rerun, so do not interpret missing JSON as rollback or retry blindly. Inspect the committed state or recreate only the isolated disposable clone. Moving assertions inside the transaction would make this cleaner but is not a domain-path blocker.

Source review only, not runtime success or a clinical workflow approval beyond the already authorized synthetic preparation.

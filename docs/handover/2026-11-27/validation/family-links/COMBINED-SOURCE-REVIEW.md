# Combined Family + Emergency source review — 26 November 2026

Scope: current `/workspace/KPOne-ca-integration` after import ordering only; read-only review of routes, Patient/Clinical projections and registration retry paths. No DB, browser, migration, build or duplicate full-suite execution. Reviewer is the Patients implementation/integration owner; this is a fresh cross-slice reconciliation review, not a claim of independent authorship. Independent Billing/Panel backend review is recorded separately.

## Finding

No actionable authority, scope, privacy or retry regression found from the reconciliations.

- Family services/controller/cards are byte-identical to frozen reviewed feature. Emergency service, controller, registration service, request validation and reusable card are byte-identical to its separately validated feature.
- Patient GET policy authorizes view before details and emergency values. Merged record redirect precedes contact projection. Emergency GET remains patient-view/no-store; PATCH uses existing patient-update plus current transactional authority. Family GET separately checks both endpoints; Family POST uses existing update and repeats both endpoint policies under actor → ascending patients → pair locks. No new role grant, kinship authority or merged-record transfer.
- Both route pairs coexist; controllers delegate to their respective services. Emergency and Family cards receive separate payloads/endpoints and preserve existing guardian/merge components. Neither feature mutates the other's record.
- Registration command key still equals registration key; canonical patient/quick-create mode binding is retained. All early-existing/catch replay paths retain emergency compatibility checks; contact write remains within the registration transaction. Tombstone version and current-value retry behavior remain unchanged.
- Family canonical actor/key HMAC, both-endpoint current merge checks before replay, inactive version and original merged-pair unlink exceptions remain intact. Existing update-only/no-view actor is rejected before target lookup and again under locks.
- Clinical Family projection adds only an optional field after existing encounter policy. It checks current live serving consultation, active branch, direct canonical relative, patient policy and QueueEntry policy, with branch-local today/waiting filters and a bounded field allowlist. Cross-doctor queue authority is not widened. No emergency values appear in Clinical Family projection.
- Search/Registration board/Queue/TV/public projection paths contain no new Family or emergency values. The patient search used for manual selection retains its prior masked contract; there is no shared-phone suggestion or automatic relation inference.
- `source-inputs.json` retains all prior inputs and records frozen Family patch SHA256 `fae0131c8adb368809661ed70795c14bb9941c1bb34e9a95ca3a733127bd9418`. Conflict preservation evidence remains valid; current import ordering has no behavioral effect.

Limits: source review only; whole-tree backend/contention/static and combined browser gates belong to coordinator/other active teams. Planned unrelated Queue touch sizing is outside this review.

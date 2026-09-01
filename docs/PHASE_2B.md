# Phase 2B — Treatment Plan & Orders

## Scope and aggregate

Phase 2B extends an authorised, `in_progress` Clinical Encounter with at most one current `TreatmentPlan`. The plan contains ordered medicine rows and ordered clinical service/procedure rows. It remains `in_progress`; there is no signing, finalisation, Visit completion, Queue completion, handover, printing, dispensing, stock movement, billing, payment, or claim state.

The Treatment Plan is a separate optimistic aggregate from the Phase 2A Encounter. Saving clinical note/vitals/diagnoses and saving treatment therefore do not create false conflicts with each other. A material Treatment Plan save compares the exact plan version, replaces the desired active ordering atomically, withdraws omitted persisted rows, increments the plan version once, and emits one structural audit event. Missing plus empty input remains absent. A repeated identical first-save request resolves the existing aggregate without a duplicate plan, child, version increment, or audit.

## Ownership and permissions

Phase 2B adds `treatment_plans.view.own`, `treatment_plans.create.own`, and `treatment_plans.update.own`. Only `resident_doctor` receives them. Patient, Visit, Queue, Encounter, Allergy, Director, CA Supervisor, and Technical Admin permissions do not imply treatment authority.

Catalogue search and every save re-derive the active branch and transactionally revalidate the complete current care relationship: active account and Staff Profile, resident-doctor role, explicit permission, effective permanent or temporary branch assignment, same organisation, registered Consultation Visit, Serving Queue Entry, in-progress Encounter, current Visit doctor, and immutable attending clinician. The current-page projection remains restricted to the active resident doctor who is the immutable attending clinician in the same organisation and effective branch; Phase 2B introduces no handover or completion transition. The public route identity remains the Visit number.

## Catalogue boundary and snapshots

Phase 2B introduces small organisation-level medicine and clinical-service catalogues solely as governed selectable sources. Catalogue records use an immutable UUID public identity, an organisation-unique internal code, display name, unit, active flag, and safe type-specific descriptors. Medicines are classified `doctor_order_required`. Doctors cannot create ad-hoc catalogue records and no catalogue administration route is included.

Search is authenticated, care-relationship gated, POST-only, bounded to 20 records, wildcard escaped, and returns an explicit minimal projection. New orders require an active same-organisation item. Existing orders remain historically meaningful after catalogue rename or inactivation because code, display name, unit, and medicine strength/form are snapshotted at creation. No price, charge, stock balance, batch, expiry, or availability claim is stored or projected.

## Medicine orders and allergy gate

A medicine order contains its catalogue reference and immutable identity snapshot plus positive quantity, required doctor-entered dosage and frequency, and optional duration, route, administration instruction, indication, and precaution. KPOne performs no dosage calculation, recommendation, interaction check, contraindication decision, or medical normal-range logic.

Every medicine add or clinically meaningful active-order edit occurs only after the transaction locks the Patient Allergy Profile, ordered Allergy rows, and Encounter Allergy Review and calls `AllergyReviewGate`. The Profile must be explicit (not `unknown`) and the review must match the exact current Profile version and the current attending clinician/Encounter. Both explicitly reviewed `no_known_allergies` and `has_allergies` pass this freshness gate; the clinician remains responsible for evaluating the actual medicine against the recorded allergies. Each created or clinically changed medicine order records the exact validated Profile version. Risk-reducing withdrawal and position-only reordering still require current-care authority and the exact Treatment Plan version, but do not require a fresh Allergy review and do not refresh the order's historical safety version. Service-only changes do not require Allergy review and never manufacture Allergy state.

## Service/procedure orders

A service order stores its governed catalogue reference, immutable code/name/unit snapshot, positive ordered quantity, and optional clinical instruction. It is an order only. Phase 2B never states that a procedure was performed or fulfilled.

## Revision and retention

Active positions are server-derived and unique per plan/type. Persisted rows are not physically deleted during normal editing. Omitted rows become `withdrawn` with server-derived time and actor provenance and cannot be reactivated or moved to another plan. A newly added unsaved browser row may simply be removed before the aggregate is first persisted.

## Concurrency and lock order

Canonical lock order extends the released clinical order:

1. actor, Staff Profile, role/permission state, assignments;
2. Patient;
3. Visit;
4. Queue Entry;
5. Clinical Encounter;
6. Patient Allergy Profile and Allergy rows by ID;
7. Encounter Allergy Review;
8. Treatment Plan;
9. medicine and service orders by ID;
10. referenced catalogue rows by ID.

Database uniqueness is the final one-plan-per-Encounter guard. Tenant-consistent composite foreign keys constrain Encounter/plan/child/catalogue/user ownership. PostgreSQL checks enforce positive versions, quantities, positions, allowed state, withdrawal provenance, and partial uniqueness of active positions. A late child or audit failure rolls back the entire save.

The PostgreSQL-only regression harness uses separate PHP processes and connections, READY/GO coordination, an independent observer, `pg_blocking_pids`, and recursive blocker-chain proof. Its 12 groups cover duplicate creation, competing aggregate saves, medicine and service update/withdrawal races, independent Encounter-note and Plan saves, account/role/permission/assignment loss, Visit/Queue/Encounter current-care loss, Allergy Profile mutation, catalogue inactivation, first-create and existing-update rollback, and cross-tenant/wrong-doctor probing. Local SQLite runs intentionally skip these PostgreSQL-only groups.

## Privacy and audit

Treatment requests and catalogue search use authenticated private/no-store routes with encrypted Inertia history. Order input roots and clinical instruction fields are excluded from validation flash. Queue, Registration, Patient Master, Visit, general search, and historical Encounter projections do not receive Treatment Plan content in Phase 2B.

Audits are limited to `treatment_plan.created` and `treatment_plan.updated`. Metadata contains only the aggregate version and server-derived changed section names (`medicine_orders`, `service_orders`). It contains no medicine/service names, quantity, dose, frequency, indication, instruction, Patient/Visit identity, clinician identity, or raw request. The global Audit Log displays only a neutral “Treatment Plan record”, hides the subject ID, and neutralises the clinical actor.

## Production and future boundary

Real-patient production approval is **not granted**. Phase 2B does not provide catalogue governance operations, governed terminology, prescription signing/legal form, clinical finalisation/addenda, handover, medication decision support, performed-service evidence, dispensary reconciliation, downstream stale-allergy revalidation, inventory, billing, retention approval, recovery validation, migration validation, or downtime procedures. Those require separately approved later phases and production safety/legal review.

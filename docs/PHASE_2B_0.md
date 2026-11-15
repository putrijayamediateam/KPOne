# Phase 2B.0 — Structured Allergy & Problem List Safety Foundation

## Scope and clinical ownership

Phase 2B.0 adds organisation-level longitudinal Allergy and Problem records to the existing in-progress Clinical Encounter workflow. Access is available only through a legitimate current care relationship: the active resident doctor must own the in-progress Encounter for a registered Consultation whose Queue Entry is Serving, remain the Visit's assigned doctor, hold the exact clinical-safety permission, and retain an effective primary or temporary assignment to the active branch.

The phase does not add a general Patient allergy browser. Patient Master, Visit, Queue, historical Encounter authorship, Director, CA, CA Supervisor, and Technical Admin authority do not imply access. Longitudinal information may cross Klinik Putrijaya branches only because a currently authorised doctor is treating the same organisation-level Patient. All clinical safety routes are Visit/Encounter scoped, authenticated, private/no-store, and use encrypted Inertia history.

## Allergy status semantics

The Allergy Profile has exactly three meanings:

- `unknown`: no explicit current allergy assessment is recorded. A missing profile projects as `unknown`; a persisted unknown profile has null review provenance. It never satisfies the future medicine gate and never displays “No known allergies.”
- `no_known_allergies`: an authorised attending clinician explicitly declared that no known allergies were identified at that time. It requires zero active Allergy Records and explicit review provenance.
- `has_allergies`: at least one active structured Allergy Record exists. The service derives this state; browser input cannot assert it.

Zero active Allergy Records never implies `no_known_allergies`. Adding the first active Allergy creates or changes the Profile to `has_allergies`. Adding an Allergy after a no-known declaration also changes it to `has_allergies`. Entering the final active Allergy in error changes the Profile to `unknown` and clears its longitudinal review provenance. Entering one of several records in error leaves the Profile at `has_allergies`. No persisted Allergy Record is physically deleted or reactivated.

Allergy category (`medication`, `food`, `environmental`, `other`) and severity (`mild`, `moderate`, `severe`) are developmental structured-capture labels. They are not governed terminology, decision support, contraindication rules, or medical recommendations. Allergen is required; reaction, category, and severity may be absent and are displayed as not recorded.

## Profile versions and consistency

`patient_allergy_profiles` is a lazy one-to-one Patient aggregate root with an exact optimistic `lock_version`. Every clinically meaningful aggregate mutation increments the Profile once and appends exactly one immutable structural row to `patient_allergy_profile_versions` in the same transaction. Reads, no-op mutations, Problem mutations, audit side effects, and Encounter review do not increment it. The ledger stores version, resulting status, time, and actor reference, never Allergy content.

Application validation enforces the status/active-row relationship on SQLite and PostgreSQL. PostgreSQL adds deferred constraint triggers so the final transaction state must satisfy the same invariant at commit. Composite foreign keys enforce organisation ownership; public Allergy identity is a server-generated UUID; clinician, Patient, organisation, provenance, status, and version-ledger fields are never browser-controlled.

## Encounter Allergy Review and stale-review rule

`clinical_encounter_allergy_reviews` stores one current review reference per Encounter. It copies no allergen, reaction, category, or severity. It records that the attending clinician explicitly reviewed Allergy Profile version N for that Encounter. An explicit “Reviewed for this consultation” action is separate from Allergy mutation.

Review uses the canonical lock order, requires a non-unknown Profile and exact submitted Profile version, verifies the immutable ledger version, and creates or updates the Encounter review atomically. Repeating a current same-version review is idempotent and does not duplicate audit. If the Profile mutates first, a stale review submission fails. If review commits first and the Profile mutates later, the review remains trace evidence but is immediately stale because its version no longer equals the Profile version.

The reusable `AllergyReviewGate` defines the future medicine-save contract. A caller must first lock and revalidate the active clinical care context, current Profile, and Encounter review. The gate requires:

- active resident-doctor authority, effective active-branch assignment, exact future treatment/allergy permission, and attending-clinician ownership;
- registered Consultation, Serving Queue, and in-progress Encounter;
- a same-organisation Profile for the same Patient with status other than `unknown`;
- a review for the same Encounter/Profile by the attending clinician; and
- exact equality between reviewed and current Profile versions.

Both `no_known_allergies` and `has_allergies` can pass after an explicit current review. This gate proves review, not medicine suitability. KPOne does not infer whether a particular medicine is safe for a particular Allergy.

Future Phase 2B medicine orders must record the Profile version validated during order save. Phase 3A Dispensary must revalidate the current Profile before fulfilment and must not silently continue when the version differs. Neither medicine ordering nor Dispensary behavior is implemented here.

## Problem List

`patient_problem_records` is a separate longitudinal Patient model; there is no Problem Profile or Encounter Problem Review. Each server-generated public record contains required condition text, optional paired code/code-system values, optional onset/resolution dates, provenance, a row `lock_version`, and one of `active`, `resolved`, or terminal `entered_in_error`. Persisted rows are not physically deleted. Active conditions are shown first; a bounded recent resolved section remains available; entered-in-error rows are excluded from current clinical context.

Problem List is clinical context only. It does not gate future medicine save, run interaction or contraindication checks, create diagnoses, or read from/write to the Encounter Clinical Note.

## Permissions and lock order

Phase 2B.0 adds `allergies.view.own`, `allergies.update.own`, `allergies.review.own`, `problems.view.own`, and `problems.update.own`. Only `resident_doctor` receives them. A Director who is also legitimately a resident doctor receives them through the clinical role. No other current role receives clinical-safety access.

The canonical mutation order is:

1. actor User, StaffProfile, role/permission state, and branch assignments;
2. Patient;
3. Visit;
4. Queue Entry;
5. Clinical Encounter;
6. Patient Allergy Profile;
7. Allergy Records in ascending ID order;
8. Encounter Allergy Review;
9. Problem Records in ascending ID order where required;
10. future Treatment Plan and order rows.

Endpoints do not lock the Profile before Visit/Encounter. Profile versions prevent stale aggregate writes; Problem versions prevent stale/future row overwrites. Mutations and structural audits share one transaction, so a late child, ledger, review, Problem, or audit failure rolls the complete change back.

## Privacy and audit

The Clinical page receives explicit projections only. Allergy and Problem values are excluded from Queue polling, Registration, Patient Master, general Visit views, global search, URLs, session flash, and general audit metadata. Models are fully guarded and Eloquent models are never serialized directly to Vue. Sensitive Allergy/Problem input fields are included in `dontFlash`.

Structural events are `allergy_profile.updated`, `allergy_record.created`, `allergy_record.updated`, `allergy_record.entered_in_error`, `encounter.allergy_reviewed`, `problem.created`, `problem.updated`, `problem.resolved`, and `problem.entered_in_error`. Metadata is limited to structural versions and changed-section names. It excludes clinical values, Patient identifiers, clinician identity, and raw requests. Global Audit Logs use neutral “Clinical allergy record” and “Clinical problem record” subjects and suppress subject IDs.

## Doctor workflow

The existing Clinical page now places a compact “Allergies & Conditions” safety section before Vitals, Clinical Note, and Diagnoses. It explicitly distinguishes unknown, no-known, and recorded-Allergy states. Encounter review status is separate and changes back to “Review required” after a Profile mutation. Doctors can add/edit active Allergies, mark a record as entered in error with corrective wording, explicitly declare no known allergies, and explicitly review the current Profile. They can add/edit/resolve Problem records or mark them entered in error. There are no Delete actions.

## PostgreSQL concurrency and testing

Feature tests cover semantics, permissions, current-care/cross-branch scope, stale versions, review invalidation, Problem transitions, privacy, neutral audit output, and rollback. The separate-process PostgreSQL Clinical safety suite uses independent workers/connections, READY/GO, an observer connection, `pg_blocking_pids`, recursive blocker-chain checks, CRLF-safe input parsing, and strict testing database guards. It covers concurrent first Profile creation, no-known versus first Allergy, competing Allergy edits, final-error versus add, review versus mutation and the reusable gate, clinician authority loss, scope probing, and late rollback.

SQLite runs skip those PostgreSQL-only cases explicitly. Dynamic execution against the disposable PostgreSQL 18 CI database remains mandatory before release.

## Catalogue governance contract

Phase 2B will use governed organisation-level catalogue items with immutable public identity and organisation-unique code. Doctors may not create ad-hoc medicines. Rename or inactivation must not rewrite historical order snapshots; inactive items cannot be newly ordered. No role receives catalogue administration merely through clinical authority. Development and UAT use synthetic catalogue data only. Production import requires validation, reconciliation, approval, and structural audit. Clinical orders will not snapshot price or stock state.

## Production boundary and non-goals

This phase implements no Treatment Plan, medicine/service order, contraindication or interaction engine, terminology integration, prescribing/signing, Dispensary, inventory, billing, CA Allergy entry, migration from Yezza, broad clinical search, Patient portal, or Visit/Queue completion.

Real-patient production approval is not granted. Governed clinical terminology, correction/retention policy, legal review, role/access review, audit retention, backup/recovery validation, migration validation, downtime procedure, catalogue governance, prescribing/finalization, handover, and Dispensary reconciliation remain production prerequisites.

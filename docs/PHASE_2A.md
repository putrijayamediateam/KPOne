# Phase 2A — Clinical Encounter

## Scope and ownership

Phase 2A begins only after a registered Consultation Visit has a Queue Entry in `serving`. It adds one `ClinicalEncounter` aggregate per Visit, one current vitals observation, and zero or more ordered diagnoses. Patient remains the organisation-level identity, Visit remains the branch attendance, and Queue Entry remains the Waiting/Serving operational workflow. Clinical note, vitals, and diagnoses are never copied into Patient, Visit, or Queue Entry.

Phase 2A ends while the Encounter is `in_progress`. It does not finalize, sign, complete, reopen, hand over, or delete a clinical record. It does not change Visit or Queue status and does not create treatment, prescribing, orders, dispensing, stock, billing, claims, or patient-facing functionality.

## Encounter lifecycle and clinician ownership

The only Phase 2A lifecycle transition is absence → `in_progress` through Start Consultation. `clinical_encounters.visit_id` is unique, so browser retry, double-click, or two tabs resolve one logical Encounter. The resident doctor assigned to the Visit at successful start is snapshotted as `attending_clinician_user_id`; only that clinician may view or update it.

Start requires the same organisation and active branch, a registered Consultation Visit, a Serving Queue Entry, matching Visit and Queue versions, the assigned doctor, an active resident-doctor account, an effective branch assignment (primary or temporary), and exact clinical permission. Start creates one structural `encounter.started` audit record inside the same transaction. A vitals row is created lazily only when at least one measurement is actually saved, so observation/authorship provenance never implies that blank vitals were recorded. Visit and Queue versions do not change.

Ordinary clinical ownership never follows a later Visit edit. Phase 1C already prohibits doctor reassignment while Serving. Phase 2A contains no takeover or handover. If the attending clinician becomes inactive, loses the resident-doctor role, loses clinical permission, or loses branch eligibility, future clinical updates fail closed.

## Permissions

Phase 2A adds:

- `encounters.view.own`
- `encounters.start.own`
- `encounters.update.own`
- `encounters.history.view.organisation`

Only `resident_doctor` receives these permissions. Director, CA, CA Supervisor, Panel, Finance, Business Development, Marketing, HR, and Technical Admin roles receive none directly. A user with both Director and resident-doctor roles receives clinical access only through the clinical role. Patient, Visit, and Queue permissions never imply Encounter access.

## Clinical aggregate and stale updates

The Encounter is one versioned aggregate. An explicit Save may update the nullable 20,000-character clinical note, the one vitals observation, and the complete diagnosis list atomically. Every material save requires exact `lock_version`, locks the aggregate and children, increments the Encounter version once, and creates one structural `encounter.updated` event. No automatic conflict merging or autosave is present. A stale tab must review the latest record before resubmitting.

Canonical clinical lock order is:

1. actor User, StaffProfile, role/permission state, and branch assignments;
2. Visit;
3. Queue Entry;
4. Clinical Encounter;
5. vital observation;
6. diagnosis rows in ID order.

Visit and Queue remain registered/serving and are not versioned merely because clinical content changed.

## Vitals

Phase 2A stores at most one current observation per Encounter. Fields use systolic/diastolic blood pressure, pulse in beats per minute, temperature in Celsius, SpO₂ percent, weight in kilograms, and height in centimetres. Values may remain absent while the Encounter is in progress. Blood-pressure values are paired, structural measurements must be positive where meaningful, and SpO₂ is constrained to 0–100. These are storage/shape rules, not diagnostic ranges. KPOne adds no warnings, scoring, or medical interpretation. BMI is derived from weight and height and is never persisted.

## Clinical note and diagnoses

The UI uses one Clinical Note field rather than forcing SOAP sections. The administrative Registration reason remains a separate Visit field and is labelled separately.

An Encounter may have multiple diagnoses. Each row has required text, optional paired code/code-system values, a stable position, and an optional primary flag. Application validation and a PostgreSQL/SQLite partial unique index permit at most one primary diagnosis. No ICD catalogue or external code integration is included. An in-progress Encounter may be saved with no diagnosis.

## Clinical history and known limitations

There is no general Clinical History browser. While the actor owns the current Encounter and has the explicit organisation-history permission, its page may show at most 15 recent Encounters for the same organisation-level Patient across branches. This initial projection is structural only: consultation date, branch, attending clinician, status, and a public Visit-number-based detail route. It contains no historical note, diagnosis, vitals, Registration reason, or internal database identifier.

Each summary may open a dedicated read-only Previous Consultation page on demand. The response re-derives authority for every request and returns full note, vitals with derived BMI, and ordered diagnoses only when the active resident doctor either authored the historical Encounter or currently owns an eligible in-progress Encounter for the same Patient. The current-care path revalidates the active account, StaffProfile, resident-doctor role, history permission, effective branch assignment or temporary coverage, current Visit assignment/status/type, and Serving Queue. A valid current care relationship may cross branch boundaries within the same organisation; the historical Encounter's branch does not become an operational branch grant. Unrelated or cross-organisation probes resolve as not found.

Historical detail has no mutation, save, reopen, finalise, sign, delete, handover, or treatment action. Its projection excludes aggregate lock versions and writable metadata. Phase 2B may later extend this read-only page with separately authorised historical treatment information without changing the Phase 2A relationship check.

Phase 2A released without a structured allergy or underlying-condition source and deliberately did not misuse Clinical Note as longitudinal master data. The subsequent Phase 2B.0 safety foundation adds explicit longitudinal Allergy/Profile review and Problem List records while preserving the Phase 2A Encounter boundary. Medication and prescribing workflows remain unavailable.

## Privacy and audit

Clinical routes are authenticated, permission-protected, private/no-store, and use encrypted Inertia history. Current Encounter routes remain active-branch scoped. The historical route uses organisation-scoped public Visit identity only to support authorised cross-branch continuity, followed by a privacy-preserving current-care/authorship check that fails as not found. Controllers return explicit arrays, never raw Eloquent models. Clinical note, vitals, and diagnosis input roots are excluded from validation flash data. Clinical content is not placed in URLs, Queue polling, Patient search, general logs, validation messages, exception context, or audit metadata.

Audits remain limited to `encounter.started` and `encounter.updated`. Update metadata may contain only record version and changed section names (`clinical_note`, `vitals`, `diagnoses`). Global Audit Logs render a neutral “Clinical record” with no subject ID or clinical link. A one-off `encounter.history_viewed` read event is deliberately deferred: the current audit contract is mutation/security-event oriented, and clinical read-access logging requires one consistent policy for all clinical views rather than a special case that would leave current-Encounter reads unaudited.

## PostgreSQL concurrency

The separate-process Clinical regression harness uses independent PostgreSQL connections, READY/GO workers, an observer connection, `pg_blocking_pids`, and recursive blocker-chain proof. It covers duplicate Start, Call In versus Start, security-state and Visit-assignment races, stale aggregate/diagnosis replacement, transactional rollback, and scope probing. Both parent and worker enforce `APP_ENV=testing`, PostgreSQL, and a database name marked test/testing.

## Production boundary

Phase 2A has no medico-legal signing, finalization, addendum, handover, completion, structured allergy/condition master, production retention decision, or clinical recovery approval. No real-patient production approval is granted. Treatment Plan and every medication, order, dispensary, billing, completion, and patient-facing workflow remain later phases.

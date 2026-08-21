# Phase 1A — Patient Master

## Scope and ownership

Phase 1A answers “Who is this patient?” through an organisation-level canonical identity. A patient is never branch-owned and `patients` has no `branch_id`. Registration history will supply branch provenance in Phase 1B; Phase 1A contains no `first_registered_at` or `first_registered_branch_id`.

The phase includes internal search, create, detail, demographic edit, initial identifiers, controlled identifier correction/retirement, stable patient numbers, and structural audit evidence. It excludes registration, appointments, QR/OTP, patient login, queue, clinical work, prescribing, billing, panel, family/emergency contacts, merge UI, documents, notifications, and Yezza.

## Data model

- `patient_number_counters`: one row per organisation, conflict-safe insertion followed by `FOR UPDATE` allocation.
- `patients`: organisation ownership, immutable `KP-00000001` number, stated demographics, contact, address, actor references, and optimistic `lock_version`.
- `patient_identifiers`: separate NRIC/passport rows with issuing country and optional retirement timestamp.

Canonical identifier uniqueness covers current and retired history across organisation, type, issuer, and normalized value. A retired identifier is never automatically reassigned. PostgreSQL additionally enforces one current NRIC per patient with a partial unique index; multiple current passports are permitted in Phase 1A. There is no patient delete, soft-delete, merge, retention job, or automatic reassignment.

NRIC accepts spaces/hyphens and stores 12 ASCII digits with issuer `MY`; display is `YYMMDD-##-####`. No checksum or birthplace assumptions are invented. NRIC-derived DOB/sex may assist future input validation but never writes authoritative fields. Passport values use Unicode NFKC, trimming, uppercase, insignificant-whitespace removal, conservative punctuation rules, and a required two-letter issuer. Malaysian phone numbers are canonicalised to E.164 where valid. Names retain display spelling plus a normalised search form.

## Access matrix

| Role | Search | View | Create | Update | Manage identifiers |
| --- | --- | --- | --- | --- | --- |
| director | yes | yes | yes | yes | yes |
| resident_doctor | yes | yes | no | no | no |
| ca | yes | yes | yes | yes | no |
| ca_supervisor | yes | yes | yes | yes | yes |
| technical_admin and all other Phase 0 roles | no | no | no | no | no |

Permissions are `patients.search.organisation`, `patients.view.organisation`, `patients.create.organisation`, `patients.update.organisation`, and `patients.identifiers.manage.organisation`. Search access is organisation-wide and deliberately independent of active branch context. It grants only a bounded masked Patient Master projection and never future clinical access.

A CA may supply initial identifiers on create or add the first identifier of a type when no history of that type exists. Existing-value retirement/replacement requires the manage-identifiers permission. Correction locks the patient, retires the current row, inserts a new row, writes structural audit evidence, and commits atomically. Historical values are never edited in place.

## Privacy and threat model

Protected assets include canonical identity, document values, demographics, contact/address data, patient numbers, and access/audit evidence. Relevant threats are unauthorised role access, cross-organisation probing, excessive search disclosure, identifier enumeration, duplicate creation races, mass assignment, stale concurrent updates, PII propagation into URLs/logs/audit/browser history, and destructive history changes.

Controls:

- authenticated, active-user, explicit permission, policy, and organisation-bound route enforcement;
- cross-organisation patient-number lookup returns 404;
- POST-only identity search, meaningful input length, rate limits, fixed pagination, masking, and no automatic directory dump;
- full-detail allow-listed projections, private/no-store responses, and encrypted Inertia history;
- fully guarded models and prohibited ownership/system request fields;
- database unique constraints as final duplicate authority and neutral conflict messages;
- transaction/row locks for number allocation and identifier correction, plus optimistic demographic updates;
- allow-listed structural audit metadata with no patient PII or search query;
- no patient seeder and synthetic-only tests.

The application does not place NRIC/passport values, masked identifiers, names, DOB/sex values, phone, email, address, search terms, request bodies, exception detail, or hashes/digests in patient audit metadata. A global audit viewer without patient-view permission receives only the neutral subject “Patient record”, with no patient name, number, identifier, or link.

## Data classification

| Data | Classification | Search projection | Full view | Audit/log treatment |
| --- | --- | --- | --- | --- |
| patient number/name | confidential patient identity | number/name only | authorised view | not duplicated in audit |
| NRIC/passport | highly restricted identifier | masked | authorised view | never logged/audited |
| DOB/sex | restricted demographic | minimum DOB/sex | authorised view | changed field name only |
| phone | restricted contact | masked | authorised view | never logged/audited |
| email/address | restricted contact/location | omitted | authorised view | changed field name only |
| actor/event/version | security evidence | omitted | authorised activity | structural metadata allowed |

## Retention, backup, and production gate

No automatic retention/deletion schedule is implemented. Healthcare identity retention and disposal require formal Klinik Putrijaya legal/compliance approval. Recovery exercises must use synthetic data and verify encrypted backup, restricted restore authority, integrity, and approved RPO/RTO without copying production records to development.

Phase 1A implementation with synthetic data is **not production approval for real patient data**. Before rollout, approve legal retention, privacy notice, DPIA/equivalent review, production access matrix, HTTPS/HSTS/security settings, encrypted database/storage/backups, restricted DB/backup roles, recovery drill and RPO/RTO, logging/APM redaction, breach-response ownership, and final field-encryption reassessment.

## Known deferrals

Immediate patient deletion, soft deletion, identifier transfer, merge, family/emergency contacts, source-system mappings, registration provenance, clinical information, and all patient-facing access are later deliberate designs. These omissions must not be simulated with destructive or branch-ownership workarounds.

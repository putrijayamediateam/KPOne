# Emergency contact — Stage 1 proposal, 26 November 2026

Status: implemented and preserved as a draft PR for handover on 26 November 2026. The owner approved the three product decisions and exact two-table migration on 26 November; the approval record at the end of this document supersedes the original proposal status. The design and executable migration below retain their reviewed scope. No new permission or existing assertion change is included. Historical descriptions of missing persistence below describe the pre-implementation baseline.

## Evidence

The supplied Clean Look demo `project/RegisterVisit.dc.html` shows Name, Relationship and Phone with the heading “Emergency contact (required for adults)” and the badge “Proposal: needs owner approval”. `project/WfRegisterVisit.dc.html` says the contact is only for an emergency and is never used for search, matching or messages. These are design proposals, not recorded owner decisions. The full 26 November handoff confirms the deadline and Sunday fallback only.

Current Registration uses `VisitRegistrationService` and the Patient services. `PatientGuardianService` stores a separate encrypted guardian contact for minors; it must not become an emergency contact implicitly. There is no patient or visit emergency-contact persistence/API. HR emergency contacts belong to staff employment files and cannot be reused.

## Proposed smallest useful slice

After approval, add one independent contact per patient, entered or updated from Register Visit after selecting a patient, and during Quick create. Proposed fields: contact name, relationship and normalised international phone. Store contact values encrypted in an organisation-scoped patient contact record with a unique patient key and lock version. Keep the phone outside patient search, duplicate detection, WhatsApp, public QR and notifications. Existing guardian requirements remain unchanged.

Use existing patient view/update authority and branch-authorised registration, with no new role grant proposed. Mutations need server permission checks, transactional actor/patient/contact locks, stale-version refusal, idempotent register replay and a structural audit containing field names only. A contact change must not survive a failed visit registration or alter the contact when a completed registration request is replayed. Render the saved contact only through explicitly authorised patient/registration payloads.

UI proposal: a compact labelled section following the selected-patient card; stacked fields at 390 px, a row at desktop; persistent entered values and inline errors after validation failure. Do not expose new contact values in the board or TV. Balqis/Daniel review is needed before building this new section.

## Recorded owner decisions — 26 November 2026

1. Contact is **optional**. If any value is provided, all three fields are required. No adult-required rule.
2. Store on the **patient**, reuse on later visits, and **do not snapshot per visit**.
3. Show in **Registration and Patient details** under existing patient-view permission; update/remove under existing patient-update permission. No board, TV or public visibility.

These answers are approved and must not be re-asked. The exact two-table migration design below is the sole remaining schema-approval item. No new permission grant is proposed.

## Validation and delivery

Add new tests for successful save/reload, adult/optional decision, minors retaining guardian behaviour, invalid phone, cross-organisation and unauthorized refusal, stale updates, failed registration rollback, duplicate idempotent replay and audit redaction. Check existing-patient and Quick create paths, 390/1440 light/dark screenshots and keyboard/errors. List any existing assertions requiring changed expectations for explicit approval before editing.

The Sunday-afternoon fallback remains the handoff proposal: hide an unfinished field and report that emergency contact is unavailable; do not show a field that appears to save without persistence. Coordinator schedules implementation after exact migration approval.

## Exact migration design for review only

The owner approved an **optional patient-level contact**, without historical visit snapshots. This section is the exact proposed schema, not executable migration source. Apply no migration until the owner approves this exact design separately.

Proposed migration name: `create_patient_emergency_contacts` (timestamp assigned only when approved).

**Table `patient_emergency_contacts`:**

| Column | Exact proposed definition |
|---|---|
| `id` | bigint identity primary key |
| `organisation_id` | bigint not null; FK organisations(id), delete restrict |
| `patient_id` | bigint not null; unique; composite FK (patient_id, organisation_id) to patients(id, organisation_id), delete restrict |
| `encrypted_contact` | text nullable; encrypted JSON of full_name, relationship and phone; null is a cleared contact tombstone |
| `lock_version` | integer not null default 1, CHECK >= 1 |
| `updated_by_user_id` | bigint not null; FK users(id), delete restrict |
| `created_at`, `updated_at` | timestamp with time zone not null |

A tombstone preserves optimistic concurrency when cleared; an old version cannot silently recreate a removed contact. The relationship is encrypted with the other values, not searchable plaintext. No indexes or hashes of contact names/phone values.

**Table `patient_emergency_contact_commands`:**

| Column | Exact proposed definition |
|---|---|
| `id` | bigint identity primary key |
| `organisation_id` | bigint not null; FK organisations(id), delete restrict |
| `actor_user_id` | bigint not null; FK users(id), delete restrict |
| `patient_id` | bigint not null; composite FK (patient_id, organisation_id) to patients(id, organisation_id), delete restrict |
| `retry_key` | UUID not null |
| `payload_hash` | char(64) not null; keyed HMAC of canonical command including patient, expected version, operation and contact payload; never plain contact JSON |
| `created_at` | timestamp with time zone not null |

Unique constraint `(organisation_id, actor_user_id, retry_key)`, named `patient_emergency_command_retry_unique`. An append-only trigger refuses UPDATE, DELETE and TRUNCATE, following existing guardian command evidence. The transaction writes command evidence only together with the committed contact mutation. The same actor/key with another payload is refused. Rollback `down()` is intentionally non-destructive and retains both contact and retry evidence, following guardian migration policy.

No role grant, permission migration, backfill, patient search change, AI read-schema addition or guardian migration. Structural audit action `patient.emergency_contact.updated` carries patient/actor identifiers, operation (`set`/`clear`), version and changed field names only.

Validation under the approved product rules: empty contact is allowed; partial entries require all three values. Full name max 200 characters, relationship max 80 characters, international phone uses the existing normalizer. Blank existing form means no mutation unless the user explicitly chooses Clear; existing contact updates carry its lock version. Omitted contact payload leaves data unchanged. No automatic reuse of guardian or HR data.

Registration service integration: enforce existing patient update permission for editing an existing patient's contact; creation remains behind patient-create authority. Keep visit permission and branch validation unchanged. Acquire actor, patient and contact locks in a consistent order; use the patient lock to serialize first-contact creation. Existing visit-idempotency replay must not apply a new contact mutation. Entire visit/contact transaction rolls back together. Patient details displays the contact only under existing patient-view authority. Correction/removal controls require existing patient-update authority. Registration must not expose contact values to an actor without patient-view authority, even if they can register a visit.

Expected new tests (no existing assertions approved for change here): optional blank/all-filled/partial inputs; normalize phone; encrypted storage and structural audit; unauthorized/cross-organisation/branch refusal; stale set/clear; first-create race; same-key replay/mismatched payload refusal; failed registration rollback; existing completed-registration replay cannot change contact; guardian/HR/search/TV exclusions. Any existing test expectation changes discovered later are listed as a single approval batch before editing.

## Reviewable implementation contract

After exact migration approval, implement as one bounded slice:

- `PatientEmergencyContactService`: `contact(User, Patient)` checks patient-view authority and organisation before decrypting; `save(User, Patient, input)` checks patient-update authority for existing patients, actor activity and organisation inside the transaction. Explicit `operation: set|clear`, `lock_version` and `idempotency_key` are mandatory for mutation. `set` carries `full_name`, `relationship`, `phone`; `clear` carries no contact values. First insert uses expected version 0; every set/clear increments version. Return the new version; stale conflicts preserve entered values for review. The clear tombstone remains an internal record and reads as no saved contact.
- Registration selection payload: include an emergency contact only when patient-view authority is present, with its lock version; never attach it to general search-result rows. Fetch after selecting the patient via a scoped/no-store endpoint if the current selected-patient contract cannot separate it from search rows. Do not widen `PatientRegistrationSummary` to broadcast contacts across the recent/search list.
- Register Visit input: an optional `emergency_contact_command`; omitted means unchanged. Quick create may include an optional complete contact set, authorised with patient creation; existing patient changes require patient-update. All contact writes share the existing register transaction. Exact completed visit replay returns without mutating contact; incompatible command reuse is refused.
- Patient details read payload: scoped contact + current version only for patient-view users; Edit/Clear controls only for patient-update users. Use a narrow patient contact update endpoint with existing update permission, transaction and idempotency. Clearing asks for confirmation using the shared confirmation component. Do not broaden demographic edit permissions or expose contact via a generic model serialization.
- Shared UI field group: three visible labels, relationship text field, existing international phone input. Optional caption explains that entering any value requires all three. Empty form has no validation error; partial values show field errors. Registration and Patient details reuse this group; existing guardian controls stay separate.
- Validation and audits: normalise with the existing patient phone normalizer; only structural audit metadata. Command fingerprints use a stable application secret and canonical JSON; never log payloads. Route errors return field names/messages, never ciphertext or raw contact values.

Planned new tests are in a dedicated emergency-contact feature file and small frontend test file; existing assertions remain untouched unless individually approved later. Migration should be tested on the isolated Registration test DB only after explicit schema approval. Coordinator/independent reviewer reviews permissions, atomicity, idempotency and screenshots before release.

## Complete proposed migration (review artifact only)

Verified source parity: `2026_10_30_000100_create_patient_guardian_contacts.php` already uses the two-table contact/command pattern, HMAC fingerprints in `PatientGuardianService::hash()`, an append-only command trigger, and non-destructive `down()`. `2026_08_21_000100_create_patient_master_tables.php:41` provides the required unique `(id, organisation_id)` index on patients. The command composite FK below additionally prevents mismatched organisation/patient evidence. The trigger repeats existing guardian behaviour; it does not add a new trigger mechanism. Contact clear tombstones and a positive version check support the approved remove operation. No snapshot table and no grant are included.

The following is the **entire proposed migration**, held only in Markdown. No corresponding file exists in `database/migrations`, and this SQL has not been executed.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_emergency_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->unique();
            $table->text('encrypted_contact')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('updated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
        });
        Schema::create('patient_emergency_contact_commands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('patient_id');
            $table->uuid('retry_key');
            $table->char('payload_hash', 64);
            $table->timestampTz('created_at');
            $table->unique(['organisation_id', 'actor_user_id', 'retry_key'], 'patient_emergency_command_retry_unique');
        });
        DB::statement('ALTER TABLE patient_emergency_contacts ADD CONSTRAINT patient_emergency_version_check CHECK (lock_version >= 1)');
        DB::statement('ALTER TABLE patient_emergency_contacts ADD CONSTRAINT patient_emergency_organisation_fk FOREIGN KEY (patient_id, organisation_id) REFERENCES patients(id, organisation_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE patient_emergency_contact_commands ADD CONSTRAINT patient_emergency_command_org_fk FOREIGN KEY (patient_id, organisation_id) REFERENCES patients(id, organisation_id) ON DELETE RESTRICT');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION refuse_patient_emergency_command_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Emergency contact commands are append-only' USING ERRCODE = 'P0001';
END;
$$;
CREATE TRIGGER patient_emergency_commands_immutable BEFORE UPDATE OR DELETE OR TRUNCATE
ON patient_emergency_contact_commands FOR EACH STATEMENT
EXECUTE FUNCTION refuse_patient_emergency_command_mutation();
SQL);
    }

    public function down(): void
    {
        // Retain contact and retry evidence; rollback never deletes patient contact data.
    }
};
```

## Independent schema review — Billing/Panel, 26 November 2026

Reviewer found no schema blocker after checking patient composite uniqueness and guardian parity. Three implementation requirements are accepted:

1. A cleared tombstone reads as `values: null` **with its current lock version**, not a nonexistent contact/version0. Only an actually absent row starts at0.
2. Check emergency-contact replay compatibility on **every** `VisitRegistrationService` existing-visit return path, including early returns and the `QueryException|ValidationException|IdempotentVisitResolved` catch. Never allow a changed contact payload to slip through the current panel-note-only replay check.
3. Command records do not store the response version. An exact retry performs no mutation and returns the **current authorised contact/version**, not a promise of the original response snapshot. Keep actor → patient → contact lock ordering and one outer registration transaction.

These requirements do not change the exact proposed migration above. Migration is still unapplied and awaits owner approval.

## Exact schema approval — 26 November 2026

Owner approved the complete two-table migration above, relayed by B.E.R.N.I.S.S. on 26 November 2026. Product rules and the independent replay/tombstone review requirements are binding. Implementation proceeds in the separate `feature/patient-emergency-contact` worktree; no additional permission grant is authorised. Prior pending-approval notes above describe review history and are superseded by this approval.

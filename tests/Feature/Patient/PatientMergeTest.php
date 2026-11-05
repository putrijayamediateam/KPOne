<?php

namespace Tests\Feature\Patient;

use App\Domain\Organisation\Services\PublicCheckInLinkService;
use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Models\PatientMergeRequest;
use App\Domain\Patient\Models\PublicIntakeSession;
use App\Domain\Patient\Models\PublicPatientIntake;
use App\Domain\Patient\Services\PatientAdministrationService;
use App\Domain\Patient\Services\PatientMergeService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Feature\Visit\VisitTestCase;

/** REG-3: two-person merge by link (owner decisions A-G, 2026-11-05). */
class PatientMergeTest extends VisitTestCase
{
    private function service(): PatientMergeService
    {
        return app(PatientMergeService::class);
    }

    private function propose(User $actor, Patient $kept, Patient $other, string $kind = 'merge', ?string $key = null, string $reason = 'same_person_registered_twice'): PatientMergeRequest
    {
        $this->selectBranch($actor);

        return $this->service()->propose($actor, $kept->refresh(), [
            'kind' => $kind,
            'other_patient_number' => $other->patient_number,
            'reason_code' => $kind === 'merge' ? $reason : 'merged_in_error',
            'expected_branch_id' => $this->branch->id,
            'kept_lock_version' => $kept->lock_version,
            'other_lock_version' => $other->refresh()->lock_version,
            'idempotency_key' => $key ?? (string) Str::uuid(),
        ]);
    }

    private function decide(string $action, User $actor, PatientMergeRequest $request): PatientMergeRequest
    {
        $this->selectBranch($actor);
        $request->refresh();

        return $this->service()->{$action}($actor, $request, ['expected_branch_id' => $this->branch->id, 'lock_version' => $request->lock_version]);
    }

    /** @return list<string> */
    private function errors(callable $action): array
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            return array_merge(...array_values($exception->errors()));
        }
        $this->fail('Expected a validation refusal.');
    }

    private function addActiveAllergy(Patient $patient, string $text, User $by): void
    {
        $profileId = DB::table('patient_allergy_profiles')->where('patient_id', $patient->id)->value('id');
        if (! $profileId) {
            $profileId = DB::table('patient_allergy_profiles')->insertGetId([
                'organisation_id' => $patient->organisation_id, 'patient_id' => $patient->id, 'status' => 'has_allergies',
                'reviewed_at' => now(), 'reviewed_by_user_id' => $by->id, 'updated_by_user_id' => $by->id, 'lock_version' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('patient_allergy_records')->insert([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $patient->organisation_id, 'patient_allergy_profile_id' => $profileId,
            'allergen_text' => $text, 'status' => 'active', 'recorded_at' => now(), 'recorded_by_user_id' => $by->id,
            'updated_by_user_id' => $by->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_two_people_merge_by_link_and_nothing_is_repointed(): void
    {
        $ca = $this->actor('ca');
        $supervisor = $this->actor('ca_supervisor');
        $kept = $this->patient();
        $duplicate = $this->patient();
        $oldVisit = $this->register($ca, $duplicate);
        $oldVisit->forceFill(['status' => Visit::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by_user_id' => $ca->id, 'cancellation_reason' => 'Synthetic'])->save();

        $request = $this->propose($ca, $kept, $duplicate);
        $this->assertSame('proposed', $request->status);
        $this->assertNull($duplicate->refresh()->merged_into_patient_id);

        $approved = $this->decide('approve', $supervisor, $request);

        $this->assertSame('approved', $approved->status);
        $this->assertSame($kept->id, $duplicate->refresh()->merged_into_patient_id);
        $this->assertNotNull($duplicate->merged_at);
        $this->assertSame($duplicate->id, $oldVisit->refresh()->patient_id, 'visits are never re-pointed');
        $this->assertDatabaseHas('patient_merges', ['patient_merge_request_id' => $request->id, 'event' => 'merged', 'kept_patient_id' => $kept->id, 'merged_patient_id' => $duplicate->id, 'approved_by_user_id' => $supervisor->id]);
        foreach (['patient_merge.proposed', 'patient_merge.approved', 'patient.merged'] as $event) {
            $this->assertDatabaseHas('audit_logs', ['event' => $event]);
        }
    }

    public function test_audit_rows_never_carry_identity_values(): void
    {
        $ca = $this->actor('ca');
        $kept = $this->patient();
        $duplicate = $this->patient(null, ['full_name' => 'Synthetic Unique Name Zulu', 'mobile_phone' => '+60123456780']);
        $this->decide('approve', $this->actor('director'), $this->propose($ca, $kept, $duplicate));

        $metadata = DB::table('audit_logs')->where('event', 'like', 'patient%merge%')->orWhere('event', 'patient.merged')->pluck('metadata')->implode(' ');
        foreach (['Synthetic Unique Name Zulu', '60123456780', $duplicate->patient_number, $kept->full_name] as $value) {
            $this->assertStringNotContainsString($value, $metadata);
        }
    }

    public function test_the_proposer_cannot_approve_and_roles_are_enforced(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $request = $this->propose($supervisor, $this->patient(), $this->patient());

        $this->assertContains('A different person must approve or reject this request.', $this->errors(fn () => $this->decide('approve', $supervisor, $request)));

        $this->expectException(AuthorizationException::class);
        $this->decide('approve', $this->actor('ca'), $request);
    }

    public function test_roles_without_the_propose_permission_cannot_propose(): void
    {
        foreach (['resident_doctor', 'finance_officer', 'administrator', 'panel_officer'] as $role) {
            try {
                $this->propose($this->actor($role), $this->patient(), $this->patient());
                $this->fail("{$role} must not propose a merge");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertDatabaseCount('patient_merge_requests', 0);
    }

    public function test_reject_and_withdraw_leave_both_records_unchanged(): void
    {
        $ca = $this->actor('ca');
        $kept = $this->patient();
        $duplicate = $this->patient();
        $this->decide('reject', $this->actor('ca_supervisor'), $this->propose($ca, $kept, $duplicate));
        $this->decide('withdraw', $ca, $this->propose($ca, $kept, $duplicate));

        $this->assertNull($duplicate->refresh()->merged_into_patient_id);
        $this->assertDatabaseCount('patient_merges', 0);
        $this->assertSame(['rejected', 'withdrawn'], PatientMergeRequest::query()->orderBy('id')->pluck('status')->all());
    }

    public function test_only_the_proposer_may_withdraw(): void
    {
        $request = $this->propose($this->actor('ca'), $this->patient(), $this->patient());
        $this->assertContains('Only the person who proposed this request can withdraw it.', $this->errors(fn () => $this->decide('withdraw', $this->actor('ca'), $request)));
    }

    public function test_unfinished_visits_block_a_merge(): void
    {
        $ca = $this->actor('ca');
        $kept = $this->patient();
        $duplicate = $this->patient();
        $this->register($ca, $duplicate);

        $this->assertContains('One of these records has a visit that is not finished. Complete or cancel it first.', $this->errors(fn () => $this->propose($ca, $kept, $duplicate)));
        $this->assertDatabaseCount('patient_merge_requests', 0);
    }

    public function test_active_allergies_missing_from_the_kept_record_block_until_recorded_there(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->actor('resident_doctor');
        $kept = $this->patient();
        $duplicate = $this->patient();
        $this->addActiveAllergy($duplicate, 'Synthetic Penicillin', $doctor);

        $this->assertContains('The duplicate has 1 active allergy that the kept record does not show. A doctor must record it on the kept record first.', $this->errors(fn () => $this->propose($ca, $kept, $duplicate)));

        $this->addActiveAllergy($kept, '  synthetic   PENICILLIN. ', $doctor);
        $this->assertSame('proposed', $this->propose($ca, $kept, $duplicate)->status);
    }

    public function test_an_allergy_added_after_proposal_blocks_approval(): void
    {
        $ca = $this->actor('ca');
        $kept = $this->patient();
        $duplicate = $this->patient();
        $request = $this->propose($ca, $kept, $duplicate);
        $this->addActiveAllergy($duplicate, 'Synthetic Latex', $this->actor('resident_doctor'));

        $this->assertNotEmpty($this->errors(fn () => $this->decide('approve', $this->actor('ca_supervisor'), $request)));
        $this->assertNull($duplicate->refresh()->merged_into_patient_id);
    }

    public function test_a_record_change_after_proposal_blocks_approval(): void
    {
        $ca = $this->actor('ca');
        $kept = $this->patient();
        $duplicate = $this->patient();
        $request = $this->propose($ca, $kept, $duplicate);
        DB::table('patients')->where('id', $duplicate->id)->increment('lock_version');

        $this->assertContains('One of the patient records changed after this request was made. Reject it and propose again.', $this->errors(fn () => $this->decide('approve', $this->actor('ca_supervisor'), $request)));
    }

    public function test_no_self_merge_no_chains_and_one_open_request(): void
    {
        $ca = $this->actor('ca');
        $supervisor = $this->actor('ca_supervisor');
        $a = $this->patient();
        $b = $this->patient();
        $c = $this->patient();

        $this->assertContains('A patient cannot be merged with itself.', $this->errors(fn () => $this->propose($ca, $a, $a)));

        $this->propose($ca, $a, $b);
        $this->assertContains('A merge request for this patient is already waiting for approval.', $this->errors(fn () => $this->propose($ca, $c, $b)));

        $this->decide('approve', $supervisor, PatientMergeRequest::query()->sole());
        $this->assertContains('One of these records is already merged into another record.', $this->errors(fn () => $this->propose($ca, $c, $b)));
        $this->assertContains('Other records are already merged into this duplicate. Merge into it instead, or unmerge them first.', $this->errors(fn () => $this->propose($ca, $c, $a)));
    }

    public function test_identical_retry_returns_the_same_request_and_a_changed_payload_is_refused(): void
    {
        $ca = $this->actor('ca');
        $kept = $this->patient();
        $duplicate = $this->patient();
        $key = (string) Str::uuid();
        $first = $this->propose($ca, $kept, $duplicate, key: $key);

        $this->assertSame($first->id, $this->propose($ca, $kept, $duplicate, key: $key)->id);
        $this->assertNotEmpty($this->errors(fn () => $this->propose($ca, $kept, $duplicate, key: $key, reason: 'wrong_identifier_typed')));
        $this->assertDatabaseCount('patient_merge_requests', 1);
    }

    public function test_unmerge_needs_two_people_and_restores_the_record_exactly(): void
    {
        $ca = $this->actor('ca');
        $supervisor = $this->actor('ca_supervisor');
        $kept = $this->patient();
        $duplicate = $this->patient();
        $this->decide('approve', $supervisor, $this->propose($ca, $kept, $duplicate));

        $unmerge = $this->propose($supervisor, $kept, $duplicate, 'unmerge');
        $this->assertSame($kept->id, $duplicate->refresh()->merged_into_patient_id, 'nothing changes until approval');
        $this->assertContains('A different person must approve or reject this request.', $this->errors(fn () => $this->decide('approve', $supervisor, $unmerge)));

        $this->decide('approve', $this->actor('director'), $unmerge);
        $duplicate->refresh();
        $this->assertNull($duplicate->merged_into_patient_id);
        $this->assertNull($duplicate->merged_at);
        $this->assertSame(['merged', 'unmerged'], DB::table('patient_merges')->orderBy('id')->pluck('event')->all());
        $this->assertDatabaseHas('audit_logs', ['event' => 'patient.unmerged']);
    }

    public function test_unmerge_refuses_a_record_that_is_not_merged_into_this_patient(): void
    {
        $this->assertContains('This record is not merged into this patient.', $this->errors(fn () => $this->propose($this->actor('ca'), $this->patient(), $this->patient(), 'unmerge')));
    }

    public function test_reasons_are_a_fixed_list(): void
    {
        $this->assertContains('Choose a reason from the list.', $this->errors(fn () => $this->propose($this->actor('ca'), $this->patient(), $this->patient(), reason: 'free text about the patient')));
    }

    public function test_postgres_refuses_a_pointer_without_an_approved_event_and_keeps_evidence(): void
    {
        $kept = $this->patient();
        $duplicate = $this->patient();

        try {
            DB::transaction(fn () => DB::table('patients')->where('id', $duplicate->id)->update(['merged_into_patient_id' => $kept->id, 'merged_at' => now()]));
            $this->fail('A direct pointer write must be refused.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('approved merge', $exception->getMessage());
        }

        $request = $this->propose($this->actor('ca'), $kept, $duplicate);
        $this->decide('approve', $this->actor('ca_supervisor'), $request);
        foreach ([
            fn () => DB::table('patient_merges')->update(['event' => 'unmerged']),
            fn () => DB::table('patient_merges')->delete(),
            fn () => DB::table('patient_merge_requests')->where('id', $request->id)->update(['reason_code' => 'wrong_identifier_typed']),
            fn () => DB::table('patient_merge_requests')->where('id', $request->id)->delete(),
        ] as $write) {
            try {
                DB::transaction($write);
                $this->fail('Merge evidence must not change.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_open_qr_check_in_carrying_either_identifier_blocks_a_merge(): void
    {
        $kept = $this->patient();
        $duplicate = $this->patient();
        $identifier = $duplicate->identifiers()->sole();
        config(['public-intake.enabled' => true]);
        $director = $this->actor('director');
        $this->selectBranch($director);
        $link = app(PublicCheckInLinkService::class)->issue($director, $this->branch, 'Synthetic merge QR test')['link'];
        $session = PublicIntakeSession::query()->create([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id, 'public_checkin_link_id' => $link->id,
            'nonce_digest' => hash('sha256', (string) Str::uuid()), 'status_receipt_digest' => hash('sha256', (string) Str::uuid()), 'submission_idempotency_key' => (string) Str::uuid(), 'expires_at' => now()->addDay(), 'consumed_at' => now(),
        ]);
        $intake = PublicPatientIntake::query()->create([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id, 'public_checkin_link_id' => $link->id, 'public_intake_session_id' => $session->id,
            'status' => 'pending', 'submission_type' => 'patient', 'privacy_notice_version' => 'synthetic-test', 'payload_fingerprint' => hash('sha256', 'synthetic-merge'),
            'encrypted_payload' => ['patient' => ['full_name' => 'Synthetic Merge Intake', 'identifiers' => [[
                'identifier_type' => $identifier->identifier_type, 'issuing_country_code' => $identifier->issuing_country_code, 'value' => $identifier->normalized_value,
            ]]]],
            'consented_at' => now(), 'submitted_at' => now(), 'expires_at' => now()->addDay(), 'payload_purge_at' => now()->addDays(30),
        ]);
        $blocker = 'One of these records has a QR check-in waiting for review. Finish the review first.';

        $this->assertContains($blocker, $this->service()->mergeBlockers($kept, $duplicate));
        $this->assertContains($blocker, $this->errors(fn () => $this->propose($this->actor('ca'), $kept, $duplicate)));

        $intake->forceFill(['status' => 'rejected'])->save();
        $this->assertNotContains($blocker, $this->service()->mergeBlockers($kept, $duplicate));
    }

    public function test_identifiers_of_a_merged_away_record_cannot_be_added_or_replaced(): void
    {
        $kept = $this->patient();
        $duplicate = $this->patient();
        $this->decide('approve', $this->actor('ca_supervisor'), $this->propose($this->actor('ca'), $kept, $duplicate));
        $director = $this->actor('director');
        $admin = app(PatientAdministrationService::class);
        $expected = 'This record was merged into another patient and can no longer be edited. Edit the kept record instead.';

        $this->assertContains($expected, $this->errors(fn () => $admin->addIdentifier($duplicate->refresh(), ['identifier_type' => 'passport', 'issuing_country_code' => 'SG', 'value' => 'SYNMERGE01'], $director)));
        $this->assertContains($expected, $this->errors(fn () => $admin->replaceIdentifier($duplicate->refresh(), $duplicate->identifiers()->sole(), ['identifier_type' => 'passport', 'issuing_country_code' => 'MY', 'value' => 'SYNMERGE02'], $director)));
        $this->assertSame(1, $duplicate->identifiers()->count());
    }

    public function test_a_repeated_decision_click_succeeds_once_and_records_once(): void
    {
        $kept = $this->patient();
        $duplicate = $this->patient();
        $supervisor = $this->actor('ca_supervisor');
        $request = $this->propose($this->actor('ca'), $kept, $duplicate);
        $staleVersion = $request->lock_version;
        $this->decide('approve', $supervisor, $request);

        $again = $this->service()->approve($supervisor, $request, ['expected_branch_id' => $this->branch->id, 'lock_version' => $staleVersion]);
        $this->assertSame(PatientMergeRequest::STATUS_APPROVED, $again->status);
        $this->assertSame(1, DB::table('patient_merges')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'patient.merged')->count());
        $this->assertContains('This request has changed. Reload and review it again.', $this->errors(fn () => $this->service()->reject($supervisor, $request, ['expected_branch_id' => $this->branch->id, 'lock_version' => $staleVersion])));
    }

    public function test_postgres_refuses_a_second_decision_and_a_changed_merge_time(): void
    {
        $kept = $this->patient();
        $duplicate = $this->patient();
        $request = $this->propose($this->actor('ca'), $kept, $duplicate);
        $this->decide('approve', $this->actor('ca_supervisor'), $request);
        foreach ([
            fn () => DB::table('patient_merge_requests')->where('id', $request->id)->update(['status' => 'rejected']),
            fn () => DB::table('patients')->where('id', $duplicate->id)->update(['merged_at' => now()->subYear()]),
        ] as $write) {
            try {
                DB::transaction($write);
                $this->fail('A decided request and the merge time must not change.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_rolling_back_the_migration_is_refused_once_evidence_exists(): void
    {
        $this->propose($this->actor('ca'), $this->patient(), $this->patient());
        $migration = require database_path('migrations/2026_11_06_000300_create_patient_merges.php');

        $this->expectException(RuntimeException::class);
        $migration->down();
    }
}

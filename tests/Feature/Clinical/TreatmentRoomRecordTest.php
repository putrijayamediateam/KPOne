<?php

namespace Tests\Feature\Clinical;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Clinical\Services\ConsultationHoldService;
use App\Domain\Clinical\TreatmentRoom\Models\TreatmentRoomRecord;
use App\Domain\Clinical\TreatmentRoom\Models\TreatmentRoomRecordVoid;
use App\Domain\Clinical\TreatmentRoom\TreatmentRoomDirectory;
use App\Domain\Clinical\TreatmentRoom\TreatmentRoomProjection;
use App\Domain\Clinical\TreatmentRoom\TreatmentRoomService;
use App\Domain\Clinical\TreatmentRoom\TreatmentRoomVocabulary;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Queue\Treatment\TreatmentStep;
use App\Domain\Queue\Treatment\TreatmentStepProjection;
use App\Domain\Queue\Treatment\TreatmentStepService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TreatmentRoomRecordTest extends ClinicalTestCase
{
    public function test_sample_failure_records_immutable_outcome_completes_step_and_preserves_consultation_hold(): void
    {
        [$ma, $doctor, $visit, $queue, $encounter, $step, $input] = $this->fixture();
        $before = [$visit->refresh()->getAttributes(), $queue->refresh()->getAttributes(), $encounter->refresh()->getAttributes(), $encounter->activeHold()->sole()->getAttributes()];
        $service = app(TreatmentRoomService::class);
        $record = $service->recordOutcome($ma, $visit, $input);
        $replay = $service->recordOutcome($ma, $visit, $input);
        $this->assertSame($record->id, $replay->id);
        $this->assertSame(1, TreatmentRoomRecord::query()->count());
        $this->assertSame('done', $step->refresh()->state);
        $this->assertTrue($record->started_at->equalTo($step->started_at));
        $this->assertTrue($record->finished_at->equalTo($step->done_at));
        $this->assertSame($before, [$visit->refresh()->getAttributes(), $queue->refresh()->getAttributes(), $encounter->refresh()->getAttributes(), $encounter->activeHold()->sole()->getAttributes()]);
        $this->assertSame(1, AuditLog::where('event', 'treatment_room.recorded')->count());
        $this->assertSame(1, DB::table('queue_treatment_step_events')->where('treatment_step_id', $step->id)->where('event_type', 'done')->where('doctor_user_id', $doctor->id)->count());
        $this->selectBranch($doctor);
        $projection = app(TreatmentRoomProjection::class)->forVisit($doctor, $visit);
        $this->assertSame('Not successful', $projection['current'][0]['outcomeLabel']);
        $this->assertSame('Patient declined', $projection['current'][0]['reasonLabel']);
        $this->assertSame('Urine sample', app(TreatmentStepProjection::class)->rows($doctor, [$queue->refresh()->load('visit')])[$queue->id]['label']);
        $this->assertSame('Not successful', app(TreatmentStepProjection::class)->rows($doctor, [$queue->refresh()->load('visit')])[$queue->id]['outcome']['outcomeLabel']);
        $updates = app(TreatmentStepProjection::class)->updates($doctor, $visit->branch_id, 0, true);
        $this->assertSame('Not successful', $updates['outcomes'][0]['outcomeLabel']);
        $this->assertSame('Patient declined', $updates['outcomes'][0]['reasonLabel']);
        $unrelated = $this->doctor();
        $this->selectBranch($unrelated);
        $this->assertArrayNotHasKey('outcome', app(TreatmentStepProjection::class)->rows($unrelated, [$queue->refresh()->load('visit')])[$queue->id]);
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->assertArrayNotHasKey('outcome', app(TreatmentStepProjection::class)->rows($director, [$queue->refresh()->load('visit')])[$queue->id]);
        $this->assertArrayNotHasKey('payload_fingerprint', $projection['history'][0]);
        $this->assertArrayNotHasKey('idempotency_key', $projection['history'][0]);
    }

    public function test_correction_then_wrong_patient_void_preserves_all_history_without_replacement_link(): void
    {
        [$ma, , $visit, $queue, , , $input] = $this->fixture();
        $service = app(TreatmentRoomService::class);
        $original = $service->recordOutcome($ma, $visit, $input);
        $correct = [...$this->versions($visit, $queue), 'target_record_id' => $original->id, 'correction_code' => 'wrong_outcome', 'procedure_code' => 'urine_sample', 'outcome_code' => 'done', 'reason_code' => null, 'idempotency_key' => (string) Str::uuid()];
        $successor = $service->correctOutcome($ma, $visit, $correct);
        $this->assertSame($successor->id, $service->correctOutcome($ma, $visit, $correct)->id);
        $this->assertSame($original->treatment_step_id, $successor->treatment_step_id);
        $this->assertTrue($original->started_at->equalTo($successor->started_at));
        $void = [...$this->versions($visit, $queue), 'target_record_id' => $successor->id, 'reason_code' => 'wrong_patient', 'idempotency_key' => (string) Str::uuid()];
        $event = $service->voidWrongPatient($ma, $visit, $void);
        $this->assertSame($event->id, $service->voidWrongPatient($ma, $visit, $void)->id);
        $this->assertSame(2, TreatmentRoomRecord::query()->count());
        $this->assertSame(1, TreatmentRoomRecordVoid::query()->count());
        $projection = app(TreatmentRoomProjection::class)->forVisit($ma, $visit);
        $this->assertSame([], $projection['current']);
        $this->assertSame(['voided', 'superseded'], array_column($projection['history'], 'status'));
        $this->assertSame('Wrong patient', $projection['history'][0]['voidReason']);
        $this->assertArrayNotHasKey('replacement_visit_id', $event->getAttributes());
        $this->assertSame('not_successful', $original->refresh()->outcome_code);
        $this->assertSame('done', $successor->refresh()->outcome_code);
        $this->expectException(ValidationException::class);
        $service->correctOutcome($ma, $visit, [...$correct, 'target_record_id' => $successor->id, 'idempotency_key' => (string) Str::uuid()]);
    }

    public function test_initial_procedure_must_match_step_and_result_or_free_text_fields_are_rejected(): void
    {
        [$ma, , $visit, , , $step, $input] = $this->fixture();
        foreach ([['procedure_code' => 'nebuliser'], ['result_value' => '4.5'], ['clinical_note' => 'Synthetic forbidden note'], ['recorded_at' => now()->toIso8601String()]] as $change) {
            try {
                app(TreatmentRoomService::class)->recordOutcome($ma, $visit, [...$input, ...$change]);
                $this->fail('Invalid clinical input must not be accepted.');
            } catch (ValidationException) {
                $this->assertSame(0, TreatmentRoomRecord::query()->count());
                $this->assertSame('started', $step->refresh()->state);
            }
        }
    }

    public function test_existing_ca_step_menu_stays_eight_and_sample_start_requires_narrow_authority(): void
    {
        [$ma, , $visit, $queue] = $this->fixture();
        $this->assertCount(8, TreatmentStepService::STEPS);
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        try {
            app(TreatmentStepService::class)->start($ca, $visit, ['expected_branch_id' => $visit->branch_id, 'step_code' => 'swab_sample', 'idempotency_key' => (string) Str::uuid()]);
            $this->fail('Ordinary Q2 start must not silently gain sample procedures.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('step_code', $exception->errors());
        }
        $this->expectException(AuthorizationException::class);
        app(TreatmentRoomService::class)->start($ca, $visit, [...$this->versions($visit, $queue), 'procedure_code' => 'swab_sample', 'idempotency_key' => (string) Str::uuid()]);
    }

    public function test_director_cannot_read_record_despite_ordinary_visit_history_access(): void
    {
        [$ma, , $visit, , , , $input] = $this->fixture();
        app(TreatmentRoomService::class)->recordOutcome($ma, $visit, $input);
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->assertTrue($director->can('visits.history.view.branch'));
        $this->expectException(AuthorizationException::class);
        app(TreatmentRoomProjection::class)->forVisit($director, $visit, true);
    }

    public function test_database_rejects_mutation_and_invalid_correction_fields(): void
    {
        [$ma, , $visit, , , , $input] = $this->fixture();
        $record = app(TreatmentRoomService::class)->recordOutcome($ma, $visit, $input);
        foreach (['update', 'delete', 'truncate'] as $operation) {
            try {
                DB::transaction(function () use ($record, $operation) {
                    match ($operation) {
                        'update' => DB::table('treatment_room_records')->where('id', $record->id)->update(['outcome_code' => 'done']),
                        'delete' => DB::table('treatment_room_records')->where('id', $record->id)->delete(),
                        'truncate' => DB::statement('TRUNCATE treatment_room_record_voids, treatment_room_records'),
                    };
                });
                $this->fail('Clinical evidence mutation must be refused by PostgreSQL.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('append-only', $exception->getMessage());
            }
        }
        $this->assertSame(1, TreatmentRoomRecord::query()->count());
    }

    public function test_all_approved_procedures_can_be_recorded_through_narrow_adapter(): void
    {
        foreach (array_keys(TreatmentRoomVocabulary::PROCEDURES) as $code) {
            [$ma, , $visit, , , , $input] = $this->fixture($code);
            $record = app(TreatmentRoomService::class)->recordOutcome($ma, $visit, $input);
            $this->assertSame($code, $record->procedure_code);
        }
        $this->assertSame(11, TreatmentRoomRecord::query()->count());
    }

    public function test_supervisor_records_and_only_approved_readers_see_current_outcome(): void
    {
        [$ma, $doctor, $visit, , , , $input] = $this->fixture();
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $record = app(TreatmentRoomService::class)->recordOutcome($supervisor, $visit, $input);
        $this->assertSame($supervisor->id, $record->recorded_by_user_id);
        foreach (['ca' => true, 'medical_assistant' => true, 'ca_supervisor' => true, 'finance_officer' => false, 'panel_officer' => false, 'technical_admin' => false, 'hr_manager' => false] as $role => $allowed) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            try {
                $rows = app(TreatmentRoomProjection::class)->forVisit($actor, $visit, true);
                $this->assertTrue($allowed, $role.' must not receive clinical outcome');
                $this->assertSame($record->id, $rows['current'][0]['id']);
            } catch (AuthorizationException) {
                $this->assertFalse($allowed, $role.' should read branch treatment history');
            }
        }
        $otherDoctor = $this->doctor();
        $this->selectBranch($otherDoctor);
        $this->assertSame($record->id, app(TreatmentRoomProjection::class)->forVisit($doctor, $visit, true)['current'][0]['id']);
        $this->expectException(AuthorizationException::class);
        app(TreatmentRoomProjection::class)->forVisit($otherDoctor, $visit, true);
    }

    public function test_read_and_write_refuse_other_branch_and_organisation_and_replay_rechecks_permission(): void
    {
        [$ma, , $visit, , , , $input] = $this->fixture();
        app(TreatmentRoomService::class)->recordOutcome($ma, $visit, $input);
        $branch = Branch::where('code', 'PUCHONG')->sole();
        $outsider = $this->actor('medical_assistant', $branch);
        $this->selectBranch($outsider, $branch);
        try {
            app(TreatmentRoomProjection::class)->forVisit($outsider, $visit, true);
            $this->fail('Another branch must not read this outcome.');
        } catch (AuthorizationException) {
            $this->assertSame(1, TreatmentRoomRecord::query()->count());
        }
        try {
            app(TreatmentRoomService::class)->recordOutcome($outsider, $visit, [...$input, 'expected_branch_id' => $branch->id]);
            $this->fail('Another branch must not write this visit.');
        } catch (ModelNotFoundException) {
            $this->assertSame(1, TreatmentRoomRecord::query()->count());
        }
        $org = Organisation::create(['code' => 'SYN-TREAT-OTHER', 'name' => 'Synthetic other organisation', 'is_active' => true]);
        $foreign = new Branch;
        $foreign->forceFill(['organisation_id' => $org->id, 'code' => 'OTHER', 'name' => 'Synthetic other branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $outsider = $this->actor('medical_assistant', $foreign);
        $this->selectBranch($outsider, $foreign);
        try {
            app(TreatmentRoomProjection::class)->forVisit($outsider, $visit, true);
            $this->fail('Another organisation must not read this outcome.');
        } catch (AuthorizationException) {
            $this->assertSame(1, TreatmentRoomRecord::query()->count());
        }
        $this->selectBranch($ma);
        $ma->syncRoles(['ca']);
        $this->expectException(AuthorizationException::class);
        app(TreatmentRoomService::class)->recordOutcome($ma, $visit, $input);
    }

    public function test_cancelled_step_is_replaced_but_already_done_step_is_not_transitioned_twice(): void
    {
        [$ma, , $visit, $queue, , $step, $input] = $this->fixture();
        app(TreatmentStepService::class)->cancel($ma, $visit, ['expected_branch_id' => $visit->branch_id, 'treatment_step_id' => $step->id, 'lock_version' => $step->lock_version, 'reason_code' => 'wrong_step', 'idempotency_key' => (string) Str::uuid()]);
        $record = app(TreatmentRoomService::class)->recordOutcome($ma, $visit, [...$input, 'step_lock_version' => $step->refresh()->lock_version]);
        $this->assertNotSame($step->id, $record->treatment_step_id);
        $this->assertSame('cancelled', $step->refresh()->state);
        $this->assertSame('done', TreatmentStep::findOrFail($record->treatment_step_id)->state);
        [$ma, , $visit, , , $step, $input] = $this->fixture('ecg');
        app(TreatmentStepService::class)->complete($ma, $visit, ['expected_branch_id' => $visit->branch_id, 'treatment_step_id' => $step->id, 'lock_version' => $step->lock_version, 'idempotency_key' => (string) Str::uuid()]);
        $before = $step->refresh()->getAttributes();
        app(TreatmentRoomService::class)->recordOutcome($ma, $visit, [...$input, 'step_lock_version' => $step->lock_version]);
        $this->assertSame($before, $step->refresh()->getAttributes());
        $this->assertSame(1, DB::table('queue_treatment_step_events')->where('treatment_step_id', $step->id)->where('event_type', 'done')->count());
    }

    public function test_raw_successor_cannot_rewrite_performance_or_unrelated_corrected_fields(): void
    {
        [$ma, , $visit, , , , $input] = $this->fixture();
        $record = app(TreatmentRoomService::class)->recordOutcome($ma, $visit, $input);
        $base = $record->getAttributes();
        unset($base['id']);
        $base = [...$base, 'supersedes_record_id' => $record->id, 'correction_code' => 'wrong_outcome'];
        foreach ([['procedure_code' => 'ecg'], ['started_at' => $record->started_at->subMinute()], ['correction_code' => 'wrong_procedure', 'outcome_code' => 'done', 'reason_code' => null]] as $change) {
            try {
                DB::transaction(fn () => DB::table('treatment_room_records')->insert([...$base, ...$change, 'idempotency_key' => (string) Str::uuid()]));
                $this->fail('Database must reject malformed correction evidence.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('preserve', $exception->getMessage());
            }
        }
        $this->assertSame(1, TreatmentRoomRecord::query()->count());
    }

    public function test_directory_exposes_held_step_versions_branch_timezone_and_record_eligibility(): void
    {
        [$ma, , $visit, $queue, , $step, $input] = $this->fixture();
        $directory = app(TreatmentRoomDirectory::class);
        $snapshot = $directory->snapshot($ma);
        $this->assertSame($visit->branch->timezone, $snapshot['branch']['timezone']);
        $this->assertCount(1, $snapshot['rows']);
        $row = $snapshot['rows'][0];
        $this->assertSame($visit->visit_number, $row['visitNumber']);
        $this->assertTrue($row['isHeld']);
        $this->assertTrue($row['canRecord']);
        $this->assertSame($step->lock_version, $row['steps'][0]['lockVersion']);
        $this->assertTrue($row['steps'][0]['canRecordOutcome']);
        app(TreatmentRoomService::class)->recordOutcome($ma, $visit, $input);
        $context = $directory->context($ma, $visit);
        $this->assertTrue($context['steps'][0]['hasOutcome']);
        $this->assertFalse($context['steps'][0]['canRecordOutcome']);
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->assertFalse($directory->context($ca, $visit)['canRecord']);
        $this->assertSame($visit->branch->timezone, $context['branchTimezone']);
    }

    public function test_explicit_read_audits_only_structural_context_and_unrelated_doctor_is_denied(): void
    {
        [$ma, , $visit, , , , $input] = $this->fixture();
        app(TreatmentRoomService::class)->recordOutcome($ma, $visit, $input);
        app(TreatmentRoomProjection::class)->forVisit($ma, $visit, true);
        $audit = DB::table('audit_logs')->where('event', 'treatment_room.viewed')->latest('id')->first();
        $this->assertSame(['visit_id' => $visit->id, 'history' => true], json_decode($audit->metadata, true));
        $other = $this->doctor();
        $this->selectBranch($other);
        $before = DB::table('audit_logs')->where('event', 'treatment_room.viewed')->count();
        try {
            app(TreatmentRoomProjection::class)->forVisit($other, $visit, true);
            $this->fail('Permission alone must not disclose unrelated clinical history.');
        } catch (AuthorizationException) {
            $this->assertSame($before, DB::table('audit_logs')->where('event', 'treatment_room.viewed')->count());
        }
    }

    public function test_completion_preserves_exact_replay_but_refuses_fresh_correction_and_hides_write_controls(): void
    {
        [$ma, $doctor, $visit, $queue, $encounter, , $input] = $this->fixture();
        $service = app(TreatmentRoomService::class);
        $record = $service->recordOutcome($ma, $visit, $input);
        $this->selectBranch($doctor);
        app(ConsultationHoldService::class)->resume($doctor, $visit, [...$this->versions($visit, $queue), 'encounter_lock_version' => $encounter->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()]);
        app(CompleteConsultationService::class)->complete($doctor, $visit, [...$this->versions($visit, $queue), 'encounter_lock_version' => $encounter->refresh()->lock_version, 'lock_version' => null, 'service_deliveries' => []]);
        $this->selectBranch($ma);
        $this->assertSame($record->id, $service->recordOutcome($ma, $visit, $input)->id);
        $context = app(TreatmentRoomDirectory::class)->context($ma, $visit);
        $this->assertFalse($context['canRecord']);
        $this->assertFalse($context['canCallTreatment']);
        $this->assertSame([], app(TreatmentRoomDirectory::class)->snapshot($ma)['rows']);
        $this->expectException(ValidationException::class);
        $service->correctOutcome($ma, $visit, [...$this->versions($visit, $queue), 'target_record_id' => $record->id, 'correction_code' => 'wrong_outcome', 'procedure_code' => 'urine_sample', 'outcome_code' => 'done', 'reason_code' => null, 'idempotency_key' => (string) Str::uuid()]);
    }

    private function fixture(string $procedure = 'urine_sample'): array
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $this->holdEncounter($doctor, $visit, $queue, $encounter);
        $ma = $this->actor('medical_assistant');
        $this->selectBranch($ma);
        $start = app(TreatmentRoomService::class)->start($ma, $visit, [...$this->versions($visit, $queue), 'procedure_code' => $procedure, 'idempotency_key' => (string) Str::uuid()]);
        $step = TreatmentStep::findOrFail($start['id']);
        $input = [...$this->versions($visit, $queue), 'treatment_step_id' => $step->id, 'step_lock_version' => $step->lock_version, 'procedure_code' => $procedure, 'outcome_code' => 'not_successful', 'reason_code' => 'patient_declined', 'idempotency_key' => (string) Str::uuid()];

        return [$ma, $doctor, $visit, $queue, $encounter, $step, $input];
    }

    private function versions($visit, $queue): array
    {
        return ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->refresh()->lock_version, 'queue_lock_version' => $queue->refresh()->lock_version];
    }
}

<?php

namespace Tests\Feature\Clinical;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Clinical\TreatmentRoom\Models\TreatmentRoomRecord;
use App\Domain\Clinical\TreatmentRoom\Models\TreatmentRoomRecordVoid;
use App\Domain\Clinical\TreatmentRoom\TreatmentRoomService;
use App\Domain\Queue\Treatment\TreatmentStep;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

class TreatmentRoomHttpTest extends ClinicalTestCase
{
    public function test_ma_can_start_record_correct_and_void_through_scoped_routes(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $ma = $this->actor('medical_assistant');
        $this->selectBranch($ma);
        $this->post(route('treatment-room.start', $visit), [...$this->versions($visit, $queue), 'procedure_code' => 'urine_sample', 'idempotency_key' => (string) Str::uuid()])->assertRedirect(route('treatment-room.show', $visit))->assertSessionHasNoErrors();
        $step = TreatmentStep::query()->where('queue_entry_id', $queue->id)->sole();
        $this->get(route('treatment-room.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('TreatmentRoom/Index')->where('room.rows.0.visitNumber', $visit->visit_number)->where('room.rows.0.canRecord', true));
        $input = [...$this->versions($visit, $queue), 'treatment_step_id' => $step->id, 'step_lock_version' => $step->lock_version, 'procedure_code' => 'urine_sample', 'outcome_code' => 'not_successful', 'reason_code' => 'patient_declined', 'idempotency_key' => (string) Str::uuid()];
        $this->post(route('treatment-room.record', $visit), $input)->assertRedirect(route('treatment-room.show', $visit))->assertSessionHasNoErrors();
        $record = TreatmentRoomRecord::query()->sole();
        $this->getJson(route('treatment-room.records', $visit))->assertOk()->assertJsonPath('data.current.0.outcomeLabel', 'Not successful')->assertJsonPath('data.current.0.reasonLabel', 'Patient declined');
        $this->post(route('treatment-room.correct', $visit), [...$this->versions($visit, $queue), 'target_record_id' => $record->id, 'correction_code' => 'wrong_outcome', 'procedure_code' => 'urine_sample', 'outcome_code' => 'done', 'reason_code' => null, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $successor = TreatmentRoomRecord::query()->where('supersedes_record_id', $record->id)->sole();
        $void = [...$this->versions($visit, $queue), 'target_record_id' => $successor->id, 'reason_code' => 'wrong_patient', 'idempotency_key' => (string) Str::uuid()];
        $this->post(route('treatment-room.void', $visit), $void)->assertSessionHasNoErrors();
        $this->post(route('treatment-room.void', $visit), $void)->assertSessionHasNoErrors();
        $this->assertSame(1, TreatmentRoomRecordVoid::query()->count());
        $this->get(route('treatment-room.show', $visit))->assertOk()->assertInertia(fn (Assert $page) => $page->component('TreatmentRoom/Show')->has('records.current', 0)->where('records.history.0.status', 'voided')->where('records.history.1.status', 'superseded'));
    }

    public function test_read_authority_does_not_imply_write_and_unrelated_roles_cannot_read(): void
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $this->selectBranch($ca);
        $this->get(route('treatment-room.show', $visit))->assertOk()->assertInertia(fn (Assert $page) => $page->where('treatment.canRecord', false));
        $this->post(route('treatment-room.start', $visit), [...$this->versions($visit, $queue), 'procedure_code' => 'urine_sample', 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->selectBranch($doctor);
        $this->getJson(route('treatment-room.records', $visit))->assertOk();
        $this->post(route('treatment-room.start', $visit), [...$this->versions($visit, $queue), 'procedure_code' => 'urine_sample', 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        foreach (['resident_doctor', 'director', 'finance_officer', 'panel_officer', 'administrator'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->getJson(route('treatment-room.records', $visit))->assertForbidden();
            $this->getJson(route('treatment-room.history', $visit))->assertForbidden();
        }
    }

    public function test_http_unknown_fields_are_rejected_and_do_not_silently_disappear(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $this->selectBranch($this->actor('medical_assistant'));
        $this->postJson(route('treatment-room.start', $visit), [...$this->versions($visit, $queue), 'procedure_code' => 'urine_sample', 'idempotency_key' => (string) Str::uuid(), 'result_value' => '4.5'])->assertUnprocessable()->assertJsonValidationErrors('treatment');
        $this->assertSame(0, TreatmentStep::query()->count());
    }

    public function test_after_completion_history_is_explicit_and_new_commands_refuse(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $ma = $this->actor('medical_assistant');
        $this->selectBranch($ma);
        $start = app(TreatmentRoomService::class)->start($ma, $visit, [...$this->versions($visit, $queue), 'procedure_code' => 'swab_sample', 'idempotency_key' => (string) Str::uuid()]);
        $input = [...$this->versions($visit, $queue), 'treatment_step_id' => $start['id'], 'step_lock_version' => $start['lockVersion'], 'procedure_code' => 'swab_sample', 'outcome_code' => 'done', 'reason_code' => null, 'idempotency_key' => (string) Str::uuid()];
        app(TreatmentRoomService::class)->recordOutcome($ma, $visit, $input);
        $this->selectBranch($doctor);
        app(CompleteConsultationService::class)->complete($doctor, $visit, [...$this->versions($visit, $queue), 'encounter_lock_version' => $encounter->refresh()->lock_version, 'lock_version' => null, 'service_deliveries' => []]);
        $this->getJson(route('treatment-room.records', $visit))->assertForbidden();
        $this->getJson(route('treatment-room.history', $visit))->assertOk()->assertJsonPath('data.current.0.procedureLabel', 'Swab sample');
        $this->selectBranch($ma);
        $this->get(route('treatment-room.show', $visit))->assertOk()->assertInertia(fn (Assert $page) => $page->where('treatment.canRecord', false));
        $this->postJson(route('treatment-room.start', $visit), [...$this->versions($visit, $queue), 'procedure_code' => 'urine_sample', 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $audit = AuditLog::query()->where('event', 'treatment_room.viewed')->latest('id')->firstOrFail();
        $this->assertSame(['visit_id' => $visit->id, 'history' => true], $audit->metadata);
    }

    private function versions($visit, $queue): array
    {
        return ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->refresh()->lock_version, 'queue_lock_version' => $queue->refresh()->lock_version];
    }
}

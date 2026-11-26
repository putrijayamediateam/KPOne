<?php

namespace Tests\Feature\Visit;

use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Services\FamilyWaitingProjection;
use App\Domain\Patient\Services\PatientFamilyLinkService;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class FamilyWaitingProjectionTest extends VisitTestCase
{
    private function link(User $actor, Patient $a, Patient $b): void
    {
        $this->selectBranch($actor);
        app(PatientFamilyLinkService::class)->execute($actor, $a, ['other_patient_number' => $b->patient_number, 'operation' => 'link', 'relationship_code' => 'parent_of', 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'expected_branch_id' => $this->branch->id]);
    }

    private function queue(Patient $patient, User $doctor, array $visit = [], array $queue = []): QueueEntry
    {
        $record = Visit::factory()->create(['patient_id' => $patient->id, 'organisation_id' => $patient->organisation_id, 'branch_id' => $this->branch->id, 'visit_type' => 'consultation', 'visit_reason' => 'Synthetic consultation', 'assigned_doctor_user_id' => $doctor->id, ...$visit]);

        return QueueEntry::factory()->create(['visit_id' => $record->id, ...$queue]);
    }

    public function test_only_direct_authorised_relatives_waiting_today_are_projected_without_clinical_fields(): void
    {
        $ca = $this->actor();
        $doctor = $this->actor('resident_doctor');
        $otherDoctor = $this->actor('resident_doctor');
        $current = $this->patient();
        $own = $this->patient();
        $other = $this->patient();
        $carry = $this->patient();
        $otc = $this->patient();
        $serving = $this->patient();
        $transitive = $this->patient();
        foreach ([$own, $other, $carry, $otc, $serving] as $patient) {
            $this->link($ca, $current, $patient);
        }
        $this->link($ca, $own, $transitive);
        $queue = $this->queue($current, $doctor, [], ['status' => 'serving', 'called_at' => now(), 'called_by_user_id' => $doctor->id]);
        $encounter = ClinicalEncounter::factory()->create(['visit_id' => $queue->visit_id]);
        $this->queue($own, $doctor);
        $this->queue($other, $otherDoctor);
        $this->queue($carry, $doctor, [], ['operational_date' => now($this->branch->timezone)->subDay()->toDateString()]);
        $this->queue($otc, $doctor, ['visit_type' => 'otc', 'visit_reason' => null, 'assigned_doctor_user_id' => null]);
        $this->queue($serving, $doctor, [], ['status' => 'serving', 'called_at' => now(), 'called_by_user_id' => $doctor->id]);
        $this->queue($transitive, $doctor);
        $this->selectBranch($doctor);
        $result = app(FamilyWaitingProjection::class)->forEncounter($doctor, $encounter);
        $this->assertCount(1, $result['rows']);
        $this->assertSame($own->patient_number, $result['rows'][0]['patientNumber']);
        $this->assertSame('Child of', $result['rows'][0]['relationshipLabel']);
        $this->assertSame(['relationshipLabel', 'name', 'patientNumber', 'queueLabel', 'url'], array_keys($result['rows'][0]));
        $doctor->givePermissionTo('queue.view.branch');
        $this->assertCount(2, app(FamilyWaitingProjection::class)->forEncounter($doctor->fresh(), $encounter)['rows']);
    }

    public function test_completed_nonserving_and_unviewable_patient_do_not_receive_card(): void
    {
        $doctor = $this->actor('resident_doctor');
        $patient = $this->patient();
        $queue = $this->queue($patient, $doctor);
        $encounter = ClinicalEncounter::factory()->create(['visit_id' => $queue->visit_id]);
        $this->selectBranch($doctor);
        $projection = app(FamilyWaitingProjection::class);
        $this->assertNull($projection->forEncounter($doctor, $encounter));
        $queue->forceFill(['status' => 'serving', 'called_at' => now(), 'called_by_user_id' => $doctor->id])->save();
        $this->assertSame([], $projection->forEncounter($doctor, $encounter->fresh())['rows']);
        $queue->visit->forceFill(['status' => Visit::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by_user_id' => $doctor->id, 'cancellation_reason' => 'Synthetic closed visit'])->save();
        $this->assertNull($projection->forEncounter($doctor, $encounter->fresh()));
        $queue->visit->forceFill(['status' => Visit::STATUS_REGISTERED, 'cancelled_at' => null, 'cancelled_by_user_id' => null, 'cancellation_reason' => null])->save();
        Gate::before(fn (User $user, string $ability, array $arguments) => ($arguments[0] ?? null) instanceof Patient && $ability === 'view' ? false : null);
        $this->assertNull($projection->forEncounter($doctor, $encounter->fresh()));
    }

    public function test_waiting_today_uses_branch_timezone_and_excludes_other_branch(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 16:30:00', 'UTC'));
        $ca = $this->actor();
        $doctor = $this->actor('resident_doctor');
        $current = $this->patient();
        $today = $this->patient();
        $yesterday = $this->patient();
        $elsewhere = $this->patient();
        foreach ([$today, $yesterday, $elsewhere] as $relative) {
            $this->link($ca, $current, $relative);
        }
        $queue = $this->queue($current, $doctor, [], ['status' => 'serving', 'called_at' => now(), 'called_by_user_id' => $doctor->id]);
        $encounter = ClinicalEncounter::factory()->create(['visit_id' => $queue->visit_id]);
        $this->queue($today, $doctor, [], ['operational_date' => '2026-11-26']);
        $this->queue($yesterday, $doctor, [], ['operational_date' => '2026-11-25']);
        $otherBranch = Branch::where('organisation_id', $this->organisation->id)->where('id', '!=', $this->branch->id)->firstOrFail();
        $this->queue($elsewhere, $doctor, ['branch_id' => $otherBranch->id], ['operational_date' => '2026-11-26']);
        $this->selectBranch($doctor);
        $result = app(FamilyWaitingProjection::class)->forEncounter($doctor, $encounter);
        $this->assertSame([$today->patient_number], array_column($result['rows'], 'patientNumber'));
        $this->travelBack();
    }
}

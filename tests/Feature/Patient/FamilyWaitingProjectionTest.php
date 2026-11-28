<?php

namespace Tests\Feature\Patient;

use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Family\FamilyWaitingProjection;
use App\Domain\Patient\Family\PatientFamilyService;
use App\Domain\Patient\Models\Patient;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\Feature\Visit\VisitTestCase;

/** FAM-1 part 1: the Consultation Family card shows relatives waiting today, with links, and no clinical data. */
class FamilyWaitingProjectionTest extends VisitTestCase
{
    private function link(User $ca, Patient $patient, Patient $relative, string $relativeRole = 'child'): void
    {
        $this->selectBranch($ca);
        $service = app(PatientFamilyService::class);
        $directory = $service->directory($ca, $patient);
        $service->execute($ca, $patient, [
            'operation' => 'add', 'relative_patient_number' => $relative->patient_number, 'relative_role' => $relativeRole,
            'patient_role' => $directory['members'] === [] ? 'parent' : null, 'expected_family_version' => $directory['familyVersion'],
            'idempotency_key' => (string) Str::uuid(), 'expected_branch_id' => $this->branch->id,
        ]);
    }

    /** @param array<string, mixed> $visit
     * @param array<string, mixed> $queue */
    private function queue(Patient $patient, User $doctor, array $visit = [], array $queue = []): QueueEntry
    {
        $record = Visit::factory()->create(['patient_id' => $patient->id, 'organisation_id' => $patient->organisation_id, 'branch_id' => $this->branch->id, 'visit_type' => 'consultation', 'visit_reason' => 'Synthetic consultation', 'assigned_doctor_user_id' => $doctor->id, ...$visit]);

        return QueueEntry::factory()->create(['visit_id' => $record->id, ...$queue]);
    }

    private function serving(Patient $patient, User $doctor): ClinicalEncounter
    {
        $queue = $this->queue($patient, $doctor, [], ['status' => 'serving', 'called_at' => now(), 'called_by_user_id' => $doctor->id]);

        return ClinicalEncounter::factory()->create(['visit_id' => $queue->visit_id]);
    }

    public function test_only_relatives_waiting_today_in_the_doctors_permitted_queue_are_listed_without_clinical_fields(): void
    {
        $ca = $this->actor();
        $doctor = $this->actor('resident_doctor');
        $otherDoctor = $this->actor('resident_doctor');
        [$current, $own, $other, $carry, $otc, $serving, $notFamily] = array_map(fn () => $this->patient(), range(1, 7));
        foreach ([$own, $other, $carry, $otc, $serving] as $relative) {
            $this->link($ca, $current, $relative);
        }
        $encounter = $this->serving($current, $doctor);
        $this->queue($own, $doctor);
        $this->queue($other, $otherDoctor);
        $this->queue($carry, $doctor, [], ['operational_date' => now($this->branch->timezone)->subDay()->toDateString()]);
        $this->queue($otc, $doctor, ['visit_type' => 'otc', 'visit_reason' => null, 'assigned_doctor_user_id' => null]);
        $this->serving($serving, $doctor);
        $this->queue($notFamily, $doctor);

        $this->selectBranch($doctor);
        $result = app(FamilyWaitingProjection::class)->forEncounter($doctor, $encounter);
        $this->assertSame([$own->patient_number], array_column($result['rows'], 'patientNumber'));
        $this->assertSame(['roleLabel', 'name', 'patientNumber', 'queueLabel', 'url'], array_keys($result['rows'][0]));
        $this->assertSame('Child', $result['rows'][0]['roleLabel']);
        $this->assertSame(route('patients.show', $own), $result['rows'][0]['url']);
        $this->assertSame(['rows', 'recordUrl'], array_keys($result));

        // A branch queue reader also sees the relative waiting for another doctor; never more than that.
        $doctor->givePermissionTo('queue.view.branch');
        $rows = app(FamilyWaitingProjection::class)->forEncounter($doctor->fresh(), $encounter)['rows'];
        $this->assertEqualsCanonicalizing([$own->patient_number, $other->patient_number], array_column($rows, 'patientNumber'));
    }

    public function test_the_consultation_page_carries_the_card_and_no_relative_clinical_data(): void
    {
        $ca = $this->actor();
        $doctor = $this->actor('resident_doctor');
        [$current, $relative] = [$this->patient(), $this->patient(null, ['full_name' => 'Synthetic Waiting Relative'])];
        $this->link($ca, $current, $relative);
        $encounter = $this->serving($current, $doctor);
        $this->queue($relative, $doctor, ['visit_reason' => 'Synthetic private reason']);
        $this->selectBranch($doctor);

        $response = $this->get(route('encounters.show', $encounter->visit))->assertOk();
        $family = $response->viewData('page')['props']['clinical']['familyWaiting'];
        $this->assertSame('Synthetic Waiting Relative', $family['rows'][0]['name']);
        $this->assertStringNotContainsString('Synthetic private reason', json_encode($family, JSON_THROW_ON_ERROR));
    }

    public function test_no_card_off_the_live_consultation_or_for_an_unviewable_patient(): void
    {
        $ca = $this->actor();
        $doctor = $this->actor('resident_doctor');
        $patient = $this->patient();
        $loner = $this->patient();
        $lonerEncounter = $this->serving($loner, $doctor);
        $this->link($ca, $patient, $this->patient());
        $queue = $this->queue($patient, $doctor);
        $encounter = ClinicalEncounter::factory()->create(['visit_id' => $queue->visit_id]);
        $this->selectBranch($doctor);
        $projection = app(FamilyWaitingProjection::class);
        // No family: no card at all, rather than an empty one on every consultation.
        $this->assertNull($projection->forEncounter($doctor, $lonerEncounter));
        $this->assertNull($projection->forEncounter($doctor, $encounter));
        $queue->forceFill(['status' => 'serving', 'called_at' => now(), 'called_by_user_id' => $doctor->id])->save();
        $this->assertSame([], $projection->forEncounter($doctor, $encounter->fresh())['rows']);
        $queue->visit->forceFill(['status' => Visit::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by_user_id' => $doctor->id, 'cancellation_reason' => 'Synthetic closed visit'])->save();
        $this->assertNull($projection->forEncounter($doctor, $encounter->fresh()));
        $queue->visit->forceFill(['status' => Visit::STATUS_REGISTERED, 'cancelled_at' => null, 'cancelled_by_user_id' => null, 'cancellation_reason' => null])->save();
        Gate::before(fn (User $user, string $ability, array $arguments) => ($arguments[0] ?? null) instanceof Patient && $ability === 'view' ? false : null);
        $this->assertNull($projection->forEncounter($doctor, $encounter->fresh()));
    }

    public function test_today_follows_the_branch_time_zone_and_other_branches_are_excluded(): void
    {
        $this->travelTo(Carbon::parse('2026-11-25 16:30:00', 'UTC'));
        $ca = $this->actor();
        $doctor = $this->actor('resident_doctor');
        [$current, $today, $yesterday, $elsewhere] = array_map(fn () => $this->patient(), range(1, 4));
        foreach ([$today, $yesterday, $elsewhere] as $relative) {
            $this->link($ca, $current, $relative);
        }
        $encounter = $this->serving($current, $doctor);
        $this->queue($today, $doctor, [], ['operational_date' => '2026-11-26']);
        $this->queue($yesterday, $doctor, [], ['operational_date' => '2026-11-25']);
        $otherBranch = Branch::query()->where('organisation_id', $this->organisation->id)->whereKeyNot($this->branch->id)->firstOrFail();
        $this->queue($elsewhere, $doctor, ['branch_id' => $otherBranch->id], ['operational_date' => '2026-11-26']);
        $this->selectBranch($doctor);
        $result = app(FamilyWaitingProjection::class)->forEncounter($doctor, $encounter);
        $this->assertSame([$today->patient_number], array_column($result['rows'], 'patientNumber'));
        $this->travelBack();
    }
}

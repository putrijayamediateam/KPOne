<?php

namespace Tests\Feature\Queue;

use App\Domain\Queue\Services\QueueDirectoryService;
use App\Domain\Visit\Services\VisitDirectoryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Tests\Feature\Clinical\Triage\TriageTestCase;

class EmergencyLaneRegistrationScopeTest extends TriageTestCase
{
    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    public function test_branch_registration_identity_does_not_grant_another_doctors_queue_or_triage_metadata(): void
    {
        Date::setTestNow(CarbonImmutable::parse('2026-08-25 09:00:00', $this->branch->timezone)->utc());
        $this->approvedGuideline();
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $other = $this->doctor();
        $ownVisit = $this->consultationVisit($ca, $doctor);
        $otherVisit = $this->consultationVisit($ca, $other);
        foreach ([$ownVisit, $otherVisit] as $visit) {
            $this->send($ca, $visit);
            $this->triage($ca, $visit->refresh(), ['chosen_zone' => 'red']);
        }
        $this->selectBranch($doctor);
        $this->assertTrue($doctor->can('visits.view.branch'));
        $this->assertTrue($doctor->can('queue.view.own'));
        $this->assertFalse($doctor->can('queue.view.branch'));
        $registration = app(VisitDirectoryService::class)->search($doctor, []);
        $this->assertSame([$ownVisit->visit_number], array_column($registration['emergency']['data'], 'visitNumber'));
        $this->assertSame([$otherVisit->visit_number], $registration['data']->pluck('visitNumber')->all());
        $row = $registration['data']->sole();
        $this->assertSame($otherVisit->patient->full_name, $row['patientName']);
        $this->assertSame($otherVisit->patient->patient_number, $row['patientNumber']);
        $this->assertSame($other->name, $row['doctorName']);
        $this->assertSame('registered', $row['status']);
        foreach (['queueNumber', 'queueStatus', 'queueLockVersion', 'triage', 'triageUrl', 'callHold'] as $field) {
            $this->assertNull($row[$field], $field.' must remain hidden for another doctor’s queue.');
        }
        // AUTO-1c adds this optional hint in its separate dependency; absence or null discloses no warning.
        $this->assertNull($row['doctorWarning'] ?? null);
        $this->assertFalse($row['can']['call']);
        $this->assertFalse($row['can']['openConsultation']);
        foreach (['episodeId', 'alertStartedAt', 'takenBy', 'canTake', 'canSeen'] as $field) {
            $this->assertArrayNotHasKey($field, $row);
        }
        $queue = app(QueueDirectoryService::class)->snapshot($doctor);
        $this->assertSame([$ownVisit->visit_number], array_column($queue['emergency']['data'], 'visitNumber'));
        $this->assertSame([], $queue['waiting']['data']->all());
        $this->assertSame(0, $queue['waiting']['total']);
    }
}

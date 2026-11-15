<?php

namespace Tests\Feature\Clinical;

use App\Domain\Visit\Services\VisitAdministrationService;
use App\Domain\Visit\Services\VisitDirectoryService;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * UX-2 (S1-005): the visit page gets one boolean, hasConsultation, that is true exactly when the
 * visit has a clinical encounter, so the Medical certificates link appears only when that page works.
 */
class VisitConsultationFlagTest extends ClinicalTestCase
{
    public function test_has_consultation_is_true_only_once_the_doctor_has_opened_the_consultation(): void
    {
        $doctor = $this->doctor();
        $ca = $this->actor('ca');
        $visit = $this->consultationVisit($ca, $doctor);

        $this->assertFlag($ca, $visit->visit_number, false, 'registered, not sent to waiting');

        $this->send($ca, $visit);
        $this->assertFlag($ca, $visit->visit_number, false, 'waiting');

        [, , $called, $serving] = $this->servingFixture($doctor, $ca);
        $this->assertNotNull($serving->called_at);
        $this->assertFlag($ca, $called->visit_number, false, 'called in, consultation not opened yet');

        $this->startEncounter($doctor, $called, $serving);
        $this->assertFlag($ca, $called->visit_number, true, 'consultation opened');

        // The other visit, still waiting, is unaffected by another visit's encounter.
        $this->assertFlag($ca, $visit->visit_number, false, 'waiting beside an opened consultation');
    }

    public function test_has_consultation_is_false_for_a_cancelled_visit_and_for_an_otc_visit(): void
    {
        $ca = $this->actor('ca');
        $consultation = $this->consultationVisit($ca, $this->doctor());
        $cancelled = app(VisitAdministrationService::class)->cancel($consultation, [
            'expected_branch_id' => $this->branch->id,
            'lock_version' => $consultation->lock_version,
            'cancellation_reason' => 'Synthetic cancellation',
        ], $ca);
        $this->assertFlag($ca, $cancelled->visit_number, false, 'cancelled');

        $otc = $this->register($ca, $this->patient($ca));
        $this->assertSame('otc', $otc->visit_type);
        $this->assertFlag($ca, $otc->visit_number, false, 'otc');
    }

    public function test_the_flag_matches_whether_the_medical_certificates_page_opens(): void
    {
        $doctor = $this->doctor();
        $supervisor = $this->actor('ca_supervisor');
        [, , $visit, $queue] = $this->servingFixture($doctor, $this->actor('ca'));

        $this->assertFlag($supervisor, $visit->visit_number, false, 'called in, consultation not opened');
        $this->get(route('medical-certificates.index', $visit))->assertNotFound();

        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $this->assertFlag($supervisor, $visit->visit_number, true, 'consultation opened');
        $this->get(route('medical-certificates.index', $visit))->assertOk();

        $this->holdEncounter($doctor, $visit, $queue, $encounter);
        $this->assertFlag($supervisor, $visit->visit_number, true, 'consultation on hold');
        $this->get(route('medical-certificates.index', $visit))->assertOk();
    }

    public function test_the_flag_is_a_plain_boolean(): void
    {
        $doctor = $this->doctor();
        $ca = $this->actor('ca');
        [, , $visit, $queue] = $this->servingFixture($doctor, $ca);
        $this->startEncounter($doctor, $visit, $queue);
        $this->selectBranch($ca);

        $detail = app(VisitDirectoryService::class)->detail($ca, $visit->refresh());

        $this->assertArrayHasKey('hasConsultation', $detail);
        $this->assertIsBool($detail['hasConsultation']);
        $this->assertTrue($detail['hasConsultation']);
    }

    private function assertFlag(User $actor, string $visitNumber, bool $expected, string $state): void
    {
        $this->selectBranch($actor);
        $response = $this->get(route('visits.show', $visitNumber))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Registration/Show')
                ->where('visit.visitNumber', $visitNumber)
                ->has('visit.hasConsultation'));

        $this->assertSame($expected, $response->viewData('page')['props']['visit']['hasConsultation'], $state);
    }
}

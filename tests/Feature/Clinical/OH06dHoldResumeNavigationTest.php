<?php

declare(strict_types=1);

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Models\ConsultationHold;
use Illuminate\Support\Str;
use Inertia\Support\SessionKey;

/**
 * OH-06d: pressing On Hold or Resume Consultation used to send the doctor to
 * the Registration board via the controller's `return back();`, because
 * Laravel's `back()` resolves to `url()->previous()` - the last *full page*
 * GET the session recorded - which, in an Inertia SPA where most navigation
 * is client-side XHR, is very often not the page the request actually came
 * from. The fix redirects explicitly to `encounters.show` instead, for both
 * the success path and a blocked Resume (which Laravel's own
 * ValidationException::redirectTo() otherwise defaults to `back()` too).
 */
class OH06dHoldResumeNavigationTest extends ClinicalTestCase
{
    public function test_hold_keeps_the_doctor_on_the_same_consultation_and_holds_it(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);

        $this->selectBranch($doctor, $visit->branch);
        $response = $this->actingAs($doctor)->post(route('encounters.hold', $visit), [
            'expected_branch_id' => $visit->branch_id,
            'visit_lock_version' => $visit->lock_version,
            'queue_lock_version' => $queue->lock_version,
            'encounter_lock_version' => $encounter->lock_version,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertRedirect(route('encounters.show', $visit));
        $this->assertTrue(
            ConsultationHold::query()
                ->where('clinical_encounter_id', $encounter->id)
                ->whereNull('resumed_at')
                ->exists(),
        );
    }

    public function test_resume_keeps_the_doctor_on_the_same_consultation_and_reactivates_it(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $this->holdEncounter($doctor, $visit, $queue, $encounter);

        $this->selectBranch($doctor, $visit->branch);
        $response = $this->actingAs($doctor)->post(route('encounters.resume', $visit), [
            'expected_branch_id' => $visit->branch_id,
            'visit_lock_version' => $visit->refresh()->lock_version,
            'queue_lock_version' => $queue->refresh()->lock_version,
            'encounter_lock_version' => $encounter->refresh()->lock_version,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertRedirect(route('encounters.show', $visit));
        $this->assertFalse(
            ConsultationHold::query()
                ->where('clinical_encounter_id', $encounter->id)
                ->whereNull('resumed_at')
                ->exists(),
            'The hold must be resolved (resumed) after a successful Resume.',
        );
    }

    public function test_a_blocked_resume_stays_on_the_same_consultation_with_an_error_toast_and_changes_nothing(): void
    {
        $doctor = $this->doctor();
        $ca = $this->actor('ca');
        [, , $visitA, $queueA] = $this->servingFixture($doctor, $ca);
        $encounterA = $this->startEncounter($doctor, $visitA, $queueA);
        $this->holdEncounter($doctor, $visitA, $queueA, $encounterA);

        // The doctor is now actively serving a second patient, B - resuming A
        // must be blocked while B stays active and unheld.
        [, , $visitB, $queueB] = $this->servingFixture($doctor, $ca);
        $this->startEncounter($doctor, $visitB, $queueB);

        $this->selectBranch($doctor, $visitA->branch);
        $response = $this->actingAs($doctor)->post(route('encounters.resume', $visitA), [
            'expected_branch_id' => $visitA->branch_id,
            'visit_lock_version' => $visitA->refresh()->lock_version,
            'queue_lock_version' => $queueA->refresh()->lock_version,
            'encounter_lock_version' => $encounterA->refresh()->lock_version,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertRedirect(route('encounters.show', $visitA));
        $response->assertSessionHasErrors('hold');
        $this->assertTrue(
            ConsultationHold::query()
                ->where('clinical_encounter_id', $encounterA->id)
                ->whereNull('resumed_at')
                ->exists(),
            'A blocked resume must leave the original hold untouched.',
        );

        $flash = session()->get(SessionKey::FLASH_DATA, []);
        $this->assertArrayHasKey('toast', $flash, 'A blocked resume must still flash a toast.');
        $this->assertSame('error', $flash['toast']['type']);
    }
}

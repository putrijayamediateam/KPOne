<?php

namespace Tests\Feature\Clinical\Triage;

use App\Domain\Clinical\Triage\TriageGuidelineVersion;
use App\Domain\Clinical\Triage\VisitTriageAssessment;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Services\VisitDirectoryService;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/** TRI-1b: the Triage tab, the Classic triage form and the guideline settings page. Synthetic data only. */
class TriageScreensTest extends TriageTestCase
{
    public function test_the_triage_tab_lists_waiting_consultations_without_triage_oldest_first_for_ca_only(): void
    {
        $this->approvedGuideline();
        [$ca, $first] = $this->waitingFixture();
        $this->travel(2)->minutes();
        [, $second] = $this->waitingFixture($ca);
        $otc = $this->register($ca, $this->patient($ca), ['visit_type' => 'otc', 'assigned_doctor_user_id' => null]);
        $this->assertNotNull($otc);
        $this->selectBranch($ca);
        $board = app(VisitDirectoryService::class)->search($ca, ['board_status' => 'triage']);
        $this->assertSame([$first->visit_number, $second->visit_number], collect($board['data'])->pluck('visitNumber')->all());
        $this->assertSame(2, $board['tabCounts']['triage']);
        $this->assertSame(route('visits.triage.show', $first), $board['data'][0]['triageUrl']);

        $this->triage($ca, $first);
        $this->selectBranch($ca);
        $board = app(VisitDirectoryService::class)->search($ca, ['board_status' => 'triage']);
        $this->assertSame([$second->visit_number], collect($board['data'])->pluck('visitNumber')->all());
        $this->assertSame(1, $board['tabCounts']['triage']);

        $this->get('/registration?tab=triage')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Registration/Index')->where('visits.tabCounts.triage', 1)->where('visits.data.0.visitNumber', $second->visit_number));

        foreach (['director', 'resident_doctor', 'finance_officer'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            if (! $actor->can('visits.view.branch')) {
                continue;
            }
            $this->postJson(route('registration.search'))->assertOk()->assertJsonMissingPath('tabCounts.triage');
            $this->postJson(route('registration.search'), ['board_status' => 'triage'])->assertNotFound();
            $this->assertNull(collect($this->postJson(route('registration.search'))->json('data'))->firstWhere('visitNumber', $second->visit_number)['triageUrl'] ?? null);
        }
    }

    public function test_the_form_is_for_ca_at_the_visit_branch_and_shows_no_date_of_birth(): void
    {
        $this->approvedGuideline();
        [$ca, $visit] = $this->waitingFixture();
        $this->selectBranch($ca);
        $this->get(route('visits.triage.show', $visit))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Triage/Show')
            ->where('visit.visitNumber', $visit->visit_number)
            ->where('readOnly', null)
            ->where('ageBand', 'adult')
            ->where('expected.latestTriageId', null)
            ->has('guideline.limits', 4)
            ->missing('visit.dateOfBirth')
            ->where('lowering.readingsToRetake', []));

        foreach (['resident_doctor', 'director', 'hr_manager', 'finance_officer', 'administrator'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->get(route('visits.triage.show', $visit))->assertForbidden();
            $this->post(route('visits.triage.store', $visit), $this->triageInput($visit))->assertForbidden();
        }
        $otherBranch = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', '<>', 'CHERAS')->firstOrFail();
        $other = $this->actor('ca', $otherBranch);
        $this->selectBranch($other, $otherBranch);
        $this->get(route('visits.triage.show', $visit))->assertNotFound();
        $this->assertSame(0, VisitTriageAssessment::query()->count());
    }

    public function test_saving_returns_to_the_triage_tab_and_a_refusal_returns_to_the_form_without_flashing_readings(): void
    {
        $this->approvedGuideline();
        [$ca, $visit] = $this->waitingFixture();
        $this->selectBranch($ca);
        $this->post(route('visits.triage.store', $visit), $this->triageInput($visit, ['temperature_celsius' => '40.00', 'chosen_zone' => 'red']))
            ->assertRedirect(route('registration.index', ['tab' => 'triage']));
        $this->assertSame('red', VisitTriageAssessment::query()->sole()->chosen_zone);

        $response = $this->from(route('registration.index'))->post(route('visits.triage.store', $visit), $this->triageInput($visit, ['temperature_celsius' => '39.80', 'chosen_zone' => 'green', 'override_reason_code' => 'device_error_confirmed']));
        $response->assertRedirect(route('visits.triage.show', $visit))->assertSessionHasErrors('chosen_zone');
        $old = (array) session()->getOldInput();
        foreach (['temperature_celsius', 'systolic_bp', 'pulse_bpm', 'spo2_percent', 'weight_kg', 'height_cm', 'diastolic_bp'] as $field) {
            $this->assertArrayNotHasKey($field, $old, "$field must not be flashed");
        }
        $this->assertSame(1, VisitTriageAssessment::query()->count());

        $this->from(route('registration.index'))->post(route('visits.triage.store', $visit), ['expected_branch_id' => $this->branch->id])
            ->assertRedirect(route('visits.triage.show', $visit));
    }

    public function test_the_form_is_read_only_once_the_doctor_starts_the_consultation(): void
    {
        $this->approvedGuideline();
        [$doctor, $ca, $serving, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $serving, $queue->refresh());
        $this->selectBranch($ca);
        $this->get(route('visits.triage.show', $serving))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('readOnly', 'Triage is read only once the doctor has started the consultation.'));
    }

    public function test_guideline_page_enter_approve_by_the_other_manager_and_discard(): void
    {
        $hr = $this->actor('hr_manager');
        $director = $this->actor('director');
        $this->actingAs($hr);
        $this->get('/triage-guideline')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('TriageGuideline/Index')->where('inForce', null)->where('draft', null));
        $input = $this->guidelineInput([
            'thresholds' => [
                ['reading' => 'temperature_celsius', 'age_band' => 'adult', 'direction' => 'very_high', 'limit_value' => '38', 'zone' => 'yellow'],
                ['reading' => 'temperature_celsius', 'age_band' => 'adult', 'direction' => 'very_high', 'limit_value' => '39.5', 'zone' => 'red'],
            ],
        ]);
        $this->post('/triage-guideline', $input)->assertRedirect(route('triage-guideline.index'));
        $draft = TriageGuidelineVersion::query()->sole();
        $this->get('/triage-guideline')->assertInertia(fn (Assert $page) => $page
            ->where('draft.enteredByYou', true)->has('draft.limits', 2));
        $this->post("/triage-guideline/{$draft->id}/approve", ['lock_version' => 1, 'matches_signed_copy' => true, 'idempotency_key' => (string) Str::uuid()])
            ->assertRedirect(route('triage-guideline.index'))->assertSessionHasErrors();
        $this->assertSame('draft', $draft->refresh()->status, 'the entrant cannot approve');

        $this->actingAs($director);
        $this->post("/triage-guideline/{$draft->id}/approve", ['lock_version' => 1, 'matches_signed_copy' => false, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('matches_signed_copy');
        $this->post("/triage-guideline/{$draft->id}/approve", ['lock_version' => 1, 'matches_signed_copy' => true, 'idempotency_key' => (string) Str::uuid()])
            ->assertRedirect(route('triage-guideline.index'))->assertSessionHasNoErrors();
        $this->assertSame('approved', $draft->refresh()->status);

        $this->post('/triage-guideline', $this->guidelineInput())->assertRedirect(route('triage-guideline.index'));
        $second = TriageGuidelineVersion::query()->where('status', 'draft')->sole();
        $this->post("/triage-guideline/{$second->id}/discard", ['lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame('discarded', $second->refresh()->status);
        $this->get('/triage-guideline')->assertInertia(fn (Assert $page) => $page->has('history', 2)->where('inForce.versionNumber', 1));

        foreach (['ca', 'ca_supervisor', 'resident_doctor', 'administrator', 'technical_admin'] as $role) {
            $this->actingAs($this->actor($role));
            $this->get('/triage-guideline')->assertForbidden();
            $this->post('/triage-guideline', $this->guidelineInput())->assertForbidden();
        }
    }

    public function test_clinic_management_shows_the_guideline_row_to_its_managers_only(): void
    {
        foreach (['director' => true, 'hr_manager' => true, 'ca' => false, 'administrator' => false] as $role => $sees) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->get('/clinic-management')->assertOk()->assertInertia(fn (Assert $page) => $page->where('groups', function ($groups) use ($sees): bool {
                $hrefs = collect($groups)->flatMap(fn ($group) => $group['links'])->pluck('href')->all();
                $this->assertSame($sees, in_array('/triage-guideline', $hrefs, true));

                return true;
            }));
        }
    }
}

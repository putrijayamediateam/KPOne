<?php

namespace Tests\Feature\Clinical;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Services\ClinicalEncounterService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Shared\ReadOnlyReviewAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;

/**
 * Item 4 (owner decision P4 of 20-21 Nov 2026; 15 = A, 16 = A): the Director's read-only view of a consultation, at
 * any branch, every open recorded in the audit log first. Synthetic data only.
 */
class ConsultationReviewTest extends ClinicalTestCase
{
    private const PERMISSION = 'consultations.view_readonly.organisation';

    /** @return array{encounter: ClinicalEncounter, doctor: User} */
    private function consultation(): array
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $encounter = app(ClinicalEncounterService::class)->update($doctor, $visit->refresh(), $this->aggregate($encounter));

        return ['encounter' => $encounter->refresh(), 'doctor' => $doctor];
    }

    private function directorAtAnotherBranch(): User
    {
        $other = Branch::query()->where('organisation_id', $this->branch->organisation_id)->whereKeyNot($this->branch->id)->firstOrFail();

        return $this->actor('director', $other);
    }

    public function test_only_the_director_holds_the_permission_and_it_is_not_people_or_access_authority(): void
    {
        $holders = collect(PermissionCatalogue::roles())->filter(fn (array $permissions): bool => in_array(self::PERMISSION, $permissions, true))->keys()->all();

        $this->assertSame(['director'], $holders);
        $this->assertContains(self::PERMISSION, PermissionCatalogue::all());
        $this->assertNotContains(self::PERMISSION, PermissionCatalogue::AUTHORITY_OVER_PEOPLE_AND_ACCESS);
    }

    public function test_the_director_opens_a_consultation_at_another_branch_and_the_view_is_recorded_first_with_ids_only(): void
    {
        $c = $this->consultation();
        $visit = $c['encounter']->visit;
        $director = $this->directorAtAnotherBranch();
        $before = AuditLog::query()->max('id') ?? 0;

        $this->actingAs($director)->get(route('encounters.review', $visit->visit_number))->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private')
            ->assertInertia(fn (Assert $page) => $page->component('Clinical/Review')
                ->where('review.patient.name', $visit->patient->full_name)
                ->where('review.clinicalNote', 'Synthetic clinical note')
                ->where('review.vitals.systolicBp', 120)
                ->where('review.diagnoses.0.text', 'Synthetic diagnosis alpha'));

        $logs = AuditLog::query()->where('id', '>', $before)->where('event', 'consultation.reviewed')->get();
        $this->assertCount(1, $logs);
        $log = $logs->sole();
        $this->assertSame($director->id, $log->actor_user_id);
        $this->assertSame($c['encounter']->id, $log->subject_id);
        $this->assertSame(['ids' => ['encounter' => $c['encounter']->id, 'visit' => $visit->id, 'patient' => $visit->patient_id]], $log->metadata);
        $this->assertStringNotContainsString($visit->patient->full_name, json_encode($log->metadata, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('Synthetic clinical note', json_encode($log->metadata, JSON_THROW_ON_ERROR));
    }

    public function test_every_open_is_recorded_and_head_records_nothing(): void
    {
        $c = $this->consultation();
        $url = route('encounters.review', $c['encounter']->visit->visit_number);
        $this->actingAs($this->directorAtAnotherBranch());
        $this->get($url)->assertOk();
        $this->get($url)->assertOk();
        $this->assertSame(2, AuditLog::query()->where('event', 'consultation.reviewed')->count());

        $this->call('HEAD', $url)->assertNoContent();
        $this->assertSame(2, AuditLog::query()->where('event', 'consultation.reviewed')->count());
    }

    public function test_the_review_data_has_pinned_keys_and_nothing_a_form_could_post(): void
    {
        $c = $this->consultation();
        $review = $this->actingAs($this->directorAtAnotherBranch())->get(route('encounters.review', $c['encounter']->visit->visit_number))->viewData('page')['props']['review'];

        $this->assertSame(['branch', 'timezone', 'patient', 'visit', 'doctor', 'status', 'startedAt', 'vitals', 'clinicalNote', 'diagnoses', 'allergies', 'medicines', 'services', 'withdrawn'], array_keys($review));
        $this->assertSame(['text', 'code', 'isPrimary'], array_keys($review['diagnoses'][0]));
        $keys = [];
        $walk = function (array $node) use (&$walk, &$keys): void {
            foreach ($node as $key => $value) {
                $keys[] = (string) $key;
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($review);
        foreach (array_unique($keys) as $key) {
            $this->assertDoesNotMatchRegularExpression('/url|lock|version|token|retry|idempotency|^can$|publicId|code$/i', $key === 'code' ? 'x' : $key, "Review key {$key} must not be posted back.");
        }
    }

    public function test_every_other_role_is_refused_and_another_organisation_gets_404(): void
    {
        $c = $this->consultation();
        $url = route('encounters.review', $c['encounter']->visit->visit_number);
        foreach (['ca', 'ca_supervisor', 'resident_doctor', 'finance_officer', 'administrator', 'technical_admin', 'hr_manager', 'panel_officer', 'queue_display'] as $role) {
            $this->actingAs($this->actor($role))->get($url)->assertForbidden();
        }
        $this->assertSame(0, AuditLog::query()->where('event', 'consultation.reviewed')->count());

        $foreign = User::factory()->create(['organisation_id' => DB::table('organisations')->insertGetId(['name' => 'Synthetic Other Clinic', 'code' => 'SYN-OTHER', 'created_at' => now(), 'updated_at' => now()]), 'email' => 'synthetic.foreign.director@kpone.test']);
        $foreign->givePermissionTo(self::PERMISSION);
        $this->actingAs($foreign)->get($url)->assertNotFound();
        $this->assertSame(0, AuditLog::query()->where('event', 'consultation.reviewed')->count());
    }

    public function test_a_failed_audit_write_shows_nothing(): void
    {
        $c = $this->consultation();
        $this->app->instance(AuditRecorder::class, new class extends AuditRecorder
        {
            public function record(string $event, ?Model $subject = null, array $metadata = [], ?User $actor = null, ?Branch $branch = null, ?int $organisationId = null): ?AuditLog
            {
                return null;
            }
        });

        // Production runs with APP_DEBUG off (with debug on, the error page lists every query, including the fixture's).
        config(['app.debug' => false]);
        $visit = $c['encounter']->visit;
        $response = $this->actingAs($this->directorAtAnotherBranch())->get(route('encounters.review', $visit->visit_number));
        $response->assertStatus(500);
        $content = (string) $response->getContent();
        $this->assertStringNotContainsString($visit->patient->full_name, $content);
        $this->assertStringNotContainsString($visit->patient->patient_number, $content);
        $this->assertStringNotContainsString('Synthetic clinical note', $content);
    }

    public function test_the_helper_refuses_an_event_or_id_key_that_is_not_allowlisted(): void
    {
        $c = $this->consultation();
        $helper = app(ReadOnlyReviewAudit::class);
        $director = $this->directorAtAnotherBranch();

        foreach ([['consultation.something_else', ['encounter' => 1]], ['consultation.reviewed', ['name' => 'x']]] as [$event, $ids]) {
            try {
                $helper->record($director, $event, $c['encounter'], $ids);
                $this->fail('An event or id key outside the allowlist must be refused.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, AuditLog::query()->where('event', 'consultation.reviewed')->count());
    }

    public function test_the_review_path_is_get_only(): void
    {
        $methods = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_contains($route->uri(), 'encounter/review'))
            ->flatMap(fn ($route): array => $route->methods())->unique()->sort()->values()->all();

        $this->assertSame(['GET', 'HEAD'], $methods);
    }

    public function test_the_director_is_refused_on_every_consultation_write_route_and_nothing_is_written(): void
    {
        $c = $this->consultation();
        $visit = $c['encounter']->visit;
        $director = $this->actor('director', $visit->branch);
        $this->selectBranch($director, $visit->branch);
        $tables = ['clinical_encounters', 'encounter_vital_observations', 'encounter_diagnoses', 'treatment_plans', 'treatment_plan_medicine_orders', 'treatment_plan_service_orders', 'patient_allergy_records', 'queue_entries', 'queue_calls', 'consultation_holds', 'dispensary_cases', 'visits'];
        $snapshot = fn (): array => collect($tables)->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all()])->all();
        $before = $snapshot();

        $writes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => preg_match('#^visits/\{(visit|historicalVisit)\}/(encounter|consultation|medical-certificates|referrals)#', $route->uri()) === 1
                && array_intersect($route->methods(), ['POST', 'PATCH', 'PUT', 'DELETE']) !== []);
        $this->assertNotEmpty($writes);
        foreach ($writes as $route) {
            $uri = preg_replace('#\{[^}]+\}#', '1', str_replace(['{visit}', '{historicalVisit}'], $visit->visit_number, $route->uri()));
            $method = strtolower(collect($route->methods())->first(fn (string $m): bool => in_array($m, ['POST', 'PATCH', 'PUT', 'DELETE'], true)));
            $status = $this->{$method.'Json'}('/'.$uri, ['expected_branch_id' => $visit->branch_id])->status();
            $this->assertContains($status, [403, 404], "{$method} /{$route->uri()} must be refused for the Director (got {$status}).");
        }

        $this->assertSame($before, $snapshot());
    }

    public function test_the_visit_page_offers_the_link_to_the_director_only_and_showing_it_records_nothing(): void
    {
        $c = $this->consultation();
        $visit = $c['encounter']->visit;
        $director = $this->actor('director', $visit->branch);
        $this->selectBranch($director, $visit->branch);
        $this->get(route('visits.show', $visit))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('visit.consultationReview', ['url' => route('encounters.review', $visit->visit_number), 'status' => 'in_progress']));

        $ca = $this->actor('ca', $visit->branch);
        $this->selectBranch($ca, $visit->branch);
        $this->actingAs($ca)->get(route('visits.show', $visit))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('visit.consultationReview', null));
        $this->assertSame(0, AuditLog::query()->where('event', 'consultation.reviewed')->count(), 'Showing the link records nothing.');
    }
}

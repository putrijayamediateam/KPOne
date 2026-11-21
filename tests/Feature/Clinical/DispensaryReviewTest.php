<?php

namespace Tests\Feature\Clinical;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Shared\ReadOnlyReviewAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\OtcDispensaryFixtures;

/**
 * DSP-25 (owner decision 4 of 20-21 Nov 2026; answers P5, 15 = A and 16 = A): the Director's read-only view of a
 * dispensary case, at any branch, every open recorded in the audit log first. Synthetic data only.
 */
class DispensaryReviewTest extends VisitTestCase
{
    use OtcDispensaryFixtures;

    private const PERMISSION = 'dispensary.view_readonly.organisation';

    /** @return array{f: array<string, mixed>, case: DispensaryCase} */
    private function completedCase(): array
    {
        $f = $this->fixture();
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case, '3.000'));
        $this->confirmAllergy($f, $case->refresh());

        return ['f' => $f, 'case' => $this->complete($f, $case)->refresh()];
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
        $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority(self::PERMISSION));
        $this->assertTrue(Role::findByName('director')->hasPermissionTo(self::PERMISSION));
    }

    public function test_the_director_opens_a_case_at_another_branch_and_the_view_is_recorded_first_with_ids_only(): void
    {
        $c = $this->completedCase();
        $director = $this->directorAtAnotherBranch();
        $before = AuditLog::query()->max('id') ?? 0;

        $this->actingAs($director)->get(route('dispensary.review', $c['case']))->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private')
            ->assertInertia(fn (Assert $page) => $page->component('Dispensary/Review')
                ->where('review.status', 'completed')
                ->where('review.patient.name', $c['f']['visit']->patient->full_name)
                ->where('review.medicines.0.dispensed.quantity', '3.000')
                ->has('review.medicines.0.batches', 1));

        $logs = AuditLog::query()->where('id', '>', $before)->where('event', 'dispensary.case_reviewed')->get();
        $this->assertCount(1, $logs);
        $log = $logs->sole();
        $this->assertSame($director->id, $log->actor_user_id);
        $this->assertSame($c['case']->id, $log->subject_id);
        $this->assertSame(['ids' => ['case' => $c['case']->id, 'visit' => $c['case']->visit_id, 'patient' => $c['f']['visit']->patient_id]], $log->metadata);
        $this->assertStringNotContainsString($c['f']['visit']->patient->full_name, json_encode($log->metadata, JSON_THROW_ON_ERROR));
    }

    public function test_every_open_is_recorded(): void
    {
        $c = $this->completedCase();
        $this->actingAs($this->directorAtAnotherBranch());
        $this->get(route('dispensary.review', $c['case']))->assertOk();
        $this->get(route('dispensary.review', $c['case']))->assertOk();

        $this->assertSame(2, AuditLog::query()->where('event', 'dispensary.case_reviewed')->count());
    }

    public function test_the_review_data_has_pinned_keys_and_nothing_a_form_could_post(): void
    {
        $c = $this->completedCase();
        $review = $this->actingAs($this->directorAtAnotherBranch())->get(route('dispensary.review', $c['case']))->viewData('page')['props']['review'];

        $this->assertSame(['status', 'caseType', 'branch', 'patient', 'visit', 'doctor', 'receivedAt', 'completedAt', 'allergies', 'medicines', 'services', 'returns'], array_keys($review));
        $this->assertSame(['name', 'strength', 'unit', 'source', 'changeState', 'status', 'ordered', 'dispensed', 'batches'], array_keys($review['medicines'][0]));
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
            $this->assertDoesNotMatchRegularExpression('/url|lock|version|token|retry|idempotency|^can$|publicId|availability/i', $key, "Review key {$key} must not be posted back.");
        }
    }

    public function test_every_other_role_is_refused_and_another_organisation_gets_404(): void
    {
        $c = $this->completedCase();
        foreach (['ca', 'ca_supervisor', 'resident_doctor', 'finance_officer', 'administrator', 'technical_admin', 'hr_manager', 'panel_officer'] as $role) {
            $this->actingAs($this->actor($role))->get(route('dispensary.review', $c['case']))->assertForbidden();
        }
        $this->assertSame(0, AuditLog::query()->where('event', 'dispensary.case_reviewed')->count());

        $foreign = User::factory()->create(['organisation_id' => $this->otherOrganisationId(), 'email' => 'synthetic.foreign.director@kpone.test']);
        $foreign->givePermissionTo(self::PERMISSION);
        $this->actingAs($foreign)->get(route('dispensary.review', $c['case']))->assertNotFound();
        $this->assertSame(0, AuditLog::query()->where('event', 'dispensary.case_reviewed')->count());
    }

    public function test_a_failed_audit_write_shows_nothing(): void
    {
        $c = $this->completedCase();
        $this->app->instance(AuditRecorder::class, new class extends AuditRecorder
        {
            public function record(string $event, ?Model $subject = null, array $metadata = [], ?User $actor = null, ?Branch $branch = null, ?int $organisationId = null): ?AuditLog
            {
                return null;
            }
        });

        $response = $this->actingAs($this->directorAtAnotherBranch())->get(route('dispensary.review', $c['case']));
        $response->assertStatus(500);
        $this->assertStringNotContainsString($c['f']['visit']->patient->full_name, (string) $response->getContent());
    }

    public function test_the_helper_refuses_an_event_or_id_key_that_is_not_allowlisted(): void
    {
        $c = $this->completedCase();
        $director = $this->directorAtAnotherBranch();

        try {
            app(ReadOnlyReviewAudit::class)->record($director, 'dispensary.something_else', $c['case'], ['case' => 1]);
            $this->fail('An event outside the allowlist must be refused.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        app(ReadOnlyReviewAudit::class)->record($director, 'dispensary.case_reviewed', $c['case'], ['name' => 'Synthetic']);
    }

    public function test_the_review_path_is_get_only(): void
    {
        $methods = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_contains($route->uri(), 'dispensary/{dispensaryCase}/review'))
            ->flatMap(fn ($route): array => $route->methods())->unique()->sort()->values()->all();

        $this->assertSame(['GET', 'HEAD'], $methods);
    }

    public function test_the_director_is_refused_on_every_dispensary_write_route_and_nothing_is_written(): void
    {
        $c = $this->completedCase();
        $director = $this->actor('director', $c['f']['visit']->branch);
        $this->selectBranch($director, $c['f']['visit']->branch);
        $tables = ['dispensary_cases', 'dispensary_items', 'dispensary_handoffs', 'dispensary_returns', 'dispensary_return_checks', 'dispensary_label_prints', 'dispensary_service_lines', 'stock_movements', 'patient_allergy_records'];
        $snapshot = fn (): array => collect($tables)->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all()])->all();
        $before = $snapshot();
        $item = $c['case']->handoffs()->sole()->items()->sole()->public_id;

        $writes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'dispensary/') && array_intersect($route->methods(), ['POST', 'PATCH', 'PUT', 'DELETE']) !== []);
        $this->assertNotEmpty($writes);
        foreach ($writes as $route) {
            $uri = str_replace(['{dispensaryCase}', '{itemPublicId}', '{returnPublicId}', '{serviceLinePublicId}', '{dispensaryItem}', '{dispensaryServiceLine}'], [$c['case']->public_id, $item, $item, $item, $item, $item], $route->uri());
            $method = strtolower(collect($route->methods())->first(fn (string $m): bool => in_array($m, ['POST', 'PATCH', 'PUT', 'DELETE'], true)));
            $status = $this->{$method.'Json'}('/'.$uri, ['expected_branch_id' => $c['f']['visit']->branch_id])->status();
            $this->assertContains($status, [403, 404, 405, 409, 422], "{$method} /{$route->uri()} must be refused for the Director (got {$status}).");
        }

        $this->assertSame($before, $snapshot());
    }

    public function test_the_visit_page_offers_the_link_to_the_director_only(): void
    {
        $c = $this->completedCase();
        $director = $this->actor('director', $c['f']['visit']->branch);
        $this->selectBranch($director, $c['f']['visit']->branch);
        $this->get(route('visits.show', $c['f']['visit']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('visit.dispensaryReviewUrl', route('dispensary.review', $c['case']->public_id)));

        $this->selectBranch($c['f']['ca'], $c['f']['visit']->branch);
        $this->get(route('visits.show', $c['f']['visit']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('visit.dispensaryReviewUrl', null));
        $this->assertSame(0, AuditLog::query()->where('event', 'dispensary.case_reviewed')->count(), 'Showing the link records nothing.');
    }

    private function otherOrganisationId(): int
    {
        return (int) DB::table('organisations')->insertGetId(['name' => 'Synthetic Other Clinic', 'code' => 'SYN-OTHER', 'created_at' => now(), 'updated_at' => now()]);
    }
}

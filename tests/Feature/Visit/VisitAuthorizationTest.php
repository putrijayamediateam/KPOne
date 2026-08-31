<?php

namespace Tests\Feature\Visit;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Models\Visit;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

class VisitAuthorizationTest extends VisitTestCase
{
    public function test_guest_inactive_and_non_operational_roles_are_denied(): void
    {
        $this->get(route('registration.index'))->assertRedirect(route('login'));

        foreach (['technical_admin', 'panel_officer', 'finance_officer', 'business_development', 'marketing', 'hr_manager'] as $role) {
            $this->actingAs($this->actor($role))->get(route('registration.index'))->assertForbidden();
        }

        $inactive = $this->actor();
        $inactive->forceFill(['is_active' => false])->save();
        $this->actingAs($inactive)->get(route('registration.index'))->assertRedirect(route('login'));
    }

    #[DataProvider('roleMatrix')]
    public function test_locked_visit_permission_matrix(string $role, array $allowed): void
    {
        $actor = $this->actor($role);
        foreach (['visits.view.branch', 'visits.create.branch', 'visits.update.branch', 'visits.cancel.branch'] as $permission) {
            $this->assertSame(in_array($permission, $allowed, true), $actor->can($permission), $role.' '.$permission);
        }
    }

    public static function roleMatrix(): array
    {
        $write = ['visits.view.branch', 'visits.create.branch', 'visits.update.branch', 'visits.cancel.branch'];

        return [
            'director' => ['director', $write],
            'doctor read only' => ['resident_doctor', ['visits.view.branch']],
            'ca' => ['ca', $write],
            'ca supervisor' => ['ca_supervisor', $write],
            'technical admin' => ['technical_admin', []],
        ];
    }

    public function test_doctor_can_view_but_cannot_create_update_or_cancel(): void
    {
        $ca = $this->actor();
        $visit = $this->register($ca, $this->patient());
        $doctor = $this->actor('resident_doctor');

        $this->actingAs($doctor)->get(route('visits.show', $visit))->assertOk();
        $this->actingAs($doctor)->get(route('registration.create'))->assertForbidden();
        $this->actingAs($doctor)->patch(route('visits.update', $visit), [])->assertForbidden();
        $this->actingAs($doctor)->patch(route('visits.cancel', $visit), [])->assertForbidden();
    }

    public function test_cross_branch_visit_probe_is_404_until_active_context_is_switched(): void
    {
        $ca = $this->actor();
        $visit = $this->register($ca, $this->patient());
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->where('id', '!=', $this->branch->id)->firstOrFail();
        $director = $this->actor('director', $other);
        $this->selectBranch($director, $other);

        $this->get(route('visits.show', $visit))->assertNotFound();
        $this->selectBranch($director, $this->branch);
        $this->get(route('visits.show', $visit))->assertOk();
    }

    public function test_browser_cannot_select_visit_ownership_and_stale_branch_context_is_rejected(): void
    {
        $ca = $this->actor();
        $patient = $this->patient();
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->where('id', '!=', $this->branch->id)->firstOrFail();
        $this->selectBranch($ca);

        $this->post(route('registration.store'), [
            ...$this->visitAttributes($patient),
            'expected_branch_id' => $other->id,
            'organisation_id' => 999,
            'branch_id' => $other->id,
        ])->assertSessionHasErrors(['organisation_id', 'branch_id']);
        $this->assertDatabaseCount('visits', 0);

        $this->post(route('registration.store'), [
            ...$this->visitAttributes($patient),
            'expected_branch_id' => $other->id,
        ])->assertSessionHasErrors('expected_branch_id');
        $this->assertDatabaseCount('visits', 0);
    }

    public function test_idempotency_key_cannot_resolve_a_visit_from_another_active_branch(): void
    {
        $director = $this->actor('director');
        $patient = $this->patient();
        $key = (string) Str::uuid();
        $visit = $this->register($director, $patient, ['idempotency_key' => $key]);
        $other = Branch::query()
            ->where('organisation_id', $this->organisation->id)
            ->whereKeyNot($this->branch->id)
            ->firstOrFail();
        $this->selectBranch($director, $other);

        $response = $this->from(route('registration.create'))->post(route('registration.store'), [
            ...$this->visitAttributes($patient),
            'idempotency_key' => $key,
            'expected_branch_id' => $other->id,
        ]);

        $response->assertRedirect(route('registration.create'))
            ->assertSessionHasErrors('idempotency_key');
        $this->assertStringNotContainsString($visit->visit_number, (string) $response->headers->get('Location'));
        $this->assertDatabaseCount('visits', 1);
    }

    public function test_branch_assignment_authority_changes_at_branch_local_midnight(): void
    {
        $expiring = $this->actor();
        $expiring->staffProfile->branchAssignments()->firstOrFail()->forceFill([
            'valid_from' => '2026-08-27',
            'valid_until' => '2026-08-27',
        ])->save();
        $starting = $this->actor();
        $starting->staffProfile->branchAssignments()->firstOrFail()->forceFill([
            'valid_from' => '2026-08-28',
            'valid_until' => null,
        ])->save();

        try {
            Date::setTestNow('2026-08-27 15:59:00 UTC');
            $this->selectBranch($expiring);
            $this->get(route('registration.index'))->assertOk();

            Date::setTestNow('2026-08-27 16:01:00 UTC');
            $this->withSession([BranchAccessService::SESSION_KEY => $this->branch->id])
                ->get(route('registration.index'))
                ->assertForbidden();
            $this->actingAs($starting)
                ->withSession([BranchAccessService::SESSION_KEY => $this->branch->id])
                ->get(route('registration.index'))
                ->assertOk();
        } finally {
            Date::setTestNow();
        }
    }

    public function test_visit_routes_expose_no_delete_or_later_phase_surface(): void
    {
        $routes = collect(app('router')->getRoutes());
        $this->assertFalse($routes->contains(fn ($route) => in_array('DELETE', $route->methods(), true) && str_starts_with($route->uri(), 'visits')));
        foreach (['clinical', 'diagnosis', 'prescription', 'billing', 'invoice', 'payment', 'qr', 'otp', 'portal'] as $term) {
            $this->assertFalse($routes->contains(fn ($route) => str_contains(strtolower($route->uri()), $term)));
        }
        $this->assertContains(Visit::STATUS_REGISTERED, ['registered', 'cancelled']);
    }
}

<?php

namespace Tests\Feature\Queue;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Services\VisitDirectoryService;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;

class QueueAuthorizationTest extends QueueTestCase
{
    public function test_guest_inactive_technical_and_unrelated_roles_are_denied(): void
    {
        $this->get('/queue')->assertRedirect('/login');

        foreach (['technical_admin', 'panel_officer', 'finance_officer'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->get('/queue')->assertForbidden();
        }

        $inactive = $this->actor('ca');
        $inactive->forceFill(['is_active' => false])->save();
        $this->selectBranch($inactive);
        $this->get('/queue')->assertRedirect();
    }

    #[DataProvider('roleMatrix')]
    public function test_queue_permission_matrix(string $role, bool $branchView, bool $enter, bool $callBranch): void
    {
        $actor = $this->actor($role);

        $this->assertSame($branchView, $actor->can('queue.view.branch'));
        $this->assertSame($enter, $actor->can('queue.enter.branch'));
        $this->assertSame($callBranch, $actor->can('queue.call.branch'));
        $this->assertSame($role === 'resident_doctor', $actor->can('queue.view.own'));
        $this->assertSame($role === 'resident_doctor', $actor->can('queue.call.own'));
    }

    public static function roleMatrix(): array
    {
        return [
            'director' => ['director', true, true, true],
            'doctor' => ['resident_doctor', false, false, false],
            'ca' => ['ca', true, true, false],
            'supervisor' => ['ca_supervisor', true, true, true],
            'technical' => ['technical_admin', false, false, false],
        ];
    }

    public function test_patient_and_visit_permissions_do_not_imply_queue_access(): void
    {
        $actor = $this->actor('technical_admin');
        foreach (['patients.search.organisation', 'visits.view.branch', 'visits.create.branch'] as $permission) {
            $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->selectBranch($actor);

        $this->get('/queue')->assertForbidden();
        $this->post('/queue/search')->assertForbidden();
    }

    public function test_cross_branch_queue_action_does_not_disclose_visit(): void
    {
        $director = $this->actor('director');
        $visit = $this->consultationVisit($director, $this->doctor());
        $entry = $this->send($director, $visit);
        $other = Branch::query()->where('organisation_id', $this->organisation->id)
            ->whereKeyNot($this->branch->id)->firstOrFail();
        $this->selectBranch($director, $other);

        $this->patch("/visits/{$visit->visit_number}/queue/call", [
            'expected_branch_id' => $other->id,
            'visit_lock_version' => $visit->lock_version,
            'queue_lock_version' => $entry->lock_version,
        ])->assertNotFound();
    }

    public function test_ca_cannot_call_and_doctor_cannot_call_another_doctors_patient(): void
    {
        $ca = $this->actor('ca');
        $assignedDoctor = $this->doctor();
        $otherDoctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $assignedDoctor);
        $entry = $this->send($ca, $visit);

        $this->selectBranch($ca);
        $this->patch("/visits/{$visit->visit_number}/queue/call", [
            'expected_branch_id' => $this->branch->id,
            'visit_lock_version' => $visit->lock_version,
            'queue_lock_version' => $entry->lock_version,
        ])->assertForbidden();

        $this->selectBranch($otherDoctor);
        $this->patch("/visits/{$visit->visit_number}/queue/call", [
            'expected_branch_id' => $this->branch->id,
            'visit_lock_version' => $visit->lock_version,
            'queue_lock_version' => $entry->lock_version,
        ])->assertForbidden();
    }

    public function test_visit_projections_do_not_bypass_doctor_own_queue_scope(): void
    {
        $ca = $this->actor('ca');
        $assignedDoctor = $this->doctor();
        $otherDoctor = $this->doctor();
        $visit = $this->consultationVisit($ca, $assignedDoctor);
        $this->send($ca, $visit);
        $this->selectBranch($otherDoctor);

        $directory = app(VisitDirectoryService::class);
        $detail = $directory->detail($otherDoctor, $visit);
        $listing = $directory->search($otherDoctor, []);
        $row = collect($listing['data'])->firstWhere('visitNumber', $visit->visit_number);

        $this->assertNull($detail['queue']);
        $this->assertNotNull($row);
        $this->assertNull($row['queueNumber']);
        $this->assertNull($row['queueStatus']);
    }

    public function test_queue_routes_have_no_delete_or_public_surface(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes());
        $queueRoutes = $routes->filter(fn ($route) => str_contains((string) $route->getName(), 'queue'));

        $this->assertNotEmpty($queueRoutes);
        $this->assertTrue($queueRoutes->every(fn ($route) => ! in_array('DELETE', $route->methods(), true)));
        $this->assertTrue($queueRoutes->every(fn ($route) => in_array('auth', $route->gatherMiddleware(), true)));
        $this->assertSame([], $routes->filter(fn ($route) => preg_match('/hold|resume|clinical|vitals|diagnosis|dispens|billing/i', (string) $route->uri()) === 1)->all());
    }
}

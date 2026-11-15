<?php

namespace Tests\Feature\Patient;

use App\Domain\Organisation\Models\Organisation;
use App\Domain\Patient\Policies\PatientPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;

class PatientAuthorizationTest extends PatientTestCase
{
    public function test_guests_and_non_patient_roles_are_denied(): void
    {
        $this->get(route('patients.index'))->assertRedirect(route('login'));

        foreach (['technical_admin', 'panel_officer', 'finance_officer', 'business_development', 'marketing', 'hr_manager'] as $role) {
            $this->actingAs($this->actor($role))->get(route('patients.index'))->assertForbidden();
        }
    }

    #[DataProvider('roleMatrix')]
    public function test_locked_role_permission_matrix(string $role, array $allowed): void
    {
        $actor = $this->actor($role);
        $all = [
            'patients.search.organisation',
            'patients.view.organisation',
            'patients.create.organisation',
            'patients.update.organisation',
            'patients.identifiers.manage.organisation',
        ];

        foreach ($all as $permission) {
            $this->assertSame(in_array($permission, $allowed, true), $actor->can($permission), $role.' '.$permission);
        }
    }

    public static function roleMatrix(): array
    {
        $read = ['patients.search.organisation', 'patients.view.organisation'];
        $write = [...$read, 'patients.create.organisation', 'patients.update.organisation'];

        return [
            'director' => ['director', [...$write, 'patients.identifiers.manage.organisation']],
            'resident doctor' => ['resident_doctor', $read],
            'ca' => ['ca', $write],
            'ca supervisor' => ['ca_supervisor', [...$write, 'patients.identifiers.manage.organisation']],
            'technical admin' => ['technical_admin', []],
        ];
    }

    public function test_cross_organisation_route_binding_is_a_404_and_branch_context_does_not_own_patients(): void
    {
        $director = $this->actor();
        $patient = $this->createPatient($director);
        $other = Organisation::query()->create(['code' => 'SYNTH_OTHER', 'name' => 'Synthetic Other Organisation', 'is_active' => true]);
        $outsider = $this->actor('director', $other);

        $this->actingAs($outsider)->get(route('patients.show', $patient))->assertNotFound();

        $ca = $this->actor('ca');
        $this->assertTrue(app(PatientPolicy::class)->view($ca, $patient));
        $this->actingAs($ca)->get(route('patients.show', $patient))->assertOk();
    }

    public function test_public_patient_surfaces_do_not_exist(): void
    {
        $this->assertFalse(collect(app('router')->getRoutes())->contains(fn ($route) => str_contains((string) $route->getName(), 'register.patient')));
        $this->post('/patient/register', [])->assertNotFound();
        $this->post('/patients/qr', [])->assertStatus(405);
        $this->assertFalse(collect(app('router')->getRoutes())->contains(fn ($route) => in_array('DELETE', $route->methods(), true) && str_starts_with($route->uri(), 'patients')));
    }

    public function test_search_permission_alone_cannot_retrieve_full_detail(): void
    {
        $patient = $this->createPatient($this->actor());
        $searchOnly = $this->actor('technical_admin');
        $searchOnly->givePermissionTo(Permission::findOrCreate('patients.search.organisation', 'web'));

        $this->actingAs($searchOnly)->get(route('patients.index'))->assertOk();
        $this->actingAs($searchOnly)->get(route('patients.show', $patient))->assertForbidden();
    }

    public function test_zero_permission_role_cannot_use_binding_as_patient_existence_oracle(): void
    {
        $patient = $this->createPatient($this->actor());
        $technical = $this->actor('technical_admin');

        $this->actingAs($technical)->get(route('patients.show', $patient))->assertForbidden();
        $this->actingAs($technical)->get('/patients/KP-99999999')->assertForbidden();
    }

    public function test_inactive_user_is_rejected_before_patient_permission_or_binding(): void
    {
        $patient = $this->createPatient($this->actor());
        $technical = $this->actor('technical_admin');
        $technical->forceFill(['is_active' => false])->save();

        $this->actingAs($technical)->get(route('patients.show', $patient))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }
}

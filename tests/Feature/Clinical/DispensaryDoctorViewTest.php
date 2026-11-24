<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Dispensary\Labels\DrugLabelService;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryHandoff;
use App\Domain\Clinical\Dispensary\Services\DispensaryDirectoryService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Clinical\Dispensary\Services\DoctorDispensaryAttentionService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\DoctorCheckFixtures;

/**
 * DSP-26 (owner decision 24 Nov 2026, "pilih (a) lihat sahaja"): the Resident Doctor opens the Dispensary pages at
 * their active branch, view only. Every write and every print stays refused; the DSP-23 Doctor's check is unchanged.
 */
class DispensaryDoctorViewTest extends ClinicalTestCase
{
    use DoctorCheckFixtures;

    /** @param array<string, mixed> $f */
    private function sendCase(array $f): DispensaryCase
    {
        $this->switchOn($f['visit']->branch);
        $this->selectBranch($f['ca'], $f['visit']->branch);

        return app(DispensaryService::class)->sendForDoctorCheck($f['ca'], $f['case']->refresh(), [
            'expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $f['case']->refresh()->lock_version, 'retry_key' => (string) Str::uuid(),
        ]);
    }

    /** @param array<string, mixed> $f */
    private function completeCase(array $f): DispensaryCase
    {
        $this->selectBranch($f['ca'], $f['visit']->branch);

        return app(DispensaryService::class)->complete($f['ca'], $f['case']->refresh(), ['expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $f['case']->refresh()->lock_version]);
    }

    private function viewAs(User $doctor, ?Branch $branch = null): static
    {
        $this->selectBranch($doctor, $branch ?? $this->branch);

        return $this->actingAs($doctor);
    }

    public function test_only_the_resident_doctor_holds_the_view_only_permission_and_the_migration_is_additive(): void
    {
        $holders = Role::query()->whereHas('permissions', fn ($q) => $q->where('name', DrugLabelService::VIEW_PERMISSION))->pluck('name')->all();
        $this->assertSame(['resident_doctor'], $holders);
        $doctor = Role::findByName('resident_doctor');
        $this->assertFalse($doctor->hasPermissionTo('dispensary.view.branch'));
        $this->assertFalse($doctor->hasPermissionTo('dispensary.labels.print.branch'));

        $doctor->revokePermissionTo(DrugLabelService::VIEW_PERMISSION);
        $before = DB::table('role_has_permissions')->count();
        $migration = require database_path('migrations/2026_11_06_200900_grant_dispensary_doctor_view_permission.php');
        $migration->up();
        $migration->up();
        $this->assertSame($before + 1, DB::table('role_has_permissions')->count(), 'one grant, replayed safely');
        $this->assertTrue(Role::findByName('resident_doctor')->hasPermissionTo(DrugLabelService::VIEW_PERMISSION));
        $migration->down();
        $this->assertSame(1, Permission::query()->where('name', DrugLabelService::VIEW_PERMISSION)->count(), 'down() changes nothing');
    }

    public function test_the_doctor_opens_a_case_in_each_status_with_every_action_off(): void
    {
        $f = $this->prepared();
        $doctor = $f['doctor'];

        $assertViewOnly = fn (Assert $page) => $page->component('Dispensary/Show')
            ->where('dispensary.viewOnly', true)
            ->where('dispensary.canPrintLabels', false)
            ->where('dispensary.can', fn ($can) => collect($can)->filter(fn ($value) => $value === true)->isEmpty())
            ->where('dispensary.backLink.label', 'Back to Registration');

        $this->viewAs($doctor)->get(route('dispensary.show', $f['case']))->assertOk()
            ->assertInertia(fn (Assert $page) => $assertViewOnly($page)->where('dispensary.canPreviewLabels', false)->missing('dispensary.labelUrl'));

        $this->sendCase($f);
        $this->viewAs($doctor)->get(route('dispensary.show', $f['case']))->assertOk()
            ->assertInertia(fn (Assert $page) => $assertViewOnly($page)->where('dispensary.canPreviewLabels', true)->where('dispensary.labelUrl', route('dispensary.labels', $f['case']))->where('dispensary.doctorCheck.canCheck', false));
    }

    public function test_the_doctor_opens_the_completed_page_with_returns_read_only_but_not_returns_to_check(): void
    {
        $f = $this->prepared();
        $this->completeCase($f);

        $this->viewAs($f['doctor'])->get(route('dispensary.show', $f['case']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dispensary/Completed')
                ->where('dispensary.viewOnly', true)
                ->where('dispensary.labelUrl', route('dispensary.labels', $f['case']))
                ->missing('dispensary.billingUrl')
                ->where('returns.canRecord', false)
                ->has('returns.lines', 1));

        $this->viewAs($f['doctor'])->get(route('dispensary.returns-to-check'))->assertForbidden();
    }

    public function test_the_label_preview_opens_only_for_finished_or_waiting_cases_without_a_token_and_without_a_print_row(): void
    {
        $f = $this->prepared();
        $doctor = $f['doctor'];

        $this->viewAs($doctor)->get(route('dispensary.labels', $f['case']))->assertNotFound();

        $this->sendCase($f);
        $this->viewAs($doctor)->get(route('dispensary.labels', $f['case']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dispensary/Labels')->where('labels.token', null)->where('labelCanPrint', false)->has('labels.items', 1));

        $item = $f['case']->handoffs()->where('status', DispensaryHandoff::STATUS_OPEN)->sole()->items()->sole();
        $this->viewAs($doctor)->get(route('dispensary.items.label', [$f['case'], $item->public_id]))->assertOk();
        $this->viewAs($doctor)->postJson(route('dispensary.labels.print', $f['case']), ['token' => 'x', 'expected_branch_id' => $f['visit']->branch_id])->assertForbidden();
        $this->assertDatabaseCount('dispensary_label_prints', 0);

        // The CA's own preview still carries the print token.
        $this->viewAs($f['ca'])->get(route('dispensary.labels', $f['case']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('labels.token', fn ($token) => is_string($token) && $token !== '')->where('labelCanPrint', true));
    }

    public function test_the_doctor_preview_takes_no_row_locks(): void
    {
        $f = $this->prepared();
        $this->sendCase($f);
        $this->selectBranch($f['doctor'], $f['visit']->branch);

        $locks = [];
        DB::listen(function ($query) use (&$locks): void {
            if (str_contains(strtolower($query->sql), 'for update')) {
                $locks[] = $query->sql;
            }
        });
        app(DrugLabelService::class)->preview($f['doctor'], $f['case']->refresh(), null);

        $this->assertSame([], $locks);
    }

    public function test_the_doctor_gets_403_on_every_dispensary_write_route_and_nothing_changes(): void
    {
        $f = $this->prepared();
        $item = $f['case']->handoffs()->where('status', DispensaryHandoff::STATUS_OPEN)->sole()->items()->sole();
        $before = [DB::table('dispensary_cases')->get()->toArray(), DB::table('dispensary_items')->get()->toArray(), DB::table('stock_movements')->count()];
        $uuid = (string) Str::uuid();
        $params = ['dispensaryCase' => $f['case']->public_id, 'item' => $item->public_id, 'itemPublicId' => $item->public_id, 'line' => $uuid, 'returnPublicId' => $uuid];

        foreach ([
            'dispensary.start', 'dispensary.items.update', 'dispensary.items.add', 'dispensary.items.edit', 'dispensary.items.remove',
            'dispensary.medicines.search', 'dispensary.medicines.by-sku', 'dispensary.services.add', 'dispensary.services.edit',
            'dispensary.services.remove', 'dispensary.services.search', 'dispensary.otc-allergy', 'dispensary.otc-allergy-records',
            'dispensary.return-to-doctor', 'dispensary.send-for-check', 'dispensary.complete', 'dispensary.labels.print',
            'dispensary.items.label.print', 'dispensary.call', 'dispensary.returns.record', 'dispensary.returns.check',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
            $url = route($name, array_intersect_key($params, array_flip($route->parameterNames())));
            $this->viewAs($f['doctor'])->json($method, $url, ['expected_branch_id' => $f['visit']->branch_id])->assertForbidden();
        }

        $this->assertEquals($before, [DB::table('dispensary_cases')->get()->toArray(), DB::table('dispensary_items')->get()->toArray(), DB::table('stock_movements')->count()]);
        $this->assertDatabaseCount('dispensary_label_prints', 0);
    }

    public function test_the_write_gate_still_refuses_the_doctor_when_called_directly(): void
    {
        $f = $this->prepared();
        $this->sendCase($f);
        $this->selectBranch($f['doctor'], $f['visit']->branch);

        try {
            app(DrugLabelService::class)->recordPrint($f['doctor'], $f['case']->refresh(), null, 'x', $f['visit']->branch_id);
            $this->fail('A view-only doctor must never record a print.');
        } catch (AuthorizationException) {
        }
        $this->assertDatabaseCount('dispensary_label_prints', 0);
    }

    public function test_a_case_at_another_branch_gives_404(): void
    {
        $f = $this->prepared();
        $puchong = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        $elsewhere = $this->doctor($puchong);

        $this->viewAs($elsewhere, $puchong)->get(route('dispensary.show', $f['case']))->assertNotFound();
    }

    public function test_the_registration_board_lists_dispensary_cases_with_open_dispensary_only(): void
    {
        $f = $this->prepared();
        $this->selectBranch($f['doctor'], $f['visit']->branch);

        $board = app(DispensaryDirectoryService::class)->board($f['doctor'], []);
        $this->assertSame(1, $board['total']);
        $this->assertSame(['openDispensary'], array_keys(array_filter($board['data'][0]['can'])));
        $this->assertSame(1, app(DispensaryDirectoryService::class)->boardCount($f['doctor'], $f['visit']->branch, []));
        $this->assertTrue(DispensaryDirectoryService::canView($f['doctor']));
        $this->assertFalse(DispensaryDirectoryService::canView($this->actor('finance_officer')));
    }

    public function test_the_back_link_returns_to_the_consultation_only_for_the_attending_doctor(): void
    {
        $f = $this->prepared();
        $this->sendCase($f);
        $consultation = route('encounters.show', $f['visit']->visit_number);

        $this->viewAs($f['doctor'])->get(route('dispensary.show', ['dispensaryCase' => $f['case'], 'from' => 'consultation']))
            ->assertInertia(fn (Assert $page) => $page->where('dispensary.backLink', ['label' => 'Back to consultation', 'href' => $consultation]));

        foreach (['registration', 'https://example.test', ''] as $from) {
            $this->viewAs($f['doctor'])->get(route('dispensary.show', ['dispensaryCase' => $f['case'], 'from' => $from]))
                ->assertInertia(fn (Assert $page) => $page->where('dispensary.backLink', ['label' => 'Back to Registration', 'href' => route('registration.index')]));
        }

        $other = $this->doctor();
        $this->viewAs($other)->get(route('dispensary.show', ['dispensaryCase' => $f['case'], 'from' => 'consultation']))
            ->assertInertia(fn (Assert $page) => $page->where('dispensary.backLink.label', 'Back to Registration'));
    }

    public function test_the_doctors_check_panel_links_to_the_view_only_case(): void
    {
        $f = $this->prepared();
        $this->sendCase($f);
        $this->selectBranch($f['doctor'], $f['visit']->branch);

        $encounter = $f['visit']->clinicalEncounter()->sole();
        $encounter->setRelation('visit', $f['visit']->refresh()->load('queueEntry'));
        $check = app(DoctorDispensaryAttentionService::class)->checkForEncounter($f['doctor'], $encounter);

        $this->assertSame(route('dispensary.show', ['dispensaryCase' => $f['case'], 'from' => 'consultation']), $check['viewUrl'] ?? null);
    }
}

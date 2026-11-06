<?php

namespace Tests\Feature\HR;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\HR\Payroll\PayrollPermissions;
use App\Domain\HR\Records\Models\Worker;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-7a payroll setup (TEAM.md decision 43; HR-PLAN 14.2 to 14.5). Every figure here is synthetic and written by hand;
 * KPOne ships no statutory number. The clock is frozen in Kuala Lumpur so dates are branch-local.
 */
class PayrollSetupTest extends VisitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00', $this->branch->timezone));
    }

    public function test_grants_are_exact_nothing_is_shipped_and_only_payroll_holders_open_the_page(): void
    {
        $holders = fn (string $permission): array => collect(PermissionCatalogue::roles())->filter(fn (array $p) => in_array($permission, $p, true))->keys()->sort()->values()->all();
        $this->assertSame(['director', 'hr_manager'], $holders(PayrollPermissions::PREPARE));
        $this->assertSame(['director'], $holders(PayrollPermissions::APPROVE));
        foreach (array_keys(PayrollPermissions::grants()) as $permission) {
            $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority($permission));
        }
        foreach (['queue_display', 'ca', 'finance_officer', 'technical_admin', 'administrator'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->get(route('hr.payroll.index'))->assertForbidden();
            $this->post(route('hr.payroll.groups.save'), ['name' => 'Monthly', 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        }
        $this->selectBranch($this->actor('hr_manager'));
        $this->get(route('hr.payroll.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('HR/Payroll')
            ->where('payroll.tabs', ['profiles', 'tables', 'settings'])->where('payroll.canApprove', false)
            ->where('payroll.setUp', ['groups' => false, 'rates' => false, 'checkedTables' => false]));
        $this->get(route('hr.payroll.index', ['tab' => 'settings']))->assertInertia(fn (Assert $page) => $page->where('payroll.settings.groups', [])
            ->where('payroll.settings.itemTypes', [])->where('payroll.settings.rates.version', 0)->where('payroll.settings.rates.daily', null));
        $this->selectBranch($this->actor('director'));
        $this->get(route('hr.payroll.index', ['tab' => 'tables']))->assertInertia(fn (Assert $page) => $page->where('payroll.canApprove', true)
            ->where('payroll.tables.tables', []));
        foreach (['hr_pay_groups', 'hr_pay_item_types', 'hr_rate_settings', 'hr_statutory_tables', 'hr_pay_profiles'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }

    public function test_pay_groups_item_types_and_rate_settings_are_clinic_entered_and_versioned(): void
    {
        $hr = $this->actor('hr_manager');
        $this->save($hr, 'hr.payroll.groups.save', ['name' => 'x', 'pay_day' => '32'])->assertSessionHasErrors(['name', 'pay_day']);
        $this->save($hr, 'hr.payroll.groups.save', ['name' => 'Monthly staff', 'pay_day' => '28'])->assertSessionHasNoErrors();
        $this->save($hr, 'hr.payroll.groups.save', ['name' => 'monthly STAFF'])->assertSessionHasErrors(['name' => 'Another pay group already has this name.']);
        $group = DB::table('hr_pay_groups')->first();
        $this->assertNotNull($group);
        $this->save($hr, 'hr.payroll.groups.save', ['group_id' => $group->public_id, 'name' => 'Monthly', 'pay_day' => '', 'is_active' => true, 'expected_lock_version' => 9])
            ->assertSessionHasErrors('records');
        $this->save($hr, 'hr.payroll.groups.save', ['group_id' => $group->public_id, 'name' => 'Monthly', 'pay_day' => '', 'is_active' => true, 'expected_lock_version' => 1])
            ->assertSessionHasNoErrors();
        $this->assertSame(['Monthly', null], [DB::table('hr_pay_groups')->value('name'), DB::table('hr_pay_groups')->value('pay_day')]);

        $this->save($hr, 'hr.payroll.item-types.save', ['name' => 'Fixed allowance', 'kind' => 'bonus'])->assertSessionHasErrors('kind');
        $this->save($hr, 'hr.payroll.item-types.save', ['name' => 'Fixed allowance', 'kind' => 'earnings', 'counts_epf' => true])->assertSessionHasNoErrors();
        $type = DB::table('hr_pay_item_types')->first();
        $this->assertNotNull($type);
        $this->save($hr, 'hr.payroll.item-types.save', ['item_type_id' => $type->public_id, 'name' => 'Fixed allowance', 'kind' => 'earnings', 'counts_epf' => true,
            'counts_socso' => true, 'expected_lock_version' => 1])->assertSessionHasNoErrors();
        $versions = DB::table('hr_pay_item_type_versions')->orderBy('version')->get();
        $this->assertSame([1, 2], $versions->pluck('version')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([false, true], $versions->pluck('counts_socso')->map(fn ($v) => (bool) $v)->all());

        $this->save($hr, 'hr.payroll.rates.save', ['daily_divisor' => '0', 'hourly_divisor' => '25', 'expected_version' => 0])
            ->assertSessionHasErrors(['daily_divisor', 'hourly_divisor']);
        $this->save($hr, 'hr.payroll.rates.save', ['daily_divisor' => '26', 'hourly_divisor' => '8', 'expected_version' => 0])->assertSessionHasNoErrors();
        $this->save($hr, 'hr.payroll.rates.save', ['daily_divisor' => '21.67', 'hourly_divisor' => '8', 'expected_version' => 0])->assertSessionHasErrors('payroll');
        $this->save($hr, 'hr.payroll.rates.save', ['daily_divisor' => '21.67', 'hourly_divisor' => '7.5', 'expected_version' => 1])->assertSessionHasNoErrors();
        $this->assertSame([[2600, 800], [2167, 750]], DB::table('hr_rate_settings')->orderBy('version')->get()
            ->map(fn ($r) => [(int) $r->daily_divisor_hundredths, (int) $r->hourly_divisor_hundredths])->all());
        $this->selectBranch($hr);
        $this->get(route('hr.payroll.index', ['tab' => 'settings']))->assertInertia(fn (Assert $page) => $page->where('payroll.settings.rates.daily', '21.67')
            ->where('payroll.settings.rates.hourly', '7.50')->has('payroll.settings.rates.history', 2)->where('payroll.settings.itemTypes.0.countsSocso', true)
            ->missing('payroll.settings.itemTypes.0.dbId'));

        $this->assertRefused('P0001', fn () => DB::table('hr_rate_settings')->update(['daily_divisor_hundredths' => 2500]));
        $this->assertRefused('P0001', fn () => DB::table('hr_pay_item_type_versions')->delete());
        $this->assertRefused('23514', fn () => DB::table('hr_rate_settings')->insert(['organisation_id' => $hr->organisation_id, 'version' => 5,
            'daily_divisor_hundredths' => 2600, 'hourly_divisor_hundredths' => 800, 'set_by_user_id' => $hr->id, 'created_at' => now()]));
        $this->assertRefused('P0001', fn () => DB::table('hr_pay_groups')->delete());
    }

    public function test_statutory_tables_name_every_band_error_and_are_checked_by_someone_else(): void
    {
        $director = $this->actor('director');
        $director2 = $this->actor('director');
        $hr = $this->actor('hr_manager');
        $hr2 = $this->actor('hr_manager');

        // One submit names every field and every band to fix.
        $this->table($hr, ['scheme' => 'kwsp', 'category' => '', 'effective_from' => '2026-02-30', 'rounding' => 'up', 'source_note' => '', 'rows' => [
            ['from' => '0.00', 'to' => '', 'employee_kind' => 'rate', 'employee' => '11', 'employer_kind' => 'rate', 'employer' => '13'],
            ['from' => '30.00', 'to' => '20.00', 'employee_kind' => 'rate', 'employee' => '101', 'employer_kind' => 'flat', 'employer' => '1'],
            ['from' => '30.00', 'to' => '', 'employee_kind' => 'fixed', 'employee' => '1.234', 'employer_kind' => 'fixed', 'employer' => '2.00'],
        ]])->assertSessionHasErrors(['scheme', 'category', 'effective_from', 'rounding', 'source_note', 'rows.0.to', 'rows.1.to', 'rows.1.employee',
            'rows.1.employer', 'rows.2.employee']);
        $this->table($hr, [...$this->epf(), 'rows' => [
            ['from' => '0.00', 'to' => '1000.00', 'employee_kind' => 'rate', 'employee' => '11', 'employer_kind' => 'rate', 'employer' => '13'],
            ['from' => '1000.50', 'to' => '', 'employee_kind' => 'rate', 'employee' => '11', 'employer_kind' => 'rate', 'employer' => '12'],
        ]])->assertSessionHasErrors(['rows.1.from' => 'There is a gap after the band above. It should start at RM 1,000.01.']);
        $this->table($hr, [...$this->epf(), 'rows' => [
            ['from' => '0.00', 'to' => '1000.00', 'employee_kind' => 'rate', 'employee' => '11', 'employer_kind' => 'rate', 'employer' => '13'],
            ['from' => '999.00', 'to' => '', 'employee_kind' => 'rate', 'employee' => '11', 'employer_kind' => 'rate', 'employer' => '12'],
        ]])->assertSessionHasErrors(['rows.1.from' => 'This band overlaps the one above. It should start at RM 1,000.01.']);
        $this->assertSame(0, DB::table('hr_statutory_tables')->count());

        // Entered by the HR Manager, with exact whole-number bands.
        $key = (string) Str::uuid();
        $this->table($hr, $this->epf(), $key)->assertSessionHasNoErrors();
        $this->table($hr, $this->epf(), $key)->assertSessionHasNoErrors();
        $first = DB::table('hr_statutory_tables')->first();
        $this->assertNotNull($first);
        $this->assertSame(1, DB::table('hr_statutory_tables')->count());
        $this->assertSame([[0, 500000, 'rate', 1100, 'rate', 1300], [500001, null, 'rate', 1100, 'fixed', 65000]],
            DB::table('hr_statutory_table_rows')->orderBy('position')->get()->map(fn ($r) => [(int) $r->wage_from_sen, $r->wage_to_sen === null ? null : (int) $r->wage_to_sen,
                $r->employee_kind, (int) $r->employee_value, $r->employer_kind, (int) $r->employer_value])->all());

        // The person who entered it, and another HR Manager, may not check it; the Director does.
        $this->decide($hr, 'tables', $first->public_id, 'check')->assertSessionHasErrors(['payroll' => 'Someone else checks this. You entered it.']);
        $this->decide($hr2, 'tables', $first->public_id, 'check')->assertSessionHasErrors(['payroll' => 'The Director checks this, or the HR Manager when a Director entered it.']);
        $this->selectBranch($hr2);
        $this->get(route('hr.payroll.index', ['tab' => 'tables']))->assertInertia(fn (Assert $page) => $page->where('payroll.tables.tables.0.canDecide', false)
            ->where('payroll.tables.tables.0.decideRefusal', 'The Director checks this, or the HR Manager when a Director entered it.')
            ->where('payroll.tables.tables.0.rows.1.employer', 'RM 650.00')->where('payroll.tables.tables.0.rows.0.employee', '11.00%')
            ->where('payroll.tables.tables.0.rows.1.to', null)->where('payroll.tables.waitingCount', 1));
        $this->decide($director, 'tables', $first->public_id, 'reject')->assertSessionHasErrors('reason');
        $this->decide($director, 'tables', $first->public_id, 'check')->assertSessionHasNoErrors();
        $this->assertSame('checked', DB::table('hr_statutory_tables')->where('id', $first->id)->value('status'));

        // A Director's table is checked by the HR Manager or another Director, never by that Director.
        $this->table($director, $this->epf())->assertSessionHasNoErrors();
        $second = DB::table('hr_statutory_tables')->where('status', 'entered')->first();
        $this->assertNotNull($second);
        $this->decide($director, 'tables', $second->public_id, 'check')->assertSessionHasErrors('payroll');
        $this->decide($hr, 'tables', $second->public_id, 'check')->assertSessionHasNoErrors();
        $this->assertSame(['replaced', 'checked'], DB::table('hr_statutory_tables')->orderBy('id')->pluck('status')->all());

        // Rejected with a reason, and withdrawn only by the person who entered it.
        $this->table($director, [...$this->epf(), 'category' => 'Foreign worker'])->assertSessionHasNoErrors();
        $third = DB::table('hr_statutory_tables')->where('status', 'entered')->first();
        $this->assertNotNull($third);
        $this->withdraw($director2, 'tables', $third->public_id)->assertSessionHasErrors(['payroll' => 'Only the person who entered this table may withdraw it.']);
        $this->decide($director2, 'tables', $third->public_id, 'reject', ['reason' => 'wrong_figures'])->assertSessionHasNoErrors();
        $this->withdraw($director, 'tables', $third->public_id)->assertSessionHasErrors('payroll');
        $this->table($hr, [...$this->epf(), 'category' => 'Foreign worker'])->assertSessionHasNoErrors();
        $fourth = DB::table('hr_statutory_tables')->where('status', 'entered')->first();
        $this->assertNotNull($fourth);
        $this->withdraw($hr, 'tables', $fourth->public_id)->assertSessionHasNoErrors();
        $this->assertSame('withdrawn', DB::table('hr_statutory_tables')->where('id', $fourth->id)->value('status'));

        // PostgreSQL: a checked table and its bands never change, the checker is never the entrant, and one is checked per date.
        $checked = DB::table('hr_statutory_tables')->where('status', 'checked')->first();
        $this->assertNotNull($checked);
        $this->assertRefused('P0001', fn () => DB::table('hr_statutory_tables')->where('id', $checked->id)->update(['category' => 'Changed']));
        $this->assertRefused('P0001', fn () => DB::table('hr_statutory_tables')->where('id', $checked->id)->update(['status' => 'entered', 'decided_by_user_id' => null, 'decided_at' => null]));
        $this->assertRefused('P0001', fn () => DB::table('hr_statutory_table_rows')->where('table_id', $checked->id)->update(['employee_value' => 900]));
        $this->assertRefused('23514', fn () => DB::table('hr_statutory_table_rows')->insert(['organisation_id' => $director->organisation_id, 'table_id' => $checked->id,
            'position' => 9, 'wage_from_sen' => 0, 'employee_kind' => 'rate', 'employee_value' => 1, 'employer_kind' => 'rate', 'employer_value' => 1]));
        $this->assertRefused('P0001', fn () => DB::table('hr_statutory_tables')->delete());
        $raw = (string) Str::uuid();
        $id = DB::table('hr_statutory_tables')->insertGetId(['public_id' => $raw, 'organisation_id' => $director->organisation_id, 'scheme' => 'epf',
            'category' => 'Malaysian below 60', 'effective_from' => '2026-01-01', 'rounding' => 'up_ringgit', 'source_note' => 'Synthetic', 'status' => 'entered',
            'entered_by_user_id' => $director->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertRefused('23514', fn () => DB::table('hr_statutory_tables')->where('id', $id)->update(['status' => 'checked', 'decided_by_user_id' => $director->id, 'decided_at' => now()]));
        $this->assertRefused('23505', fn () => DB::table('hr_statutory_tables')->where('id', $id)->update(['status' => 'checked', 'decided_by_user_id' => $hr->id, 'decided_at' => now()]));
        $this->assertRefused('23514', fn () => DB::table('hr_statutory_tables')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $director->organisation_id,
            'scheme' => 'epf', 'category' => 'x1', 'effective_from' => '2026-01-01', 'rounding' => 'none', 'source_note' => 'Synthetic', 'status' => 'checked',
            'entered_by_user_id' => $director->id, 'decided_by_user_id' => $hr->id, 'decided_at' => now(), 'created_at' => now(), 'updated_at' => now()]));
        $this->assertNoAmountsInAudit();
    }

    public function test_pay_profiles_are_confirmed_by_someone_else_never_by_the_worker_and_salaries_stay_encrypted(): void
    {
        $director = $this->actor('director');
        $director2 = $this->actor('director');
        $hr = $this->actor('hr_manager');
        $ca = $this->actor('ca');
        [$group, $allowance, $deduction] = $this->setUpPay($hr);
        $caWorker = $this->workerFor($director, $ca);
        $hrWorker = $this->workerFor($director, $hr);
        $directorWorker = $this->workerFor($director2, $director);

        // One submit names everything to fix.
        $this->profile($hr, $caWorker, ['pay_group_id' => '', 'pay_type' => 'weekly', 'rate' => '2,500.555', 'epf' => true, 'epf_category' => '',
            'effective_from' => '2026-13-01', 'items' => [['item_type_id' => '', 'amount' => 'abc']]])
            ->assertSessionHasErrors(['pay_group_id', 'pay_type', 'rate', 'epf_category', 'effective_from', 'items.0.item_type_id', 'items.0.amount']);
        $this->profile($hr, $caWorker, [...$this->pay($group), 'items' => [['item_type_id' => $deduction, 'amount' => '10.00']]])
            ->assertSessionHasErrors(['items.0.item_type_id' => 'Choose an earnings pay item type that is in use.']);
        // Nobody enters their own profile, and only a Director enters a Director's.
        $this->profile($hr, $hrWorker, $this->pay($group))->assertSessionHasErrors(['payroll' => 'Nobody enters their own pay profile. Ask someone else.']);
        $this->profile($hr, $directorWorker, $this->pay($group))->assertSessionHasErrors('payroll');

        $key = (string) Str::uuid();
        $this->profile($hr, $caWorker, [...$this->pay($group), 'items' => [['item_type_id' => $allowance, 'amount' => '150.00']]], $key)->assertSessionHasNoErrors();
        $this->profile($hr, $caWorker, [...$this->pay($group), 'items' => [['item_type_id' => $allowance, 'amount' => '150.00']]], $key)->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('hr_pay_profiles')->count());
        $this->profile($hr, $caWorker, [...$this->pay($group), 'rate' => '2,600.00'])
            ->assertSessionHasErrors(['payroll' => 'A pay profile change is already waiting. Confirm, reject or withdraw it first.']);
        $profile = DB::table('hr_pay_profiles')->first();
        $this->assertNotNull($profile);
        $this->assertSame('250000', Crypt::decryptString((string) $profile->rate_encrypted));
        $this->assertStringNotContainsString('250000', (string) $profile->rate_encrypted);
        $this->assertSame('15000', Crypt::decryptString((string) DB::table('hr_pay_profile_items')->value('amount_encrypted')));

        // The entrant and an HR Manager may not confirm an HR Manager's entry; the Director does.
        $this->decide($hr, 'profiles', $profile->public_id, 'confirm')->assertSessionHasErrors(['payroll' => 'Someone else checks this. You entered it.']);
        $this->selectBranch($director);
        $this->get(route('hr.payroll.index'))->assertInertia(fn (Assert $page) => $page->where('payroll.profiles.waitingCount', 1)
            ->where('payroll.profiles.people', fn ($people) => collect($people)->contains(fn ($p) => $p['id'] === $caWorker->public_id
                && $p['waiting']['rateSen'] === 250000 && $p['waiting']['items'][0]['amountSen'] === 15000 && $p['waiting']['canDecide'] === true
                && $p['waiting']['schemes'] === ['EPF (Malaysian below 60)', 'SOCSO (Category 1)', 'EIS'] && $p['current'] === null)));
        $this->decide($director, 'profiles', $profile->public_id, 'confirm')->assertSessionHasNoErrors();
        $this->assertSame('confirmed', DB::table('hr_pay_profiles')->where('id', $profile->id)->value('status'));

        // A change waits beside the profile in force; confirming one for the same date replaces it.
        $this->profile($hr, $caWorker, [...$this->pay($group), 'rate' => '2,700.00'])->assertSessionHasNoErrors();
        $this->selectBranch($director);
        $this->get(route('hr.payroll.index'))->assertInertia(fn (Assert $page) => $page->where('payroll.profiles.people', fn ($people) => collect($people)
            ->contains(fn ($p) => $p['id'] === $caWorker->public_id && $p['current']['rateSen'] === 250000 && $p['waiting']['rateSen'] === 270000)));
        $changed = DB::table('hr_pay_profiles')->where('status', 'waiting')->first();
        $this->assertNotNull($changed);
        $this->decide($director, 'profiles', $changed->public_id, 'confirm')->assertSessionHasNoErrors();
        $this->assertSame(['replaced', 'confirmed'], DB::table('hr_pay_profiles')->orderBy('id')->pluck('status')->all());

        // A Director's profile is entered by another Director and never confirmed by its own Director; the HR Manager may.
        $this->profile($director2, $directorWorker, $this->pay($group))->assertSessionHasNoErrors();
        $own = DB::table('hr_pay_profiles')->where('worker_id', $directorWorker->id)->first();
        $this->assertNotNull($own);
        $this->decide($director, 'profiles', $own->public_id, 'confirm')->assertSessionHasErrors(['payroll' => 'Nobody confirms their own pay profile.']);
        $this->decide($hr, 'profiles', $own->public_id, 'reject', ['reason' => 'wrong_amount'])->assertSessionHasNoErrors();
        $this->profile($director2, $directorWorker, $this->pay($group))->assertSessionHasNoErrors();
        $again = DB::table('hr_pay_profiles')->where('worker_id', $directorWorker->id)->where('status', 'waiting')->first();
        $this->assertNotNull($again);
        $this->withdraw($hr, 'profiles', $again->public_id)->assertSessionHasErrors(['payroll' => 'Only the person who entered this pay profile may withdraw it.']);
        $this->withdraw($director2, 'profiles', $again->public_id)->assertSessionHasNoErrors();

        // PostgreSQL: nobody enters or confirms their own, a confirmed profile never changes, allowances only while it waits.
        $confirmed = DB::table('hr_pay_profiles')->where('status', 'confirmed')->first();
        $this->assertNotNull($confirmed);
        $this->assertRefused('P0001', fn () => DB::table('hr_pay_profiles')->where('id', $confirmed->id)->update(['rate_encrypted' => Crypt::encryptString('999999')]));
        $this->assertRefused('P0001', fn () => DB::table('hr_pay_profiles')->where('id', $confirmed->id)->update(['status' => 'waiting', 'decided_by_user_id' => null, 'decided_at' => null]));
        $this->assertRefused('23514', fn () => DB::table('hr_pay_profile_items')->insert(['organisation_id' => $hr->organisation_id, 'profile_id' => $confirmed->id,
            'position' => 5, 'item_type_id' => DB::table('hr_pay_item_types')->where('public_id', $allowance)->value('id'), 'item_type_version' => 1,
            'amount_encrypted' => Crypt::encryptString('1')]));
        $raw = fn (int $by, array $extra = []) => DB::table('hr_pay_profiles')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $hr->organisation_id,
            'worker_id' => $hrWorker->id, 'pay_group_id' => DB::table('hr_pay_groups')->where('public_id', $group)->value('id'), 'pay_type' => 'monthly',
            'rate_encrypted' => Crypt::encryptString('100'), 'epf' => false, 'socso' => false, 'eis' => false, 'mtd' => false, 'hrd' => false,
            'effective_from' => '2026-11-01', 'status' => 'waiting', 'entered_by_user_id' => $by, 'created_at' => now(), 'updated_at' => now(), ...$extra]);
        $this->assertRefused('23514', fn () => $raw($hr->id));
        $waitingId = $raw($director->id);
        $this->assertRefused('23514', fn () => DB::table('hr_pay_profiles')->where('id', $waitingId)->update(['status' => 'confirmed', 'decided_by_user_id' => $hr->id, 'decided_at' => now()]));
        $this->assertRefused('23505', fn () => $raw($director2->id)); // one waiting change per person
        $this->assertRefused('23514', fn () => DB::table('hr_pay_profile_items')->insert(['organisation_id' => $hr->organisation_id, 'profile_id' => $waitingId,
            'position' => 1, 'item_type_id' => DB::table('hr_pay_item_types')->where('public_id', $deduction)->value('id'), 'item_type_version' => 1,
            'amount_encrypted' => Crypt::encryptString('1')]));
        $this->assertRefused('P0001', fn () => DB::table('hr_pay_profiles')->delete());

        // Salaries reach the payroll page only; never audit or the staff files.
        $this->assertNoAmountsInAudit();
        $this->selectBranch($director);
        $records = (string) $this->get(route('hr.records.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('rateSen', $records);
        $this->assertStringNotContainsString('250000', $records);
    }

    public function test_locums_and_another_organisations_records_are_refused(): void
    {
        $director = $this->actor('director');
        [$group] = $this->setUpPay($director);
        $this->selectBranch($director);
        $this->post(route('hr.records.locums.store'), ['display_name' => 'Synthetic Locum', 'phone' => '+60123456780', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $locum = Worker::query()->where('kind', 'locum')->firstOrFail();
        $this->profile($director, $locum, $this->pay($group))->assertSessionHasErrors(['worker_id' => 'Locums are paid outside KPOne in this version.']);

        $org = new Organisation;
        $org->forceFill(['code' => 'HR_OTHER_'.Str::upper(Str::random(6)), 'name' => 'Synthetic Other Organisation', 'is_active' => true])->save();
        $user = new User;
        $user->forceFill(['organisation_id' => $org->id, 'name' => 'Synthetic Other User', 'email' => 'other.'.Str::lower(Str::random(8)).'@kpone.test', 'is_active' => true])->save();
        $foreignTable = (string) Str::uuid();
        DB::table('hr_statutory_tables')->insert(['public_id' => $foreignTable, 'organisation_id' => $org->id, 'scheme' => 'eis', 'category' => 'Other org',
            'effective_from' => '2026-01-01', 'rounding' => 'none', 'source_note' => 'Synthetic', 'status' => 'entered', 'entered_by_user_id' => $user->id,
            'created_at' => now(), 'updated_at' => now()]);
        $foreignGroup = (string) Str::uuid();
        DB::table('hr_pay_groups')->insert(['public_id' => $foreignGroup, 'organisation_id' => $org->id, 'name' => 'Other monthly', 'is_active' => true,
            'created_by_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->decide($director, 'tables', $foreignTable, 'check')->assertNotFound();
        $this->save($director, 'hr.payroll.groups.save', ['group_id' => $foreignGroup, 'name' => 'Taken over', 'expected_lock_version' => 1])->assertNotFound();
        $ca = $this->actor('ca');
        $this->profile($director, $this->workerFor($director, $ca), $this->pay($foreignGroup))->assertSessionHasErrors(['pay_group_id' => 'Choose a pay group that is in use.']);
        $this->decide($director, 'profiles', (string) Str::uuid(), 'confirm')->assertNotFound();
        $this->selectBranch($director);
        $this->get(route('hr.payroll.index', ['tab' => 'tables']))->assertInertia(fn (Assert $page) => $page->where('payroll.tables.tables', []));
    }

    /** @return array{0:string, 1:string, 2:string} pay group, earnings allowance type and deduction type public ids */
    private function setUpPay(User $actor): array
    {
        $this->save($actor, 'hr.payroll.groups.save', ['name' => 'Monthly staff', 'pay_day' => '28'])->assertSessionHasNoErrors();
        $this->save($actor, 'hr.payroll.item-types.save', ['name' => 'Fixed allowance', 'kind' => 'earnings', 'counts_epf' => true])->assertSessionHasNoErrors();
        $this->save($actor, 'hr.payroll.item-types.save', ['name' => 'Other deduction', 'kind' => 'deduction'])->assertSessionHasNoErrors();
        $type = fn (string $name): string => (string) DB::table('hr_pay_item_type_versions as v')->join('hr_pay_item_types as t', 't.id', '=', 'v.item_type_id')
            ->where('v.name', $name)->value('t.public_id');

        return [(string) DB::table('hr_pay_groups')->where('name', 'Monthly staff')->value('public_id'), $type('Fixed allowance'), $type('Other deduction')];
    }

    /** @return array<string, mixed> */
    private function pay(string $group): array
    {
        return ['pay_group_id' => $group, 'pay_type' => 'monthly', 'rate' => '2,500.00', 'epf' => true, 'epf_category' => 'Malaysian below 60',
            'socso' => true, 'socso_category' => 'Category 1', 'eis' => true, 'mtd' => false, 'hrd' => false, 'effective_from' => '2026-11-01'];
    }

    /** @return array<string, mixed> a synthetic two-band table; not a real statutory figure */
    private function epf(): array
    {
        return ['scheme' => 'epf', 'category' => 'Malaysian below 60', 'effective_from' => '2026-01-01', 'rounding' => 'up_ringgit',
            'source_note' => 'Synthetic test figures, not an official table', 'rows' => [
                ['from' => '0', 'to' => '5,000.00', 'employee_kind' => 'rate', 'employee' => '11', 'employer_kind' => 'rate', 'employer' => '13'],
                ['from' => '5,000.01', 'to' => '', 'employee_kind' => 'rate', 'employee' => '11.00', 'employer_kind' => 'fixed', 'employer' => '650.00'],
            ]];
    }

    private function workerFor(User $manager, User $subject): Worker
    {
        $this->selectBranch($manager);
        $this->post(route('hr.records.files.store'), ['user_id' => $subject->id, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        return Worker::query()->where('user_id', $subject->id)->firstOrFail();
    }

    /** @param array<string, mixed> $input */
    private function save(User $actor, string $route, array $input): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route($route), ['is_active' => true, ...$input, 'idempotency_key' => (string) Str::uuid()]);
    }

    /** @param array<string, mixed> $input */
    private function table(User $actor, array $input, ?string $key = null): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.payroll.tables.enter'), [...$input, 'idempotency_key' => $key ?? (string) Str::uuid()]);
    }

    /** @param array<string, mixed> $input */
    private function profile(User $actor, Worker $worker, array $input, ?string $key = null): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.payroll.profiles.enter'), ['worker_id' => $worker->public_id, ...$input, 'idempotency_key' => $key ?? (string) Str::uuid()]);
    }

    /** @param array<string, mixed> $input */
    private function decide(User $actor, string $kind, string $id, string $decision, array $input = []): TestResponse
    {
        $this->selectBranch($actor);
        $table = $kind === 'tables' ? 'hr_statutory_tables' : 'hr_pay_profiles';

        return $this->post(route("hr.payroll.{$kind}.decide", [$id, $decision]), [...$input,
            'expected_lock_version' => (int) (DB::table($table)->where('public_id', $id)->value('lock_version') ?? 1), 'idempotency_key' => (string) Str::uuid()]);
    }

    private function withdraw(User $actor, string $kind, string $id): TestResponse
    {
        $this->selectBranch($actor);
        $table = $kind === 'tables' ? 'hr_statutory_tables' : 'hr_pay_profiles';

        return $this->post(route("hr.payroll.{$kind}.withdraw", $id), [
            'expected_lock_version' => (int) DB::table($table)->where('public_id', $id)->value('lock_version'), 'idempotency_key' => (string) Str::uuid()]);
    }

    private function assertNoAmountsInAudit(): void
    {
        $audit = (string) json_encode(DB::table('audit_logs')->where('event', 'like', 'hr.payroll.%')->pluck('metadata')->all());
        foreach (['250000', '270000', '15000', '2500', 'rate_sen', 'amount_sen', 'Synthetic test figures'] as $value) {
            $this->assertStringNotContainsString($value, $audit);
        }
    }

    private function assertRefused(string $state, callable $write): void
    {
        try {
            DB::transaction(fn () => $write());
            $this->fail("Expected PostgreSQL to refuse with {$state}.");
        } catch (QueryException $e) {
            $this->assertSame($state, $e->errorInfo[0] ?? null, $e->getMessage());
        }
    }
}

<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Payroll\PayProfileMtd;
use App\Domain\HR\Payroll\Services\PayrollSetupService;
use App\Domain\HR\Records\Models\Worker;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-7d MTD (PCB) worked out by KPOne (HR-PLAN section 18; the owner's "start the HR-7d build", 2026-11-07). Every
 * expected figure is written by hand from synthetic inputs; no band, relief or limit below is LHDN's.
 */
class PayrollMtdTest extends VisitTestCase
{
    private User $hr;

    private User $director;

    private User $director2;

    private User $staff;

    private string $group = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-01-02 09:00', $this->branch->timezone));
        $this->hr = $this->actor('hr_manager');
        $this->director = $this->actor('director');
        $this->director2 = $this->actor('director');
        $this->staff = $this->actor('ca');
    }

    public function test_the_mtd_schedule_is_entered_and_checked_by_two_people_and_never_changes(): void
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.payroll.mtd-schedules.enter'), [...$this->schedule(), 'rows' => [['from' => '0', 'to' => '', 'rate' => '0', 'base_cat13' => '0', 'base_cat2' => '0'],
            ['from' => '5,000.00', 'to' => '', 'rate' => '1', 'base_cat13' => '-400', 'base_cat2' => '-800']], 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors(['rows.0.to']);
        $id = $this->enterSchedule($this->hr);
        $this->assertSame(['entered', 4], [DB::table('hr_mtd_schedules')->value('status'), DB::table('hr_mtd_schedule_rows')->count()]);
        $this->assertSame(-40000, (int) DB::table('hr_mtd_schedule_rows')->where('position', 2)->value('base_cat13_sen'));

        // A second schedule for the same year waits until this one is decided.
        $this->post(route('hr.payroll.mtd-schedules.enter'), [...$this->schedule(), 'rows' => $this->bands(), 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('year');

        // The page shows it, and the person who entered it cannot check it.
        $this->get(route('hr.payroll.index', ['tab' => 'tables']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('payroll.tables.mtdSchedules.0.year', 2026)->where('payroll.tables.mtdSchedules.0.status', 'entered')
            ->where('payroll.tables.mtdSchedules.0.rows.1.baseCat13', '−RM 400.00')->where('payroll.tables.mtdSchedules.0.rows.3.to', null)
            ->where('payroll.tables.mtdSchedules.0.canDecide', false)->where('payroll.tables.mtdSchedules.0.decideRefusal', 'Someone else checks this. You entered it.'));
        $this->post(route('hr.payroll.mtd-schedules.decide', [$id, 'check']), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $this->assertSame('entered', DB::table('hr_mtd_schedules')->value('status'));

        $this->selectBranch($this->director);
        $this->post(route('hr.payroll.mtd-schedules.decide', [$id, 'check']), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame('checked', DB::table('hr_mtd_schedules')->value('status'));

        // A later checked schedule for the year replaces it.
        $this->selectBranch($this->hr);
        $second = $this->enterSchedule($this->hr);
        $this->selectBranch($this->director);
        $this->post(route('hr.payroll.mtd-schedules.decide', [$second, 'check']), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame(['replaced', 'checked'], DB::table('hr_mtd_schedules')->orderBy('id')->pluck('status')->all());

        // Figures never change and nothing is deleted; audit holds no amount.
        $this->assertRefused('P0001', fn () => DB::table('hr_mtd_schedules')->where('public_id', $id)->update(['individual_relief_sen' => 1]));
        $this->assertRefused('P0001', fn () => DB::table('hr_mtd_schedule_rows')->limit(1)->update(['rate_bps' => 1]));
        $this->assertRefused('P0001', fn () => DB::table('hr_mtd_schedules')->where('public_id', $id)->delete());
        $this->assertRefused('23514', fn () => DB::table('hr_mtd_schedule_rows')->insert(['organisation_id' => $this->hr->organisation_id,
            'schedule_id' => DB::table('hr_mtd_schedules')->where('public_id', $id)->value('id'), 'position' => 9, 'chargeable_from_sen' => 0, 'rate_bps' => 0,
            'base_cat13_sen' => 0, 'base_cat2_sen' => 0]));
        $audit = DB::table('audit_logs')->where('event', 'like', 'hr.payroll.mtd_schedule%')->pluck('metadata')->implode(' ');
        $this->assertStringContainsString('"bands":4', $audit);
        $this->assertStringNotContainsString('900000', $audit);
        $this->assertStringNotContainsString('9,000', $audit);
    }

    public function test_kpone_works_out_mtd_from_the_year_to_date_of_locked_runs(): void
    {
        $worker = $this->clinic(['mtd_category' => '1', 'mtd_children' => '0']);

        // January without a checked schedule: the person's MTD cannot be worked out.
        $this->travelTo(CarbonImmutable::parse('2026-02-03 09:00', $this->branch->timezone));
        $jan = $this->prepareRun('2026-01');
        $this->assertSame(['No checked MTD schedule for this year'], $this->person($jan)['errors']);

        $schedule = $this->enterSchedule($this->hr);
        $this->selectBranch($this->director);
        $this->post(route('hr.payroll.mtd-schedules.decide', [$schedule, 'check']), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->step($this->hr, $jan, 'again')->assertSessionHasNoErrors();
        // n = 11; K2 = min(550.00, (4,000.00 − 550.00) ÷ 11); P = RM 47,000.07; ((P − 35,000.00) × 8% + 600.00) ÷ 12 = 130.0005 → RM 130.05.
        $this->assertSame(['mtd', 'mtd', 13005, 'MTD (PCB) · worked out: RM 47,000.07 chargeable for the year, category 1, 11 months left'], $this->mtdLine($worker, $jan));
        $this->assertSame([], $this->person($jan)['errors']);
        $this->lock($jan);

        // February counts January: Y 5,000.00, K 550.00, X 130.05; n = 10; K2 = min(550.00, 2,900.00 ÷ 10 = 290.00).
        // P = 4,450.00 + 4,450.00 + 4,710.00 × 10 − 9,000.00 = 47,000.00; (12,000.00 × 8% + 600.00 − 130.05) ÷ 11 = 129.9954 → RM 130.00.
        $this->travelTo(CarbonImmutable::parse('2026-03-03 09:00', $this->branch->timezone));
        $feb = $this->prepareRun('2026-02');
        $this->assertSame(['mtd', 'mtd', 13000, 'MTD (PCB) · worked out: RM 47,000.00 chargeable for the year, category 1, 10 months left'], $this->mtdLine($worker, $feb));

        // A typed figure replaces it for this run, and the run shows both; clearing it brings the worked-out one back.
        $this->step($this->hr, $feb, 'mtd', ['worker_id' => $worker->public_id, 'mtd' => '200.00'])->assertSessionHasNoErrors();
        $this->assertSame(['mtd', 'mtd', 20000, 'MTD (PCB) · entered for this run (worked out: RM 130.00)'], $this->mtdLine($worker, $feb));
        $this->assertContains('MTD typed for this run instead of the worked-out figure', $this->person($feb)['warnings']);
        $this->assertTrue($this->person($feb)['needsMtd']);
        $this->step($this->hr, $feb, 'mtd', ['worker_id' => $worker->public_id, 'mtd' => ''])->assertSessionHasNoErrors();
        $this->assertSame(13000, $this->mtdLine($worker, $feb)[2]);
        $this->assertNotContains('MTD typed for this run instead of the worked-out figure', $this->person($feb)['warnings']);
        $this->assertNotContains('Joined this year: pay from a previous employer (TP3) is not counted in MTD', $this->person($feb)['warnings']);
    }

    public function test_one_off_pay_an_unlocked_earlier_month_or_no_schedule_ask_for_a_typed_mtd(): void
    {
        $worker = $this->clinic(['mtd_category' => '1', 'mtd_children' => '0']);
        $this->travelTo(CarbonImmutable::parse('2026-03-03 09:00', $this->branch->timezone));

        // No checked schedule yet: an error, but a typed MTD gets past it with a warning (Suresh, M1).
        $jan = $this->prepareRun('2026-01');
        $this->assertSame(['No checked MTD schedule for this year'], $this->person($jan)['errors']);
        $this->step($this->hr, $jan, 'mtd', ['worker_id' => $worker->public_id, 'mtd' => '125.00'])->assertSessionHasNoErrors();
        $this->assertSame([], $this->person($jan)['errors']);
        $this->assertContains('MTD could not be worked out for this run; the typed figure is used', $this->person($jan)['warnings']);
        $this->assertSame(['mtd', 'mtd', 12500, 'MTD (PCB) · entered for this run'], $this->mtdLine($worker, $jan));

        $schedule = $this->enterSchedule($this->hr);
        $this->selectBranch($this->director);
        $this->post(route('hr.payroll.mtd-schedules.decide', [$schedule, 'check']), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        // January is not locked yet, so February's year to date would miss it (Suresh, S2).
        $feb = $this->prepareRun('2026-02');
        $this->assertSame(['An earlier month this year is not locked yet: lock it and prepare again, or type the MTD'], $this->person($feb)['errors']);
        $this->assertNull($this->mtdLine($worker, $feb));
        // The schedule checked after January was prepared changes January's lines, so it is prepared again before locking.
        $this->step($this->hr, $jan, 'again')->assertSessionHasNoErrors();
        $this->lock($jan);
        $this->step($this->hr, $feb, 'again')->assertSessionHasNoErrors();
        $this->assertSame([], $this->person($feb)['errors']);
        // X is January's typed 125.00: (12,000.00 × 8% + 600.00 − 125.00) ÷ 11 = 130.4545 → RM 130.50.
        $this->assertSame(13050, $this->mtdLine($worker, $feb)[2]);

        // A bonus as a manual line is one-off pay: never projected over the year, so the MTD must be typed (Suresh, S1).
        $bonus = app(PayrollSetupService::class)->saveItemType($this->hr, ['name' => 'Bonus', 'kind' => 'earnings', 'counts_mtd' => true, 'is_active' => true,
            'idempotency_key' => (string) Str::uuid()]);
        $this->step($this->hr, $feb, 'manual', ['worker_id' => $worker->public_id, 'item_type_id' => $bonus, 'amount' => '3,000.00', 'reason_code' => 'bonus'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['One-off pay on this run counts for MTD: type the MTD for this person'], $this->person($feb)['errors']);
        $this->assertNull($this->mtdLine($worker, $feb));
        $this->step($this->hr, $feb, 'mtd', ['worker_id' => $worker->public_id, 'mtd' => '430.00'])->assertSessionHasNoErrors();
        $this->assertSame([], $this->person($feb)['errors']);
        $this->assertSame(43000, $this->mtdLine($worker, $feb)[2]);
    }

    public function test_category_2_children_and_the_joiner_warning(): void
    {
        $worker = $this->clinic(['mtd_category' => '2', 'mtd_children' => '2'], '2026-01-05');
        $this->travelTo(CarbonImmutable::parse('2026-02-03 09:00', $this->branch->timezone));
        $schedule = $this->enterSchedule($this->hr);
        $this->selectBranch($this->director);
        $this->post(route('hr.payroll.mtd-schedules.decide', [$schedule, 'check']), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $jan = $this->prepareRun('2026-01');
        // Employed 27 of 31 days: basic 5,000.00 × 27 ÷ 31 = 4,354.83 (down); EPF 11% = 479.03 (nearest sen).
        // K2 = min(479.03, (4,000.00 − 479.03) ÷ 11 = 320.08); reliefs 9,000 + 4,000 + 2 × 2,000 = 17,000.00.
        // P = 3,875.80 + (4,354.83 − 320.08) × 11 − 17,000.00 = 31,258.05 (band 3%, category 2 base −650.00).
        // ((31,258.05 − 20,000.00) × 3% − 650.00) ÷ 12 = 0.0846 → under RM 10.00, so nothing.
        $this->assertSame(['mtd', 'mtd', 0, 'MTD (PCB) · worked out: RM 31,258.05 chargeable for the year, category 2, 11 months left'], $this->mtdLine($worker, $jan));
        $this->assertContains('Joined this year: pay from a previous employer (TP3) is not counted in MTD', $this->person($jan)['warnings']);
    }

    public function test_a_profile_without_mtd_details_keeps_mtd_typed_each_run_and_details_never_change(): void
    {
        $worker = $this->clinic([]);
        $this->travelTo(CarbonImmutable::parse('2026-02-03 09:00', $this->branch->timezone));
        $schedule = $this->enterSchedule($this->hr);
        $this->selectBranch($this->director);
        $this->post(route('hr.payroll.mtd-schedules.decide', [$schedule, 'check']), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $jan = $this->prepareRun('2026-01');
        $this->assertSame(['MTD not entered'], $this->person($jan)['errors']);

        // The details belong to the profile and never change after entry; a category needs MTD, and a disabled spouse category 2.
        $profile = DB::table('hr_pay_profiles')->where('worker_id', $worker->id)->first();
        $this->assertNotNull($profile);
        $this->assertRefused('P0001', fn () => DB::table('hr_pay_profiles')->where('id', $profile->id)->update(['mtd_category' => 1, 'mtd_children' => 0]));
        $this->selectBranch($this->hr);
        $this->post(route('hr.payroll.profiles.enter'), [...$this->pay(), 'worker_id' => $worker->public_id, 'mtd_category' => '4', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('mtd_category');
        $this->post(route('hr.payroll.profiles.enter'), [...$this->pay(), 'worker_id' => $worker->public_id, 'mtd_category' => '1', 'mtd_children' => '21', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('mtd_children');
        $this->post(route('hr.payroll.profiles.enter'), [...$this->pay(), 'worker_id' => $worker->public_id, 'effective_from' => '2026-02-01', 'mtd_category' => '1',
            'mtd_children' => '1', 'mtd_disabled_spouse' => true, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $waiting = DB::table('hr_pay_profiles')->where('worker_id', $worker->id)->where('status', 'waiting')->first();
        $this->assertSame([1, 1, false], [(int) $waiting?->mtd_category, (int) $waiting?->mtd_children, (bool) $waiting?->mtd_disabled_spouse]);
        $this->get(route('hr.payroll.index'))->assertInertia(fn (Assert $page) => $page->where('payroll.profiles.people', fn ($people) => collect($people)
            ->contains(fn ($p) => $p['id'] === $worker->public_id && in_array('MTD (category 1, 1 child)', $p['waiting']['schemes'], true))));
    }

    // ---- fixture ----

    public function test_hr_7e_the_mtd_details_are_stored_encrypted_and_never_readable_in_the_table(): void
    {
        $worker = $this->clinic(['mtd_category' => '2', 'mtd_children' => '3', 'mtd_disabled_self' => '1', 'mtd_disabled_spouse' => '1']);
        $profile = DB::table('hr_pay_profiles')->where('worker_id', $worker->id)->sole();
        $raw = json_encode((array) $profile);
        // No plain column is left, and the stored value shows neither the field names nor the disability flags.
        $this->assertFalse(Schema::hasColumns('hr_pay_profiles', ['mtd_category']));
        $this->assertFalse(Schema::hasColumns('hr_pay_profiles', ['mtd_children']));
        $this->assertFalse(Schema::hasColumns('hr_pay_profiles', ['mtd_disabled_self']));
        $this->assertFalse(Schema::hasColumns('hr_pay_profiles', ['mtd_disabled_spouse']));
        foreach (['category', 'children', 'disabled', 'true'] as $plain) {
            $this->assertStringNotContainsString($plain, (string) $profile->mtd_details_encrypted);
        }
        $this->assertStringNotContainsString('"disabled_self"', $raw);
        $this->assertSame(['category' => 2, 'children' => 3, 'disabled_self' => true, 'disabled_spouse' => true], PayProfileMtd::read($profile));
        // The audit trail never holds them either.
        $audit = DB::table('audit_logs')->where('event', 'like', 'hr.payroll.profile%')->pluck('metadata')->implode(' ');
        $this->assertStringNotContainsString('disabled', $audit);
        // A value that cannot be decrypted reads as no details (MTD then needs a typed figure), never as a guess.
        $this->assertNull(PayProfileMtd::read((object) ['id' => 1, 'mtd_details_encrypted' => 'not-encrypted']));
    }

    public function test_hr_7e_the_migration_encrypts_existing_plain_details_and_drops_the_plain_columns(): void
    {
        $worker = $this->clinic(['mtd_category' => '2', 'mtd_children' => '1', 'mtd_disabled_spouse' => '1']);
        $plain = $this->clinicSecond();
        // Put the table back as it was before HR-7e (inside this test's transaction), with plain values on two profiles.
        DB::unprepared(<<<'SQL'
DROP TRIGGER hr_pay_profiles_mtd_guard ON hr_pay_profiles;
DROP FUNCTION guard_hr_pay_profile_mtd();
ALTER TABLE hr_pay_profiles DROP CONSTRAINT hr_pay_profiles_mtd_shape;
ALTER TABLE hr_pay_profiles DROP COLUMN mtd_details_encrypted;
ALTER TABLE hr_pay_profiles ADD COLUMN mtd_category smallint NULL, ADD COLUMN mtd_children smallint NULL,
  ADD COLUMN mtd_disabled_self boolean NOT NULL DEFAULT false, ADD COLUMN mtd_disabled_spouse boolean NOT NULL DEFAULT false;
ALTER TABLE hr_pay_profiles ADD CONSTRAINT hr_pay_profiles_mtd_shape CHECK (mtd_category IS NULL OR (mtd AND mtd_category IN (1, 2, 3)));
CREATE FUNCTION guard_hr_pay_profile_mtd() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.mtd_category IS DISTINCT FROM OLD.mtd_category THEN
  RAISE EXCEPTION 'A pay profile never changes after it is entered; enter a new one' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_pay_profiles_mtd_guard BEFORE UPDATE ON hr_pay_profiles FOR EACH ROW EXECUTE FUNCTION guard_hr_pay_profile_mtd();
ALTER TABLE hr_pay_profiles DISABLE TRIGGER USER;
SQL);
        DB::table('hr_pay_profiles')->where('worker_id', $worker->id)->update(['mtd_category' => 2, 'mtd_children' => 1, 'mtd_disabled_spouse' => true]);
        DB::statement('ALTER TABLE hr_pay_profiles ENABLE TRIGGER USER');

        (require base_path('database/migrations/2026_11_10_003950_encrypt_hr_pay_profile_mtd_details.php'))->up();

        $this->assertFalse(Schema::hasColumns('hr_pay_profiles', ['mtd_category']));
        $this->assertSame(['category' => 2, 'children' => 1, 'disabled_self' => false, 'disabled_spouse' => true],
            PayProfileMtd::read(DB::table('hr_pay_profiles')->where('worker_id', $worker->id)->sole()));
        $this->assertNull(DB::table('hr_pay_profiles')->where('id', $plain)->value('mtd_details_encrypted'));
        // The guard is back: the encrypted value never changes after entry.
        $this->assertRefused('P0001', fn () => DB::table('hr_pay_profiles')->where('worker_id', $worker->id)->update(['mtd_details_encrypted' => null]));
    }

    /** A second confirmed profile with MTD switched on but no details (MTD typed each run). */
    private function clinicSecond(): int
    {
        $setup = app(PayrollSetupService::class);
        $other = $this->actor('ca');
        $this->selectBranch($this->director);
        $this->post(route('hr.records.files.store'), ['user_id' => $other->id, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $worker = Worker::query()->where('user_id', $other->id)->firstOrFail();
        DB::table('hr_employment_periods')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $worker->organisation_id, 'worker_id' => $worker->id,
            'type' => 'permanent', 'starts_on' => '2026-01-01', 'created_by_user_id' => $this->director->id, 'created_at' => now(), 'updated_at' => now()]);
        $setup->enterProfile($this->hr, [...$this->pay(), 'worker_id' => $worker->public_id, 'idempotency_key' => (string) Str::uuid()]);

        return (int) DB::table('hr_pay_profiles')->where('worker_id', $worker->id)->value('id');
    }

    /** @return array<string, mixed> */
    private function schedule(): array
    {
        return ['year' => '2026', 'rounding' => 'up_5_sen', 'minimum' => '10.00', 'individual_relief' => '9,000.00', 'spouse_relief' => '4,000.00',
            'disabled_self_relief' => '7,000.00', 'disabled_spouse_relief' => '6,000.00', 'child_relief' => '2,000.00', 'epf_relief_cap' => '4,000.00',
            'source_note' => 'Synthetic test figures, not an official schedule'];
    }

    /** @return list<array<string, string>> */
    private function bands(): array
    {
        return [['from' => '0', 'to' => '4,999.99', 'rate' => '0', 'base_cat13' => '0', 'base_cat2' => '0'],
            ['from' => '5,000.00', 'to' => '19,999.99', 'rate' => '1', 'base_cat13' => '-400.00', 'base_cat2' => '-800.00'],
            ['from' => '20,000.00', 'to' => '34,999.99', 'rate' => '3', 'base_cat13' => '-250.00', 'base_cat2' => '-650.00'],
            ['from' => '35,000.00', 'to' => '', 'rate' => '8', 'base_cat13' => '600.00', 'base_cat2' => '600.00']];
    }

    private function enterSchedule(User $actor): string
    {
        $this->selectBranch($actor);
        $this->post(route('hr.payroll.mtd-schedules.enter'), [...$this->schedule(), 'rows' => $this->bands(), 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        return (string) DB::table('hr_mtd_schedules')->orderByDesc('id')->value('public_id');
    }

    /** @return array<string, mixed> */
    private function pay(): array
    {
        return ['pay_group_id' => $this->group, 'pay_type' => 'monthly', 'rate' => '5,000.00', 'epf' => true, 'epf_category' => 'Malaysian below 60', 'socso' => false,
            'eis' => false, 'mtd' => true, 'hrd' => false, 'effective_from' => '2026-01-01', 'items' => []];
    }

    /** @param array<string, string> $mtd */
    private function clinic(array $mtd, string $starts = '2026-01-01'): Worker
    {
        $setup = app(PayrollSetupService::class);
        $k = fn (): string => (string) Str::uuid();
        $this->group = $setup->saveGroup($this->hr, ['name' => 'Cheras pay', 'pay_day' => '28', 'is_active' => true, 'idempotency_key' => $k()]);
        $table = $setup->enterTable($this->hr, ['scheme' => 'epf', 'category' => 'Malaysian below 60', 'effective_from' => '2026-01-01', 'rounding' => 'nearest_sen',
            'source_note' => 'Synthetic test figures, not an official table', 'rows' => [['from' => '0', 'to' => '', 'employee_kind' => 'rate', 'employee' => '11',
                'employer_kind' => 'rate', 'employer' => '13']], 'idempotency_key' => $k()]);
        $setup->decideTable($this->director, $table, 'check', ['expected_lock_version' => 1, 'idempotency_key' => $k()]);
        $this->selectBranch($this->director);
        $this->post(route('hr.records.files.store'), ['user_id' => $this->staff->id, 'idempotency_key' => $k()])->assertSessionHasNoErrors();
        $worker = Worker::query()->with('user')->where('user_id', $this->staff->id)->firstOrFail();
        DB::table('hr_employment_periods')->insert(['public_id' => $k(), 'organisation_id' => $worker->organisation_id, 'worker_id' => $worker->id,
            'type' => 'permanent', 'starts_on' => $starts, 'created_by_user_id' => $this->director->id, 'created_at' => now(), 'updated_at' => now()]);
        $id = $setup->enterProfile($this->hr, [...$this->pay(), ...$mtd, 'worker_id' => $worker->public_id, 'idempotency_key' => $k()]);
        $setup->decideProfile($this->director, $id, 'confirm', ['expected_lock_version' => 1, 'idempotency_key' => $k()]);

        return $worker;
    }

    private function prepareRun(string $month): string
    {
        $this->selectBranch($this->hr);
        $this->post(route('hr.payroll.runs.prepare'), ['pay_group_id' => $this->group, 'month' => $month, 'kind' => 'regular', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();

        return (string) DB::table('hr_payroll_runs')->orderByDesc('id')->value('public_id');
    }

    private function lock(string $run): void
    {
        $this->step($this->hr, $run, 'send')->assertSessionHasNoErrors();
        $this->step($this->director2, $run, 'approve')->assertSessionHasNoErrors();
        $this->step($this->director2, $run, 'lock')->assertSessionHasNoErrors();
        $this->assertSame('locked', DB::table('hr_payroll_runs')->where('public_id', $run)->value('status'));
    }

    /** @param array<string, mixed> $input */
    private function step(User $actor, string $run, string $step, array $input = []): TestResponse
    {
        $this->selectBranch($actor);

        return $this->post(route('hr.payroll.runs.step', [$run, $step]), [...$input,
            'expected_lock_version' => (int) (DB::table('hr_payroll_runs')->where('public_id', $run)->value('lock_version') ?? 1), 'idempotency_key' => (string) Str::uuid()]);
    }

    /** @return array<string, mixed> the one person on the run page */
    private function person(string $run): array
    {
        $this->selectBranch($this->hr);
        $people = [];
        $this->get(route('hr.payroll.runs.show', $run))->assertOk()->assertInertia(function (Assert $page) use (&$people) {
            $people = $page->toArray()['props']['run']['people'];

            return $page;
        });

        return $people[0];
    }

    /** @return array{0:string, 1:string, 2:int, 3:string}|null */
    private function mtdLine(Worker $worker, string $runId): ?array
    {
        $run = DB::table('hr_payroll_runs')->where('public_id', $runId)->value('id');
        $person = DB::table('hr_payroll_run_people')->where('run_id', $run)->where('worker_id', $worker->id)->value('id');
        $line = DB::table('hr_payroll_lines')->where('run_person_id', $person)->where('kind', 'mtd')->first();

        return $line === null ? null : [(string) $line->kind, (string) $line->section, (int) Crypt::decryptString((string) $line->amount_encrypted),
            Crypt::decryptString((string) $line->explanation_encrypted)];
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

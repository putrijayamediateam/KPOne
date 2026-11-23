<?php

namespace Tests\Feature\Billing;

use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Shared\Services\ClinicInsightsReportService;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Models\PaymentRefund;
use App\Domain\Visit\Billing\Models\SalesTarget;
use App\Domain\Visit\Billing\Models\SalesTargetRevision;
use App\Domain\Visit\Billing\Services\SalesTargetService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\OtcDispensaryFixtures;

/**
 * BIL-30: monthly sales targets per branch and per doctor. Achieved must equal the Insights net sales figure (K1, K2);
 * past months are locked (1A, K5); a doctor sees only their own line (P7, K7). All data is synthetic.
 *
 * Dates sit in a month two months ahead of the real clock, because PostgreSQL judges "past month" by its own clock
 * (which the tests cannot move); the domain is checked with Carbon's frozen clock.
 */
class SalesTargetTest extends BillingTestCase
{
    use OtcDispensaryFixtures;

    private CarbonImmutable $month;

    protected function setUp(): void
    {
        parent::setUp();
        $this->month = CarbonImmutable::now($this->branch->timezone)->startOfMonth()->addMonthsNoOverflow(2);
    }

    public function test_achieved_matches_insights_for_the_branch_and_the_doctor_with_a_refund_credit_on_its_own_day(): void
    {
        $saleDay = $this->month->setDay(10)->setTime(10, 0);
        $this->travelTo($saleDay);
        [$doctor, $ca, $visit, $invoice] = $this->finalizedFixture(4000);
        $cash = (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash', 'classification' => 'cash']);
        $cash->save();
        $this->selectBranch($ca);
        $this->post(route('billing.payment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => '4000', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post(route('billing.complete', $visit), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'visit_lock_version' => $visit->refresh()->lock_version])->assertSessionHasNoErrors();
        $receipt = DB::table('payments')->latest('id')->value('public_id');
        $this->selectBranch($this->actor('ca_supervisor'));
        $this->post(route('billing.refund', [$visit, $receipt]), ['expected_branch_id' => $visit->branch_id, 'payment_method_id' => $cash->id, 'reason_code' => 'medicine_returned', 'amount_sen' => '1500', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->travelTo($this->month->setDay(12)->setTime(9, 0));
        $this->selectBranch($this->actor('finance_officer'));
        $this->post(route('billing.refund.decide', [$visit, PaymentRefund::query()->sole()->public_id, 'approved']), ['expected_branch_id' => $visit->branch_id, 'reason_code' => null, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        $director = $this->actor('director');
        $this->selectBranch($director);
        $from = $this->month->toDateString();
        $to = $this->month->endOfMonth()->toDateString();
        $insights = fn (?int $doctorId) => app(ClinicInsightsReportService::class)->report($director, 'sales', 'CHERAS', $from, $to, $doctorId)['report']['summary']['totalSen'];
        $this->assertSame(2500, $insights(null));

        $this->post(route('sales-targets.store'), $this->input(['amount' => '100.00']))->assertSessionHasNoErrors();
        $this->post(route('sales-targets.store'), $this->input(['amount' => '20', 'doctor_user_id' => $doctor->id]))->assertSessionHasNoErrors();
        $page = $this->get(route('sales-targets.index', ['month' => $this->month->format('Y-m')]))->assertOk();
        $branch = collect($page->viewData('page')['props']['salesTargets']['branches'])->firstWhere('code', 'CHERAS');
        $this->assertSame($insights(null), $branch['achievedSen']);
        $this->assertSame([10000, 7500, 25], [$branch['targetSen'], $branch['balanceSen'], $branch['percent']]);
        $line = collect($branch['doctors'])->firstWhere('doctorId', $doctor->id);
        $this->assertSame($insights($doctor->id), $line['achievedSen']);
        $this->assertSame([2000, 2500, 0, 500, true], [$line['targetSen'], $line['achievedSen'], $line['balanceSen'], $line['overSen'], $line['reached']]);

        // The doctor's own card shows the same line, and nothing else.
        $this->selectBranch($doctor);
        $this->get(route('queue.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('mySalesTarget.targetSen', 2000)->where('mySalesTarget.achievedSen', 2500)->where('mySalesTarget.reached', true)
            ->where('mySalesTarget', fn ($card): bool => array_keys(collect($card)->all()) === ['month', 'branch', 'targetSen', 'achievedSen', 'balanceSen', 'overSen', 'neededPerDaySen', 'daysLeft', 'percent', 'reached']));
    }

    public function test_set_change_and_clear_keep_one_row_with_append_only_revisions_and_an_audit(): void
    {
        $this->travelTo($this->month->setDay(5)->setTime(9, 0));
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $service = app(SalesTargetService::class);

        $service->save($finance, $this->input(['amount' => '1,000.50']));
        $target = SalesTarget::query()->sole();
        $this->assertSame([100050, 1, null], [$target->amount_sen, $target->lock_version, $target->cleared_at]);

        $this->assertRefused(fn () => $service->save($finance, $this->input(['amount' => '1200', 'lock_version' => 1])), 'reason', 'Write why the target is changed.');
        $service->save($finance, $this->input(['amount' => '1200', 'lock_version' => 1, 'reason' => 'Synthetic busier month']));
        $this->assertRefused(fn () => $service->save($finance, $this->input(['amount' => '1300', 'lock_version' => 0, 'reason' => 'Stale'])), 'amount', 'This target changed while you were editing. Reload and try again.');
        $service->save($finance, $this->input(['amount' => '', 'lock_version' => 2, 'reason' => 'Synthetic clear']));
        $target->refresh();
        $this->assertSame([null, 3], [$target->amount_sen, $target->lock_version]);
        $this->assertNotNull($target->cleared_at);
        $service->save($finance, $this->input(['amount' => '900', 'lock_version' => 3]));

        $this->assertSame(1, SalesTarget::query()->count());
        $this->assertSame(
            [['set', null, 100050], ['changed', 100050, 120000], ['cleared', 120000, null], ['set', null, 90000]],
            SalesTargetRevision::query()->orderBy('id')->get()->map(fn ($r): array => [$r->action, $r->old_amount_sen, $r->new_amount_sen])->all(),
        );
        $audits = DB::table('audit_logs')->where('event', 'like', 'sales_target.%')->orderBy('id')->get();
        $this->assertSame(['sales_target.set', 'sales_target.changed', 'sales_target.cleared', 'sales_target.set'], $audits->pluck('event')->all());
        $this->assertStringNotContainsString('Synthetic', (string) $audits->pluck('metadata')->implode(' '), 'Reasons stay out of the audit metadata.');

        // PostgreSQL keeps revisions append-only and targets undeletable.
        $this->assertDatabaseRefuses(fn () => DB::table('sales_target_revisions')->update(['reason' => 'x']), 'append-only');
        $this->assertDatabaseRefuses(fn () => DB::table('sales_target_revisions')->delete(), 'append-only');
        $this->assertDatabaseRefuses(fn () => DB::table('sales_targets')->delete(), 'never deleted');
    }

    public function test_a_retry_returns_the_first_result_and_a_changed_payload_with_the_same_key_is_refused(): void
    {
        $this->travelTo($this->month->setDay(5)->setTime(9, 0));
        $director = $this->actor('director');
        $this->selectBranch($director);
        $service = app(SalesTargetService::class);
        $input = $this->input(['amount' => '500']);
        $first = $service->save($director, $input);
        $this->assertTrue($first->is($service->save($director, $input)));
        $this->assertSame(1, SalesTargetRevision::query()->count());
        $this->assertRefused(fn () => $service->save($director, [...$input, 'amount' => '600']), 'idempotency_key', 'This retry key has already been used for another change. Reload and try again.');
    }

    public function test_a_past_month_is_locked_by_the_domain_at_local_midnight_and_by_postgresql(): void
    {
        $director = $this->actor('director');
        $last = $this->month->endOfMonth();
        $this->travelTo(CarbonImmutable::parse($last->toDateString().' 23:59', $this->branch->timezone));
        $this->selectBranch($director);
        $service = app(SalesTargetService::class);
        $service->save($director, $this->input(['amount' => '100']));
        $this->travelTo(CarbonImmutable::parse($last->addDay()->toDateString().' 00:01', $this->branch->timezone));
        $this->assertRefused(fn () => $service->save($director, $this->input(['amount' => '200', 'lock_version' => 1])), 'amount', 'This month has ended. Its targets can no longer be changed.');
        $page = $this->get(route('sales-targets.index', ['month' => $this->month->format('Y-m')]))->assertOk();
        $this->assertFalse($page->viewData('page')['props']['salesTargets']['open']);

        // PostgreSQL judges by its own clock: a month before the real current month is refused.
        $this->travelBack();
        $past = CarbonImmutable::now($this->branch->timezone)->startOfMonth()->subMonthNoOverflow();
        $this->assertDatabaseRefuses(fn () => DB::table('sales_targets')->insert([
            'organisation_id' => $this->branch->organisation_id, 'branch_id' => $this->branch->id, 'month' => $past->toDateString(), 'amount_sen' => 100,
            'lock_version' => 0, 'created_by_user_id' => $director->id, 'updated_by_user_id' => $director->id, 'created_at' => now(), 'updated_at' => now(),
        ]), 'past month');
    }

    public function test_a_doctor_target_needs_a_doctor_who_works_at_the_branch_in_that_month(): void
    {
        $this->travelTo($this->month->setDay(5)->setTime(9, 0));
        $director = $this->actor('director');
        $this->selectBranch($director);
        $other = $this->branch->newQuery()->where('organisation_id', $this->branch->organisation_id)->where('id', '<>', $this->branch->id)->where('is_active', true)->firstOrFail();
        $elsewhere = $this->actor('resident_doctor', $other);
        $ca = $this->actor('ca');
        $service = app(SalesTargetService::class);
        foreach ([$elsewhere->id, $ca->id] as $id) {
            $this->assertRefused(fn () => $service->save($director, $this->input(['amount' => '100', 'doctor_user_id' => $id])), 'amount', 'This doctor does not work at this branch in this month.');
        }
        $here = $this->actor('resident_doctor');
        $service->save($director, $this->input(['amount' => '100', 'doctor_user_id' => $here->id]));
        $this->assertSame($here->id, SalesTarget::query()->sole()->doctor_user_id);
    }

    public function test_needed_per_day_uses_the_calendar_days_left_counting_today(): void
    {
        $this->travelTo($this->month->setDay(1)->setTime(9, 0));
        $service = app(SalesTargetService::class);
        $days = $this->month->daysInMonth;
        $first = $service->progress($this->branch, $this->month, 10000, 0);
        $this->assertSame([$days, (int) ceil(10000 / $days)], [$first['daysLeft'], $first['neededPerDaySen']]);
        $this->travelTo($this->month->setDay(15)->setTime(9, 0));
        $middle = $service->progress($this->branch, $this->month, 10000, 3000);
        $this->assertSame([$days - 14, (int) ceil(7000 / ($days - 14)), 30], [$middle['daysLeft'], $middle['neededPerDaySen'], $middle['percent']]);
        $this->travelTo($this->month->endOfMonth()->setTime(9, 0));
        $last = $service->progress($this->branch, $this->month, 10000, 2999);
        $this->assertSame([1, 7001], [$last['daysLeft'], $last['neededPerDaySen']]);
        $reached = $service->progress($this->branch, $this->month, 10000, 12000);
        $this->assertSame([0, 2000, null, true, 120], [$reached['balanceSen'], $reached['overSen'], $reached['neededPerDaySen'], $reached['reached'], $reached['percent']]);
        $future = $service->progress($this->branch, $this->month->addMonthNoOverflow(), 9000, 0);
        $this->assertSame([$this->month->addMonthNoOverflow()->daysInMonth, 0], [$future['daysLeft'], $future['achievedSen']]);
        $this->travelTo($this->month->addMonthNoOverflow()->setDay(2)->setTime(9, 0));
        $ended = $service->progress($this->branch, $this->month, 10000, 0);
        $this->assertSame([null, null], [$ended['daysLeft'], $ended['neededPerDaySen']]);
    }

    public function test_only_director_and_finance_officer_open_the_page_and_a_doctor_sees_only_their_own_target(): void
    {
        $this->travelTo($this->month->setDay(5)->setTime(9, 0));
        foreach (['director', 'finance_officer'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->get(route('sales-targets.index'))->assertOk();
        }
        foreach (['ca', 'ca_supervisor', 'administrator', 'panel_officer', 'branch_manager', 'account_assistant', 'store_admin', 'locum', 'resident_doctor', 'medical_assistant'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->get(route('sales-targets.index'))->assertForbidden();
            $this->post(route('sales-targets.store'), $this->input(['amount' => '1']))->assertForbidden();
        }

        $director = $this->actor('director');
        $mine = $this->actor('resident_doctor');
        $theirs = $this->actor('resident_doctor');
        $this->selectBranch($director);
        $service = app(SalesTargetService::class);
        $service->save($director, $this->input(['amount' => '300', 'doctor_user_id' => $theirs->id]));
        $service->save($director, $this->input(['amount' => '999']));
        $this->assertNull($service->own($mine), 'No card without your own target, even when others have one.');
        $service->save($director, $this->input(['amount' => '200', 'doctor_user_id' => $mine->id]));
        $this->selectBranch($mine);
        $this->get(route('queue.index', ['doctor_user_id' => $theirs->id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('mySalesTarget.targetSen', 20000));
        $this->assertNull(app(SalesTargetService::class)->own($this->actor('ca')));
        $this->expectException(AuthorizationException::class);
        $service->page($mine, null);
    }

    public function test_the_permission_migration_is_additive_and_idempotent(): void
    {
        $migration = require database_path('migrations/2026_11_23_200100_grant_sales_target_permissions.php');
        $migration->up();
        $migration->up();
        $holders = fn (string $permission): array => DB::table('role_has_permissions')->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')->where('permissions.name', $permission)->orderBy('roles.name')->pluck('roles.name')->all();
        $this->assertSame(['director', 'finance_officer'], $holders('sales_targets.manage.organisation'));
        $this->assertSame(['resident_doctor'], $holders('sales_targets.view.own'));
    }

    public function test_a_first_set_from_a_stale_page_is_refused_as_changed_not_taken_as_a_change(): void
    {
        $this->travelTo($this->month->setDay(5)->setTime(9, 0));
        $director = $this->actor('director');
        $this->selectBranch($director);
        $service = app(SalesTargetService::class);
        $service->save($director, $this->input(['amount' => '100']));
        // A second person opened "Set target" before the first save (version 0 means "no target yet").
        $this->assertRefused(fn () => $service->save($director, $this->input(['amount' => '200', 'reason' => 'Overwrite'])), 'amount', 'This target changed while you were editing. Reload and try again.');
        $this->assertSame(10000, SalesTarget::query()->sole()->amount_sen);
    }

    public function test_a_doctor_who_left_keeps_a_target_that_can_still_be_changed_or_cleared(): void
    {
        $this->travelTo($this->month->setDay(5)->setTime(9, 0));
        $director = $this->actor('director');
        $this->selectBranch($director);
        $doctor = $this->actor('resident_doctor');
        $service = app(SalesTargetService::class);
        $service->save($director, $this->input(['amount' => '100', 'doctor_user_id' => $doctor->id]));
        $doctor->forceFill(['is_active' => false])->save();
        $service->save($director, $this->input(['amount' => '', 'doctor_user_id' => $doctor->id, 'lock_version' => 1, 'reason' => 'Synthetic leaver']));
        $this->assertSame(2, SalesTarget::query()->sole()->lock_version);
        $this->assertRefused(fn () => $service->save($director, $this->input(['amount' => '50', 'doctor_user_id' => $doctor->id, 'lock_version' => 2])), 'amount', 'This doctor does not work at this branch in this month.');
    }

    public function test_targets_can_be_set_up_to_twelve_months_ahead_and_no_further(): void
    {
        $this->travelTo($this->month->setDay(5)->setTime(9, 0));
        $director = $this->actor('director');
        $this->selectBranch($director);
        $service = app(SalesTargetService::class);
        $service->save($director, $this->input(['amount' => '100', 'month' => $this->month->addMonthsNoOverflow(12)->format('Y-m')]));
        $this->assertRefused(fn () => $service->save($director, $this->input(['amount' => '100', 'month' => $this->month->addMonthsNoOverflow(13)->format('Y-m')])), 'amount', 'Targets can be set up to twelve months ahead.');
        $this->assertSame($this->month->addMonthsNoOverflow(12)->format('Y-m'), $service->page($director, null)['lastMonth']);
    }

    public function test_doctor_lines_leave_out_otc_sales_and_match_the_insights_provider_row(): void
    {
        $this->travelTo($this->month->setDay(10)->setTime(10, 0));
        [$doctor] = $this->finalizedFixture(4000);
        $f = $this->fixture();
        $this->otcPrice($f, 250);
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case, '2.000'));
        $this->confirmAllergy($f, $case);
        $this->complete($f, $case);
        $otc = app(BillingBuilderService::class)->build($f['ca'], $f['visit'], ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => null]);
        app(BillingBuilderService::class)->finalize($f['ca'], $f['visit']->refresh(), $otc, ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => $otc->lock_version]);

        $director = $this->actor('director');
        $this->selectBranch($director);
        $report = app(ClinicInsightsReportService::class)->report($director, 'sales', 'CHERAS', $this->month->toDateString(), $this->month->endOfMonth()->toDateString(), null)['report'];
        $page = app(SalesTargetService::class)->page($director, $this->month->format('Y-m'));
        $branch = collect($page['branches'])->firstWhere('code', 'CHERAS');
        $this->assertSame(4500, $branch['achievedSen']);
        $this->assertSame($report['summary']['totalSen'], $branch['achievedSen']);
        $line = collect($branch['doctors'])->firstWhere('doctorId', $doctor->id);
        $this->assertSame(4000, $line['achievedSen'], 'The OTC sale is not the doctor\'s.');
        $this->assertSame(collect($report['providers'])->firstWhere('name', $doctor->name)['salesSen'], $line['achievedSen']);
    }

    public function test_postgresql_refuses_a_revision_that_does_not_match_its_target_and_any_change_to_a_past_month(): void
    {
        $this->travelTo($this->month->setDay(5)->setTime(9, 0));
        $director = $this->actor('director');
        $this->selectBranch($director);
        $revision = app(SalesTargetService::class)->save($director, $this->input(['amount' => '100']));
        $this->assertDatabaseRefuses(fn () => DB::table('sales_target_revisions')->insert([
            ...collect($revision->getAttributes())->except(['id'])->all(), 'action' => 'changed', 'old_amount_sen' => 10000, 'new_amount_sen' => 20000,
            'reason' => 'Forged', 'idempotency_key' => (string) Str::uuid(),
        ]), 'must match its target');

        $this->travelBack();
        $past = CarbonImmutable::now($this->branch->timezone)->startOfMonth()->subMonthNoOverflow()->toDateString();
        DB::statement('ALTER TABLE sales_targets DISABLE TRIGGER USER');
        $id = DB::table('sales_targets')->insertGetId(['organisation_id' => $this->branch->organisation_id, 'branch_id' => $this->branch->id, 'month' => $past, 'amount_sen' => 100,
            'lock_version' => 1, 'created_by_user_id' => $director->id, 'updated_by_user_id' => $director->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::statement('ALTER TABLE sales_targets ENABLE TRIGGER USER');
        $this->assertDatabaseRefuses(fn () => DB::table('sales_targets')->where('id', $id)->update(['amount_sen' => 200, 'lock_version' => 2]), 'past month');
        $this->assertDatabaseRefuses(fn () => DB::table('sales_targets')->where('id', $id)->update(['amount_sen' => null, 'cleared_at' => now(), 'lock_version' => 2]), 'past month');
    }

    /** @param array<string, mixed> $f */
    private function otcPrice(array $f, int $sen): void
    {
        $book = PriceBook::query()->where('organisation_id', $f['ca']->organisation_id)->where('scope_key', 'organisation')->first();
        if ($book === null) {
            $book = new PriceBook;
            $book->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['ca']->organisation_id, 'scope_key' => 'organisation', 'name' => 'Synthetic OTC prices', 'currency' => 'MYR'])->save();
        }
        $charge = new ChargeDefinition;
        $charge->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['ca']->organisation_id, 'type' => 'medicine', 'code' => 'OTC-'.Str::upper(Str::random(5)), 'display_name' => 'Synthetic OTC charge', 'source_key' => 'medicine:'.$f['medicine']->id, 'medicine_catalogue_item_id' => $f['medicine']->id, 'unit' => $f['medicine']->order_unit, 'is_active' => true])->save();
        (new PriceEntry)->forceFill(['organisation_id' => $f['ca']->organisation_id, 'price_book_id' => $book->id, 'charge_definition_id' => $charge->id, 'version' => 1, 'unit_price_sen' => $sen, 'effective_at' => now()->subMinute(), 'published_by_user_id' => $f['ca']->id])->save();
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed> */
    private function input(array $overrides): array
    {
        return [
            'expected_branch_id' => $this->branch->id, 'branch_id' => $this->branch->id, 'month' => $this->month->format('Y-m'), 'doctor_user_id' => null,
            'lock_version' => 0, 'reason' => null, 'idempotency_key' => (string) Str::uuid(), ...$overrides,
        ];
    }

    private function assertRefused(callable $call, string $field, string $message): void
    {
        try {
            DB::transaction(fn () => $call());
            $this->fail('The service accepted what it must refuse.');
        } catch (ValidationException $exception) {
            $this->assertSame([$message], $exception->errors()[$field] ?? null);
        }
    }

    private function assertDatabaseRefuses(callable $statement, string $message): void
    {
        try {
            DB::transaction(fn () => $statement());
            $this->fail('PostgreSQL accepted a statement it must refuse.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}

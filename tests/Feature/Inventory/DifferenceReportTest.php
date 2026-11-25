<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Organisation\Inventory\Services\DifferenceReportService as Reports;
use App\Domain\Organisation\Inventory\Services\GovernedStockService;
use App\Domain\Organisation\Inventory\Services\InventoryBatchHoldService;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\GovernedStockFixture;

/** STK-45: Report a difference opens or joins a governed STK-5 count and never moves stock. */
class DifferenceReportTest extends VisitTestCase
{
    private function f(string $quantity = '10.000'): array
    {
        return (new GovernedStockFixture($this->branch))->build($quantity);
    }

    private function actorAt(string $role): User
    {
        $actor = (new GovernedStockFixture($this->branch))->actor($role);
        app(BranchAccessService::class)->select($actor, $this->branch);

        return $actor;
    }

    private function input(array $f, array $overrides = []): array
    {
        $balance = DB::table('inventory_stock_balances')->where('id', $f['balance']->id)->sole();

        return ['expected_branch_id' => $this->branch->id, 'retry_key' => (string) Str::uuid(), 'balance_id' => $balance->id, 'balance_version' => $balance->lock_version, 'observed' => 'fewer', ...$overrides];
    }

    private function command(int $version, ?string $quantity = null): array
    {
        return ['expected_branch_id' => $this->branch->id, 'lock_version' => $version, 'retry_key' => (string) Str::uuid(), ...($quantity !== null ? ['quantity' => $quantity, 'unit' => 'unit'] : [])];
    }

    /** @return array<string,int> */
    private function stockSnapshot(): array
    {
        return ['movements' => DB::table('stock_movements')->count(), 'balances' => (int) DB::table('inventory_stock_balances')->sum(DB::raw('lock_version'))];
    }

    public function test_exactly_the_approved_roles_hold_the_permission_and_the_medical_assistant_matches_the_ca(): void
    {
        $holders = collect(PermissionCatalogue::roles())->filter(fn ($p) => in_array(Reports::PERMISSION, $p, true))->keys()->sort()->values()->all();
        $this->assertSame(['branch_manager', 'ca', 'ca_supervisor', 'medical_assistant', 'store_admin'], $holders);
        foreach ($holders as $role) {
            $this->assertTrue(Role::findByName($role)->hasPermissionTo(Reports::PERMISSION), $role);
        }
        $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority(Reports::PERMISSION));
    }

    public function test_each_role_can_or_cannot_report_and_no_stock_moves(): void
    {
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $f = $this->f();
            $actor = $this->actorAt($role);
            $before = $this->stockSnapshot();
            $allowed = in_array($role, ['branch_manager', 'ca', 'ca_supervisor', 'medical_assistant', 'store_admin'], true);
            $this->actingAs($actor)->post(route('inventory.difference-reports.store'), [...$this->input($f), 'return_to' => 'inventory'])
                ->assertStatus($allowed ? 302 : 403);
            $this->assertSame($before['movements'], $this->stockSnapshot()['movements'], $role);
            $this->assertSame($allowed ? 1 : 0, DB::table('inventory_difference_reports')->where('reporter_user_id', $actor->id)->count(), $role);
        }
    }

    public function test_a_report_opens_one_blind_count_with_the_right_snapshot_and_a_second_report_joins_it(): void
    {
        $f = $this->f('10.000');
        $ca = $this->actorAt('ca');
        $before = $this->stockSnapshot();
        $first = app(Reports::class)->report($ca, $this->input($f, ['rough_quantity' => '6', 'note' => 'Box looks half empty']));
        $this->assertTrue($first['openedCase']);
        $case = DB::table('inventory_governed_cases')->where('id', $first['caseId'])->sole();
        $this->assertSame(['count', 'count_variance', '10.000', $ca->id], [$case->kind, $case->reason, (string) $case->expected_quantity, (int) $case->proposer_user_id]);
        $this->assertSame('awaiting_count', DB::table('inventory_governed_actions')->where('case_id', $case->id)->value('state'));
        $report = DB::table('inventory_difference_reports')->where('id', $first['reportId'])->sole();
        $this->assertSame(['fewer', '6.000', true], [$report->observed, (string) $report->rough_quantity, (bool) $report->opened_case]);
        $this->assertNotSame('Box looks half empty', $report->note_encrypted);

        $other = $this->actorAt('ca');
        $second = app(Reports::class)->report($other, $this->input($f, ['observed' => 'unsure']));
        $this->assertFalse($second['openedCase']);
        $this->assertSame($first['caseId'], $second['caseId']);
        $this->assertSame(1, DB::table('inventory_governed_cases')->where('balance_id', $f['balance']->id)->count());
        $this->assertSame($before, $this->stockSnapshot());

        $audit = DB::table('audit_logs')->where('event', 'inventory.difference_reported')->orderBy('id')->get();
        $this->assertCount(2, $audit);
        $text = json_encode($audit->pluck('metadata')->all());
        $this->assertStringNotContainsString('half empty', (string) $text);
        $this->assertStringNotContainsString('6.000', (string) $text);
    }

    public function test_a_report_never_joins_a_stale_count_and_opens_a_new_live_one(): void
    {
        $f = $this->f('10.000');
        $first = app(Reports::class)->report($this->actorAt('ca'), $this->input($f));
        // The stock changes after the snapshot: the first case goes stale (STK-5 can no longer count it).
        $this->stockChanged($f);
        $second = app(Reports::class)->report($this->actorAt('ca'), $this->input($f));
        $this->assertTrue($second['openedCase']);
        $this->assertNotSame($first['caseId'], $second['caseId']);
        $this->assertSame($second['caseId'], app(GovernedStockService::class)->newestLiveCountId($this->branch->organisation_id, $this->branch->id, $f['balance']->id));
    }

    public function test_the_reporter_never_counts_recounts_or_approves_and_a_counter_never_reports(): void
    {
        $f = $this->f('10.000');
        $supervisor = $this->actorAt('ca_supervisor');
        $result = app(Reports::class)->report($supervisor, $this->input($f));
        $service = app(GovernedStockService::class);
        try {
            $service->act($supervisor, $result['caseId'], 'counted', $this->command(1, '8.000'));
            $this->fail('A reporter counted.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $counter = $this->actorAt('ca_supervisor');
        $service->act($counter, $result['caseId'], 'counted', $this->command(1, '8.000'));
        try {
            $service->act($supervisor, $result['caseId'], 'recounted', $this->command(2, '8.000'));
            $this->fail('A reporter recounted.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        // The counter can't then report on the case they counted.
        try {
            app(Reports::class)->report($counter, $this->input($f));
            $this->fail('A counter reported.');
        } catch (ValidationException $e) {
            $this->assertSame(Reports::COUNTED_REFUSAL, $e->errors()['balance_id'][0]);
        }
        $service->act($this->actorAt('ca_supervisor'), $result['caseId'], 'recounted', $this->command(2, '8.000'));
        try {
            $service->act($supervisor, $result['caseId'], 'approved', $this->command(3));
            $this->fail('A reporter approved.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $service->act($this->actorAt('finance_officer'), $result['caseId'], 'approved', $this->command(3));
        $this->assertSame('posted', DB::table('inventory_governed_actions')->where('case_id', $result['caseId'])->orderByDesc('lock_version')->value('state'));
    }

    public function test_postgresql_refuses_a_reporters_count_and_a_report_on_a_closed_or_stale_case(): void
    {
        $f = $this->f('10.000');
        $ca = $this->actorAt('ca');
        $result = app(Reports::class)->report($ca, $this->input($f));
        $case = DB::table('inventory_governed_cases')->where('id', $result['caseId'])->sole();
        $row = fn (int $actorId, string $action, string $state, int $version): array => ['organisation_id' => $case->organisation_id, 'case_id' => $case->id, 'actor_user_id' => $actorId, 'action' => $action, 'state' => $state, 'quantity' => '8.000', 'movement_id' => null, 'lock_version' => $version, 'retry_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'created_at' => now()];
        $this->assertDatabaseRefuses(fn () => DB::table('inventory_governed_actions')->insert($row($ca->id, 'counted', 'awaiting_recount', 2)), 'Invalid independent count');
        // A recount by a reporter is refused too, after an honest first count.
        $counter = $this->actorAt('ca_supervisor');
        app(GovernedStockService::class)->act($counter, $case->id, 'counted', $this->command(1, '8.000'));
        $this->assertDatabaseRefuses(fn () => DB::table('inventory_governed_actions')->insert($row($ca->id, 'recounted', 'awaiting_approval', 3)), 'Invalid independent count');

        // Update, delete and truncate of reports are refused.
        $this->assertDatabaseRefuses(fn () => DB::table('inventory_difference_reports')->where('id', $result['reportId'])->update(['observed' => 'more']), 'append-only');
        $this->assertDatabaseRefuses(fn () => DB::table('inventory_difference_reports')->where('id', $result['reportId'])->delete(), 'append-only');

        // A direct insert can't attach a report to a stale case.
        $this->stockChanged($f);
        $other = $this->actorAt('ca');
        $this->assertDatabaseRefuses(fn () => DB::table('inventory_difference_reports')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $case->organisation_id, 'branch_id' => $case->branch_id, 'balance_id' => $case->balance_id, 'case_id' => $case->id, 'reporter_user_id' => $other->id, 'observed' => 'more', 'rough_quantity' => null, 'base_unit' => $case->base_unit, 'note_encrypted' => null, 'opened_case' => false, 'retry_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('b', 64), 'created_at' => now()]), 'open, live count');
    }

    public function test_an_ordinary_stk5_proposer_still_counts_and_stk5_propose_is_not_blocked_by_a_report(): void
    {
        $f = $this->f('10.000');
        app(Reports::class)->report($this->actorAt('ca'), $this->input($f));
        app(BranchAccessService::class)->select($f['proposer'], $this->branch);
        $balance = DB::table('inventory_stock_balances')->where('id', $f['balance']->id)->sole();
        $id = app(GovernedStockService::class)->propose($f['proposer'], 'count', ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'retry_key' => (string) Str::uuid(), 'balance_id' => $balance->id, 'balance_version' => $balance->lock_version, 'unit' => 'unit']);
        app(GovernedStockService::class)->act($f['proposer'], $id, 'counted', $this->command(1, '10.000'));
        $this->assertSame('closed_zero', DB::table('inventory_governed_actions')->where('case_id', $id)->orderByDesc('lock_version')->value('state'));
    }

    public function test_a_held_batch_can_still_be_reported(): void
    {
        $f = $this->f('10.000');
        app(InventoryBatchHoldService::class)->act($f['proposer'], $f['batch']->public_id, 'place', ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'retry_key' => (string) Str::uuid(), 'reason' => 'recall']);
        $result = app(Reports::class)->report($this->actorAt('ca'), $this->input($f, ['observed' => 'damaged_or_expired']));
        $this->assertTrue($result['openedCase']);
    }

    public function test_stale_page_retry_key_and_unreportable_stock(): void
    {
        $f = $this->f('10.000');
        $ca = $this->actorAt('ca');
        $input = $this->input($f);
        $first = app(Reports::class)->report($ca, $input);
        $this->assertSame($first, app(Reports::class)->report($ca, $input));
        try {
            app(Reports::class)->report($ca, [...$input, 'observed' => 'more']);
            $this->fail('A changed payload reused the key.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('retry_key', $e->errors());
        }
        try {
            app(Reports::class)->report($ca, $this->input($f, ['balance_version' => $input['balance_version'] + 5]));
            $this->fail('A stale page reported.');
        } catch (ValidationException $e) {
            $this->assertSame('Stock changed. Reload before reporting.', $e->errors()['balance_version'][0]);
        }
        try {
            app(Reports::class)->report($ca, $this->input($f, ['observed' => null]));
            $this->fail('No choice was accepted.');
        } catch (ValidationException $e) {
            $this->assertSame('Pick what you saw.', $e->errors()['observed'][0]);
        }
        try {
            app(Reports::class)->report($ca, $this->input($f, ['rough_quantity' => '-1']));
            $this->fail('A negative rough count was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('rough_quantity', $e->errors());
        }
        DB::table('inventory_skus')->where('id', $f['sku']->id)->update(['is_active' => false]);
        try {
            app(Reports::class)->report($ca, $this->input($f));
            $this->fail('A deactivated SKU was reported.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('deactivated', $e->errors()['balance_id'][0]);
        }
    }

    public function test_the_counter_never_sees_the_rough_count_or_note_and_the_approver_does(): void
    {
        $f = $this->f('10.000');
        $ca = $this->actorAt('ca');
        $result = app(Reports::class)->report($ca, $this->input($f, ['rough_quantity' => '4', 'note' => 'SECRET-NOTE']));
        $counter = $this->actorAt('ca_supervisor');
        $this->actingAs($counter)->get(route('inventory.governed-counts'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('stock.cases.rows.0.reports.0.observed', 'fewer')->missing('stock.cases.rows.0.reports.0.roughQuantity')->missing('stock.cases.rows.0.reports.0.note'));
        $this->assertStringNotContainsString('SECRET-NOTE', (string) $this->get(route('inventory.governed-counts'))->getContent());
        $service = app(GovernedStockService::class);
        $service->act($counter, $result['caseId'], 'counted', $this->command(1, '8.000'));
        $service->act($this->actorAt('ca_supervisor'), $result['caseId'], 'recounted', $this->command(2, '8.000'));
        $this->actingAs($this->actorAt('finance_officer'))->get(route('inventory.governed-counts'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('stock.cases.rows.0.reports.0.roughQuantity', '4.000')->where('stock.cases.rows.0.reports.0.note', 'SECRET-NOTE'));
        // The reporter's own Stock count page offers no count or approval.
        $this->actingAs($ca)->get(route('inventory.governed-counts'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('stock.cases.rows.0.canApprove', false));
    }

    public function test_inventory_rows_and_my_reports_show_the_state_and_low_stock_rows_offer_nothing(): void
    {
        $f = $this->f('10.000');
        $ca = $this->actorAt('ca');
        $this->actingAs($ca)->get(route('inventory.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('differences.canReport', true)->where('differences.myReports.total', 0)
            ->where('inventory.data.0.difference.balanceId', $f['balance']->id)->where('inventory.data.0.difference.openCount', false));
        app(Reports::class)->report($ca, $this->input($f));
        $this->get(route('inventory.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('inventory.data.0.difference.openCount', true)->where('inventory.data.0.difference.blocked', false)
            ->where('differences.myReports.total', 1)->where('differences.myReports.rows.0.state', 'awaiting_count'));
        // The stock changes: the report's case goes stale and My reports says so.
        $this->stockChanged($f);
        $this->get(route('inventory.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('differences.myReports.rows.0.state', 'stale')->where('inventory.data.0.difference.openCount', false));
        // A role without the permission gets no row data.
        $this->actingAs($this->actorAt('finance_officer'))->get(route('inventory.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('differences.canReport', false)->missing('inventory.data.0.difference'));
        // Stock review: Expiring rows are balance rows; Low stock rows never carry a report state.
        $this->actingAs($ca)->get(route('inventory.stock-review'))->assertOk()->assertInertia(function (AssertableInertia $page): void {
            $page->where('differences.canReport', true);
            foreach ($page->toArray()['props']['review']['lists']['low_stock']['rows'] as $row) {
                $this->assertArrayNotHasKey('difference', $row);
            }
        });
    }

    public function test_the_route_returns_to_the_page_it_came_from_with_the_toast(): void
    {
        $f = $this->f();
        $ca = $this->actorAt('ca');
        $this->actingAs($ca)->post(route('inventory.difference-reports.store'), [...$this->input($f), 'return_to' => 'stock-review'])
            ->assertRedirect(route('inventory.stock-review'))->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Sent. A stock count was opened for this batch.']);
        $this->post(route('inventory.difference-reports.store'), [...$this->input($f), 'return_to' => 'inventory', 'return_query' => ['tab' => 'stock', 'evil' => 'x']])
            ->assertRedirect(route('inventory.index', ['tab' => 'stock']))->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Sent. Added to the stock count already open for this batch.']);
    }

    /** Synthetic bootstrap: the stock changes after a snapshot (as any movement would), so open cases go stale. */
    private function stockChanged(array $f): void
    {
        DB::table('inventory_stock_balances')->where('id', $f['balance']->id)->update(['quantity' => DB::raw('quantity + 1'), 'lock_version' => DB::raw('lock_version + 1')]);
    }

    private function assertDatabaseRefuses(callable $write, string $message): void
    {
        try {
            DB::transaction(fn () => $write());
            $this->fail('The database accepted the write.');
        } catch (QueryException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }
}

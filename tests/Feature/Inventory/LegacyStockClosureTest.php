<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Organisation\Inventory\Services\InventoryControlService;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Inventory\Services\InventoryOperationsDirectoryService;
use App\Domain\Organisation\Inventory\Services\LegacyStockPath;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\AssertsLegacyStockRefusal;
use Tests\Support\GovernedStockFixture;
use Tests\Support\LegacyStockHistoryFixture as History;

class LegacyStockClosureTest extends VisitTestCase
{
    use AssertsLegacyStockRefusal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:00', 'Asia/Kuala_Lumpur'));
    }

    public function test_exact_message_all_catalogue_roles_and_eight_legacy_entrypoints_leave_stock_unchanged(): void
    {
        $this->assertSame('Legacy stocktake and manual adjustment are closed. Use Stock count (governed) or Write-off (governed) in Inventory; independent approval is required.', LegacyStockPath::MESSAGE);
        $f = (new GovernedStockFixture($this->branch))->build();
        $history = History::stocktake($f['proposer'], $f, 'review', '10.000', '9.000');
        $control = app(InventoryControlService::class);
        $ledger = app(InventoryMovementService::class);
        $payload = ['expected_branch_id' => $this->branch->id, 'lock_version' => $history->lock_version, 'location_public_id' => $f['location']->public_id, 'sku_public_id' => $f['sku']->public_id, 'batch_public_id' => $f['batch']->public_id, 'direction' => 'out', 'quantity' => '1.000', 'reason_code' => 'damage', 'idempotency_key' => (string) Str::uuid()];
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $actor = (new GovernedStockFixture($this->branch))->actor($role);
            app(BranchAccessService::class)->select($actor, $this->branch);
            $operations = [
                fn () => $control->createStocktake($actor, $payload),
                fn () => $control->startCounting($actor, $history, $payload),
                fn () => $control->recordCounts($actor, $history, $payload),
                fn () => $control->postStocktake($actor, $history, $payload),
                fn () => $control->cancelStocktake($actor, $history, $payload),
                fn () => $control->adjust($actor, $payload),
                fn () => $ledger->recordStocktakeVariance($actor, $f['location'], $f['sku'], $f['batch'], '0.000', $history->public_id),
                fn () => $ledger->recordAdjustment($actor, $f['location'], $f['sku'], $f['batch'], 'out', '1.000', (string) Str::uuid()),
            ];
            foreach ($operations as $operation) {
                if ($role === 'ca_supervisor') {
                    $this->expectLegacyRefusal($operation);
                } else {
                    $before = $this->legacyStockSnapshot();
                    try {
                        $operation();
                        $this->fail('An unprivileged legacy domain call succeeded: '.$role);
                    } catch (AuthorizationException) {
                        $this->assertSame($before, $this->legacyStockSnapshot());
                    }
                }
            }
            $this->actingAs($actor);
            $routes = [['inventory.stocktakes.store', []], ['inventory.stocktakes.start', ['stocktake' => $history]], ['inventory.stocktakes.count', ['stocktake' => $history]], ['inventory.stocktakes.post', ['stocktake' => $history]], ['inventory.stocktakes.cancel', ['stocktake' => $history]], ['inventory.adjustments.store', []]];
            foreach ($routes as [$name, $parameters]) {
                $before = $this->legacyStockSnapshot();
                $response = $this->post(route($name, $parameters), $payload);
                if ($role === 'ca_supervisor') {
                    $response->assertRedirect(route('inventory.index'))->assertSessionHasErrors(['legacy_stock_path' => LegacyStockPath::MESSAGE]);
                } else {
                    $response->assertForbidden();
                }
                $after = $this->legacyStockSnapshot();
                if ($role !== 'ca_supervisor') {
                    $this->assertSame(count(json_decode($before['audit_logs'], true)) + 1, AuditLog::query()->count());
                    $this->assertSame('authorization.denied', AuditLog::query()->orderByDesc('id')->firstOrFail()->event);
                    unset($before['audit_logs'], $after['audit_logs']);
                }
                $this->assertSame($before, $after);
            }
        }
    }

    public function test_fresh_revocation_inactive_ended_assignments_and_same_or_conflicting_retries_stay_closed(): void
    {
        $f = (new GovernedStockFixture($this->branch))->build();
        $actor = $f['proposer'];
        $control = app(InventoryControlService::class);
        $old = History::adjustment($actor, $f, 'in', '1.000');
        $payload = ['expected_branch_id' => $this->branch->id, 'location_public_id' => $f['location']->public_id, 'sku_public_id' => $f['sku']->public_id, 'batch_public_id' => $f['batch']->public_id, 'direction' => 'in', 'quantity' => '1.000', 'reason_code' => 'correction', 'idempotency_key' => $old->idempotency_key];
        foreach ([$payload, $payload, [...$payload, 'quantity' => '999.000'], [...$payload, 'quantity' => 'invalid', 'reason_code' => '', 'location_public_id' => 'invalid']] as $retry) {
            $this->expectLegacyRefusal(fn () => $control->adjust($actor, $retry));
        }
        $role = Role::findByName('ca_supervisor');
        $role->revokePermissionTo(InventoryControlService::ADJUST_PERMISSION);
        $before = $this->legacyStockSnapshot();
        try {
            $control->adjust($actor, $payload);
            $this->fail('Permission revocation was cached away.');
        } catch (AuthorizationException) {
            $this->assertSame($before, $this->legacyStockSnapshot());
        }
        $role->givePermissionTo(InventoryControlService::ADJUST_PERMISSION);
        $actor->forceFill(['is_active' => false])->save();
        $before = $this->legacyStockSnapshot();
        try {
            $control->adjust($actor, $payload);
            $this->fail('Inactive actor reached legacy mutation.');
        } catch (AuthorizationException) {
            $this->assertSame($before, $this->legacyStockSnapshot());
        }
        $actor->forceFill(['is_active' => true])->save();
        StaffBranchAssignment::query()->where('staff_profile_id', $actor->staffProfile->id)->update(['valid_until' => '2026-10-28']);
        $before = $this->legacyStockSnapshot();
        try {
            $control->adjust($actor, $payload);
            $this->fail('Ended assignment reached legacy mutation.');
        } catch (AuthorizationException) {
            $this->assertSame($before, $this->legacyStockSnapshot());
        }
    }

    public function test_foreign_branch_and_organisation_legacy_history_remain_scoped(): void
    {
        $f = (new GovernedStockFixture($this->branch))->build();
        $history = History::stocktake($f['proposer'], $f);
        $other = Branch::query()->where('organisation_id', $this->branch->organisation_id)->where('code', '!=', $this->branch->code)->orderBy('id')->firstOrFail();
        $organisation = Organisation::query()->create(['code' => 'SYN-STK6A', 'name' => 'Synthetic other stock organisation', 'is_active' => true]);
        $foreign = new Branch;
        $foreign->forceFill(['organisation_id' => $organisation->id, 'code' => 'SYN6A', 'name' => 'Synthetic other stock branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        foreach ([$other, $foreign] as $branch) {
            $actor = (new GovernedStockFixture($branch))->actor('ca_supervisor');
            app(BranchAccessService::class)->select($actor, $branch);
            $before = $this->legacyStockSnapshot();
            $this->actingAs($actor)->postJson(route('inventory.stocktakes.post', ['stocktake' => $history]), ['expected_branch_id' => $branch->id, 'lock_version' => $history->lock_version])->assertNotFound()->assertDontSee($history->stocktake_number);
            $this->assertSame($before, $this->legacyStockSnapshot());
        }
    }

    public function test_history_report_numbers_and_stock_rows_are_identical_after_json_refusals(): void
    {
        $f = (new GovernedStockFixture($this->branch))->build();
        $actor = $f['proposer'];
        $history = History::stocktake($actor, $f, 'posted', '10.000', '12.000');
        History::movement($actor, $f, 'stocktake_gain', '2.000', $history->public_id);
        History::adjustment($actor, $f, 'out', '1.000', 'damage');
        $finance = (new GovernedStockFixture($this->branch))->actor('finance_officer');
        $filters = ['expected_branch_id' => $this->branch->id, 'from' => '2026-10-29', 'to' => '2026-10-29'];
        app(BranchAccessService::class)->select($finance, $this->branch);
        $beforeReport = $this->actingAs($finance)->postJson(route('reports.stock-movement.run'), $filters)->assertOk()->json('result');
        app(BranchAccessService::class)->select($actor, $this->branch);
        $directory = app(InventoryOperationsDirectoryService::class);
        $beforeHistory = $directory->directory($actor);
        $this->assertFalse($beforeHistory['permissions']['stocktake']);
        $this->assertFalse($beforeHistory['permissions']['adjust']);
        $before = $this->legacyStockSnapshot();
        $this->actingAs($actor)->postJson(route('inventory.stocktakes.post', ['stocktake' => $history]), ['expected_branch_id' => $this->branch->id, 'lock_version' => $history->lock_version])->assertUnprocessable()->assertJsonPath('errors.legacy_stock_path.0', LegacyStockPath::MESSAGE);
        $this->assertSame($before, $this->legacyStockSnapshot());
        $this->assertSame($beforeHistory, $directory->directory($actor));
        app(BranchAccessService::class)->select($finance, $this->branch);
        $afterReport = $this->actingAs($finance)->postJson(route('reports.stock-movement.run'), $filters)->assertOk()->json('result');
        $this->assertSame($beforeReport['rows'], $afterReport['rows']);
        $this->assertSame($beforeReport['totals'], $afterReport['totals']);
        $this->assertSame('11.000', DB::table('inventory_stock_balances')->where('id', $f['balance']->id)->value('quantity'));
    }
}

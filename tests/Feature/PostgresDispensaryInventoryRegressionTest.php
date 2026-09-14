<?php

namespace Tests\Feature;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryHandoff;
use App\Domain\Clinical\Dispensary\Models\DispensaryItemException;
use App\Domain\Clinical\Dispensary\Services\DispensaryHandoffService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Models\TreatmentPlan;
use App\Domain\Clinical\Services\ClinicalEncounterService;
use App\Domain\Clinical\Services\PatientAllergyService;
use App\Domain\Clinical\Services\TreatmentPlanService;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Inventory\Models\GoodsReceipt;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryItem;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\InventoryStockBalance;
use App\Domain\Organisation\Inventory\Models\InventorySupplier;
use App\Domain\Organisation\Inventory\Models\MedicineCatalogueInventorySku;
use App\Domain\Organisation\Inventory\Models\PurchaseOrder;
use App\Domain\Organisation\Inventory\Models\PurchaseOrderLine;
use App\Domain\Organisation\Inventory\Models\StockRequest;
use App\Domain\Organisation\Inventory\Models\StockRequestLine;
use App\Domain\Organisation\Inventory\Services\InventoryControlService;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Inventory\Services\StockRequestService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Patient\Models\Patient;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDOException;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\StaffBranchAssignmentBootstrapper;
use Tests\TestCase;

/** @phpstan-type Worker array{process: Process, input: InputStream} */
class PostgresDispensaryInventoryRegressionTest extends TestCase
{
    private const OBSERVER = 'pgsql_dispensary_inventory_observer';

    private const INVENTORY_OPERATIONS_MIGRATION = '2026_09_11_000100_create_inventory_operations';

    private const INVENTORY_OPERATIONS_TABLES = [
        'inventory_reorder_levels',
        'inventory_adjustments',
        'inventory_stocktake_lines',
        'inventory_stocktakes',
        'inventory_stock_request_lines',
        'inventory_stock_requests',
        'inventory_goods_receipt_lines',
        'inventory_goods_receipts',
        'inventory_purchase_order_lines',
        'inventory_purchase_orders',
        'inventory_suppliers',
    ];

    /** @var list<int> */
    private array $organisationIds = [];

    /** @var list<Process> */
    private array $workers = [];

    /** @var list<InputStream> */
    private array $inputs = [];

    private int $sequence = 1;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL Phase 3A regressions require DB_CONNECTION=pgsql.');
        }
        $database = (string) DB::connection()->getDatabaseName();
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', $database) !== 1) {
            throw new RuntimeException('Phase 3A concurrency tests require an isolated PostgreSQL test database.');
        }
        foreach (['dispensary_cases', 'dispensary_items', 'inventory_stock_balances', 'stock_movements'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Migrate the isolated PostgreSQL test database before Phase 3A regressions.');
            }
        }
        Config::set('database.connections.'.self::OBSERVER, config('database.connections.'.config('database.default')));
        DB::purge(self::OBSERVER);
    }

    protected function tearDown(): void
    {
        foreach ($this->inputs as $input) {
            if (! $input->isClosed()) {
                $input->close();
            }
        }
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
        foreach ($this->organisationIds as $id) {
            $this->deleteOrganisation($id);
        }
        DB::purge(self::OBSERVER);
        parent::tearDown();
    }

    public function test_double_send_creates_one_handoff_case_queue_transition_and_audit(): void
    {
        $f = $this->fixture();
        $args = ['send', (string) $f['doctor']->id, (string) $f['branch']->id, $f['visit']->visit_number, (string) $f['plan']->lock_version];
        $output = $this->race([$this->worker($args), $this->worker($args)], $f['patient']);
        $this->assertSame(1, substr_count($output, 'SENT'));
        $this->assertSame(1, substr_count($output, 'STALE') + substr_count($output, 'DENIED'));
        $this->assertSame(1, DispensaryCase::query()->where('treatment_plan_id', $f['plan']->id)->count());
        $this->assertSame(1, DispensaryHandoff::query()->where('organisation_id', $f['organisation']->id)->where('status', 'open')->count());
        $this->assertSame(1, AuditLog::query()->where('organisation_id', $f['organisation']->id)->where('event', 'treatment_plan.sent_to_dispensary')->count());
    }

    public function test_send_and_plan_edit_serialize_without_lost_update(): void
    {
        $f = $this->fixture();
        $workers = [
            $this->worker(['send', (string) $f['doctor']->id, (string) $f['branch']->id, $f['visit']->visit_number, '1']),
            $this->worker(['plan-edit', (string) $f['doctor']->id, (string) $f['branch']->id, $f['visit']->visit_number, (string) $f['plan']->id, '1']),
        ];
        $output = $this->race($workers, $f['visit']);
        $this->assertSame(1, substr_count($output, 'SENT') + substr_count($output, 'PLAN_SAVED'));
        $this->assertSame(1, substr_count($output, 'STALE') + substr_count($output, 'DENIED'));
        $this->assertContains($f['plan']->refresh()->status, [TreatmentPlan::STATUS_IN_PROGRESS, TreatmentPlan::STATUS_READY_FOR_DISPENSING]);
    }

    public function test_two_cas_start_one_case_once(): void
    {
        $f = $this->sentFixture();
        $workers = [
            $this->worker(['start', (string) $f['ca']->id, (string) $f['branch']->id, $f['case']->public_id, '1']),
            $this->worker(['start', (string) $f['ca2']->id, (string) $f['branch']->id, $f['case']->public_id, '1']),
        ];
        $output = $this->race($workers, $f['patient']);
        $this->assertSame(1, substr_count($output, 'STARTD'));
        $this->assertSame(1, substr_count($output, 'STALE'));
        $this->assertNotNull($f['case']->refresh()->current_handler_user_id);
        $this->assertSame(1, AuditLog::query()->where('organisation_id', $f['organisation']->id)->where('event', 'dispensary.started')->count());
    }

    public function test_concurrent_item_edits_have_one_versioned_winner(): void
    {
        $f = $this->startedFixture();
        $args = ['update-item', (string) $f['ca']->id, (string) $f['branch']->id, $f['case']->public_id, (string) $f['case']->lock_version, $f['item']->public_id, (string) $f['item']->lock_version, 'not_dispensed', '0.000', 'patient_declined'];
        $output = $this->race([$this->worker($args), $this->worker($args)], $f['patient']);
        $this->assertSame(1, substr_count($output, 'ITEM_UPDATED'));
        $this->assertSame(1, substr_count($output, 'STALE'));
        $this->assertSame(2, $f['item']->refresh()->lock_version);
    }

    public function test_duplicate_complete_commits_once_without_duplicate_movement(): void
    {
        $f = $this->completableFixture('10.000', '4.000');
        $args = ['complete', (string) $f['ca']->id, (string) $f['branch']->id, $f['case']->public_id, (string) $f['case']->lock_version];
        $output = $this->race([$this->worker($args), $this->worker($args)], $f['patient']);
        $this->assertSame(1, substr_count($output, 'COMPLETED'));
        $this->assertSame(1, substr_count($output, 'STALE'));
        $this->assertSame(1, DB::table('stock_movements')->where('organisation_id', $f['organisation']->id)->where('movement_type', 'dispense')->count());
        $this->assertSame('6.000', (string) DB::table('inventory_stock_balances')->where('inventory_location_id', $f['location']->id)->value('quantity'));

        $movement = DB::table('stock_movements')->where('organisation_id', $f['organisation']->id)->where('movement_type', 'dispense')->sole();
        try {
            DB::transaction(fn () => DB::table('stock_movements')->where('id', $movement->id)->update(['quantity' => '3.000']));
            $this->fail('Deferred reconciliation accepted movement quantity that differed from its allocation.');
        } catch (QueryException|PDOException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
        $this->assertSame('4.000', (string) DB::table('stock_movements')->where('id', $movement->id)->value('quantity'));
    }

    public function test_two_cases_consuming_last_stock_never_make_balance_negative(): void
    {
        $f = $this->completableFixture('10.000', '8.000');
        $second = $this->additionalCompletableCase($f, '8.000');
        $workers = [
            $this->worker(['complete', (string) $f['ca']->id, (string) $f['branch']->id, $f['case']->public_id, (string) $f['case']->lock_version]),
            $this->worker(['complete', (string) $f['ca2']->id, (string) $f['branch']->id, $second['case']->public_id, (string) $second['case']->lock_version]),
        ];
        $output = $this->race($workers, $f['balance']);
        $this->assertSame(1, substr_count($output, 'COMPLETED'));
        $this->assertSame(1, substr_count($output, 'STALE'));
        $this->assertGreaterThanOrEqual(0, (float) $f['balance']->refresh()->quantity);
        $this->assertSame(8.0, (float) DB::table('stock_movements')->where('organisation_id', $f['organisation']->id)->where('movement_type', 'dispense')->sum('quantity'));
    }

    public function test_transfer_and_dispense_from_same_balance_serialize_and_reconcile(): void
    {
        $f = $this->completableFixture('10.000', '7.000');
        $workers = [
            $this->worker(['complete', (string) $f['ca']->id, (string) $f['branch']->id, $f['case']->public_id, (string) $f['case']->lock_version]),
            $this->worker(['transfer', (string) $f['ca2']->id, (string) $f['branch']->id, $f['location']->public_id, $f['destination']->public_id, $f['sku']->public_id, $f['batch']->public_id, '5.000']),
        ];
        $output = $this->race($workers, $f['balance']);
        $this->assertSame(1, substr_count($output, 'COMPLETED') + substr_count($output, 'TRANSFERRED'));
        $this->assertSame(1, substr_count($output, 'STALE'));
        $this->assertGreaterThanOrEqual(0, (float) $f['balance']->refresh()->quantity);
    }

    public function test_approved_stock_request_revalidates_branch_owned_source_before_postgres_debit(): void
    {
        $fixture = $this->completableFixture('10.000', '4.000');
        $requester = $fixture['ca'];
        $actor = $fixture['inventorySupervisor'];
        $requester->givePermissionTo(Permission::findOrCreate(StockRequestService::CREATE_PERMISSION, 'web'));
        $actor->givePermissionTo(Permission::findOrCreate(StockRequestService::APPROVE_PERMISSION, 'web'));

        $requester = $requester->refresh();
        $actor = $actor->refresh();
        $requesterProfile = StaffProfile::query()->where('user_id', $requester->id)->sole();
        $actorProfile = StaffProfile::query()->where('user_id', $actor->id)->sole();
        $requesterBranchIds = StaffBranchAssignment::query()->where('staff_profile_id', $requesterProfile->id)->effectiveAt()->orderBy('branch_id')->pluck('branch_id')->map(fn ($id): int => (int) $id)->all();
        $actorBranchIds = StaffBranchAssignment::query()->where('staff_profile_id', $actorProfile->id)->effectiveAt()->orderBy('branch_id')->pluck('branch_id')->map(fn ($id): int => (int) $id)->all();

        $this->assertTrue($requester->is_active);
        $this->assertTrue($actor->is_active);
        $this->assertSame($fixture['organisation']->id, $requester->organisation_id);
        $this->assertSame($fixture['organisation']->id, $actor->organisation_id);
        $this->assertContains($fixture['branch']->id, $requesterBranchIds);
        $this->assertSame([$fixture['branch']->id], $actorBranchIds);
        $this->assertTrue($requester->can(StockRequestService::CREATE_PERMISSION));
        $this->assertFalse($requester->can(StockRequestService::APPROVE_PERMISSION));
        $this->assertFalse($requester->can(StockRequestService::DISPATCH_PERMISSION));
        $this->assertFalse($requester->can(StockRequestService::WAREHOUSE_PERMISSION));
        $this->assertTrue($actor->can(StockRequestService::APPROVE_PERMISSION));
        $this->assertTrue($actor->can(StockRequestService::DISPATCH_PERMISSION));

        $service = app(StockRequestService::class);
        $request = $service->create($requester, [
            'expected_branch_id' => $fixture['branch']->id,
            'source_location_public_id' => $fixture['location']->public_id,
            'destination_location_public_id' => $fixture['destination']->public_id,
            'lines' => [['sku_public_id' => $fixture['sku']->public_id, 'quantity' => '1.000']],
        ]);
        $requestPublicId = (string) $request->public_id;
        $this->assertTrue(Str::isUuid($requestPublicId));
        $this->assertDatabaseHas('inventory_stock_requests', ['id' => $request->id, 'public_id' => $requestPublicId]);
        $this->assertSame(StockRequest::STATUS_REQUESTED, $request->status);
        $this->assertSame($requester->id, $request->requested_by_user_id);
        $this->assertSame($fixture['location']->id, $request->source_location_id);
        $this->assertSame($fixture['destination']->id, $request->destination_location_id);

        $request = $service->approve($actor, $request, [
            'expected_branch_id' => $fixture['branch']->id,
            'lock_version' => $request->lock_version,
        ]);
        $this->assertSame(StockRequest::STATUS_APPROVED, $request->status);
        $this->assertSame($actor->id, $request->decided_by_user_id);
        $this->assertSame($fixture['location']->id, $request->source_location_id);
        $this->assertSame($fixture['destination']->id, $request->destination_location_id);

        $otherBranch = new Branch;
        $otherBranch->forceFill([
            'organisation_id' => $fixture['organisation']->id,
            'code' => 'PG-SOURCE-'.Str::upper(Str::random(6)),
            'name' => 'Synthetic PostgreSQL Foreign Source Branch',
            'timezone' => 'Asia/Kuala_Lumpur',
            'is_active' => true,
        ])->save();
        $otherSource = new InventoryLocation;
        $otherSource->forceFill([
            'public_id' => (string) Str::uuid(),
            'organisation_id' => $fixture['organisation']->id,
            'branch_id' => $otherBranch->id,
            'parent_id' => null,
            'code' => 'PG-OTHER-SOURCE-'.Str::upper(Str::random(6)),
            'name' => 'Synthetic PostgreSQL Other Branch Store',
            'type' => InventoryLocation::TYPE_BRANCH_STORE,
            'is_active' => true,
        ])->save();
        $this->assertTrue($otherBranch->is_active);
        $this->assertTrue($otherSource->is_active);
        $this->assertSame($fixture['organisation']->id, $otherSource->organisation_id);
        $this->assertSame($otherBranch->id, $otherSource->branch_id);
        $this->assertNotSame($fixture['branch']->id, $otherSource->branch_id);
        $this->assertSame(InventoryLocation::TYPE_BRANCH_STORE, $otherSource->type);

        $this->assertSame(1, DB::table('inventory_stock_requests')->where('id', $request->id)->update(['source_location_id' => $otherSource->id]));
        $request = $request->refresh();
        $this->assertSame($requestPublicId, $request->public_id);
        $this->assertSame(StockRequest::STATUS_APPROVED, $request->status);
        $this->assertSame($otherSource->id, $request->source_location_id);
        $actorBranchIds = StaffBranchAssignment::query()->where('staff_profile_id', $actorProfile->id)->effectiveAt()->orderBy('branch_id')->pluck('branch_id')->map(fn ($id): int => (int) $id)->all();
        $this->assertSame([$fixture['branch']->id], $actorBranchIds);
        $this->assertNotContains($otherBranch->id, $actorBranchIds);
        $this->assertTrue($actor->can(StockRequestService::APPROVE_PERMISSION));
        $this->assertTrue($actor->can(StockRequestService::DISPATCH_PERMISSION));

        $line = $request->lines()->sole();
        $sku = $fixture['sku']->refresh();
        $batch = $fixture['batch']->refresh();
        $destination = $fixture['destination']->refresh();
        $item = InventoryItem::query()->whereKey($sku->inventory_item_id)->sole();
        $branchDate = now()->setTimezone($fixture['branch']->timezone)->toDateString();
        $this->assertSame($sku->id, $line->inventory_sku_id);
        $this->assertSame('1.000', $line->requested_quantity);
        $this->assertTrue($item->is_active);
        $this->assertTrue($sku->is_active);
        $this->assertSame($fixture['organisation']->id, $sku->organisation_id);
        $this->assertSame(InventoryBatch::STATUS_AVAILABLE, $batch->status);
        $this->assertSame($fixture['organisation']->id, $batch->organisation_id);
        $this->assertSame($sku->id, $batch->inventory_sku_id);
        $this->assertGreaterThan($branchDate, $batch->expiry_date->toDateString());
        $this->assertTrue($destination->is_active);
        $this->assertSame($fixture['organisation']->id, $destination->organisation_id);
        $this->assertSame($fixture['branch']->id, $destination->branch_id);
        $this->assertSame(0, DB::table('inventory_stock_balances')
            ->where('organisation_id', $fixture['organisation']->id)
            ->where('inventory_location_id', $otherSource->id)
            ->where('inventory_sku_id', $line->inventory_sku_id)
            ->where('inventory_batch_id', $batch->id)
            ->count());

        $balanceTimestamp = now()->utc();
        // Synthetic positive-control state: dispatch-time source authority, not opening-balance authority, is under test.
        DB::table('inventory_stock_balances')->insert([
            'organisation_id' => $fixture['organisation']->id,
            'inventory_location_id' => $otherSource->id,
            'inventory_sku_id' => $line->inventory_sku_id,
            'inventory_batch_id' => $batch->id,
            'quantity' => '2.000',
            'lock_version' => 1,
            'created_at' => $balanceTimestamp,
            'updated_at' => $balanceTimestamp,
        ]);
        $craftedSourceBalanceBefore = InventoryStockBalance::query()
            ->where('organisation_id', $fixture['organisation']->id)
            ->where('inventory_location_id', $otherSource->id)
            ->where('inventory_sku_id', $line->inventory_sku_id)
            ->where('inventory_batch_id', $batch->id)
            ->sole();
        $craftedSourceBalanceId = $craftedSourceBalanceBefore->id;
        $craftedSourceBalanceQuantityBefore = (string) $craftedSourceBalanceBefore->quantity;
        $craftedSourceBalanceLockVersionBefore = $craftedSourceBalanceBefore->lock_version;
        $this->assertSame('2.000', $craftedSourceBalanceQuantityBefore);
        $this->assertSame(1, $craftedSourceBalanceLockVersionBefore);
        $this->assertTrue(DB::table('inventory_stock_balances')
            ->where('id', $craftedSourceBalanceId)
            ->where('quantity', '>=', $line->requested_quantity)
            ->exists());
        $dispatchIdempotencyKey = (string) Str::uuid();
        $this->assertTrue(Str::isUuid($dispatchIdempotencyKey));

        $lineStateBefore = [$line->inventory_batch_id, $line->dispatched_quantity, $line->received_quantity];
        $statusBefore = $request->status;
        $lockVersionBefore = $request->lock_version;
        $originalSourceBalanceBefore = $fixture['balance']->refresh()->quantity;
        $destinationBalanceBefore = DB::table('inventory_stock_balances')
            ->where('organisation_id', $fixture['organisation']->id)
            ->where('inventory_location_id', $destination->id)
            ->where('inventory_sku_id', $sku->id)
            ->where('inventory_batch_id', $batch->id)
            ->first(['id', 'quantity', 'lock_version']);
        $movementCount = DB::table('stock_movements')->where('organisation_id', $fixture['organisation']->id)->count();
        $dispatchMovementCount = DB::table('stock_movements')->where('organisation_id', $fixture['organisation']->id)->where('movement_type', 'transfer_dispatch')->count();
        $receiptMovementCount = DB::table('stock_movements')->where('organisation_id', $fixture['organisation']->id)->where('movement_type', 'transfer_receipt')->count();
        $goodsReceiptCount = GoodsReceipt::query()->where('organisation_id', $fixture['organisation']->id)->count();
        $auditCount = AuditLog::query()->where('organisation_id', $fixture['organisation']->id)->count();
        $dispatchAuditCount = AuditLog::query()
            ->where('organisation_id', $fixture['organisation']->id)
            ->where('event', 'inventory.stock_request.dispatched')
            ->where('subject_type', $request->getMorphClass())
            ->where('subject_id', $request->id)
            ->count();

        try {
            $service->dispatch($actor, $request, [
                'expected_branch_id' => $fixture['branch']->id,
                'lock_version' => $request->lock_version,
                'dispatch_idempotency_key' => $dispatchIdempotencyKey,
                'lines' => [[
                    'line_public_id' => $line->public_id,
                    'batch_public_id' => $batch->public_id,
                    'quantity' => '1.000',
                ]],
            ]);
            $this->fail('A PostgreSQL stock debit used a branch-owned source outside the actor assignment.');
        } catch (NotFoundHttpException $exception) {
            $this->assertSame(NotFoundHttpException::class, $exception::class);
            $this->assertSame(404, $exception->getStatusCode());
            $this->assertNotSame('You may not perform this Inventory operation.', $exception->getMessage());
            $this->assertTrue(collect($exception->getTrace())->contains(
                fn (array $frame): bool => ($frame['class'] ?? null) === StockRequestService::class
                    && ($frame['function'] ?? null) === 'assertSourceAuthority',
            ));
        }

        $request = $request->refresh();
        $this->assertSame($statusBefore, $request->status);
        $this->assertSame(StockRequest::STATUS_APPROVED, $request->status);
        $this->assertSame($lockVersionBefore, $request->lock_version);
        $this->assertSame($otherSource->id, $request->source_location_id);
        $line = $line->refresh();
        $this->assertSame($lineStateBefore, [$line->inventory_batch_id, $line->dispatched_quantity, $line->received_quantity]);
        $this->assertSame($originalSourceBalanceBefore, $fixture['balance']->refresh()->quantity);
        $craftedSourceBalanceAfter = InventoryStockBalance::query()
            ->where('organisation_id', $fixture['organisation']->id)
            ->where('inventory_location_id', $otherSource->id)
            ->where('inventory_sku_id', $line->inventory_sku_id)
            ->where('inventory_batch_id', $batch->id)
            ->sole();
        $this->assertSame($craftedSourceBalanceId, $craftedSourceBalanceAfter->id);
        $this->assertSame($craftedSourceBalanceQuantityBefore, (string) $craftedSourceBalanceAfter->quantity);
        $this->assertSame($craftedSourceBalanceLockVersionBefore, $craftedSourceBalanceAfter->lock_version);
        $this->assertSame(1, InventoryStockBalance::query()
            ->where('organisation_id', $fixture['organisation']->id)
            ->where('inventory_location_id', $otherSource->id)
            ->where('inventory_sku_id', $line->inventory_sku_id)
            ->where('inventory_batch_id', $batch->id)
            ->count());
        $this->assertEquals($destinationBalanceBefore, DB::table('inventory_stock_balances')
            ->where('organisation_id', $fixture['organisation']->id)
            ->where('inventory_location_id', $destination->id)
            ->where('inventory_sku_id', $sku->id)
            ->where('inventory_batch_id', $batch->id)
            ->first(['id', 'quantity', 'lock_version']));
        $this->assertSame($movementCount, DB::table('stock_movements')->where('organisation_id', $fixture['organisation']->id)->count());
        $this->assertSame($dispatchMovementCount, DB::table('stock_movements')->where('organisation_id', $fixture['organisation']->id)->where('movement_type', 'transfer_dispatch')->count());
        $this->assertSame($receiptMovementCount, DB::table('stock_movements')->where('organisation_id', $fixture['organisation']->id)->where('movement_type', 'transfer_receipt')->count());
        $this->assertSame($goodsReceiptCount, GoodsReceipt::query()->where('organisation_id', $fixture['organisation']->id)->count());
        $this->assertSame($auditCount, AuditLog::query()->where('organisation_id', $fixture['organisation']->id)->count());
        $this->assertSame($dispatchAuditCount, AuditLog::query()
            ->where('organisation_id', $fixture['organisation']->id)
            ->where('event', 'inventory.stock_request.dispatched')
            ->where('subject_type', $request->getMorphClass())
            ->where('subject_id', $request->id)
            ->count());
    }

    public function test_allergy_mutation_winning_before_complete_rejects_stock_movement(): void
    {
        $f = $this->completableFixture('10.000', '4.000');
        $leader = $this->worker(['allergy-mutate', (string) $f['profile']->id]);
        $follower = $this->worker(['complete', (string) $f['ca']->id, (string) $f['branch']->id, $f['case']->public_id, (string) $f['case']->lock_version]);
        $output = $this->leaderThenFollower($leader, $follower);
        $this->assertStringContainsString('STALE', $output);
        $this->assertDatabaseMissing('stock_movements', ['organisation_id' => $f['organisation']->id, 'movement_type' => 'dispense']);
    }

    public function test_plan_version_change_winning_before_complete_rejects_handoff(): void
    {
        $f = $this->completableFixture('10.000', '4.000');
        $leader = $this->worker(['plan-version', (string) $f['plan']->id]);
        $follower = $this->worker(['complete', (string) $f['ca']->id, (string) $f['branch']->id, $f['case']->public_id, (string) $f['case']->lock_version]);
        $this->assertStringContainsString('STALE', $this->leaderThenFollower($leader, $follower));
        $this->assertDatabaseMissing('stock_movements', ['organisation_id' => $f['organisation']->id, 'movement_type' => 'dispense']);
    }

    public function test_return_and_complete_have_exactly_one_state_winner(): void
    {
        $f = $this->zeroCompletableFixture();
        $workers = [
            $this->worker(['complete', (string) $f['ca']->id, (string) $f['branch']->id, $f['case']->public_id, (string) $f['case']->lock_version]),
            $this->worker(['return', (string) $f['ca']->id, (string) $f['branch']->id, $f['case']->public_id, (string) $f['case']->lock_version]),
        ];
        $output = $this->race($workers, $f['patient']);
        $this->assertSame(1, substr_count($output, 'COMPLETED') + substr_count($output, 'RETURND'));
        $this->assertSame(1, substr_count($output, 'STALE'));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_acknowledgement_and_proposal_change_never_cross_authorize_quantities(): void
    {
        $f = $this->patientDeclinedFixture();
        $workers = [
            $this->worker(['acknowledge', (string) $f['doctor']->id, (string) $f['branch']->id, $f['exception']->public_id, (string) $f['case']->lock_version, (string) $f['item']->lock_version]),
            $this->worker(['proposal', (string) $f['ca']->id, (string) $f['branch']->id, $f['case']->public_id, (string) $f['case']->lock_version, $f['item']->public_id, (string) $f['item']->lock_version, 'partial', '0.500', 'patient_declined']),
        ];
        $output = $this->race($workers, $f['visit']);
        $this->assertSame(1, substr_count($output, 'ITEM_UPDATED'));
        $this->assertSame(1, substr_count($output, 'ACKNOWLEDGED') + substr_count($output, 'STALE'));
        $item = $f['item']->refresh();
        $ack = $item->exceptions()->where('status', DispensaryItemException::STATUS_ACKNOWLEDGED)->latest('id')->first();
        $this->assertTrue(! $ack || $ack->proposed_quantity_dispensed === $item->quantity_dispensed);
    }

    public function test_ca_deactivation_race_fails_closed(): void
    {
        $this->assertAuthorityLossFailsClosed('deactivate');
    }

    public function test_exact_permission_loss_race_fails_closed(): void
    {
        $this->assertAuthorityLossFailsClosed('revoke');
    }

    public function test_branch_assignment_loss_race_fails_closed(): void
    {
        $this->assertAuthorityLossFailsClosed('end-assignment');
    }

    public function test_visit_queue_and_encounter_state_loss_races_fail_closed(): void
    {
        foreach (['visit', 'queue', 'encounter'] as $kind) {
            $f = $this->zeroCompletableFixture();
            $leader = $this->worker(['state-loss', $f['visit']->visit_number, $kind, (string) $f['operator']->id]);
            $follower = $this->worker(['complete', (string) $f['ca']->id, (string) $f['branch']->id, $f['case']->public_id, (string) $f['case']->lock_version]);
            $this->assertStringContainsString('STALE', $this->leaderThenFollower($leader, $follower), $kind);
            $this->assertNotSame(DispensaryCase::STATUS_COMPLETED, $f['case']->refresh()->status);
        }
    }

    public function test_late_audit_failure_rolls_back_completion_balances_movements_and_case(): void
    {
        $f = $this->completableFixture('10.000', '4.000');
        $before = [$f['balance']->quantity, $f['case']->lock_version, $f['item']->lock_version];
        DB::unprepared(<<<'SQL'
            create or replace function kpone_phase3a_fail_audit() returns trigger language plpgsql as $$
            begin
                if new.event in ('dispensary.completed', 'dispensary.updated') then raise exception 'synthetic late audit failure'; end if;
                return new;
            end $$;
            create trigger kpone_phase3a_fail_audit before insert on audit_logs
            for each row execute function kpone_phase3a_fail_audit();
            SQL);
        try {
            $worker = $this->worker(['complete', (string) $f['ca']->id, (string) $f['branch']->id, $f['case']->public_id, (string) $f['case']->lock_version]);
            $this->runWorkers([$worker], false);
            $this->assertSame(70, $worker['process']->getExitCode());
            $this->assertStringContainsString('synthetic late audit failure', $worker['process']->getErrorOutput());

            $update = $this->worker(['update-item', (string) $f['ca']->id, (string) $f['branch']->id, $f['case']->public_id, (string) $f['case']->lock_version, $f['item']->public_id, (string) $f['item']->lock_version, 'not_dispensed', '0.000', 'other']);
            $this->runWorkers([$update], false);
            $this->assertSame(70, $update['process']->getExitCode());
            $this->assertStringContainsString('synthetic late audit failure', $update['process']->getErrorOutput());
        } finally {
            DB::unprepared('drop trigger if exists kpone_phase3a_fail_audit on audit_logs; drop function if exists kpone_phase3a_fail_audit()');
        }
        $this->assertSame($before, [$f['balance']->refresh()->quantity, $f['case']->refresh()->lock_version, $f['item']->refresh()->lock_version]);
        $this->assertDatabaseMissing('stock_movements', ['organisation_id' => $f['organisation']->id, 'movement_type' => 'dispense']);
    }

    public function test_wrong_branch_cross_org_and_unrelated_workers_are_privacy_preserving(): void
    {
        $f = $this->zeroCompletableFixture();
        $foreign = $this->fixture();
        $wrongBranch = new Branch;
        $wrongBranch->forceFill(['organisation_id' => $f['organisation']->id, 'code' => 'W'.Str::upper(Str::random(5)), 'name' => 'Synthetic Wrong Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $wrongBranchCa = $this->user($f['organisation'], $wrongBranch, 'ca', $this->caPermissions());
        $unrelated = $this->user($f['organisation'], $f['branch'], 'ca', ['branch_context.switch.branch']);
        $workers = [
            $this->worker(['complete', (string) $foreign['ca']->id, (string) $foreign['branch']->id, $f['case']->public_id, (string) $f['case']->lock_version]),
            $this->worker(['complete', (string) $wrongBranchCa->id, (string) $wrongBranch->id, $f['case']->public_id, (string) $f['case']->lock_version]),
            $this->worker(['complete', (string) $unrelated->id, (string) $f['branch']->id, $f['case']->public_id, (string) $f['case']->lock_version]),
        ];
        $this->runWorkers($workers);
        foreach ($workers as $worker) {
            $this->assertStringContainsString('DENIED', $worker['process']->getOutput());
        }
        $this->assertNotSame(DispensaryCase::STATUS_COMPLETED, $f['case']->refresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_concurrent_stock_request_dispatch_is_idempotent_and_debits_once(): void
    {
        $f = $this->completableFixture('10.000', '4.000');
        $request = new StockRequest;
        $request->forceFill([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $f['organisation']->id, 'requesting_branch_id' => $f['branch']->id,
            'source_location_id' => $f['location']->id, 'destination_location_id' => $f['destination']->id,
            'request_number' => 'SR-PG-'.Str::upper(Str::random(8)), 'status' => StockRequest::STATUS_APPROVED,
            'lock_version' => 1, 'requested_by_user_id' => $f['ca']->id, 'requested_at' => now()->utc(),
            'decided_by_user_id' => $f['inventorySupervisor']->id, 'decided_at' => now()->utc(),
        ])->save();
        $line = new StockRequestLine;
        $line->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['organisation']->id, 'stock_request_id' => $request->id, 'inventory_sku_id' => $f['sku']->id, 'requested_quantity' => '6.000'])->save();
        $key = (string) Str::uuid();
        $args = ['stock-request-dispatch', (string) $f['inventorySupervisor']->id, (string) $f['branch']->id, $request->public_id, '1', $key, $line->public_id, $f['batch']->public_id, '6.000'];

        $output = $this->race([$this->worker($args), $this->worker($args)], $request);

        $this->assertSame(2, substr_count($output, 'DISPATCHED'));
        $this->assertSame(1, DB::table('stock_movements')->where('organisation_id', $f['organisation']->id)->where('movement_type', 'transfer_dispatch')->count());
        $this->assertSame('4.000', (string) DB::table('inventory_stock_balances')->where('id', $f['balance']->id)->value('quantity'));
        $this->assertSame(StockRequest::STATUS_DISPATCHED, $request->refresh()->status);
    }

    public function test_inventory_operations_migration_empty_rollback_and_reapply_use_the_laravel_migrator(): void
    {
        foreach (self::INVENTORY_OPERATIONS_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $this->assertSame(0, DB::table('stock_movements')->whereIn('movement_type', $this->inventoryOperationsMovementTypes())->count());

        $migrator = app('migrator');
        $path = database_path('migrations/'.self::INVENTORY_OPERATIONS_MIGRATION.'.php');
        try {
            $migrator->rollback([$path], ['step' => 1]);
            foreach (self::INVENTORY_OPERATIONS_TABLES as $table) {
                $this->assertFalse(Schema::hasTable($table), $table);
            }
            $this->assertFalse(DB::table('migrations')->where('migration', self::INVENTORY_OPERATIONS_MIGRATION)->exists());
        } finally {
            if (! Schema::hasTable('inventory_suppliers')) {
                $migrator->run([$path], ['step' => true]);
            }
        }

        foreach (self::INVENTORY_OPERATIONS_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
        $this->assertTrue(DB::table('migrations')->where('migration', self::INVENTORY_OPERATIONS_MIGRATION)->exists());
    }

    public function test_inventory_operations_migration_refuses_every_retained_evidence_table_and_new_movement_without_changing_migrator_evidence(): void
    {
        $fixture = $this->completableFixture('10.000', '4.000');
        $organisationId = $fixture['organisation']->id;
        $order = $this->purchaseOrderFixture($fixture, PurchaseOrder::STATUS_APPROVED);
        $orderLine = $order->lines()->sole();
        $now = now()->utc();
        $receiptId = DB::table('inventory_goods_receipts')->insertGetId([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $organisationId, 'purchase_order_id' => $order->id,
            'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
            'received_by_user_id' => $fixture['inventorySupervisor']->id, 'received_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('inventory_goods_receipt_lines')->insert([
            'organisation_id' => $organisationId, 'goods_receipt_id' => $receiptId, 'purchase_order_line_id' => $orderLine->id,
            'inventory_sku_id' => $fixture['sku']->id, 'inventory_batch_id' => $fixture['batch']->id,
            'quantity' => '1.000', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $requestId = DB::table('inventory_stock_requests')->insertGetId([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $organisationId, 'requesting_branch_id' => $fixture['branch']->id,
            'source_location_id' => $fixture['location']->id, 'destination_location_id' => $fixture['destination']->id,
            'request_number' => 'SR-ROLLBACK-'.Str::upper(Str::random(6)), 'status' => StockRequest::STATUS_REQUESTED, 'lock_version' => 1,
            'requested_by_user_id' => $fixture['ca']->id, 'requested_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('inventory_stock_request_lines')->insert([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $organisationId, 'stock_request_id' => $requestId,
            'inventory_sku_id' => $fixture['sku']->id, 'inventory_batch_id' => $fixture['batch']->id,
            'requested_quantity' => '1.000', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $stocktakeId = DB::table('inventory_stocktakes')->insertGetId([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $organisationId, 'inventory_location_id' => $fixture['location']->id,
            'stocktake_number' => 'ST-ROLLBACK-'.Str::upper(Str::random(6)), 'status' => 'draft', 'lock_version' => 1,
            'created_by_user_id' => $fixture['inventorySupervisor']->id, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('inventory_stocktake_lines')->insert([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $organisationId, 'stocktake_id' => $stocktakeId,
            'inventory_sku_id' => $fixture['sku']->id, 'inventory_batch_id' => $fixture['batch']->id,
            'expected_quantity' => '10.000', 'expected_balance_lock_version' => $fixture['balance']->lock_version,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('inventory_adjustments')->insert([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $organisationId,
            'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('b', 64), 'inventory_location_id' => $fixture['location']->id,
            'inventory_sku_id' => $fixture['sku']->id, 'inventory_batch_id' => $fixture['batch']->id,
            'direction' => 'in', 'quantity' => '1.000', 'reason_code' => 'correction',
            'posted_by_user_id' => $fixture['inventorySupervisor']->id, 'posted_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('inventory_reorder_levels')->insert([
            'organisation_id' => $organisationId, 'inventory_location_id' => $fixture['location']->id,
            'inventory_sku_id' => $fixture['sku']->id, 'reorder_level' => '5.000',
            'updated_by_user_id' => $fixture['inventorySupervisor']->id, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $migrationBatch = DB::table('migrations')->where('migration', self::INVENTORY_OPERATIONS_MIGRATION)->value('batch');
        $migrator = app('migrator');
        $path = database_path('migrations/'.self::INVENTORY_OPERATIONS_MIGRATION.'.php');

        foreach (self::INVENTORY_OPERATIONS_TABLES as $table) {
            try {
                $migrator->rollback([$path], ['step' => 1]);
                $this->fail("Laravel migrator removed retained evidence from [{$table}].");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString("retained evidence exists in [{$table}]", $exception->getMessage());
            }
            foreach (self::INVENTORY_OPERATIONS_TABLES as $expectedTable) {
                $this->assertTrue(Schema::hasTable($expectedTable), $expectedTable);
            }
            $this->assertTrue(DB::table($table)->where('organisation_id', $organisationId)->exists(), $table);
            $this->assertSame($migrationBatch, DB::table('migrations')->where('migration', self::INVENTORY_OPERATIONS_MIGRATION)->value('batch'));
            DB::table($table)->where('organisation_id', $organisationId)->delete();
        }

        DB::table('stock_movements')->insert([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $organisationId,
            'inventory_sku_id' => $fixture['sku']->id, 'inventory_batch_id' => $fixture['batch']->id,
            'source_location_id' => null, 'destination_location_id' => $fixture['location']->id,
            'dispensary_item_batch_allocation_id' => null, 'quantity' => '1.000', 'movement_type' => 'purchase_receipt',
            'reference_type' => 'inventory_goods_receipt', 'reference_public_id' => (string) Str::uuid(),
            'actor_user_id' => $fixture['inventorySupervisor']->id, 'occurred_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        try {
            $migrator->rollback([$path], ['step' => 1]);
            $this->fail('Laravel migrator removed retained Inventory Operations movement evidence.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('posted movement evidence cannot be rolled back', $exception->getMessage());
        }
        foreach (self::INVENTORY_OPERATIONS_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
        $this->assertSame($migrationBatch, DB::table('migrations')->where('migration', self::INVENTORY_OPERATIONS_MIGRATION)->value('batch'));
        $this->assertSame(1, DB::table('stock_movements')->where('organisation_id', $organisationId)->where('movement_type', 'purchase_receipt')->count());
    }

    public function test_concurrent_purchase_order_approval_and_cancellation_have_one_versioned_winner(): void
    {
        $f = $this->completableFixture('10.000', '4.000');
        $order = $this->purchaseOrderFixture($f, PurchaseOrder::STATUS_SUBMITTED);
        $workers = [
            $this->worker(['purchase-order-approve', (string) $f['inventorySupervisor']->id, (string) $f['branch']->id, $order->public_id, '1']),
            $this->worker(['purchase-order-cancel', (string) $f['inventorySupervisor']->id, (string) $f['branch']->id, $order->public_id, '1']),
        ];

        $output = $this->race($workers, $order);

        $this->assertSame(1, substr_count($output, 'APPROVED') + substr_count($output, 'CANCELLED'));
        $this->assertSame(1, substr_count($output, 'STALE lock_version'));
        $this->assertContains($order->refresh()->status, [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_CANCELLED]);
        $this->assertSame(1, AuditLog::query()->where('organisation_id', $f['organisation']->id)->whereIn('event', ['inventory.purchase_order.approved', 'inventory.purchase_order.cancelled'])->count());
    }

    public function test_concurrent_purchase_receipts_cannot_over_receive_the_order(): void
    {
        $f = $this->completableFixture('10.000', '4.000');
        $order = $this->purchaseOrderFixture($f, PurchaseOrder::STATUS_APPROVED);
        $line = $order->lines()->sole();
        $workers = [
            $this->worker(['purchase-order-receive', (string) $f['inventorySupervisor']->id, (string) $f['branch']->id, $order->public_id, '1', (string) Str::uuid(), $line->public_id, $f['batch']->batch_number, $f['batch']->expiry_date->toDateString(), '6.000']),
            $this->worker(['purchase-order-receive', (string) $f['inventorySupervisor']->id, (string) $f['branch']->id, $order->public_id, '1', (string) Str::uuid(), $line->public_id, $f['batch']->batch_number, $f['batch']->expiry_date->toDateString(), '6.000']),
        ];

        $output = $this->race($workers, $order);

        $this->assertSame(1, substr_count($output, 'RECEIVED'));
        $this->assertSame(1, substr_count($output, 'STALE lock_version'));
        $this->assertSame(1, GoodsReceipt::query()->where('organisation_id', $f['organisation']->id)->count());
        $this->assertSame(1, DB::table('stock_movements')->where('organisation_id', $f['organisation']->id)->where('movement_type', 'purchase_receipt')->count());
        $this->assertSame('16.000', (string) DB::table('inventory_stock_balances')->where('id', $f['balance']->id)->value('quantity'));
        $this->assertSame('6.000', $line->refresh()->received_quantity);
        $this->assertSame(PurchaseOrder::STATUS_PARTIALLY_RECEIVED, $order->refresh()->status);
    }

    public function test_concurrent_cross_purchase_order_receipt_key_has_one_controlled_winner(): void
    {
        $f = $this->completableFixture('10.000', '4.000');
        $otherReceiver = $this->user($f['organisation'], $f['branch'], 'ca_supervisor', $this->inventorySupervisorPermissions());
        [$secondSku, $secondBatch] = $this->additionalInventoryKey($f);
        $secondFixture = [...$f, 'sku' => $secondSku, 'batch' => $secondBatch];
        $first = $this->purchaseOrderFixture($f, PurchaseOrder::STATUS_APPROVED);
        $second = $this->purchaseOrderFixture($secondFixture, PurchaseOrder::STATUS_APPROVED, $f['destination']);
        $firstLine = $first->lines()->sole();
        $secondLine = $second->lines()->sole();
        $key = (string) Str::uuid();
        $workers = [
            $this->worker(['purchase-order-receive', (string) $f['inventorySupervisor']->id, (string) $f['branch']->id, $first->public_id, '1', $key, $firstLine->public_id, $f['batch']->batch_number, $f['batch']->expiry_date->toDateString(), '4.000']),
            $this->worker(['purchase-order-receive', (string) $otherReceiver->id, (string) $f['branch']->id, $second->public_id, '1', $key, $secondLine->public_id, $secondBatch->batch_number, $secondBatch->expiry_date->toDateString(), '4.000']),
        ];

        $output = $this->race($workers, $f['organisation']);

        $this->assertSame(1, substr_count($output, 'RECEIVED'));
        $this->assertSame(1, substr_count($output, 'STALE idempotency_key'));
        $this->assertSame(1, GoodsReceipt::query()->where('organisation_id', $f['organisation']->id)->where('idempotency_key', $key)->count());
        $this->assertSame(1, DB::table('stock_movements')->where('organisation_id', $f['organisation']->id)->where('movement_type', 'purchase_receipt')->count());
        $this->assertSame(1, AuditLog::query()->where('organisation_id', $f['organisation']->id)->where('event', 'inventory.goods_receipt.posted')->count());
        $this->assertSame('4.000', (string) DB::table('inventory_purchase_order_lines')->whereIn('purchase_order_id', [$first->id, $second->id])->sum('received_quantity'));
    }

    public function test_concurrent_adjustment_replay_posts_one_adjustment_and_movement(): void
    {
        $f = $this->completableFixture('10.000', '4.000');
        $otherActor = $this->user($f['organisation'], $f['branch'], 'ca_supervisor', $this->inventorySupervisorPermissions());
        $key = (string) Str::uuid();
        $arguments = fn (User $actor): array => ['adjustment-replay', (string) $actor->id, (string) $f['branch']->id, $f['location']->public_id, $f['sku']->public_id, $f['batch']->public_id, $key];

        $output = $this->race([$this->worker($arguments($f['inventorySupervisor'])), $this->worker($arguments($otherActor))], $f['organisation']);

        $this->assertSame(2, substr_count($output, 'ADJUSTED'));
        $this->assertSame(1, DB::table('inventory_adjustments')->where('organisation_id', $f['organisation']->id)->where('idempotency_key', $key)->count());
        $this->assertSame(1, DB::table('stock_movements')->where('organisation_id', $f['organisation']->id)->where('movement_type', 'adjustment_in')->count());
        $this->assertSame(1, AuditLog::query()->where('organisation_id', $f['organisation']->id)->where('event', 'inventory.adjustment.posted')->count());
        $this->assertSame('11.000', (string) DB::table('inventory_stock_balances')->where('id', $f['balance']->id)->value('quantity'));
    }

    public function test_stocktake_posting_rejects_live_stock_change_after_snapshot(): void
    {
        $f = $this->completableFixture('10.000', '4.000');
        session([BranchAccessService::SESSION_KEY => $f['branch']->id]);
        $service = app(InventoryControlService::class);
        $stocktake = $service->createStocktake($f['inventorySupervisor'], ['expected_branch_id' => $f['branch']->id, 'location_public_id' => $f['location']->public_id]);
        $stocktake = $service->startCounting($f['inventorySupervisor'], $stocktake, ['expected_branch_id' => $f['branch']->id, 'lock_version' => $stocktake->lock_version]);
        $line = $stocktake->lines()->sole();
        $stocktake = $service->recordCounts($f['inventorySupervisor'], $stocktake, ['expected_branch_id' => $f['branch']->id, 'lock_version' => $stocktake->lock_version, 'lines' => [['line_public_id' => $line->public_id, 'physical_quantity' => '9.000']]]);
        $leader = $this->worker(['adjustment-hold', (string) $f['inventorySupervisor']->id, (string) $f['branch']->id, $f['location']->public_id, $f['sku']->public_id, $f['batch']->public_id, (string) Str::uuid()]);
        $follower = $this->worker(['stocktake-post', (string) $f['inventorySupervisor']->id, (string) $f['branch']->id, $stocktake->public_id, (string) $stocktake->lock_version]);

        $output = $this->leaderThenFollower($leader, $follower);

        $this->assertStringContainsString('ADJUSTED', $output);
        $this->assertStringContainsString('STALE stocktake', $output);
        $this->assertSame('11.000', (string) DB::table('inventory_stock_balances')->where('id', $f['balance']->id)->value('quantity'));
        $this->assertSame('review', $stocktake->refresh()->status);
        $this->assertSame(0, DB::table('stock_movements')->where('organisation_id', $f['organisation']->id)->whereIn('movement_type', ['stocktake_gain', 'stocktake_loss'])->count());
    }

    public function test_stocktake_posting_rejects_a_new_balance_key_committed_after_snapshot(): void
    {
        $f = $this->completableFixture('10.000', '4.000');
        session([BranchAccessService::SESSION_KEY => $f['branch']->id]);
        $service = app(InventoryControlService::class);
        $stocktake = $service->createStocktake($f['inventorySupervisor'], ['expected_branch_id' => $f['branch']->id, 'location_public_id' => $f['location']->public_id]);
        $stocktake = $service->startCounting($f['inventorySupervisor'], $stocktake, ['expected_branch_id' => $f['branch']->id, 'lock_version' => $stocktake->lock_version]);
        $line = $stocktake->lines()->sole();
        $stocktake = $service->recordCounts($f['inventorySupervisor'], $stocktake, ['expected_branch_id' => $f['branch']->id, 'lock_version' => $stocktake->lock_version, 'lines' => [['line_public_id' => $line->public_id, 'physical_quantity' => '10.000']]]);
        [$sku, $batch] = $this->additionalInventoryKey($f);
        $leader = $this->worker(['adjustment-hold', (string) $f['inventorySupervisor']->id, (string) $f['branch']->id, $f['location']->public_id, $sku->public_id, $batch->public_id, (string) Str::uuid()]);
        $follower = $this->worker(['stocktake-post', (string) $f['inventorySupervisor']->id, (string) $f['branch']->id, $stocktake->public_id, (string) $stocktake->lock_version]);

        $output = $this->leaderThenFollower($leader, $follower);

        $this->assertStringContainsString('ADJUSTED', $output);
        $this->assertStringContainsString('STALE stocktake', $output);
        $this->assertSame('10.000', (string) DB::table('inventory_stock_balances')->where('id', $f['balance']->id)->value('quantity'));
        $this->assertSame('1.000', (string) DB::table('inventory_stock_balances')->where('inventory_location_id', $f['location']->id)->where('inventory_sku_id', $sku->id)->where('inventory_batch_id', $batch->id)->value('quantity'));
        $this->assertSame('review', $stocktake->refresh()->status);
        $this->assertSame(0, DB::table('stock_movements')->where('organisation_id', $f['organisation']->id)->whereIn('movement_type', ['stocktake_gain', 'stocktake_loss'])->count());
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'DISP_PG_'.Str::upper(Str::random(8)), 'name' => 'Synthetic Phase 3A PG', 'is_active' => true])->save();
        $this->organisationIds[] = $organisation->id;
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $organisation->id, 'code' => 'D'.Str::upper(Str::random(5)), 'name' => 'Synthetic Dispensary Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $operator = $this->user($organisation, $branch, null, ['queue.view.branch', 'queue.enter.branch', 'queue.call.branch', 'visits.view.branch', 'branch_context.switch.organisation']);
        $doctor = $this->user($organisation, $branch, 'resident_doctor', $this->doctorPermissions());
        $ca = $this->user($organisation, $branch, 'ca', $this->caPermissions());
        $ca2 = $this->user($organisation, $branch, 'ca', $this->caPermissions());
        $inventorySupervisor = $this->user($organisation, $branch, 'ca_supervisor', $this->inventorySupervisorPermissions());
        $patient = new Patient;
        $patient->forceFill(['organisation_id' => $organisation->id, 'patient_number' => sprintf('KP-%08d', 96_000_000 + $this->sequence), 'full_name' => 'Synthetic Dispensary Patient', 'search_name' => 'synthetic dispensary patient', 'sex' => 'unknown', 'lock_version' => 1])->save();
        $visit = new Visit;
        $visit->forceFill(['organisation_id' => $organisation->id, 'branch_id' => $branch->id, 'patient_id' => $patient->id, 'visit_number' => sprintf('KPV-%08d', 96_000_000 + $this->sequence++), 'idempotency_key' => (string) Str::uuid(), 'visit_type' => 'consultation', 'status' => Visit::STATUS_REGISTERED, 'priority' => 'normal', 'visit_reason' => 'Synthetic Dispensary reason', 'assigned_doctor_user_id' => $doctor->id, 'coverage_type' => 'self_pay', 'registered_at' => now()->utc(), 'registered_by_user_id' => $operator->id, 'updated_by_user_id' => $operator->id, 'lock_version' => 1])->save();
        app('session')->start();
        session([BranchAccessService::SESSION_KEY => $branch->id]);
        $queue = app(QueueEntryService::class)->enter($operator, $visit, ['expected_branch_id' => $branch->id, 'visit_lock_version' => $visit->lock_version]);
        $queue = app(QueueEntryService::class)->call($operator, $visit, ['expected_branch_id' => $branch->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version]);
        session([BranchAccessService::SESSION_KEY => $branch->id]);
        $encounter = app(ClinicalEncounterService::class)->start($doctor, $visit, ['expected_branch_id' => $branch->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version]);
        $profile = app(PatientAllergyService::class)->declareNoKnown($doctor, $visit, ['expected_branch_id' => $branch->id, 'profile_lock_version' => null]);
        app(PatientAllergyService::class)->review($doctor, $visit, ['expected_branch_id' => $branch->id, 'profile_lock_version' => $profile->lock_version]);
        $medicine = new MedicineCatalogueItem;
        $medicine->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'code' => 'MED-'.Str::upper(Str::random(7)), 'display_name' => 'Synthetic concurrency medicine', 'strength_text' => 'Synthetic strength', 'dosage_form' => 'unit', 'order_unit' => 'unit', 'authorisation_class' => MedicineCatalogueItem::AUTHORISATION_DOCTOR_REQUIRED, 'is_active' => true, 'created_by_user_id' => $doctor->id, 'updated_by_user_id' => $doctor->id])->save();
        $plan = app(TreatmentPlanService::class)->save($doctor, $visit, ['expected_branch_id' => $branch->id, 'lock_version' => null, 'medicines' => [[
            'public_id' => null, 'catalogue_public_id' => $medicine->public_id, 'quantity_ordered' => '10.000',
            'dosage' => 'Synthetic dosage', 'frequency' => 'Synthetic frequency', 'duration' => null, 'route' => null,
            'administration_instruction' => null, 'indication' => null, 'precaution' => null,
        ]], 'services' => []]);

        return compact('organisation', 'branch', 'operator', 'doctor', 'ca', 'ca2', 'inventorySupervisor', 'patient', 'visit', 'queue', 'encounter', 'profile', 'medicine', 'plan');
    }

    /** @return array<string, mixed> */
    private function sentFixture(): array
    {
        $f = $this->fixture();
        session([BranchAccessService::SESSION_KEY => $f['branch']->id]);
        $f['case'] = app(DispensaryHandoffService::class)->send($f['doctor'], $f['visit'], ['expected_branch_id' => $f['branch']->id, 'lock_version' => $f['plan']->lock_version]);
        $f['item'] = $f['case']->handoffs()->where('status', DispensaryHandoff::STATUS_OPEN)->sole()->items()->sole();

        return $f;
    }

    /** @return array<string, mixed> */
    private function startedFixture(): array
    {
        $f = $this->sentFixture();
        session([BranchAccessService::SESSION_KEY => $f['branch']->id]);
        $f['case'] = app(DispensaryService::class)->start($f['ca'], $f['case'], ['expected_branch_id' => $f['branch']->id, 'case_lock_version' => $f['case']->lock_version]);
        $f['item'] = $f['item']->refresh();

        return $f;
    }

    /** @return array<string, mixed> */
    private function patientDeclinedFixture(): array
    {
        $f = $this->startedFixture();
        $f['item'] = app(DispensaryService::class)->updateItem($f['ca'], $f['case'], $f['item'], ['expected_branch_id' => $f['branch']->id, 'case_lock_version' => $f['case']->lock_version, 'item_lock_version' => $f['item']->lock_version, 'status' => 'not_dispensed', 'quantity_dispensed' => '0.000', 'reason' => 'patient_declined', 'allocations' => []]);
        $f['case']->refresh();
        $f['exception'] = $f['item']->exceptions()->where('status', DispensaryItemException::STATUS_AWAITING)->sole();

        return $f;
    }

    /** @return array<string, mixed> */
    private function zeroCompletableFixture(): array
    {
        $f = $this->patientDeclinedFixture();
        session([BranchAccessService::SESSION_KEY => $f['branch']->id]);
        app(DispensaryService::class)->acknowledge($f['doctor'], $f['exception'], ['case_lock_version' => $f['case']->lock_version, 'item_lock_version' => $f['item']->lock_version]);

        return $f;
    }

    /** @return array<string, mixed> */
    private function completableFixture(string $opening, string $actual): array
    {
        $f = $this->startedFixture();
        $inventoryItem = new InventoryItem;
        $inventoryItem->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['organisation']->id, 'code' => 'ITEM-'.Str::upper(Str::random(6)), 'generic_name' => 'Synthetic stock item', 'is_active' => true])->save();
        $sku = new InventorySku;
        $sku->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['organisation']->id, 'inventory_item_id' => $inventoryItem->id, 'sku_code' => 'SKU-'.Str::upper(Str::random(6)), 'pack_size' => 1, 'purchase_unit' => 'unit', 'stock_unit' => 'unit', 'dispensing_unit' => 'unit', 'unit_conversion' => 1, 'storage_type' => 'ambient', 'cold_chain_required' => false, 'do_not_freeze' => false, 'protect_from_light' => false, 'batch_tracking_required' => true, 'expiry_tracking_required' => true, 'is_active' => true])->save();
        $mapping = new MedicineCatalogueInventorySku;
        $mapping->forceFill(['organisation_id' => $f['organisation']->id, 'medicine_catalogue_item_id' => $f['medicine']->id, 'inventory_sku_id' => $sku->id, 'is_active' => true, 'approved_by_user_id' => $f['inventorySupervisor']->id, 'approved_at' => now()->utc()])->save();
        $location = $this->location($f, 'DISP-A');
        $destination = $this->location($f, 'DISP-B');
        $batch = new InventoryBatch;
        $batch->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['organisation']->id, 'inventory_sku_id' => $sku->id, 'batch_number' => 'B-'.Str::upper(Str::random(6)), 'expiry_date' => now()->addMonth()->toDateString(), 'received_at' => now()->subDay()->toDateString(), 'status' => InventoryBatch::STATUS_AVAILABLE])->save();
        session([BranchAccessService::SESSION_KEY => $f['branch']->id]);
        app(InventoryMovementService::class)->openingBalance($f['inventorySupervisor'], ['expected_branch_id' => $f['branch']->id, 'location_public_id' => $location->public_id, 'sku_public_id' => $sku->public_id, 'batch_public_id' => $batch->public_id, 'quantity' => $opening]);
        $f['item'] = app(DispensaryService::class)->updateItem($f['ca'], $f['case'], $f['item'], ['expected_branch_id' => $f['branch']->id, 'case_lock_version' => $f['case']->lock_version, 'item_lock_version' => $f['item']->lock_version, 'status' => 'partial', 'quantity_dispensed' => $actual, 'reason' => 'patient_declined', 'allocations' => [[
            'location_public_id' => $location->public_id, 'sku_public_id' => $sku->public_id, 'batch_public_id' => $batch->public_id, 'quantity' => $actual,
        ]]]);
        $f['case']->refresh();
        $exception = $f['item']->exceptions()->where('status', DispensaryItemException::STATUS_AWAITING)->sole();
        app(DispensaryService::class)->acknowledge($f['doctor'], $exception, ['case_lock_version' => $f['case']->lock_version, 'item_lock_version' => $f['item']->lock_version]);
        $f['sku'] = $sku;
        $f['batch'] = $batch;
        $f['location'] = $location;
        $f['destination'] = $destination;
        $f['balance'] = InventoryStockBalance::query()->where('inventory_location_id', $location->id)->sole();

        return $f;
    }

    /** @param array<string, mixed> $f */
    private function location(array $f, string $suffix): InventoryLocation
    {
        $location = new InventoryLocation;
        $location->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['organisation']->id, 'branch_id' => $f['branch']->id, 'code' => $suffix.Str::upper(Str::random(4)), 'name' => 'Synthetic '.$suffix, 'type' => InventoryLocation::TYPE_DISPENSARY, 'is_active' => true])->save();

        return $location;
    }

    /** @param array<string, mixed> $f @return array<string, mixed> */
    private function additionalCompletableCase(array $f, string $actual): array
    {
        $patient = new Patient;
        $patient->forceFill(['organisation_id' => $f['organisation']->id, 'patient_number' => sprintf('KP-%08d', 96_000_000 + $this->sequence), 'full_name' => 'Synthetic Competing Patient', 'search_name' => 'synthetic competing patient', 'sex' => 'unknown', 'lock_version' => 1])->save();
        $visit = new Visit;
        $visit->forceFill(['organisation_id' => $f['organisation']->id, 'branch_id' => $f['branch']->id, 'patient_id' => $patient->id, 'visit_number' => sprintf('KPV-%08d', 96_000_000 + $this->sequence++), 'idempotency_key' => (string) Str::uuid(), 'visit_type' => 'consultation', 'status' => Visit::STATUS_REGISTERED, 'priority' => 'normal', 'visit_reason' => 'Synthetic competing stock reason', 'assigned_doctor_user_id' => $f['doctor']->id, 'coverage_type' => 'self_pay', 'registered_at' => now()->utc(), 'registered_by_user_id' => $f['operator']->id, 'updated_by_user_id' => $f['operator']->id, 'lock_version' => 1])->save();
        session([BranchAccessService::SESSION_KEY => $f['branch']->id]);
        $queue = app(QueueEntryService::class)->enter($f['operator'], $visit, ['expected_branch_id' => $f['branch']->id, 'visit_lock_version' => $visit->lock_version]);
        $queue = app(QueueEntryService::class)->call($f['operator'], $visit, ['expected_branch_id' => $f['branch']->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version]);
        $encounter = app(ClinicalEncounterService::class)->start($f['doctor'], $visit, ['expected_branch_id' => $f['branch']->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version]);
        $profile = app(PatientAllergyService::class)->declareNoKnown($f['doctor'], $visit, ['expected_branch_id' => $f['branch']->id, 'profile_lock_version' => null]);
        app(PatientAllergyService::class)->review($f['doctor'], $visit, ['expected_branch_id' => $f['branch']->id, 'profile_lock_version' => $profile->lock_version]);
        $plan = app(TreatmentPlanService::class)->save($f['doctor'], $visit, ['expected_branch_id' => $f['branch']->id, 'lock_version' => null, 'medicines' => [[
            'public_id' => null, 'catalogue_public_id' => $f['medicine']->public_id, 'quantity_ordered' => '10.000', 'dosage' => 'Synthetic dosage', 'frequency' => 'Synthetic frequency', 'duration' => null, 'route' => null, 'administration_instruction' => null, 'indication' => null, 'precaution' => null,
        ]], 'services' => []]);
        $case = app(DispensaryHandoffService::class)->send($f['doctor'], $visit, ['expected_branch_id' => $f['branch']->id, 'lock_version' => $plan->lock_version]);
        $case = app(DispensaryService::class)->start($f['ca2'], $case, ['expected_branch_id' => $f['branch']->id, 'case_lock_version' => $case->lock_version]);
        $item = $case->handoffs()->where('status', DispensaryHandoff::STATUS_OPEN)->sole()->items()->sole();
        $item = app(DispensaryService::class)->updateItem($f['ca2'], $case, $item, ['expected_branch_id' => $f['branch']->id, 'case_lock_version' => $case->lock_version, 'item_lock_version' => $item->lock_version, 'status' => 'partial', 'quantity_dispensed' => $actual, 'reason' => 'patient_declined', 'allocations' => [[
            'location_public_id' => $f['location']->public_id, 'sku_public_id' => $f['sku']->public_id, 'batch_public_id' => $f['batch']->public_id, 'quantity' => $actual,
        ]]]);
        $case->refresh();
        $exception = $item->exceptions()->where('status', DispensaryItemException::STATUS_AWAITING)->sole();
        app(DispensaryService::class)->acknowledge($f['doctor'], $exception, ['case_lock_version' => $case->lock_version, 'item_lock_version' => $item->lock_version]);

        return compact('patient', 'visit', 'queue', 'encounter', 'profile', 'plan', 'case', 'item');
    }

    private function assertAuthorityLossFailsClosed(string $mode): void
    {
        $f = $this->zeroCompletableFixture();
        $leaderArgs = [$mode, (string) $f['ca']->id];
        if ($mode === 'revoke') {
            $leaderArgs[] = 'dispensary.complete.branch';
        } elseif ($mode === 'end-assignment') {
            $leaderArgs[] = (string) $f['branch']->id;
        }
        $leader = $this->worker($leaderArgs);
        $follower = $this->worker(['complete', (string) $f['ca']->id, (string) $f['branch']->id, $f['case']->public_id, (string) $f['case']->lock_version]);
        $this->assertStringContainsString('DENIED', $this->leaderThenFollower($leader, $follower));
        $this->assertNotSame(DispensaryCase::STATUS_COMPLETED, $f['case']->refresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    /** @return list<string> */
    private function doctorPermissions(): array
    {
        return ['consultations.complete.own', 'branch_context.switch.branch', 'queue.view.own', 'queue.call.own', 'encounters.view.own', 'encounters.start.own', 'encounters.update.own', 'allergies.view.own', 'allergies.update.own', 'allergies.review.own', 'treatment_plans.view.own', 'treatment_plans.create.own', 'treatment_plans.update.own', 'treatment_plans.send_to_dispensary.own', 'dispensary.acknowledge_partial.own'];
    }

    /** @return list<string> */
    private function caPermissions(): array
    {
        return ['branch_context.switch.branch', 'dispensary.view.branch', 'dispensary.start.branch', 'dispensary.update.branch', 'dispensary.complete.branch', 'dispensary.return_to_doctor.branch', 'inventory.view.branch', 'inventory.transfer.branch'];
    }

    /** @return list<string> */
    private function inventorySupervisorPermissions(): array
    {
        return [...$this->caPermissions(), 'inventory.opening_balance.branch', 'inventory.transfer.organisation', 'inventory.purchase_orders.approve.organisation', 'inventory.receiving.branch', 'inventory.transfers.dispatch.branch', 'inventory.stocktake.branch', 'inventory.adjust.branch', 'inventory.warehouse.organisation'];
    }

    /** @param array<string, mixed> $fixture */
    private function purchaseOrderFixture(array $fixture, string $status, ?InventoryLocation $destination = null): PurchaseOrder
    {
        $supplier = new InventorySupplier;
        $supplier->forceFill([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $fixture['organisation']->id,
            'code' => 'SYN-PG-'.Str::upper(Str::random(6)), 'name' => 'Synthetic PostgreSQL Supplier',
            'is_active' => true,
        ])->save();
        $order = new PurchaseOrder;
        $order->forceFill([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $fixture['organisation']->id,
            'supplier_id' => $supplier->id, 'destination_location_id' => ($destination ?? $fixture['location'])->id,
            'order_number' => 'PO-PG-'.Str::upper(Str::random(8)), 'status' => $status, 'lock_version' => 1,
            'created_by_user_id' => $fixture['ca']->id, 'submitted_by_user_id' => $fixture['ca']->id,
            'submitted_at' => now()->utc(),
            'approved_by_user_id' => $status === PurchaseOrder::STATUS_APPROVED ? $fixture['inventorySupervisor']->id : null,
            'approved_at' => $status === PurchaseOrder::STATUS_APPROVED ? now()->utc() : null,
        ])->save();
        $line = new PurchaseOrderLine;
        $line->forceFill([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $fixture['organisation']->id,
            'purchase_order_id' => $order->id, 'inventory_sku_id' => $fixture['sku']->id,
            'ordered_quantity' => '10.000', 'received_quantity' => '0.000',
        ])->save();

        return $order;
    }

    /** @param array<string, mixed> $fixture @return array{InventorySku, InventoryBatch} */
    private function additionalInventoryKey(array $fixture): array
    {
        $item = new InventoryItem;
        $item->forceFill([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $fixture['organisation']->id,
            'code' => 'I2-KEY-'.Str::upper(Str::random(6)), 'generic_name' => 'Synthetic Stocktake Key Item', 'is_active' => true,
        ])->save();
        $sku = new InventorySku;
        $sku->forceFill([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $fixture['organisation']->id, 'inventory_item_id' => $item->id,
            'sku_code' => 'I2-KEY-SKU-'.Str::upper(Str::random(6)), 'pack_size' => 1, 'purchase_unit' => 'unit', 'stock_unit' => 'unit',
            'dispensing_unit' => 'unit', 'unit_conversion' => 1, 'storage_type' => 'ambient', 'cold_chain_required' => false,
            'do_not_freeze' => false, 'protect_from_light' => false, 'batch_tracking_required' => true, 'expiry_tracking_required' => true, 'is_active' => true,
        ])->save();
        $batch = new InventoryBatch;
        $batch->forceFill([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $fixture['organisation']->id, 'inventory_sku_id' => $sku->id,
            'batch_number' => 'I2-KEY-BATCH-'.Str::upper(Str::random(6)), 'expiry_date' => now()->addMonth()->toDateString(),
            'received_at' => now()->subDay()->toDateString(), 'status' => InventoryBatch::STATUS_AVAILABLE,
        ])->save();

        return [$sku, $batch];
    }

    /** @return list<string> */
    private function inventoryOperationsMovementTypes(): array
    {
        return ['purchase_receipt', 'transfer_dispatch', 'transfer_receipt', 'stocktake_gain', 'stocktake_loss', 'adjustment_in', 'adjustment_out'];
    }

    /** @param list<string> $permissions */
    private function user(Organisation $organisation, Branch $branch, ?string $role, array $permissions): User
    {
        $user = new User;
        $user->forceFill(['organisation_id' => $organisation->id, 'name' => 'Synthetic Phase 3A User', 'email' => 'disp.pg.'.Str::lower(Str::random(10)).'@kpone.test', 'is_active' => true])->save();
        if ($role) {
            $user->assignRole(Role::findOrCreate($role, 'web'));
        }
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $profile = new StaffProfile;
        $profile->forceFill(['user_id' => $user->id, 'department_id' => null])->save();
        StaffBranchAssignmentBootstrapper::create($profile, $branch, ['assignment_type' => 'temporary', 'is_primary' => false, 'valid_from' => now()->subDay()->toDateString(), 'valid_until' => now()->addDay()->toDateString()]);

        return $user->refresh();
    }

    /** @param list<string> $arguments @return Worker */
    private function worker(array $arguments): array
    {
        $input = new InputStream;
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresDispensaryInventoryWorker.php'), ...$arguments, 'kpone-dispensary-pg-'.Str::lower(Str::random(8))], base_path());
        $process->setInput($input);
        $process->setTimeout(40);
        $this->workers[] = $process;
        $this->inputs[] = $input;

        return ['process' => $process, 'input' => $input];
    }

    /** @param list<Worker> $workers */
    private function race(array $workers, Model $blocker): string
    {
        DB::beginTransaction();
        try {
            $blocker->newQuery()->whereKey($blocker->getKey())->lockForUpdate()->firstOrFail();
            $parentPid = (int) DB::scalar('select pg_backend_pid()');
            foreach ($workers as $worker) {
                $worker['process']->start();
            }
            $this->waitReady($workers);
            foreach ($workers as $worker) {
                $worker['input']->write("GO\n");
                $worker['input']->close();
            }
            foreach ($workers as $worker) {
                $this->waitForDatabaseBlock($worker['process'], $parentPid);
            }
            DB::commit();
        } catch (\Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $exception;
        }
        $this->finish($workers);

        return $this->workerOutput($workers);
    }

    private function leaderThenFollower(array $leader, array $follower): string
    {
        $leader['process']->start();
        $this->waitReady([$leader]);
        $leader['input']->write("GO\n");
        $this->waitForOutput($leader['process'], 'LOCKED');
        $leaderPid = $this->workerPid($leader['process']);
        $follower['process']->start();
        $this->waitReady([$follower]);
        $follower['input']->write("GO\n");
        $follower['input']->close();
        $this->waitForDatabaseBlock($follower['process'], $leaderPid);
        $leader['input']->write("COMMIT\n");
        $leader['input']->close();
        $this->finish([$leader, $follower]);

        return $this->workerOutput([$leader, $follower]);
    }

    /** @param list<Worker> $workers */
    private function runWorkers(array $workers): void
    {
        foreach ($workers as $worker) {
            $worker['process']->start();
        }
        $this->waitReady($workers);
        foreach ($workers as $worker) {
            $worker['input']->write("GO\n");
            $worker['input']->close();
        }
        $this->finish($workers, false);
    }

    /** @param list<Worker> $workers */
    private function finish(array $workers, bool $requireZero = true): void
    {
        foreach ($workers as $worker) {
            $worker['process']->wait();
            if ($requireZero) {
                $this->assertSame(0, $worker['process']->getExitCode(), $worker['process']->getOutput().' STDERR: '.$worker['process']->getErrorOutput());
            }
        }
    }

    /** @param list<Worker> $workers */
    private function waitReady(array $workers): void
    {
        $deadline = microtime(true) + 12;
        do {
            if (collect($workers)->every(fn ($worker) => str_contains(str_replace("\r\n", "\n", $worker['process']->getOutput()), 'READY '))) {
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Phase 3A worker did not report READY. '.$this->diagnostics($workers));
    }

    private function waitForOutput(Process $process, string $needle): void
    {
        $deadline = microtime(true) + 12;
        do {
            if (str_contains(str_replace("\r\n", "\n", $process->getOutput()), $needle)) {
                return;
            }
            if ($process->isTerminated()) {
                throw new RuntimeException('Phase 3A worker exited before '.$needle.'. '.$this->processDiagnostics($process));
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Phase 3A worker protocol timeout for '.$needle.'. '.$this->processDiagnostics($process));
    }

    private function workerPid(Process $process): int
    {
        preg_match('/READY ([1-9][0-9]*)/', str_replace("\r\n", "\n", $process->getOutput()), $matches);
        $pid = (int) ($matches[1] ?? 0);
        if ($pid <= 0) {
            throw new RuntimeException('Phase 3A worker did not report a backend PID. '.$this->processDiagnostics($process));
        }

        return $pid;
    }

    private function waitForDatabaseBlock(Process $process, int $expectedBlockerPid): void
    {
        $pid = $this->workerPid($process);
        $deadline = microtime(true) + 12;
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException('Required Phase 3A contention was not reached. '.$this->processDiagnostics($process));
            }
            $result = $this->observer()->selectOne(<<<'SQL'
                select exists (
                    with recursive blockers(pid) as (
                        select unnest(pg_blocking_pids(activity.pid))
                        union
                        select unnest(pg_blocking_pids(blockers.pid)) from blockers
                    ) select 1 from blockers where pid = ?
                ) as expected_blocker
                from pg_stat_activity as activity where pid = ?
                SQL, [$expectedBlockerPid, $pid]);
            if ($result && filter_var($result->expected_blocker, FILTER_VALIDATE_BOOL)) {
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Expected Phase 3A PostgreSQL blocker chain was not observed. '.$this->processDiagnostics($process));
    }

    private function observer(): Connection
    {
        return DB::connection(self::OBSERVER);
    }

    /** @param list<Worker> $workers */
    private function workerOutput(array $workers): string
    {
        return implode('', array_map(fn ($worker) => $worker['process']->getOutput(), $workers));
    }

    /** @param list<Worker> $workers */
    private function diagnostics(array $workers): string
    {
        return implode(' | ', array_map(fn ($worker) => $this->processDiagnostics($worker['process']), $workers));
    }

    private function processDiagnostics(Process $process): string
    {
        return 'exit='.var_export($process->getExitCode(), true).' stdout='.trim(str_replace("\r\n", "\n", $process->getOutput())).' stderr='.trim(str_replace("\r\n", "\n", $process->getErrorOutput()));
    }

    private function deleteOrganisation(int $id): void
    {
        DB::transaction(function () use ($id): void {
            DB::table('service_deliveries')->where('organisation_id', $id)->delete();
            DB::table('consultation_checkouts')->where('organisation_id', $id)->delete();
            foreach (['stock_movements', 'inventory_goods_receipt_lines', 'inventory_goods_receipts', 'inventory_purchase_order_lines', 'inventory_purchase_orders', 'inventory_suppliers', 'inventory_stocktake_lines', 'inventory_stocktakes', 'inventory_stock_request_lines', 'inventory_stock_requests', 'inventory_adjustments', 'inventory_reorder_levels', 'dispensary_item_batch_allocations', 'dispensary_item_exceptions', 'dispensary_items', 'dispensary_handoffs', 'dispensary_cases', 'inventory_stock_balances', 'medicine_catalogue_inventory_skus', 'medicine_catalogue_aliases', 'inventory_batches', 'inventory_locations', 'inventory_skus', 'inventory_items', 'treatment_plan_service_orders', 'treatment_plan_medicine_orders', 'treatment_plans', 'clinical_service_catalogue_items', 'medicine_catalogue_items', 'clinical_encounter_allergy_reviews', 'patient_allergy_records', 'patient_allergy_profile_versions', 'patient_allergy_profiles', 'audit_logs', 'clinical_encounters', 'queue_entries', 'queue_number_counters', 'visits', 'visit_number_counters', 'patients', 'patient_number_counters'] as $table) {
                DB::table($table)->where('organisation_id', $id)->delete();
            }
            $branchIds = DB::table('branches')->where('organisation_id', $id)->pluck('id');
            DB::table('staff_branch_assignments')->whereIn('branch_id', $branchIds)->delete();
            $userIds = DB::table('users')->where('organisation_id', $id)->pluck('id');
            DB::table('model_has_permissions')->where('model_type', User::class)->whereIn('model_id', $userIds)->delete();
            DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $userIds)->delete();
            DB::table('staff_profiles')->whereIn('user_id', $userIds)->delete();
            DB::table('users')->where('organisation_id', $id)->delete();
            DB::table('branches')->where('organisation_id', $id)->delete();
            DB::table('organisations')->where('id', $id)->delete();
        });
    }
}

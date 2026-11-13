<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Inventory\Models\StockRequest;
use App\Domain\Organisation\Inventory\Services\InventoryOperationsDirectoryService;
use App\Domain\Organisation\Inventory\Services\InventoryPickerService;
use App\Domain\Organisation\Inventory\Services\StockRequestService as Service;
use App\Domain\Organisation\Inventory\Services\StockUnitQuantity;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\TransferShortageFixture;

class TransferShortageTest extends VisitTestCase
{
    private const RECEIVERS = ['ca', 'ca_supervisor'];

    private const RESOLVERS = ['ca_supervisor', 'director', 'finance_officer'];

    private function fixture(): TransferShortageFixture
    {
        return new TransferShortageFixture($this->branch);
    }

    private function balance(int $locationId, int $batchId): string
    {
        return (string) (DB::table('inventory_stock_balances')->where('inventory_location_id', $locationId)->where('inventory_batch_id', $batchId)->value('quantity') ?? '0.000');
    }

    /** @return array<string,mixed> */
    private function received(array $counts, string $role = 'ca', string $quantity = '10.000'): array
    {
        $fx = $this->fixture();
        $f = $fx->dispatched($quantity);
        $receiver = $fx->actor($role);
        $f['request'] = app(Service::class)->receive($receiver, $f['request'], $fx->receipt($f['request'], $f['line']->public_id, $counts));
        $f['receiver'] = $receiver;

        return $f;
    }

    private function difference(array $f, string $kind): object
    {
        return DB::table('inventory_transfer_differences')->where('stock_request_id', $f['request']->id)->where('kind', $kind)->sole();
    }

    private function resolution(array $f, object $difference, string $outcome, array $overrides = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'lock_version' => StockRequest::query()->whereKey($f['request']->id)->value('lock_version'), 'retry_key' => (string) Str::uuid(), 'difference_public_id' => $difference->public_id, 'outcome' => $outcome, 'reference' => 'SYN-TRANSFER-LOG-1', ...$overrides];
    }

    public function test_exact_catalogue_roles_for_receipt_and_independent_resolution(): void
    {
        $holders = fn (string $permission): array => collect(PermissionCatalogue::roles())->filter(fn ($p) => in_array($permission, $p, true))->keys()->sort()->values()->all();
        $this->assertSame(self::RECEIVERS, $holders(Service::RECEIVE_PERMISSION));
        $this->assertSame(self::RESOLVERS, $holders(Service::RESOLVE_PERMISSION));
        $this->assertSame('inventory.variances.approve.branch', Service::RESOLVE_PERMISSION);
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $this->assertSame(in_array($role, self::RESOLVERS, true), Role::findByName($role)->hasPermissionTo(Service::RESOLVE_PERMISSION), $role);
            $fx = $this->fixture();
            $f = $fx->dispatched('4.000');
            $actor = $fx->actor($role);
            $service = app(Service::class);
            try {
                $f['request'] = $service->receive($actor, $f['request'], $fx->receipt($f['request'], $f['line']->public_id, ['accepted' => '3.000', 'short' => '1.000']));
                $this->assertContains($role, self::RECEIVERS);
            } catch (AuthorizationException) {
                $this->assertNotContains($role, self::RECEIVERS);
                $this->assertSame(0, DB::table('inventory_transfer_receipt_lines')->where('stock_request_id', $f['request']->id)->count());
                $f['request'] = $service->receive($f['requester'], $f['request'], $fx->receipt($f['request'], $f['line']->public_id, ['accepted' => '3.000', 'short' => '1.000']));
            }
            $resolver = $fx->actor($role);
            try {
                $service->resolveDifference($resolver, $f['request'], $this->resolution($f, $this->difference($f, 'short'), 'lost_in_transit'));
                $this->assertContains($role, self::RESOLVERS);
                $this->assertSame(1, DB::table('inventory_transfer_difference_resolutions')->where('resolved_by_user_id', $resolver->id)->count());
            } catch (AuthorizationException) {
                $this->assertNotContains($role, self::RESOLVERS);
                $this->assertSame(0, DB::table('inventory_transfer_difference_resolutions')->where('resolved_by_user_id', $resolver->id)->count());
            }
        }
    }

    public function test_full_receipt_with_lines_creates_evidence_and_no_differences(): void
    {
        $f = $this->received(['accepted' => '10.000']);
        $this->assertSame(StockRequest::STATUS_RECEIVED, $f['request']->status);
        $this->assertSame('10.000', $this->balance($f['destination']->id, $f['batch']->id));
        $this->assertSame('10.000', $this->balance($f['location']->id, $f['batch']->id));
        $line = DB::table('inventory_transfer_receipt_lines')->where('stock_request_id', $f['request']->id)->sole();
        $this->assertSame(['10.000', '10.000', '0.000', '0.000', '0.000'], [$line->dispatched_quantity, $line->accepted_quantity, $line->short_quantity, $line->damaged_quantity, $line->extra_quantity]);
        $this->assertNotNull($line->movement_id);
        $this->assertSame(0, DB::table('inventory_transfer_differences')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'inventory.transfer_difference.recorded')->count());
    }

    public function test_legacy_receive_without_lines_keeps_full_receipt_and_records_evidence(): void
    {
        $fx = $this->fixture();
        $f = $fx->dispatched('7.000');
        $key = (string) Str::uuid();
        $attributes = ['expected_branch_id' => $this->branch->id, 'lock_version' => $f['request']->lock_version, 'receive_idempotency_key' => $key];
        $request = app(Service::class)->receive($f['requester'], $f['request'], $attributes);
        app(Service::class)->receive($f['requester'], $request, $attributes);
        $this->assertSame('7.000', $this->balance($f['destination']->id, $f['batch']->id));
        $this->assertSame('7.000', DB::table('inventory_transfer_receipt_lines')->where('stock_request_id', $request->id)->value('accepted_quantity'));
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', StockMovement::TYPE_TRANSFER_RECEIPT)->count());
    }

    public function test_partial_receipt_credits_only_accepted_and_conserves_quantity(): void
    {
        $f = $this->received(['accepted' => '5.500', 'short' => '3.250', 'damaged' => '1.250', 'extra' => '2.000']);
        $this->assertSame('5.500', $this->balance($f['destination']->id, $f['batch']->id));
        $this->assertSame('10.000', $this->balance($f['location']->id, $f['batch']->id));
        $this->assertSame('5.500', $f['line']->refresh()->received_quantity);
        $differences = DB::table('inventory_transfer_differences')->where('stock_request_id', $f['request']->id)->orderBy('id')->get();
        $this->assertSame(['short' => '3.250', 'damaged' => '1.250', 'extra' => '2.000'], $differences->pluck('quantity', 'kind')->all());
        $this->assertSame([$f['receiver']->id], $differences->pluck('received_by_user_id')->unique()->values()->all());
        $movement = DB::table('stock_movements')->where('movement_type', StockMovement::TYPE_TRANSFER_RECEIPT)->sole();
        $this->assertSame(['5.500', $f['destination']->id, $f['request']->public_id, $f['receiver']->id], [$movement->quantity, $movement->destination_location_id, $movement->reference_public_id, $movement->actor_user_id]);
        $audit = DB::table('audit_logs')->where('event', 'inventory.transfer_difference.recorded')->sole();
        $this->assertSame(['difference_count' => 3], json_decode($audit->metadata, true));
        // Transit conservation: dispatched = credited + open short + open damaged; extra is outside the dispatch.
        $dispatched = DB::table('stock_movements')->where('movement_type', StockMovement::TYPE_TRANSFER_DISPATCH)->where('reference_public_id', $f['request']->public_id)->value('quantity');
        $this->assertSame('10.000', $dispatched);
        $credited = DB::table('stock_movements')->where('movement_type', StockMovement::TYPE_TRANSFER_RECEIPT)->where('reference_public_id', $f['request']->public_id)->sum('quantity');
        $open = DB::table('inventory_transfer_differences')->where('stock_request_id', $f['request']->id)->whereIn('kind', ['short', 'damaged'])->sum('quantity');
        $this->assertSame(StockUnitQuantity::scaled('10.000'), StockUnitQuantity::scaled((string) $credited) + StockUnitQuantity::scaled((string) $open));
    }

    public function test_receipt_that_does_not_reconcile_or_misses_lines_is_refused_atomically(): void
    {
        $fx = $this->fixture();
        $f = $fx->dispatched('10.000');
        $service = app(Service::class);
        $cases = [
            ['accepted' => '9.000'],
            ['accepted' => '10.000', 'short' => '0.001'],
            ['accepted' => '9.0001', 'short' => '0.9999'],
            ['accepted' => '-1.000', 'short' => '11.000'],
            ['accepted' => '1e1'],
        ];
        foreach ($cases as $counts) {
            try {
                $service->receive($f['requester'], $f['request'], $fx->receipt($f['request'], $f['line']->public_id, $counts));
                $this->fail('Unreconciled receipt accepted: '.json_encode($counts));
            } catch (ValidationException $e) {
                $this->assertNotEmpty(array_intersect(['lines', 'quantity'], array_keys($e->errors())));
            }
        }
        foreach ([[], [['line_public_id' => (string) Str::uuid(), 'accepted_quantity' => '10', 'short_quantity' => '0', 'damaged_quantity' => '0', 'extra_quantity' => '0']]] as $lines) {
            try {
                $service->receive($f['requester'], $f['request'], ['expected_branch_id' => $this->branch->id, 'lock_version' => $f['request']->lock_version, 'receive_idempotency_key' => (string) Str::uuid(), 'lines' => $lines]);
                $this->fail('Incomplete receipt accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('lines', $e->errors());
            }
        }
        $this->assertSame(StockRequest::STATUS_DISPATCHED, $f['request']->refresh()->status);
        $this->assertSame('0.000', $this->balance($f['destination']->id, $f['batch']->id));
        $this->assertSame(0, DB::table('inventory_transfer_receipt_lines')->count());
        $this->assertSame(0, DB::table('stock_movements')->where('movement_type', StockMovement::TYPE_TRANSFER_RECEIPT)->count());
    }

    public function test_receipt_retry_keys_and_stale_versions(): void
    {
        $fx = $this->fixture();
        $f = $fx->dispatched('10.000');
        $service = app(Service::class);
        $key = (string) Str::uuid();
        $payload = $fx->receipt($f['request'], $f['line']->public_id, ['accepted' => '8.000', 'short' => '2.000'], $key);
        try {
            $service->receive($f['requester'], $f['request'], [...$payload, 'lock_version' => $f['request']->lock_version - 1]);
            $this->fail('Stale receipt accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lock_version', $e->errors());
        }
        $request = $service->receive($f['requester'], $f['request'], $payload);
        $again = $service->receive($f['requester'], $request, $payload);
        $this->assertSame($request->id, $again->id);
        $this->assertSame(1, DB::table('inventory_transfer_receipt_lines')->count());
        $this->assertSame(1, DB::table('inventory_transfer_differences')->count());
        try {
            $service->receive($f['requester'], $request, $fx->receipt($f['request'], $f['line']->public_id, ['accepted' => '10.000'], $key));
            $this->fail('Conflicting retry accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('receive_idempotency_key', $e->errors());
        }
        try {
            $service->receive($f['requester'], $request, $fx->receipt($f['request'], $f['line']->public_id, ['accepted' => '10.000']));
            $this->fail('Second receipt accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('receive_idempotency_key', $e->errors());
        }
        $this->assertSame('8.000', $this->balance($f['destination']->id, $f['batch']->id));
    }

    public function test_each_outcome_moves_stock_only_when_accepted_and_needs_references(): void
    {
        $f = $this->received(['accepted' => '5.000', 'short' => '3.000', 'damaged' => '2.000', 'extra' => '1.000']);
        $resolver = $this->fixture()->actor('finance_officer');
        $service = app(Service::class);
        try {
            $service->resolveDifference($resolver, $f['request'], $this->resolution($f, $this->difference($f, 'short'), 'written_off'));
            $this->fail('Outcome for wrong kind accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('outcome', $e->errors());
        }
        try {
            $service->resolveDifference($resolver, $f['request'], $this->resolution($f, $this->difference($f, 'damaged'), 'written_off', ['reference' => '   ']));
            $this->fail('Missing reference accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reference', $e->errors());
        }
        try {
            $service->resolveDifference($resolver, $f['request'], $this->resolution($f, $this->difference($f, 'short'), 'arrived_later', ['reference' => '']));
            $this->fail('Stock credit without a delivery reference accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reference', $e->errors());
        }
        $service->resolveDifference($resolver, $f['request'], $this->resolution($f, $this->difference($f, 'short'), 'arrived_later', ['reference' => 'SYN-DELIVERY-NOTE-2']));
        $this->assertSame('8.000', $this->balance($f['destination']->id, $f['batch']->id));
        $service->resolveDifference($resolver, $f['request'], $this->resolution($f, $this->difference($f, 'damaged'), 'written_off'));
        $service->resolveDifference($resolver, $f['request'], $this->resolution($f, $this->difference($f, 'extra'), 'returned_to_source', ['reference' => 'SYN-RETURN-NOTE-3']));
        $this->assertSame('8.000', $this->balance($f['destination']->id, $f['batch']->id));
        $this->assertSame('10.000', $this->balance($f['location']->id, $f['batch']->id));
        $receipts = DB::table('stock_movements')->where('movement_type', StockMovement::TYPE_TRANSFER_RECEIPT)->orderBy('id')->get();
        $this->assertSame(['5.000', '3.000'], $receipts->pluck('quantity')->all());
        $resolutions = DB::table('inventory_transfer_difference_resolutions')->orderBy('id')->get();
        $this->assertSame(['arrived_later', 'written_off', 'returned_to_source'], $resolutions->pluck('outcome')->all());
        $this->assertSame([$receipts[1]->id, null, null], $resolutions->pluck('movement_id')->all());
        $this->assertSame($resolver->id, $receipts[1]->actor_user_id);
        // Dispatched 10 = credited 5 + arrived later 3 + written off 2; the extra 1 was returned, outside the dispatch.
        $this->assertSame(0, DB::table('inventory_transfer_differences as d')->leftJoin('inventory_transfer_difference_resolutions as r', 'r.difference_id', '=', 'd.id')->whereNull('r.id')->count());
        try {
            $service->resolveDifference($resolver, $f['request'], $this->resolution($f, $this->difference($f, 'short'), 'arrived_later'));
            $this->fail('Second resolution accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('difference_public_id', $e->errors());
        }
        foreach (DB::table('audit_logs')->where('event', 'inventory.transfer_difference.resolved')->get() as $row) {
            $keys = array_keys(json_decode($row->metadata, true));
            sort($keys);
            $this->assertSame(['difference_id', 'kind', 'outcome', 'resolution_id'], $keys);
        }
    }

    public function test_receiver_can_never_resolve_their_own_difference_in_domain_or_database(): void
    {
        $f = $this->received(['accepted' => '6.000', 'short' => '4.000'], 'ca_supervisor');
        $this->assertTrue($f['receiver']->can(Service::RESOLVE_PERMISSION));
        $difference = $this->difference($f, 'short');
        try {
            app(Service::class)->resolveDifference($f['receiver'], $f['request'], $this->resolution($f, $difference, 'lost_in_transit'));
            $this->fail('Receiver resolved own difference');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('inventory_transfer_difference_resolutions')->count());
        }
        try {
            DB::transaction(fn () => DB::table('inventory_transfer_difference_resolutions')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'difference_id' => $difference->id, 'outcome' => 'lost_in_transit', 'quantity' => '4.000', 'reference' => 'SYN', 'resolved_by_user_id' => $f['receiver']->id, 'retry_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'created_at' => now()]));
            $this->fail('Database accepted self-resolution');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $other = $this->fixture()->actor('ca_supervisor');
        app(Service::class)->resolveDifference($other, $f['request'], $this->resolution($f, $difference, 'lost_in_transit'));
        $this->assertSame(1, DB::table('inventory_transfer_difference_resolutions')->count());
    }

    public function test_resolution_retry_keys_and_stale_versions(): void
    {
        $f = $this->received(['accepted' => '6.000', 'short' => '2.000', 'damaged' => '2.000']);
        $resolver = $this->fixture()->actor('director');
        $service = app(Service::class);
        $stale = $this->resolution($f, $this->difference($f, 'short'), 'arrived_later', ['lock_version' => $f['request']->lock_version - 1]);
        try {
            $service->resolveDifference($resolver, $f['request'], $stale);
            $this->fail('Stale resolution accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lock_version', $e->errors());
        }
        $payload = $this->resolution($f, $this->difference($f, 'short'), 'arrived_later');
        $service->resolveDifference($resolver, $f['request'], $payload);
        $service->resolveDifference($resolver, $f['request'], $payload);
        $this->assertSame(1, DB::table('inventory_transfer_difference_resolutions')->count());
        $this->assertSame('8.000', $this->balance($f['destination']->id, $f['batch']->id));
        try {
            $service->resolveDifference($resolver, $f['request'], [...$payload, 'outcome' => 'lost_in_transit']);
            $this->fail('Conflicting retry accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('retry_key', $e->errors());
        }
        try {
            $service->resolveDifference($resolver, $f['request'], [...$this->resolution($f, $this->difference($f, 'damaged'), 'written_off'), 'lock_version' => $payload['lock_version']]);
            $this->fail('Resolution on superseded version accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lock_version', $e->errors());
        }
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'inventory.transfer_difference.resolved')->count());
    }

    public function test_fresh_revocation_ended_assignment_inactive_foreign_branch_and_organisation(): void
    {
        $f = $this->received(['accepted' => '9.000', 'short' => '1.000']);
        $difference = $this->difference($f, 'short');
        $service = app(Service::class);
        $actor = $this->fixture()->actor('finance_officer');
        $actor->load('roles.permissions');
        $actor->syncRoles(['marketing']);
        $refused = function (callable $attempt, string $label): void {
            try {
                $attempt();
                $this->fail($label);
            } catch (AuthorizationException|HttpException|ModelNotFoundException) {
                $this->assertSame(0, DB::table('inventory_transfer_difference_resolutions')->count(), $label);
            }
        };
        $refused(fn () => $service->resolveDifference($actor, $f['request'], $this->resolution($f, $difference, 'lost_in_transit')), 'Revoked permission');
        $actor->syncRoles(['finance_officer']);
        DB::table('staff_branch_assignments')->where('staff_profile_id', $actor->staffProfile->id)->update(['valid_from' => now()->subDays(3)->toDateString(), 'valid_until' => now()->subDays(2)->toDateString()]);
        $refused(fn () => $service->resolveDifference($actor, $f['request'], $this->resolution($f, $difference, 'lost_in_transit')), 'Ended assignment');
        $inactive = $this->fixture()->actor('director');
        $inactive->forceFill(['is_active' => false])->save();
        $refused(fn () => $service->resolveDifference($inactive, $f['request'], $this->resolution($f, $difference, 'lost_in_transit')), 'Inactive');
        $otherBranch = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'SUNGAI_BESI')->sole();
        $foreignBranchActor = $this->fixture()->actor('director', $otherBranch);
        $refused(fn () => $service->resolveDifference($foreignBranchActor, $f['request'], $this->resolution($f, $difference, 'lost_in_transit', ['expected_branch_id' => $otherBranch->id])), 'Foreign branch');
        $org = Organisation::query()->create(['code' => 'SYN-STK6', 'name' => 'Synthetic transfer organisation', 'is_active' => true]);
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $org->id, 'code' => 'SYN-STK6', 'name' => 'Synthetic transfer branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $foreign = (new TransferShortageFixture($branch))->actor('director');
        $refused(fn () => $service->resolveDifference($foreign, $f['request'], $this->resolution($f, $difference, 'lost_in_transit', ['expected_branch_id' => $branch->id])), 'Foreign organisation');
        $this->assertSame('9.000', $this->balance($f['destination']->id, $f['batch']->id));
    }

    public function test_audit_failure_rolls_back_receipt_and_resolution(): void
    {
        $fx = $this->fixture();
        $f = $fx->dispatched('10.000');
        $moves = DB::table('stock_movements')->orderBy('id')->get()->toJson();
        $balances = DB::table('inventory_stock_balances')->orderBy('id')->get()->toJson();
        $this->instance(AuditRecorder::class, \Mockery::mock(AuditRecorder::class, fn ($m) => $m->shouldReceive('record')->andReturnNull()));
        try {
            app(Service::class)->receive($f['requester'], $f['request'], $fx->receipt($f['request'], $f['line']->public_id, ['accepted' => '7.000', 'short' => '3.000']));
            $this->fail('Unaudited receipt');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->assertSame($moves, DB::table('stock_movements')->orderBy('id')->get()->toJson());
        $this->assertSame($balances, DB::table('inventory_stock_balances')->orderBy('id')->get()->toJson());
        $this->assertSame(0, DB::table('inventory_transfer_receipt_lines')->count());
        $this->assertSame(StockRequest::STATUS_DISPATCHED, $f['request']->refresh()->status);
        app()->forgetInstance(AuditRecorder::class);

        $request = app(Service::class)->receive($f['requester'], $f['request'], $fx->receipt($f['request'], $f['line']->public_id, ['accepted' => '7.000', 'short' => '3.000']));
        $f['request'] = $request;
        $resolver = $fx->actor('finance_officer');
        $moves = DB::table('stock_movements')->orderBy('id')->get()->toJson();
        $balances = DB::table('inventory_stock_balances')->orderBy('id')->get()->toJson();
        $version = $request->refresh()->lock_version;
        $payload = $this->resolution($f, $this->difference($f, 'short'), 'arrived_later');
        $this->instance(AuditRecorder::class, \Mockery::mock(AuditRecorder::class, fn ($m) => $m->shouldReceive('record')->andReturnNull()));
        try {
            app(Service::class)->resolveDifference($resolver, $request, $payload);
            $this->fail('Unaudited resolution');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        app()->forgetInstance(AuditRecorder::class);
        $this->assertSame($moves, DB::table('stock_movements')->orderBy('id')->get()->toJson());
        $this->assertSame($balances, DB::table('inventory_stock_balances')->orderBy('id')->get()->toJson());
        $this->assertSame(0, DB::table('inventory_transfer_difference_resolutions')->count());
        $this->assertSame($version, $request->refresh()->lock_version);
    }

    public function test_postgresql_evidence_is_append_only_and_receipt_movements_need_evidence(): void
    {
        $f = $this->received(['accepted' => '5.000', 'short' => '5.000']);
        app(Service::class)->resolveDifference($this->fixture()->actor('director'), $f['request'], $this->resolution($f, $this->difference($f, 'short'), 'lost_in_transit'));
        // Run the commit-time evidence checks now; they pass, and PostgreSQL then allows the TRUNCATE probes below.
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        foreach (['inventory_transfer_receipt_lines', 'inventory_transfer_differences', 'inventory_transfer_difference_resolutions'] as $table) {
            foreach (["UPDATE {$table} SET id=id", "DELETE FROM {$table}", "TRUNCATE {$table} CASCADE"] as $sql) {
                try {
                    DB::transaction(fn () => DB::statement($sql));
                    $this->fail('Mutable evidence: '.$sql);
                } catch (QueryException $e) {
                    $this->assertSame('P0001', $e->errorInfo[0], $sql);
                }
            }
            $this->assertSame(1, DB::table($table)->count());
        }
        $receiptLine = DB::table('inventory_transfer_receipt_lines')->sole();
        $bad = ['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'stock_request_id' => $f['request']->id, 'receipt_line_id' => $receiptLine->id, 'kind' => 'damaged', 'quantity' => '1.000', 'received_by_user_id' => $f['receiver']->id, 'created_at' => now()];
        try {
            DB::transaction(fn () => DB::table('inventory_transfer_differences')->insert($bad));
            $this->fail('Invented difference accepted');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        try {
            DB::transaction(function () use ($f): void {
                DB::table('stock_movements')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'inventory_sku_id' => $f['sku']->id, 'inventory_batch_id' => $f['batch']->id, 'destination_location_id' => $f['destination']->id, 'quantity' => '1.000', 'movement_type' => 'transfer_receipt', 'reference_type' => 'inventory_stock_request', 'reference_public_id' => $f['request']->public_id, 'actor_user_id' => $f['receiver']->id, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                DB::statement('SET CONSTRAINTS transfer_receipt_movement_evidence IMMEDIATE');
            });
            $this->fail('Unevidenced transfer receipt accepted');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', 'transfer_receipt')->count());
        $g = $this->fixture()->dispatched('10.000');
        try {
            DB::transaction(function () use ($g): void {
                DB::table('inventory_transfer_receipt_lines')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'stock_request_id' => $g['request']->id, 'stock_request_line_id' => $g['line']->id, 'inventory_sku_id' => $g['sku']->id, 'inventory_batch_id' => $g['batch']->id, 'base_unit' => $g['sku']->stock_unit, 'dispatched_quantity' => '10.000', 'accepted_quantity' => '0.000', 'short_quantity' => '10.000', 'damaged_quantity' => '0.000', 'extra_quantity' => '0.000', 'received_by_user_id' => $g['requester']->id, 'created_at' => now()]);
                DB::statement('SET CONSTRAINTS transfer_receipt_line_differences IMMEDIATE');
            });
            $this->fail('Receipt line without its difference row accepted');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
            $this->assertStringContainsString('needs exactly one open difference', $e->getMessage());
        }
        $this->assertSame(0, DB::table('inventory_transfer_receipt_lines')->where('stock_request_id', $g['request']->id)->count());
    }

    public function test_directory_request_lines_name_the_item_and_its_stock_unit(): void
    {
        // V2 page 2b (Daniel): a person counting a transfer must see what the item is, not only its SKU code.
        $g = $this->fixture()->dispatched('4.000');
        $line = collect(app(InventoryOperationsDirectoryService::class)->directory($g['supervisor'])['stockRequests'])->firstWhere('publicId', $g['request']->public_id)['lines'][0];
        $item = DB::table('inventory_items')->where('id', $g['sku']->inventory_item_id)->value('generic_name');

        $row = collect(app(InventoryOperationsDirectoryService::class)->directory($g['supervisor'])['stockRequests'])->firstWhere('publicId', $g['request']->public_id);
        $this->assertSame([$item, $g['sku']->stock_unit, '4.000'], [$line['item'], $line['stockUnit'], $line['dispatchedQuantity']]);
        $this->assertSame($g['location']->public_id, $row['sourcePublicId']);
    }

    public function test_batch_picker_says_how_much_of_each_batch_is_at_a_location_of_this_branch(): void
    {
        $g = $this->fixture()->dispatched('4.000', '20.000');
        app(BranchAccessService::class)->select($g['supervisor'], $this->branch);
        $picker = app(InventoryPickerService::class);

        $here = $picker->choices($g['supervisor'], $this->branch, 'batches', 'operations', sku: $g['sku']->public_id, location: $g['location']->public_id);
        $row = collect($here)->firstWhere('publicId', $g['batch']->public_id);
        $this->assertSame('16.000', StockUnitQuantity::format(StockUnitQuantity::scaled((string) $row['onHand'])));
        // Without a location the rows are as before, with no quantity.
        $plain = $picker->choices($g['supervisor'], $this->branch, 'batches', 'operations', sku: $g['sku']->public_id);
        $this->assertArrayNotHasKey('onHand', collect($plain)->firstWhere('publicId', $g['batch']->public_id));
        $this->assertSame(array_column($plain, 'publicId'), array_column($here, 'publicId'));

        // A location of another branch is not found.
        $other = new Branch;
        $other->forceFill(['organisation_id' => $this->branch->organisation_id, 'code' => 'SYN-ONHAND-B', 'name' => 'Synthetic On-hand Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $elsewhere = $g['destination']->replicate();
        $elsewhere->forceFill(['public_id' => (string) Str::uuid(), 'branch_id' => $other->id, 'code' => 'ONHAND-'.Str::upper(Str::random(6))])->save();
        $this->expectException(NotFoundHttpException::class);
        $picker->choices($g['supervisor'], $this->branch, 'batches', 'operations', sku: $g['sku']->public_id, location: $elsewhere->public_id);
    }

    public function test_directory_shows_differences_and_who_may_resolve_without_names(): void
    {
        $f = $this->received(['accepted' => '8.000', 'damaged' => '2.000'], 'ca_supervisor');
        $directory = app(InventoryOperationsDirectoryService::class);
        $row = collect($directory->directory($f['receiver'])['stockRequests'])->firstWhere('publicId', $f['request']->public_id);
        $this->assertCount(1, $row['differences']);
        $difference = $row['differences'][0];
        $this->assertSame(['damaged', '2.000', 'open', false, true], [$difference['kind'], $difference['quantity'], $difference['status'], $difference['canResolve'], $difference['receivedByYou']]);
        $this->assertSame(['written_off', 'accepted_after_inspection'], $difference['outcomes']);
        $this->assertStringNotContainsString($f['receiver']->name, json_encode($row, JSON_THROW_ON_ERROR));
        $finance = $this->fixture()->actor('finance_officer');
        $payload = $directory->directory($finance);
        $this->assertTrue($payload['permissions']['resolveTransferDifferences']);
        $this->assertTrue(collect($payload['stockRequests'])->firstWhere('publicId', $f['request']->public_id)['differences'][0]['canResolve']);
        $this->assertFalse($directory->directory($f['requester'])['permissions']['resolveTransferDifferences']);
    }

    public function test_http_routes_enforce_permission_and_stay_on_inventory(): void
    {
        $f = $this->received(['accepted' => '8.000', 'short' => '2.000']);
        $difference = $this->difference($f, 'short');
        $this->actingAs($f['requester']);
        app(BranchAccessService::class)->select($f['requester'], $this->branch);
        $this->post(route('inventory.stock-requests.differences.resolve', $f['request']), $this->resolution($f, $difference, 'lost_in_transit'))->assertForbidden();
        $finance = $this->fixture()->actor('finance_officer');
        $this->actingAs($finance);
        app(BranchAccessService::class)->select($finance, $this->branch);
        $this->post(route('inventory.stock-requests.differences.resolve', $f['request']), $this->resolution($f, $difference, 'lost_in_transit', ['reference' => '']))
            ->assertRedirect(route('inventory.index'))->assertSessionHasErrors('reference');
        $this->post(route('inventory.stock-requests.differences.resolve', $f['request']), $this->resolution($f, $difference, 'lost_in_transit'))
            ->assertRedirect(route('inventory.index'));
        $this->assertSame(1, DB::table('inventory_transfer_difference_resolutions')->count());
    }

    public function test_unrelated_tables_are_untouched_by_receipt_and_resolution(): void
    {
        $fx = $this->fixture();
        $f = $fx->dispatched('10.000');
        $resolver = $fx->actor('finance_officer');
        $owned = ['inventory_transfer_receipt_lines', 'inventory_transfer_differences', 'inventory_transfer_difference_resolutions', 'inventory_stock_requests', 'inventory_stock_request_lines', 'audit_logs', 'stock_movements', 'inventory_stock_balances'];
        $snapshot = function () use ($owned): array {
            $result = [];
            foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $table) {
                if (in_array($table->tablename, $owned, true)) {
                    continue;
                }
                $q = DB::table($table->tablename);
                $result[$table->tablename] = Schema::hasColumn($table->tablename, 'id') ? $q->orderBy('id')->get()->toJson() : $q->get()->map(fn ($r) => json_encode($r, JSON_THROW_ON_ERROR))->sort()->values()->toJson();
            }

            return $result;
        };
        $before = $snapshot();
        $moves = DB::table('stock_movements')->orderBy('id')->get()->toJson();
        $max = DB::table('stock_movements')->max('id');
        $request = app(Service::class)->receive($f['requester'], $f['request'], $fx->receipt($f['request'], $f['line']->public_id, ['accepted' => '6.000', 'short' => '4.000']));
        $f['request'] = $request;
        app(Service::class)->resolveDifference($resolver, $request, $this->resolution($f, $this->difference($f, 'short'), 'arrived_later'));
        $this->assertSame($before, $snapshot());
        $this->assertSame($moves, DB::table('stock_movements')->where('id', '<=', $max)->orderBy('id')->get()->toJson());
        $this->assertSame(['6.000', '4.000'], DB::table('stock_movements')->where('id', '>', $max)->orderBy('id')->pluck('quantity')->all());
        $this->assertSame('10.000', $this->balance($f['destination']->id, $f['batch']->id));
    }
}

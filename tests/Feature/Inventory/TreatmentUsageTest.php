<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Inventory\Services\InventoryDirectoryService;
use App\Domain\Organisation\Inventory\Services\TreatmentUsageService as Service;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\SupplierReturnFixture;

final class TreatmentUsageTest extends VisitTestCase
{
    private const RECORDERS = ['ca', 'ca_supervisor'];

    private const REVERSERS = ['ca_supervisor', 'director', 'finance_officer'];

    private function fx(): SupplierReturnFixture
    {
        return new SupplierReturnFixture($this->branch);
    }

    private function balance(array $f): string
    {
        return (string) (DB::table('inventory_stock_balances')->where('inventory_location_id', $f['location']->id)->where('inventory_batch_id', $f['returnBatchId'])->value('quantity') ?? '0.000');
    }

    /** @return array<string, mixed> */
    private function entry(array $f, string $quantity, array $overrides = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'retry_key' => (string) Str::uuid(), 'location_id' => $f['location']->id, 'batch_id' => $f['returnBatchId'],
            'unit' => $f['sku']->stock_unit, 'quantity' => $quantity, 'kind' => 'usage', 'reason' => 'wound_care', ...$overrides];
    }

    /** @return array<string, mixed> */
    private function undo(array $overrides = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'retry_key' => (string) Str::uuid(), 'reason' => 'recorded_in_error', ...$overrides];
    }

    private function as(User $actor): User
    {
        app(BranchAccessService::class)->select($actor, $this->branch);

        return $actor;
    }

    private function refused(callable $attempt, string $field): void
    {
        try {
            $attempt();
            $this->fail('Expected refusal on '.$field);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    private function room(string $kind, ?Branch $branch = null): int
    {
        $branch ??= $this->branch;

        return DB::table('branch_rooms')->insertGetId(['organisation_id' => $branch->organisation_id, 'branch_id' => $branch->id, 'kind' => $kind, 'name' => 'Synthetic '.$kind.' room '.Str::random(4),
            'sort_order' => 1, 'is_active' => true, 'lock_version' => 1, 'updated_by_user_id' => $this->fx()->actor('director', $branch)->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_exact_catalogue_roles_for_recording_and_reversal(): void
    {
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $this->assertSame(in_array($role, self::RECORDERS, true), Role::findByName($role)->hasPermissionTo(Service::RECORD), $role);
            $this->assertSame(in_array($role, self::REVERSERS, true), Role::findByName($role)->hasPermissionTo(Service::REVERSE), $role);
        }
        $f = $this->fx()->received('20.000');
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $actor = $this->as($this->fx()->actor($role));
            try {
                app(Service::class)->record($actor, $this->entry($f, '0.500'));
                $this->assertContains($role, self::RECORDERS);
            } catch (AuthorizationException) {
                $this->assertNotContains($role, self::RECORDERS);
            }
        }
        $this->assertSame((string) (20 - 0.5 * count(self::RECORDERS)).'.000', $this->balance($f));
    }

    public function test_usage_and_waste_debit_the_ledger_and_a_different_supervisor_reverses_once(): void
    {
        $f = $this->fx()->received('10.000');
        $service = app(Service::class);
        $ca = $this->as($this->fx()->actor('ca'));
        $room = $this->room('treatment');
        $auditMax = (int) DB::table('audit_logs')->max('id');
        $used = $service->record($ca, $this->entry($f, '2.500', ['room_id' => $room]));
        $wasted = $service->record($ca, $this->entry($f, '1.000', ['kind' => 'waste', 'reason' => 'spilled_or_broken']));
        $this->assertSame('6.500', $this->balance($f));
        $usage = DB::table('inventory_treatment_usages')->find($used);
        $this->assertSame([$room, 'usage', 'wound_care', '2.500', $ca->id], [(int) $usage->branch_room_id, $usage->kind, $usage->reason, $usage->quantity, (int) $usage->recorder_user_id]);
        $movement = DB::table('stock_movements')->find($usage->movement_id);
        $this->assertSame(['treatment_usage', 'inventory_treatment_usage', $usage->public_id, $f['location']->id, null], [$movement->movement_type, $movement->reference_type, $movement->reference_public_id, (int) $movement->source_location_id, $movement->destination_location_id]);
        $this->assertSame('treatment_waste', DB::table('stock_movements')->where('id', DB::table('inventory_treatment_usages')->where('id', $wasted)->value('movement_id'))->value('movement_type'));

        $this->assertSame(2, DB::table('audit_logs')->where('id', '>', $auditMax)->where('event', 'inventory.treatment_usage.recorded')->count());
        $metadata = DB::table('audit_logs')->where('id', '>', $auditMax)->where('event', 'inventory.treatment_usage.recorded')->orderBy('id')->value('metadata');
        $this->assertSame(['kind', 'movement_id', 'usage_id'], collect(json_decode((string) $metadata, true))->keys()->sort()->values()->all());

        $supervisor = $this->as($this->fx()->actor('ca_supervisor'));
        $this->assertSame($used, $service->reverse($supervisor, $used, $this->undo()));
        $this->assertSame('9.000', $this->balance($f));
        $reversal = DB::table('inventory_treatment_usage_reversals')->where('usage_id', $used)->sole();
        $credit = DB::table('stock_movements')->find($reversal->movement_id);
        $this->assertSame(['adjustment_in', 'inventory_treatment_usage', $usage->public_id, '2.500', $f['location']->id], [$credit->movement_type, $credit->reference_type, $credit->reference_public_id, $credit->quantity, (int) $credit->destination_location_id]);
        $this->refused(fn () => $service->reverse($this->as($this->fx()->actor('director')), $used, $this->undo()), 'state');
        $this->assertSame('9.000', $this->balance($f));
        try {
            $service->reverse($this->as($this->fx()->actor('ca')), $wasted, $this->undo());
            $this->fail('CA reversed an entry');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('inventory_treatment_usage_reversals')->where('usage_id', $wasted)->count());
        }
    }

    public function test_the_recorder_can_never_reverse_their_own_entry_in_domain_or_database(): void
    {
        $f = $this->fx()->received('5.000');
        $service = app(Service::class);
        $supervisor = $this->as($f['receiver']);
        $id = $service->record($supervisor, $this->entry($f, '1.000'));
        $this->refused(fn () => $service->reverse($supervisor, $id, $this->undo()), 'state');
        $usage = DB::table('inventory_treatment_usages')->find($id);
        try {
            DB::transaction(function () use ($f, $usage, $supervisor): void {
                $movement = DB::table('stock_movements')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'inventory_sku_id' => $usage->inventory_sku_id, 'inventory_batch_id' => $usage->inventory_batch_id, 'destination_location_id' => $f['location']->id, 'quantity' => '1.000', 'movement_type' => 'adjustment_in', 'reference_type' => 'inventory_treatment_usage', 'reference_public_id' => $usage->public_id, 'actor_user_id' => $supervisor->id, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                DB::table('inventory_treatment_usage_reversals')->insert(['organisation_id' => $this->organisation->id, 'usage_id' => $usage->id, 'actor_user_id' => $supervisor->id, 'reason' => 'recorded_in_error', 'movement_id' => $movement, 'retry_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'created_at' => now()]);
            });
            $this->fail('PostgreSQL accepted a self-reversal');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $this->assertSame('4.000', $this->balance($f));
    }

    public function test_held_or_expired_batches_are_refused_for_use_and_waste_and_left_to_the_governed_write_off(): void
    {
        $service = app(Service::class);
        $ca = $this->as($this->fx()->actor('ca'));
        $f = $this->fx()->quarantined('4.000');
        $g = $this->fx()->received('3.000');
        DB::table('inventory_batches')->where('id', $g['returnBatchId'])->update(['received_at' => now()->subDays(10), 'expiry_date' => now()->subDays(3)->toDateString()]);
        foreach ([$f, $g] as $fixture) {
            foreach ([[], ['kind' => 'waste', 'reason' => 'contaminated']] as $overrides) {
                try {
                    $service->record($ca, $this->entry($fixture, '1.000', $overrides));
                    $this->fail('Ineligible batch recorded');
                } catch (ValidationException $e) {
                    $this->assertStringContainsString('governed Write-off', $e->errors()['batch_id'][0]);
                }
            }
        }
        $this->assertSame(['4.000', '3.000'], [$this->balance($f), $this->balance($g)]);
        $this->assertSame(0, DB::table('inventory_treatment_usages')->count());
        $this->actingAs($ca)->get(route('inventory.treatment-usage'))->assertInertia(fn ($page) => $page
            ->where('usage.stock.rows', fn ($rows) => collect($rows)->where('batch_id', $f['returnBatchId'])->every(fn ($row) => $row['usable'] === false && $row['eligibility_reason'] === 'held')
                && collect($rows)->where('batch_id', $g['returnBatchId'])->every(fn ($row) => $row['usable'] === false && $row['eligibility_reason'] === 'expiry')));
    }

    public function test_a_reversal_is_refused_once_a_governed_count_or_write_off_has_posted_for_the_batch(): void
    {
        $f = $this->fx()->received('10.000');
        $service = app(Service::class);
        $id = $service->record($this->as($this->fx()->actor('ca')), $this->entry($f, '5.000', ['kind' => 'waste', 'reason' => 'spilled_or_broken']));
        // A later governed count found the "wasted" stock and posted it back (the deferred approval-evidence
        // trigger does not fire inside the test transaction).
        DB::table('stock_movements')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'inventory_sku_id' => $f['sku']->id, 'inventory_batch_id' => $f['returnBatchId'], 'destination_location_id' => $f['location']->id, 'quantity' => '5.000', 'movement_type' => 'stocktake_gain', 'reference_type' => 'inventory_governed_case', 'reference_public_id' => (string) Str::uuid(), 'actor_user_id' => $f['director']->id, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->refused(fn () => $service->reverse($this->as($this->fx()->actor('ca_supervisor')), $id, $this->undo()), 'state');
        $this->assertSame(0, DB::table('inventory_treatment_usage_reversals')->count());
    }

    public function test_the_page_says_when_a_later_governed_count_blocks_a_reversal(): void
    {
        // V2 page 4 (Daniel): the row says so before the press, by the same rule reverse() applies; canReverse is unchanged.
        $f = $this->fx()->received('10.000');
        app(Service::class)->record($this->as($this->fx()->actor('ca')), $this->entry($f, '5.000', ['kind' => 'waste', 'reason' => 'spilled_or_broken']));
        $finance = $this->as($this->fx()->actor('finance_officer'));
        $row = fn () => $this->actingAs($finance)->get(route('inventory.treatment-usage'))->viewData('page')['props']['usage']['entries']['rows'][0];
        $this->assertFalse($row()['later_governed']);
        $this->assertTrue($row()['canReverse']);

        DB::table('stock_movements')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'inventory_sku_id' => $f['sku']->id, 'inventory_batch_id' => $f['returnBatchId'], 'destination_location_id' => $f['location']->id, 'quantity' => '5.000', 'movement_type' => 'stocktake_gain', 'reference_type' => 'inventory_governed_case', 'reference_public_id' => (string) Str::uuid(), 'actor_user_id' => $f['director']->id, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertTrue($row()['later_governed']);
        $this->assertTrue($row()['canReverse']);
    }

    public function test_quantity_unit_reason_room_and_stock_limits(): void
    {
        $f = $this->fx()->received('5.000');
        $service = app(Service::class);
        $ca = $this->as($this->fx()->actor('ca'));
        $this->refused(fn () => $service->record($ca, $this->entry($f, '5.001')), 'quantity');
        $this->refused(fn () => $service->record($ca, $this->entry($f, '1.0005')), 'quantity');
        $this->refused(fn () => $service->record($ca, $this->entry($f, '0')), 'quantity');
        $this->refused(fn () => $service->record($ca, $this->entry($f, '1.000', ['unit' => 'box'])), 'quantity');
        $this->refused(fn () => $service->record($ca, $this->entry($f, '1.000', ['reason' => 'spilled_or_broken'])), 'reason');
        $this->refused(fn () => $service->record($ca, $this->entry($f, '1.000', ['kind' => 'waste', 'reason' => 'procedure'])), 'reason');
        $this->refused(fn () => $service->record($ca, $this->entry($f, '1.000', ['room_id' => $this->room('consultation')])), 'room_id');
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'SUNGAI_BESI')->sole();
        $this->refused(fn () => $service->record($ca, $this->entry($f, '1.000', ['room_id' => $this->room('treatment', $other)])), 'room_id');
        $this->assertSame('5.000', $this->balance($f));
        $this->assertSame(0, DB::table('inventory_treatment_usages')->count());
        $service->record($ca, $this->entry($f, '5.000'));
        $this->assertSame('0.000', $this->balance($f));
        $this->refused(fn () => $service->record($ca, $this->entry($f, '0.001')), 'quantity');
    }

    public function test_retry_keys_replay_and_refuse_a_changed_payload(): void
    {
        $f = $this->fx()->received('5.000');
        $service = app(Service::class);
        $ca = $this->as($this->fx()->actor('ca'));
        $entry = $this->entry($f, '1.000');
        $id = $service->record($ca, $entry);
        $this->assertSame($id, $service->record($ca, $entry));
        $this->assertSame('4.000', $this->balance($f));
        $this->refused(fn () => $service->record($ca, [...$entry, 'quantity' => '2.000']), 'retry_key');
        $supervisor = $this->as($this->fx()->actor('ca_supervisor'));
        $undo = $this->undo();
        $service->reverse($supervisor, $id, $undo);
        $this->assertSame($id, $service->reverse($supervisor, $id, $undo));
        $this->refused(fn () => $service->reverse($supervisor, $id, [...$undo, 'reason' => 'wrong_quantity']), 'retry_key');
        $this->assertSame('5.000', $this->balance($f));
        $this->assertSame(1, DB::table('inventory_treatment_usage_reversals')->count());
    }

    public function test_fresh_revocation_ended_assignment_inactive_foreign_branch_and_organisation(): void
    {
        $f = $this->fx()->received('5.000');
        $service = app(Service::class);
        $refused = function (callable $attempt, string $label) use ($f): void {
            try {
                $attempt();
                $this->fail($label);
            } catch (AuthorizationException|HttpException) {
                $this->assertSame('5.000', $this->balance($f), $label);
            }
        };
        $actor = $this->as($this->fx()->actor('ca'));
        $actor->syncRoles(['marketing']);
        $refused(fn () => $service->record($actor, $this->entry($f, '1.000')), 'Revoked permission');
        $actor->syncRoles(['ca']);
        DB::table('staff_branch_assignments')->where('staff_profile_id', $actor->staffProfile->id)->update(['valid_from' => now()->subDays(3)->toDateString(), 'valid_until' => now()->subDays(2)->toDateString()]);
        $refused(fn () => $service->record($actor, $this->entry($f, '1.000')), 'Ended assignment');
        $inactive = $this->as($this->fx()->actor('ca_supervisor'));
        $inactive->forceFill(['is_active' => false])->save();
        $refused(fn () => $service->record($inactive, $this->entry($f, '1.000')), 'Inactive');
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'SUNGAI_BESI')->sole();
        $foreign = $this->fx()->actor('ca', $other);
        $refused(fn () => $service->record($foreign, $this->entry($f, '1.000', ['expected_branch_id' => $other->id])), 'Foreign-branch location');
        $id = $service->record($this->as($this->fx()->actor('ca')), $this->entry($f, '1.000'));
        $foreignSupervisor = $this->fx()->actor('ca_supervisor', $other);
        try {
            $service->reverse($foreignSupervisor, $id, $this->undo(['expected_branch_id' => $other->id]));
            $this->fail('Foreign-branch reversal');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $org = Organisation::query()->create(['code' => 'SYN-STK8', 'name' => 'Synthetic usage organisation', 'is_active' => true]);
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $org->id, 'code' => 'SYN-STK8', 'name' => 'Synthetic usage branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $outsider = (new SupplierReturnFixture($branch))->actor('ca');
        try {
            $service->record($outsider, $this->entry($f, '1.000', ['expected_branch_id' => $branch->id]));
            $this->fail('Foreign organisation');
        } catch (AuthorizationException|HttpException) {
            $this->assertSame('4.000', $this->balance($f));
        }
    }

    public function test_audit_failure_rolls_back_recording_and_reversal(): void
    {
        $f = $this->fx()->received('5.000');
        $ca = $this->as($this->fx()->actor('ca'));
        $snapshot = fn (): string => DB::table('stock_movements')->orderBy('id')->get()->toJson().DB::table('inventory_stock_balances')->orderBy('id')->get()->toJson();
        $before = $snapshot();
        $this->instance(AuditRecorder::class, \Mockery::mock(AuditRecorder::class, fn ($m) => $m->shouldReceive('record')->andReturnNull()));
        try {
            app(Service::class)->record($ca, $this->entry($f, '1.000'));
            $this->fail('Unaudited usage');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        app()->forgetInstance(AuditRecorder::class);
        $this->assertSame($before, $snapshot());
        $this->assertSame(0, DB::table('inventory_treatment_usages')->count());
        $id = app(Service::class)->record($ca, $this->entry($f, '1.000'));
        $supervisor = $this->as($this->fx()->actor('ca_supervisor'));
        $before = $snapshot();
        $this->instance(AuditRecorder::class, \Mockery::mock(AuditRecorder::class, fn ($m) => $m->shouldReceive('record')->andReturnNull()));
        try {
            app(Service::class)->reverse($supervisor, $id, $this->undo());
            $this->fail('Unaudited reversal');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        app()->forgetInstance(AuditRecorder::class);
        $this->assertSame($before, $snapshot());
        $this->assertSame(0, DB::table('inventory_treatment_usage_reversals')->count());
    }

    public function test_postgresql_evidence_is_append_only_and_usage_movements_need_evidence(): void
    {
        $f = $this->fx()->received('5.000');
        $id = app(Service::class)->record($this->as($this->fx()->actor('ca')), $this->entry($f, '1.000'));
        app(Service::class)->reverse($this->as($this->fx()->actor('ca_supervisor')), $id, $this->undo());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        foreach (['inventory_treatment_usages', 'inventory_treatment_usage_reversals'] as $table) {
            foreach (["UPDATE {$table} SET id=id", "DELETE FROM {$table}", "TRUNCATE {$table} CASCADE"] as $sql) {
                try {
                    DB::transaction(fn () => DB::statement($sql));
                    $this->fail('Mutable evidence: '.$sql);
                } catch (QueryException $e) {
                    $this->assertSame('P0001', $e->errorInfo[0], $sql);
                }
            }
        }
        $usage = DB::table('inventory_treatment_usages')->find($id);
        foreach ([['treatment_usage', 'inventory_treatment_usage', 'source_location_id'], ['treatment_waste', 'inventory_adjustment', 'source_location_id'], ['adjustment_in', 'inventory_treatment_usage', 'destination_location_id']] as [$type, $reference, $side]) {
            try {
                DB::transaction(function () use ($f, $usage, $type, $reference, $side): void {
                    DB::table('stock_movements')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'inventory_sku_id' => $usage->inventory_sku_id, 'inventory_batch_id' => $usage->inventory_batch_id, $side => $f['location']->id, 'quantity' => '1.000', 'movement_type' => $type, 'reference_type' => $reference, 'reference_public_id' => $usage->public_id, 'actor_user_id' => $usage->recorder_user_id, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                });
                $this->fail('Unevidenced movement accepted: '.$type);
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0], $type);
            }
        }
        $this->assertSame(2, DB::table('stock_movements')->where('reference_type', 'inventory_treatment_usage')->count());
    }

    public function test_page_http_routes_and_movement_history(): void
    {
        $f = $this->fx()->received('10.000');
        $ca = $this->as($this->fx()->actor('ca'));
        app(Service::class)->record($ca, $this->entry($f, '2.000'));
        foreach (['ca' => 200, 'ca_supervisor' => 200, 'finance_officer' => 200, 'director' => 200, 'administrator' => 200, 'technical_admin' => 403, 'resident_doctor' => 403] as $role => $status) {
            $this->actingAs($this->as($this->fx()->actor($role)))->get(route('inventory.treatment-usage'))->assertStatus($status);
        }
        $this->actingAs($ca)->get(route('inventory.treatment-usage'))->assertInertia(fn ($page) => $page->component('Inventory/TreatmentUsage')
            ->where('usage.canRecord', true)->where('usage.canReverse', false)
            ->where('usage.stock.rows', fn ($rows) => collect($rows)->contains(fn ($row) => (int) $row['batch_id'] === $f['returnBatchId'] && $row['on_hand'] === '8.000' && $row['usable'] === true))
            ->where('usage.entries.rows.0.recordedByYou', true)->where('usage.entries.rows.0.canReverse', false));
        $finance = $this->as($this->fx()->actor('finance_officer'));
        $this->actingAs($finance)->get(route('inventory.treatment-usage'))->assertInertia(fn ($page) => $page->where('usage.canRecord', false)->where('usage.stock.total', 0)->where('usage.entries.rows.0.canReverse', true));
        $this->actingAs($finance)->post(route('inventory.treatment-usage.store'), $this->entry($f, '1.000'))->assertForbidden();
        $this->actingAs($ca)->post(route('inventory.treatment-usage.store'), $this->entry($f, '99.000'))->assertRedirect(route('inventory.treatment-usage'))->assertSessionHasErrors('quantity');
        $this->actingAs($ca)->post(route('inventory.treatment-usage.store'), $this->entry($f, '1.000', ['kind' => 'waste', 'reason' => 'opened_not_used']))->assertRedirect(route('inventory.treatment-usage'))->assertSessionHasNoErrors();
        $id = (int) DB::table('inventory_treatment_usages')->max('id');
        $this->actingAs($ca)->post(route('inventory.treatment-usage.reverse', ['treatmentUsage' => $id]), $this->undo())->assertForbidden();
        $this->actingAs($finance)->post(route('inventory.treatment-usage.reverse', ['treatmentUsage' => $id]), $this->undo())->assertRedirect(route('inventory.treatment-usage'))->assertSessionHasNoErrors();
        $this->assertSame('8.000', $this->balance($f));
        $this->actingAs($finance)->get(route('inventory.index', ['tab' => 'movements', 'movement_type' => 'treatment_usage']))
            ->assertInertia(fn ($page) => $page->where('inventory.total', 1)->where('inventory.data.0.typeLabel', 'Treatment Room Use')
                ->where('inventory.data.0.quantityDisplay', '-2')->where('inventory.data.0.reference', 'Treatment room usage'));
        foreach ([StockMovement::TYPE_TREATMENT_USAGE, StockMovement::TYPE_TREATMENT_WASTE] as $type) {
            $this->assertContains($type, InventoryDirectoryService::MOVEMENT_TYPES);
        }
    }

    public function test_unrelated_tables_are_untouched(): void
    {
        $f = $this->fx()->received('10.000');
        $owned = ['inventory_treatment_usages', 'inventory_treatment_usage_reversals', 'audit_logs', 'stock_movements', 'inventory_stock_balances'];
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
        $ca = $this->as($this->fx()->actor('ca'));
        $supervisor = $this->as($this->fx()->actor('ca_supervisor'));
        $before = $snapshot();
        $moves = DB::table('stock_movements')->orderBy('id')->get()->toJson();
        $max = DB::table('stock_movements')->max('id');
        $id = app(Service::class)->record($this->as($ca), $this->entry($f, '3.000'));
        app(Service::class)->reverse($this->as($supervisor), $id, $this->undo());
        $this->assertSame($before, $snapshot());
        $this->assertSame($moves, DB::table('stock_movements')->where('id', '<=', $max)->orderBy('id')->get()->toJson());
        $this->assertSame(['3.000', '3.000'], DB::table('stock_movements')->where('id', '>', $max)->pluck('quantity')->all());
        $this->assertSame('10.000', $this->balance($f));
    }
}

<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Services\ControlledRegisterService;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\GovernedStockFixture;

/** STK-24 (plan approved by B.E.R.N.I.S.S for the owner, 2026-11-11): the controlled-drug register and its checks. */
final class ControlledRegisterTest extends VisitTestCase
{
    private function actorAt(string $role): User
    {
        $actor = (new GovernedStockFixture($this->branch))->actor($role);
        app(BranchAccessService::class)->select($actor, $this->branch);

        return $actor;
    }

    /** @return array<string, mixed> stock fixture with its SKU linked to a controlled medicine */
    private function controlled(string $quantity = '10.000'): array
    {
        $f = (new GovernedStockFixture($this->branch))->build($quantity);
        $medicine = MedicineCatalogueItem::factory()->create(['organisation_id' => $this->branch->organisation_id, 'created_by_user_id' => $f['proposer']->id,
            'updated_by_user_id' => $f['proposer']->id, 'is_controlled' => true]);
        DB::table('medicine_catalogue_inventory_skus')->insert(['organisation_id' => $this->branch->organisation_id, 'medicine_catalogue_item_id' => $medicine->id,
            'inventory_sku_id' => $f['sku']->id, 'is_active' => true, 'approved_by_user_id' => $f['proposer']->id, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return $f + ['medicine' => $medicine];
    }

    /** @return array<string, mixed> */
    private function registerPage(User $viewer, string $sku, array $query = []): array
    {
        return $this->actingAs($viewer)->get(route('inventory.controlled-register.show', ['sku' => $sku, ...$query]))->assertOk()->viewData('page')['props']['register'];
    }

    /** @return array<string, mixed> */
    private function input(int $seen, string $counted, array $extra = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), 'seen_movement_id' => $seen, 'counted' => $counted, ...$extra];
    }

    /** More stock arrives as a new batch, because an opening balance is recorded once per batch and location. */
    private function receive(array $f, User $actor, string $quantity): void
    {
        $batch = $f['batch']->replicate();
        $batch->forceFill(['public_id' => (string) Str::uuid(), 'batch_number' => 'SYN-'.Str::upper(Str::random(8))])->save();
        app(InventoryMovementService::class)->openingBalance($actor, ['expected_branch_id' => $this->branch->id, 'sku_public_id' => $f['sku']->public_id,
            'location_public_id' => $f['location']->public_id, 'batch_public_id' => $batch->public_id, 'quantity' => $quantity]);
    }

    public function test_only_controlled_medicines_are_listed_with_their_balance_and_check_status(): void
    {
        $f = $this->controlled('10.000');
        $plain = (new GovernedStockFixture($this->branch))->build('4.000');
        $viewer = $this->actorAt('finance_officer');

        $rows = $this->actingAs($viewer)->get(route('inventory.controlled-register'))->assertOk()->viewData('page')['props']['register']['rows'];
        $this->assertSame([$f['sku']->public_id], array_column($rows, 'skuPublicId'));
        $this->assertSame(['10', 'never', null], [$rows[0]['balance'], $rows[0]['status'], $rows[0]['lastCheckedOn']]);
        $this->assertNotContains($plain['sku']->public_id, array_column($rows, 'skuPublicId'));

        // A CA has no register permission; other roles without it are refused too.
        foreach (['ca', 'resident_doctor', 'panel_officer', 'administrator'] as $role) {
            $this->actingAs($this->actorAt($role))->get(route('inventory.controlled-register'))->assertForbidden();
        }
        foreach (['ca_supervisor', 'director'] as $role) {
            $this->actingAs($this->actorAt($role))->get(route('inventory.controlled-register'))->assertOk();
        }

        $register = $this->registerPage($viewer, $f['sku']->public_id);
        $this->actingAs($viewer)->post(route('inventory.controlled-register.check', ['sku' => $f['sku']->public_id]), $this->input($register['newestMovementId'], '10'))
            ->assertRedirect(route('inventory.controlled-register.show', ['sku' => $f['sku']->public_id]))->assertSessionHas('success', 'Check recorded.');
        $row = $this->actingAs($viewer)->get(route('inventory.controlled-register'))->viewData('page')['props']['register']['rows'][0];
        $this->assertSame(['checked', 'matches'], [$row['status'], $row['lastOutcome']]);

        $this->receive($f, $f['proposer'], '2.000');
        $row = $this->actingAs($viewer)->get(route('inventory.controlled-register'))->viewData('page')['props']['register']['rows'][0];
        $this->assertSame(['moved_since', '12'], [$row['status'], $row['balance']]);
    }

    public function test_the_register_runs_a_balance_with_in_out_and_moves_between_branch_locations(): void
    {
        $f = $this->controlled('10.000');
        $second = new InventoryLocation;
        $second->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->branch->organisation_id, 'branch_id' => $this->branch->id, 'code' => 'SYN-CD-STORE',
            'name' => 'Synthetic CD cupboard', 'type' => 'store', 'is_active' => true])->save();
        $this->receive($f, $f['proposer'], '5.000');
        app(InventoryMovementService::class)->transfer($f['proposer'], ['expected_branch_id' => $this->branch->id, 'sku_public_id' => $f['sku']->public_id,
            'batch_public_id' => $f['batch']->public_id, 'source_location_public_id' => $f['location']->public_id, 'destination_location_public_id' => $second->public_id, 'quantity' => '3.000']);
        $viewer = $this->actorAt('finance_officer');

        $register = $this->registerPage($viewer, $f['sku']->public_id);
        $this->assertSame(['0', '15', '15', 3], [$register['opening'], $register['closing'], $register['balanceNow'], $register['lines']['total']]);
        $rows = $register['lines']['rows'];
        $this->assertSame(['10', null, '10'], [$rows[0]['in'], $rows[0]['out'], $rows[0]['balance']]);
        $this->assertSame(['5', '15'], [$rows[1]['in'], $rows[1]['balance']]);
        // A move inside the branch shows both sides and leaves the balance unchanged.
        $this->assertSame(['3', '3', '15', 'Synthetic governed dispensary → Synthetic CD cupboard'], [$rows[2]['in'], $rows[2]['out'], $rows[2]['balance'], $rows[2]['location']]);
        $this->assertSame('Synthetic stock ca_supervisor', $rows[0]['recordedBy']);
        // No patient or prescriber is ever on the register.
        $this->assertDoesNotMatchRegularExpression('/patient|prescriber|ic_number|nric/i', json_encode($register, JSON_THROW_ON_ERROR));

        // A range after today is empty but opens on the balance so far.
        $later = now()->setTimezone($this->branch->timezone)->addDays(5)->toDateString();
        $empty = $this->registerPage($viewer, $f['sku']->public_id, ['from' => $later, 'to' => $later]);
        $this->assertSame(['15', '15', 0], [$empty['opening'], $empty['closing'], $empty['lines']['total']]);

        // A medicine that is not controlled has no register.
        $plain = (new GovernedStockFixture($this->branch))->build('1.000');
        $this->actingAs($viewer)->get(route('inventory.controlled-register.show', ['sku' => $plain['sku']->public_id]))->assertNotFound();
    }

    public function test_the_check_is_signed_by_a_second_person(): void
    {
        $f = $this->controlled('10.000');
        $url = route('inventory.controlled-register.check', ['sku' => $f['sku']->public_id]);
        $back = route('inventory.controlled-register.show', ['sku' => $f['sku']->public_id]);
        $seen = $this->registerPage($f['proposer'], $f['sku']->public_id)['newestMovementId'];

        // Whoever recorded a movement in the range cannot check it, on the page or by posting.
        $page = $this->registerPage($f['proposer'], $f['sku']->public_id);
        $this->assertSame([false, 'You recorded a movement since the previous check, so another person must check this register.'], [$page['can']['check'], $page['checkBlockedReason']]);
        $this->actingAs($f['proposer'])->post($url, $this->input($seen, '10'))->assertRedirect($back)
            ->assertSessionHasErrors(['counted' => 'You recorded a movement since the previous check, so another person must check this register.']);

        $first = $this->actorAt('finance_officer');
        $this->actingAs($first)->post($url, $this->input($seen, '10'))->assertSessionHasNoErrors();
        $check = DB::table('inventory_controlled_register_checks')->sole();
        $this->assertSame([1, 0, $seen, '10.000', 'matches', null], [(int) $check->sequence, (int) $check->after_movement_id, (int) $check->through_movement_id, (string) $check->counted_quantity, $check->outcome, $check->reason]);

        // The same person cannot do the next check, even with no new movement.
        $this->actingAs($first)->post($url, $this->input($seen, '10'))->assertSessionHasErrors(['counted' => 'You did the previous check, so another person must check this register.']);

        // A count that differs needs a reason, and "other" needs a note; the check never moves stock.
        $second = $this->actorAt('director');
        $this->actingAs($second)->post($url, $this->input($seen, '9'))->assertSessionHasErrors(['reason' => 'The count differs from the register. Choose a reason.']);
        $this->actingAs($second)->post($url, $this->input($seen, '9', ['reason' => 'other']))->assertSessionHasErrors(['note' => 'Add a note to explain the difference.']);
        $movements = DB::table('stock_movements')->count();
        $this->actingAs($second)->post($url, $this->input($seen, '9', ['reason' => 'count_to_follow']))->assertSessionHasNoErrors();
        $this->assertSame($movements, DB::table('stock_movements')->count());
        $this->assertSame(['differs', 'count_to_follow', '10.000', '9.000'], array_values((array) DB::table('inventory_controlled_register_checks')->where('sequence', 2)->first(['outcome', 'reason', 'ledger_quantity', 'counted_quantity'])));

        // A movement arriving after the page was opened makes the check stale.
        $this->receive($f, $f['proposer'], '1.000');
        $third = $this->actorAt('ca_supervisor');
        $this->actingAs($third)->post($url, $this->input($seen, '11'))->assertSessionHasErrors(['seen_movement_id' => 'The register changed. Reload and check again.']);

        // Audit holds ids and codes only.
        $audit = DB::table('audit_logs')->where('event', 'inventory.controlled_register.checked')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertDoesNotMatchRegularExpression('/quantity|counted|ledger|note/i', (string) $audit->metadata);
    }

    public function test_a_retried_check_returns_the_same_check(): void
    {
        $f = $this->controlled('10.000');
        $checker = $this->actorAt('finance_officer');
        $service = app(ControlledRegisterService::class);
        $input = $this->input($this->registerPage($checker, $f['sku']->public_id)['newestMovementId'], '10');
        $id = $service->check($checker, $f['sku']->public_id, $input);
        $this->assertSame($id, $service->check($checker, $f['sku']->public_id, $input));
        $this->assertSame(1, DB::table('inventory_controlled_register_checks')->count());
        $this->expectExceptionMessage('This page was already used for a different check.');
        $service->check($checker, $f['sku']->public_id, [...$input, 'counted' => '9', 'reason' => 'investigate']);
    }

    public function test_postgresql_keeps_checks_as_signed_and_refuses_the_recorder(): void
    {
        $f = $this->controlled('10.000');
        $newest = (int) DB::table('stock_movements')->where('inventory_sku_id', $f['sku']->id)->max('id');
        $row = ['public_id' => (string) Str::uuid(), 'organisation_id' => $this->branch->organisation_id, 'branch_id' => $this->branch->id, 'inventory_sku_id' => $f['sku']->id,
            'sequence' => 1, 'after_movement_id' => 0, 'through_movement_id' => $newest, 'ledger_quantity' => '10.000', 'counted_quantity' => '10.000', 'outcome' => 'matches',
            'checked_at' => now(), 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64)];
        $refused = function (array $values, string $message): void {
            try {
                DB::transaction(fn () => DB::table('inventory_controlled_register_checks')->insert($values));
                $this->fail('Accepted: '.$message);
            } catch (QueryException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        };

        $refused([...$row, 'checked_by_user_id' => $f['proposer']->id], 'Someone who recorded a movement in this range cannot check it');
        $checker = $this->actorAt('finance_officer');
        $refused([...$row, 'checked_by_user_id' => $checker->id, 'ledger_quantity' => '9.000', 'counted_quantity' => '9.000'], 'A controlled register check records the ledger balance');
        $refused([...$row, 'checked_by_user_id' => $checker->id, 'sequence' => 2], 'A controlled register check starts where the previous check ended');
        $plain = (new GovernedStockFixture($this->branch))->build('1.000');
        $refused([...$row, 'checked_by_user_id' => $checker->id, 'inventory_sku_id' => $plain['sku']->id], 'Only a controlled medicine has a controlled register');

        DB::table('inventory_controlled_register_checks')->insert([...$row, 'checked_by_user_id' => $checker->id]);
        $refused([...$row, 'public_id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(), 'sequence' => 2, 'after_movement_id' => $newest, 'checked_by_user_id' => $checker->id],
            'The previous checker cannot check this register again');
        foreach (['update' => fn () => DB::table('inventory_controlled_register_checks')->update(['note' => 'x']), 'delete' => fn () => DB::table('inventory_controlled_register_checks')->delete(),
            'truncate' => fn () => DB::statement('TRUNCATE inventory_controlled_register_checks')] as $what => $change) {
            try {
                DB::transaction($change);
                $this->fail("Accepted {$what}");
            } catch (QueryException $e) {
                $this->assertStringContainsString('Controlled register checks are kept as signed', $e->getMessage());
            }
        }
    }

    public function test_the_print_is_audited_and_holds_the_whole_range(): void
    {
        $f = $this->controlled('10.000');
        $viewer = $this->actorAt('director');
        $props = $this->actingAs($viewer)->get(route('inventory.controlled-register.print', ['sku' => $f['sku']->public_id]))->assertOk()->viewData('page');
        $this->assertSame('Inventory/ControlledRegisterPrint', $props['component']);
        $this->assertSame([1, '10'], [$props['props']['register']['lines']['total'], $props['props']['register']['closing']]);
        $audit = DB::table('audit_logs')->where('event', 'inventory.controlled_register.printed')->sole();
        $this->assertSame((int) $viewer->id, (int) $audit->actor_user_id);
    }
}

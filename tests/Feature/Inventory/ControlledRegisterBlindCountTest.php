<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\GovernedStockFixture;

/**
 * STK-40 (owner: "blind count yes"; option A approved by B.E.R.N.I.S.S for the owner, 2026-11-15): someone who may check a
 * controlled register now receives no figure that gives its balance away, until they have checked or a rule blocks them.
 */
final class ControlledRegisterBlindCountTest extends VisitTestCase
{
    private function actorAt(string $role): User
    {
        $actor = (new GovernedStockFixture($this->branch))->actor($role);
        app(BranchAccessService::class)->select($actor, $this->branch);

        return $actor;
    }

    /** @return array<string, mixed> stock fixture with its SKU linked to a controlled medicine (10 on hand) */
    private function controlled(): array
    {
        $f = (new GovernedStockFixture($this->branch))->build('10.000');
        $medicine = MedicineCatalogueItem::factory()->create(['organisation_id' => $this->branch->organisation_id, 'created_by_user_id' => $f['proposer']->id,
            'updated_by_user_id' => $f['proposer']->id, 'is_controlled' => true]);
        DB::table('medicine_catalogue_inventory_skus')->insert(['organisation_id' => $this->branch->organisation_id, 'medicine_catalogue_item_id' => $medicine->id,
            'inventory_sku_id' => $f['sku']->id, 'is_active' => true, 'approved_by_user_id' => $f['proposer']->id, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return $f;
    }

    /** @return array<string, mixed> */
    private function page(User $viewer, string $sku): array
    {
        return $this->actingAs($viewer)->get(route('inventory.controlled-register.show', ['sku' => $sku]))->assertOk()->viewData('page')['props']['register'];
    }

    /** @return array<string, mixed> */
    private function row(User $viewer): array
    {
        return $this->actingAs($viewer)->get(route('inventory.controlled-register'))->assertOk()->viewData('page')['props']['register']['rows'][0];
    }

    /** @param array<string, mixed> $register @return array<string, mixed> */
    private function input(array $register, string $counted, array $extra = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), 'seen_movement_id' => $register['newestMovementId'],
            'seen_check_sequence' => $register['newestCheckSequence'], 'counted' => $counted, ...$extra];
    }

    /** @param array<string, mixed> $register */
    private function assertNoBalance(array $register): void
    {
        $this->assertTrue($register['blind']);
        $this->assertSame([null, null, null], [$register['opening'], $register['closing'], $register['balanceNow']]);
        foreach ($register['lines']['rows'] as $line) {
            $this->assertSame([null, null, null], [$line['in'], $line['out'], $line['balance']]);
        }
        foreach ($register['checks'] as $check) {
            $this->assertSame([null, null, null], [$check['ledger'], $check['counted'], $check['difference']]);
        }
        // No figure that gives the balance away reaches the page.
        $this->assertStringNotContainsString('"10"', json_encode($register, JSON_THROW_ON_ERROR));
    }

    public function test_a_checker_gets_no_balance_and_no_print_until_their_count_then_sees_it_with_the_difference(): void
    {
        $f = $this->controlled();
        $checker = $this->actorAt('finance_officer');
        $sku = $f['sku']->public_id;

        $row = $this->row($checker);
        $this->assertSame([null, true], [$row['balance'], $row['blind']]);
        $register = $this->page($checker, $sku);
        $this->assertNoBalance($register);
        $this->assertTrue($register['can']['check']);
        $this->actingAs($checker)->get(route('inventory.controlled-register.print', ['sku' => $sku]))->assertForbidden();
        $this->actingAs($checker)->postJson(route('inventory.controlled-register.print.record', ['sku' => $sku]),
            ['expected_branch_id' => $this->branch->id, 'from' => $register['filters']['from'], 'to' => $register['filters']['to']])->assertForbidden();
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'inventory.controlled_register.printed')->count());

        // A count that differs is refused by the existing rule until a reason is chosen; only "differs" is said, never the balance.
        $this->actingAs($checker)->post(route('inventory.controlled-register.check', ['sku' => $sku]), $this->input($register, '8'))
            ->assertSessionHasErrors(['reason' => 'The count differs from the register. Choose a reason.']);
        $this->assertSame(0, DB::table('inventory_controlled_register_checks')->count());
        $this->assertNoBalance($this->page($checker, $sku));

        $this->actingAs($checker)->post(route('inventory.controlled-register.check', ['sku' => $sku]), $this->input($register, '8', ['reason' => 'investigate']))
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Check recorded.');

        // Their check is in: now blocked as the previous checker, so every figure comes back with the expected balance and the difference.
        $after = $this->page($checker, $sku);
        $this->assertFalse($after['blind']);
        $this->assertSame(['10', '10'], [$after['closing'], $after['balanceNow']]);
        $this->assertSame(['10', '8', '-2'], [$after['checks'][0]['ledger'], $after['checks'][0]['counted'], $after['checks'][0]['difference']]);
        $this->assertSame('10', $after['lines']['rows'][0]['balance']);
        $this->assertSame(['10', false], [$this->row($checker)['balance'], $this->row($checker)['blind']]);
        $this->actingAs($checker)->get(route('inventory.controlled-register.print', ['sku' => $sku]))->assertOk();
    }

    public function test_a_second_checker_still_counts_blind_after_someone_elses_check(): void
    {
        $f = $this->controlled();
        $first = $this->actorAt('finance_officer');
        $second = $this->actorAt('director');
        $sku = $f['sku']->public_id;
        $register = $this->page($first, $sku);
        $this->actingAs($first)->post(route('inventory.controlled-register.check', ['sku' => $sku]), $this->input($register, '10'))->assertSessionHasNoErrors();

        // The director did not record a movement and did not do the last check, so may check now: blind, including the earlier check's quantities.
        $page = $this->page($second, $sku);
        $this->assertNoBalance($page);
        $this->assertSame(['matches', 1], [$page['checks'][0]['outcome'], count($page['checks'])]);
        $this->actingAs($second)->post(route('inventory.controlled-register.check', ['sku' => $sku]), $this->input($page, '10'))->assertSessionHasNoErrors();
        $this->assertSame(['10', '0'], [$this->page($second, $sku)['balanceNow'], $this->page($second, $sku)['checks'][0]['difference']]);
    }

    public function test_someone_a_rule_blocks_sees_everything_as_before_and_the_check_rules_and_audit_are_unchanged(): void
    {
        $f = $this->controlled();
        $sku = $f['sku']->public_id;

        // The supervisor who recorded the opening may not check, so sees the balance and may print.
        $page = $this->page($f['proposer'], $sku);
        $this->assertSame([false, false, '10'], [$page['blind'], $page['can']['check'], $page['balanceNow']]);
        $this->assertSame('You recorded a movement since the previous check, so another person must check this register.', $page['checkBlockedReason']);
        $this->assertSame(['10', false], [$this->row($f['proposer'])['balance'], $this->row($f['proposer'])['blind']]);
        $this->actingAs($f['proposer'])->get(route('inventory.controlled-register.print', ['sku' => $sku]))->assertOk();
        $this->actingAs($f['proposer'])->post(route('inventory.controlled-register.check', ['sku' => $sku]), $this->input($page, '10'))
            ->assertSessionHasErrors(['counted' => 'You recorded a movement since the previous check, so another person must check this register.']);

        // A blind check writes the same audit row as before: ids and codes, no quantities.
        $checker = $this->actorAt('finance_officer');
        $this->actingAs($checker)->post(route('inventory.controlled-register.check', ['sku' => $sku]), $this->input($this->page($checker, $sku), '10'))->assertSessionHasNoErrors();
        $audit = DB::table('audit_logs')->where('event', 'inventory.controlled_register.checked')->sole();
        $this->assertSame(['controlled_register_check_id', 'inventory_sku_id', 'outcome', 'reason'], collect(json_decode((string) $audit->metadata, true))->keys()->sort()->values()->all());
    }
}

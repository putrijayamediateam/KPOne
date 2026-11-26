<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\GovernedStockFixture;

/**
 * STK-40b (owner, 16 Nov 2026: "go with option b for blind count"; no second person on the reason): a check that differs
 * is recorded with no reason, and the person who recorded it adds the reason afterwards, once, in an append-only step.
 */
final class ControlledRegisterReasonTest extends VisitTestCase
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

    /** Records a check by $checker with the counted quantity and returns its public id. */
    private function check(User $checker, string $sku, string $counted): string
    {
        $register = $this->page($checker, $sku);
        $this->actingAs($checker)->post(route('inventory.controlled-register.check', ['sku' => $sku]), ['expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(),
            'seen_movement_id' => $register['newestMovementId'], 'seen_check_sequence' => $register['newestCheckSequence'], 'counted' => $counted])->assertSessionHasNoErrors();

        return (string) DB::table('inventory_controlled_register_checks')->orderByDesc('sequence')->value('public_id');
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function reason(array $extra, ?string $key = null): array
    {
        return ['expected_branch_id' => $this->branch->id, 'idempotency_key' => $key ?? (string) Str::uuid(), ...$extra];
    }

    public function test_only_the_checker_adds_the_reason_once_and_the_audit_holds_no_note_text(): void
    {
        $f = $this->controlled();
        $sku = $f['sku']->public_id;
        $checker = $this->actorAt('finance_officer');
        $differing = $this->check($checker, $sku, '7');
        $url = route('inventory.controlled-register.reason', ['sku' => $sku, 'check' => $differing]);

        // The list says the reason is needed, in words for everyone; only the checker may add it.
        $director = $this->actorAt('director');
        $row = $this->actingAs($director)->get(route('inventory.controlled-register'))->assertOk()->viewData('page')['props']['register']['rows'][0];
        $this->assertTrue($row['reasonNeeded']);
        $this->assertSame([true, false], [$this->page($director, $sku)['checks'][0]['reasonNeeded'], $this->page($director, $sku)['checks'][0]['canAddReason']]);
        $this->actingAs($director)->post($url, $this->reason(['reason' => 'investigate']))
            ->assertSessionHasErrors(['reason' => 'Only the person who recorded this check can add its reason.']);
        $this->assertSame(0, DB::table('inventory_controlled_register_check_reasons')->count());

        // A retry with the same key returns the same reason; a second reason is refused.
        $key = (string) Str::uuid();
        $this->actingAs($checker)->post($url, $this->reason(['reason' => 'other', 'note' => 'Box found in the second cabinet'], $key))->assertSessionHasNoErrors()->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Reason added.']);
        $this->actingAs($checker)->post($url, $this->reason(['reason' => 'other', 'note' => 'Box found in the second cabinet'], $key))->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('inventory_controlled_register_check_reasons')->count());
        $this->actingAs($checker)->post($url, $this->reason(['reason' => 'investigate']))->assertSessionHasErrors(['reason' => 'A reason was already added to this check.']);

        $check = $this->page($director, $sku)['checks'][0];
        $this->assertSame(['other', 'Box found in the second cabinet', false, false], [$check['reason'], $check['note'], $check['reasonNeeded'], $check['canAddReason']]);
        $this->assertFalse($this->actingAs($director)->get(route('inventory.controlled-register'))->viewData('page')['props']['register']['rows'][0]['reasonNeeded']);

        $audit = DB::table('audit_logs')->where('event', 'inventory.controlled_register.check_explained')->sole();
        $this->assertSame('other', json_decode((string) $audit->metadata, true)['reason']);
        $this->assertDoesNotMatchRegularExpression('/cabinet|note|quantity|counted|ledger/i', (string) $audit->metadata);
        $this->assertSame((int) $checker->id, (int) $audit->actor_user_id);
    }

    public function test_a_matching_check_takes_no_reason_and_a_reason_needs_a_valid_code(): void
    {
        $f = $this->controlled();
        $sku = $f['sku']->public_id;
        $checker = $this->actorAt('finance_officer');
        $matching = $this->check($checker, $sku, '10');
        $url = route('inventory.controlled-register.reason', ['sku' => $sku, 'check' => $matching]);

        $this->assertFalse($this->page($checker, $sku)['checks'][0]['reasonNeeded']);
        $this->actingAs($checker)->post($url, $this->reason(['reason' => 'investigate']))->assertSessionHasErrors(['reason' => 'This check matches the register, so it needs no reason.']);
        $this->actingAs($checker)->post($url, $this->reason(['reason' => 'lost']))->assertSessionHasErrors(['reason' => 'Choose a reason.']);
        $this->actingAs($checker)->post($url, $this->reason([]))->assertSessionHasErrors(['reason' => 'Choose a reason.']);
        $this->assertSame(0, DB::table('inventory_controlled_register_check_reasons')->count());
    }

    public function test_a_reason_still_needed_never_blocks_the_next_check(): void
    {
        $f = $this->controlled();
        $sku = $f['sku']->public_id;
        $this->check($this->actorAt('finance_officer'), $sku, '7');
        $director = $this->actorAt('director');

        $this->assertTrue($this->page($director, $sku)['can']['check']);
        $this->check($director, $sku, '10');
        $checks = $this->page($director, $sku)['checks'];
        $this->assertSame([false, true], [$checks[0]['reasonNeeded'], $checks[1]['reasonNeeded']]);
    }

    public function test_postgresql_keeps_reasons_as_recorded_and_refuses_anyone_but_the_checker(): void
    {
        $f = $this->controlled();
        $sku = $f['sku']->public_id;
        $checker = $this->actorAt('finance_officer');
        $differing = DB::table('inventory_controlled_register_checks')->where('public_id', $this->check($checker, $sku, '7'))->sole();
        $director = $this->actorAt('director');
        $row = ['public_id' => (string) Str::uuid(), 'organisation_id' => $this->branch->organisation_id, 'branch_id' => $this->branch->id, 'controlled_register_check_id' => $differing->id,
            'reason' => 'investigate', 'note' => null, 'recorded_at' => now(), 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64)];
        $refused = function (callable $change, string $message): void {
            try {
                DB::transaction($change);
                $this->fail('Accepted: '.$message);
            } catch (QueryException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        };

        $refused(fn () => DB::table('inventory_controlled_register_check_reasons')->insert([...$row, 'recorded_by_user_id' => $director->id]), 'Only the person who recorded the check adds its reason');
        $refused(fn () => DB::table('inventory_controlled_register_check_reasons')->insert([...$row, 'recorded_by_user_id' => $checker->id, 'reason' => 'other']), 'controlled_register_check_reasons_shape');

        DB::table('inventory_controlled_register_check_reasons')->insert([...$row, 'recorded_by_user_id' => $checker->id]);
        $refused(fn () => DB::table('inventory_controlled_register_check_reasons')->update(['reason' => 'other', 'note' => 'x']), 'Controlled register check reasons are kept as recorded');
        $refused(fn () => DB::table('inventory_controlled_register_check_reasons')->delete(), 'Controlled register check reasons are kept as recorded');
        $refused(fn () => DB::statement('TRUNCATE inventory_controlled_register_check_reasons'), 'Controlled register check reasons are kept as recorded');

        // A matching check never takes a reason, even straight into the table.
        $matching = DB::table('inventory_controlled_register_checks')->where('public_id', $this->check($director, $sku, '10'))->sole();
        $refused(fn () => DB::table('inventory_controlled_register_check_reasons')->insert([...$row, 'public_id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(),
            'controlled_register_check_id' => $matching->id, 'recorded_by_user_id' => $director->id]), 'Only a check that differs and has no reason yet takes a reason');
    }
}

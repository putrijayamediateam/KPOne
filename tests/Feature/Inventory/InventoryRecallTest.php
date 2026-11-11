<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Services\GovernedStockService;
use App\Domain\Organisation\Inventory\Services\InventoryBatchEligibility;
use App\Domain\Organisation\Inventory\Services\InventoryBatchHoldService;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Inventory\Services\InventoryRecallService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\GovernedStockFixture;

/** STK-25 (plan approved by B.E.R.N.I.S.S for the owner, 2026-11-11): recalls across branches. */
final class InventoryRecallTest extends VisitTestCase
{
    private function actorAt(string $role, ?Branch $branch = null): User
    {
        $branch ??= $this->branch;
        $actor = (new GovernedStockFixture($branch))->actor($role);
        app(BranchAccessService::class)->select($actor, $branch);

        return $actor;
    }

    private function otherBranch(): Branch
    {
        return Branch::query()->where('organisation_id', $this->branch->organisation_id)->whereKeyNot($this->branch->id)->orderBy('id')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function startInput(array $f, array $extra = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), 'sku_public_id' => $f['sku']->public_id,
            'batch_public_ids' => [$f['batch']->public_id], 'reason' => 'supplier_recall', 'reference' => 'SUP-NOTICE-7', ...$extra];
    }

    private function reason(Branch $branch, int $batchId): ?string
    {
        return app(InventoryBatchEligibility::class)->query($branch->organisation_id, $branch)->where('eligibility.id', $batchId)->value('eligibility_reason');
    }

    public function test_a_recall_blocks_the_batch_at_every_branch_including_stock_that_arrives_later(): void
    {
        $f = (new GovernedStockFixture($this->branch))->build('10.000');
        $director = $this->actorAt('director');
        $this->assertNull($this->reason($this->branch, $f['batch']->id));

        $this->actingAs($director)->post(route('inventory.recalls.store'), $this->startInput($f, ['note' => 'Supplier letter 12 Nov']))
            ->assertRedirect(route('inventory.recalls'))->assertSessionHasNoErrors();
        $recall = DB::table('inventory_recalls')->sole();
        $this->assertSame(['active', 'Supplier letter 12 Nov'], [$recall->status, Crypt::decryptString((string) $recall->note_encrypted)]);
        $this->assertSame('recalled', $this->reason($this->branch, $f['batch']->id));

        // Stock of the same batch arriving later at another branch is blocked there too.
        $other = $this->otherBranch();
        $location = new InventoryLocation;
        $location->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $other->organisation_id, 'branch_id' => $other->id, 'code' => 'SYN-RECALL-'.Str::upper(Str::random(5)),
            'name' => 'Synthetic other dispensary', 'type' => 'dispensary', 'is_active' => true])->save();
        $there = $this->actorAt('ca_supervisor', $other);
        app(InventoryMovementService::class)->openingBalance($there, ['expected_branch_id' => $other->id, 'sku_public_id' => $f['sku']->public_id,
            'location_public_id' => $location->public_id, 'batch_public_id' => $f['batch']->public_id, 'quantity' => '4.000']);
        $this->assertSame('recalled', $this->reason($other, $f['batch']->id));

        // Dispensing, picking and treatment use the shared rule, so they refuse it.
        try {
            app(InventoryBatchEligibility::class)->assertEligible($this->branch->organisation_id, $other, $f['batch']->id, 'Synthetic medicine');
            $this->fail('A recalled batch was eligible');
        } catch (ValidationException $e) {
            $this->assertSame('Synthetic medicine: the batch is under a recall at every branch.', $e->errors()['allocations'][0]);
        }
        // Pickers still show it, marked as held for "recall", like an STK-2 hold.
        $row = app(InventoryBatchEligibility::class)->query($other->organisation_id, $other)->where('eligibility.id', $f['batch']->id)->first();
        $this->assertSame('all_branch_recall', $row->hold_reason);

        // The Director sees the stock at both branches; a branch viewer sees only their branch. Notes only for starters.
        app(BranchAccessService::class)->select($director, $this->branch);
        $page = $this->actingAs($director)->get(route('inventory.recalls'))->assertOk()->viewData('page')['props']['recalls'];
        $this->assertSame([1, ['Synthetic other dispensary', 'Synthetic governed dispensary']], [$page['counts']['active'], collect($page['recalls']['rows'][0]['whereIs'])->pluck('location')->sort()->values()->reverse()->values()->all()]);
        $this->assertSame('Supplier letter 12 Nov', $page['recalls']['rows'][0]['note']);
        $ca = $this->actorAt('ca');
        $view = $this->actingAs($ca)->get(route('inventory.recalls'))->assertOk()->viewData('page')['props']['recalls'];
        $this->assertSame([false, false, ['Synthetic governed dispensary'], null], [$view['can']['start'], $view['allBranches'], collect($view['recalls']['rows'][0]['whereIs'])->pluck('location')->all(), $view['recalls']['rows'][0]['note']]);

        // Audit holds ids and codes only.
        $audit = DB::table('audit_logs')->where('event', 'inventory.recall.started')->sole();
        $this->assertDoesNotMatchRegularExpression('/SUP-NOTICE|Supplier letter/', (string) $audit->metadata);
    }

    public function test_only_a_director_starts_a_recall_and_never_lifts_their_own(): void
    {
        $f = (new GovernedStockFixture($this->branch))->build('10.000');
        foreach (['ca', 'ca_supervisor', 'finance_officer', 'administrator'] as $role) {
            $this->actingAs($this->actorAt($role))->post(route('inventory.recalls.store'), $this->startInput($f))->assertForbidden();
        }
        $starter = $this->actorAt('director');
        $this->actingAs($starter)->post(route('inventory.recalls.store'), $this->startInput($f))->assertSessionHasNoErrors();
        $recall = DB::table('inventory_recalls')->sole();
        $lift = ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'retry_key' => (string) Str::uuid(), 'reason' => 'supplier_cleared'];

        $this->actingAs($starter)->post(route('inventory.recalls.lift', ['recall' => $recall->public_id]), $lift)
            ->assertSessionHasErrors(['status' => 'You started this recall, so another person must lift it.']);
        // Until the owner decides, only a Director lifts.
        $this->actingAs($this->actorAt('finance_officer'))->post(route('inventory.recalls.lift', ['recall' => $recall->public_id]), $lift)->assertForbidden();
        $this->actingAs($this->actorAt('director'))->post(route('inventory.recalls.lift', ['recall' => $recall->public_id]), [...$lift, 'reason' => 'other'])
            ->assertSessionHasErrors(['note' => 'Add a note to explain why the recall is lifted.']);
        $other = $this->actorAt('director');
        $this->actingAs($other)->post(route('inventory.recalls.lift', ['recall' => $recall->public_id]), $lift)
            ->assertRedirect(route('inventory.recalls', ['tab' => 'lifted']))->assertSessionHasNoErrors();
        // The same press sent again (a lost reply or a double press) is the same lift, not an error.
        $this->actingAs($other)->post(route('inventory.recalls.lift', ['recall' => $recall->public_id]), $lift)->assertSessionHasNoErrors();
        $this->assertNull($this->reason($this->branch, $f['batch']->id));
        $this->assertSame([false], DB::table('inventory_recall_batches')->pluck('active')->map(fn ($v) => (bool) $v)->all());
        // Lifting again from an old page is refused.
        $this->actingAs($other)->post(route('inventory.recalls.lift', ['recall' => $recall->public_id]), [...$lift, 'retry_key' => (string) Str::uuid()])
            ->assertSessionHasErrors(['lock_version' => 'This recall changed. Reload and review it again.']);
        // A batch can be recalled again once its recall is lifted.
        $this->actingAs($starter)->post(route('inventory.recalls.store'), $this->startInput($f))->assertSessionHasNoErrors();
        $this->assertSame('recalled', $this->reason($this->branch, $f['batch']->id));
    }

    public function test_one_active_recall_per_batch_and_a_retry_returns_the_same_recall(): void
    {
        $f = (new GovernedStockFixture($this->branch))->build('10.000');
        $director = $this->actorAt('director');
        $service = app(InventoryRecallService::class);
        $input = $this->startInput($f);
        $id = $service->start($director, $input);
        $this->assertSame($id, $service->start($director, $input));
        try {
            $service->start($this->actorAt('director'), $this->startInput($f));
            $this->fail('A second active recall was accepted');
        } catch (ValidationException $e) {
            $this->assertSame('A ticked batch is already under an active recall. Reload to see it.', $e->errors()['batch_public_ids'][0]);
        }
        // A batch of another SKU is refused.
        $other = (new GovernedStockFixture($this->branch))->build('1.000');
        $this->expectExceptionMessage('A ticked batch is not one of this SKU\'s batches.');
        $service->start($director, $this->startInput($f, ['batch_public_ids' => [$other['batch']->public_id]]));
    }

    public function test_a_branch_hold_and_a_recall_on_the_same_batch_stay_independent(): void
    {
        $f = (new GovernedStockFixture($this->branch))->build('10.000');
        $holder = $this->actorAt('ca_supervisor');
        $releaser = $this->actorAt('finance_officer');
        $holds = app(InventoryBatchHoldService::class);
        $hold = fn (User $actor, string $action, int $version, string $reason) => $holds->act($actor, $f['batch']->public_id, $action,
            ['expected_branch_id' => $this->branch->id, 'lock_version' => $version, 'retry_key' => (string) Str::uuid(), 'reason' => $reason]);
        $recalls = app(InventoryRecallService::class);

        // Hold, then recall: the recall is what shows, not the branch hold's reason.
        $hold($holder, 'place', 0, 'damaged');
        $recalls->start($this->actorAt('director'), $this->startInput($f));
        $this->assertSame('all_branch_recall', app(InventoryBatchEligibility::class)->query($this->branch->organisation_id, $this->branch)->where('eligibility.id', $f['batch']->id)->value('hold_reason'));
        // Releasing the hold leaves the recall in force.
        $hold($releaser, 'release', 1, 'inspection_passed');
        $this->assertSame('recalled', $this->reason($this->branch, $f['batch']->id));

        // Hold again, then lift the recall: the hold is still in force.
        $hold($holder, 'place', 2, 'damaged');
        $recall = DB::table('inventory_recalls')->sole();
        $recalls->lift($this->actorAt('director'), $recall->public_id, ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'retry_key' => (string) Str::uuid(), 'reason' => 'recall_withdrawn']);
        $this->assertSame('held', $this->reason($this->branch, $f['batch']->id));
        $this->assertSame(3, (int) DB::table('inventory_batch_hold_states')->where('inventory_batch_id', $f['batch']->id)->value('lock_version'));
    }

    public function test_postgresql_keeps_recalls_and_refuses_the_starter_lifting(): void
    {
        $f = (new GovernedStockFixture($this->branch))->build('10.000');
        $starter = $this->actorAt('director');
        app(InventoryRecallService::class)->start($starter, $this->startInput($f));
        $recall = DB::table('inventory_recalls')->sole();
        $refused = function (callable $change, string $message): void {
            try {
                DB::transaction($change);
                $this->fail('Accepted: '.$message);
            } catch (QueryException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        };

        $refused(fn () => DB::table('inventory_recalls')->update(['status' => 'lifted', 'lifted_by_user_id' => $starter->id, 'lifted_at' => now(), 'lift_reason' => 'supplier_cleared', 'lock_version' => 2]), 'inventory_recalls_shape');
        $refused(fn () => DB::table('inventory_recalls')->update(['reason' => 'quality_issue']), 'A recall is lifted once and otherwise never changes');
        $refused(fn () => DB::table('inventory_recall_batches')->update(['active' => false]), 'A recalled batch is released only when its recall is lifted');
        $stranger = (new GovernedStockFixture($this->branch))->build('1.000');
        $refused(fn () => DB::table('inventory_recall_batches')->insert(['organisation_id' => $recall->organisation_id, 'recall_id' => $recall->id, 'inventory_item_id' => $stranger['item']->id,
            'batch_key' => Str::upper($stranger['batch']->batch_number), 'inventory_batch_id' => $stranger['batch']->id, 'active' => true]), 'A recalled batch belongs to the recall\'s SKU');
        // Lifting the recall row alone, without releasing its batches, fails at commit.
        $lifter = $this->actorAt('director');
        $refused(function () use ($lifter): void {
            // The check runs at commit; the test's own transaction never commits, so it is asked for at once here.
            DB::statement('SET CONSTRAINTS inventory_recalls_released IMMEDIATE');
            DB::table('inventory_recalls')->update(['status' => 'lifted', 'lifted_by_user_id' => $lifter->id, 'lifted_at' => now(), 'lift_reason' => 'supplier_cleared',
                'lift_idempotency_key' => (string) Str::uuid(), 'lift_request_hash' => str_repeat('a', 64), 'lock_version' => 2]);
        }, 'A lifted recall releases all its batches');
        $refused(fn () => DB::statement('TRUNCATE inventory_recall_batches'), 'Recalls are kept');
        $refused(fn () => DB::table('inventory_recalls')->delete(), 'Recalls are kept');
        $refused(fn () => DB::table('inventory_recall_batches')->delete(), 'Recalls are kept');
        $refused(fn () => DB::statement('TRUNCATE inventory_recalls CASCADE'), 'Recalls are kept');
    }

    public function test_the_same_batch_number_arriving_later_with_another_expiry_or_pack_size_is_blocked(): void
    {
        $f = (new GovernedStockFixture($this->branch))->build('10.000');
        app(InventoryRecallService::class)->start($this->actorAt('director'), $this->startInput($f));
        // The same number with another expiry (a new batch row) is blocked too.
        $later = $f['batch']->replicate();
        $later->forceFill(['public_id' => (string) Str::uuid(), 'batch_number' => ' '.strtolower($f['batch']->batch_number).' ', 'expiry_date' => now()->addDays(200)->toDateString()])->save();
        $this->assertSame('recalled', $this->reason($this->branch, $later->id));
        // And in another pack size (another SKU of the same item).
        $pack = $f['sku']->replicate();
        $pack->forceFill(['public_id' => (string) Str::uuid(), 'sku_code' => $f['sku']->sku_code.'-BOX'])->save();
        $boxed = $f['batch']->replicate();
        $boxed->forceFill(['public_id' => (string) Str::uuid(), 'inventory_sku_id' => $pack->id])->save();
        $this->assertSame('recalled', $this->reason($this->branch, $boxed->id));
        // Another number of the same item is not.
        $other = $f['batch']->replicate();
        $other->forceFill(['public_id' => (string) Str::uuid(), 'batch_number' => 'SYN-OTHER-NUMBER'])->save();
        $this->assertNull($this->reason($this->branch, $other->id));
    }

    public function test_recalled_stock_can_still_be_written_off_and_moved_and_shared_stores_show(): void
    {
        $f = (new GovernedStockFixture($this->branch))->build('10.000');
        $director = $this->actorAt('director');
        app(InventoryRecallService::class)->start($director, $this->startInput($f, ['reference' => 'SUP-REF-9']));

        // Moving it inside the branch still works.
        $cupboard = new InventoryLocation;
        $cupboard->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->branch->organisation_id, 'branch_id' => $this->branch->id, 'code' => 'SYN-QUAR-'.Str::upper(Str::random(4)),
            'name' => 'Synthetic quarantine shelf', 'type' => 'store', 'is_active' => true])->save();
        app(InventoryMovementService::class)->transfer($f['proposer'], ['expected_branch_id' => $this->branch->id, 'sku_public_id' => $f['sku']->public_id,
            'batch_public_id' => $f['batch']->public_id, 'source_location_public_id' => $f['location']->public_id, 'destination_location_public_id' => $cupboard->public_id, 'quantity' => '3.000']);

        // A governed write-off of recalled stock is proposed and approved as usual.
        $governed = app(GovernedStockService::class);
        $balance = DB::table('inventory_stock_balances')->where('inventory_location_id', $f['location']->id)->where('inventory_batch_id', $f['batch']->id)->sole();
        $case = $governed->propose($f['proposer'], 'writeoff', ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'retry_key' => (string) Str::uuid(), 'balance_id' => $balance->id,
            'balance_version' => $balance->lock_version, 'unit' => 'unit', 'quantity' => '7.000', 'reason' => 'other_approved', 'disposal_reference' => 'Recall SUP-REF-9 disposal log']);
        // A new case is at version 1, as in the STK-5 and STK-23 tests.
        $governed->act($this->actorAt('finance_officer'), $case, 'approved', ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'retry_key' => (string) Str::uuid()]);
        $this->assertSame('0.000', (string) DB::table('inventory_stock_balances')->where('id', $balance->id)->value('quantity'));

        // Stock in a shared store (no branch) shows on the recall page for those who start or lift recalls.
        $shared = new InventoryLocation;
        $shared->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->branch->organisation_id, 'branch_id' => null, 'code' => 'SYN-CENTRAL-'.Str::upper(Str::random(4)),
            'name' => 'Synthetic central store', 'type' => 'store', 'is_active' => true])->save();
        DB::table('inventory_stock_balances')->insert(['organisation_id' => $this->branch->organisation_id, 'inventory_location_id' => $shared->id, 'inventory_sku_id' => $f['sku']->id,
            'inventory_batch_id' => $f['batch']->id, 'quantity' => '2.000', 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        app(BranchAccessService::class)->select($director, $this->branch);
        $row = $this->actingAs($director)->get(route('inventory.recalls'))->viewData('page')['props']['recalls']['recalls']['rows'][0];
        $where = collect($row['whereIs'])->mapWithKeys(fn (array $w): array => [$w['branch'].' / '.$w['location'] => $w['quantity']])->all();
        $this->assertSame('2', $where['Shared store / Synthetic central store']);
        $this->assertSame('3', collect($row['whereIs'])->firstWhere('location', 'Synthetic quarantine shelf')['quantity']);
        $this->assertSame('SUP-REF-9', $row['reference']);
        // Branch staff see neither the reference nor other places.
        $view = $this->actingAs($this->actorAt('ca'))->get(route('inventory.recalls'))->viewData('page')['props']['recalls']['recalls']['rows'][0];
        $this->assertNull($view['reference']);
        $this->assertNotContains('Synthetic central store', array_column($view['whereIs'], 'location'));
    }
}

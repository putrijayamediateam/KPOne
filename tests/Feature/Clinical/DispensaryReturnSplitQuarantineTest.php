<?php

namespace Tests\Feature\Clinical;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryReturn;
use App\Domain\Clinical\Dispensary\Models\DispensaryReturnCheck;
use App\Domain\Clinical\Dispensary\Models\DispensaryReturnCheckBatch;
use App\Domain\Clinical\Dispensary\Services\DispensaryReturnService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\OtcDispensaryFixtures;
use Tests\Support\ReturnsLocationFixture;

/**
 * DSP-15 (owner choice 2026-11-10): one return quarantined across the batches its line was dispensed from. Each
 * share has its own STK-11 movement; the shares add up to the return; no batch gets back more than the line
 * dispensed from it, counting single-batch checks and shares together. Synthetic data only.
 */
class DispensaryReturnSplitQuarantineTest extends VisitTestCase
{
    use OtcDispensaryFixtures;

    /**
     * A line of 5 dispensed as 3 from an earlier batch and 2 from a later one.
     *
     * @return array{f: array<string, mixed>, case: DispensaryCase, item: string, later: InventoryBatch, supervisor: User, location: InventoryLocation}
     */
    private function twoBatchLine(): array
    {
        $f = $this->fixture();
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor, $f['visit']->branch);
        $later = new InventoryBatch;
        $later->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['batch']->organisation_id, 'inventory_sku_id' => $f['sku']->id, 'batch_number' => 'SYN-LATER-'.Str::upper(Str::random(4)), 'expiry_date' => now()->setTimezone($f['visit']->branch->timezone)->addYears(3)->toDateString(), 'received_at' => now()->subDay()->toDateString(), 'status' => InventoryBatch::STATUS_AVAILABLE])->save();
        app(InventoryMovementService::class)->openingBalance($supervisor, ['expected_branch_id' => $f['visit']->branch_id, 'location_public_id' => $f['location']->public_id, 'sku_public_id' => $f['sku']->public_id, 'batch_public_id' => $later->public_id, 'quantity' => '10.000']);
        $this->selectBranch($f['ca'], $f['visit']->branch);
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case, '5.000', ['allocations' => [
            ['location_public_id' => $f['location']->public_id, 'sku_public_id' => $f['sku']->public_id, 'batch_public_id' => $f['batch']->public_id, 'quantity' => '3.000'],
            ['location_public_id' => $f['location']->public_id, 'sku_public_id' => $f['sku']->public_id, 'batch_public_id' => $later->public_id, 'quantity' => '2.000', 'fefo_exception_reason' => 'earlier_batch_insufficient'],
        ]]));
        $this->confirmAllergy($f, $case->refresh());
        $case = $this->complete($f, $case)->refresh();
        $item = $case->handoffs()->sole()->items()->sole()->public_id;
        $location = ReturnsLocationFixture::create($supervisor, $f['visit']->branch);
        $this->actingAs($supervisor);

        return ['f' => $f, 'case' => $case, 'item' => $item, 'later' => $later, 'supervisor' => $supervisor, 'location' => $location];
    }

    /** @param array<string, mixed> $w */
    private function record(array $w, string $quantity, int $count = 0): DispensaryReturn
    {
        return app(DispensaryReturnService::class)->record($w['f']['ca'], $w['case'], $w['item'], ['expected_branch_id' => $w['f']['visit']->branch_id, 'retry_key' => (string) Str::uuid(), 'expected_return_count' => $count, 'quantity' => $quantity, 'reason' => 'not_used', 'note' => null, 'condition' => 'sealed_in_date']);
    }

    /**
     * @param  array<string, mixed>  $w
     * @param  list<array{batch_public_id: string, quantity: string}>|null  $batches
     * @return array<string, mixed>
     */
    private function payload(array $w, ?array $batches, ?string $batch = null): array
    {
        return ['expected_branch_id' => $w['f']['visit']->branch_id, 'retry_key' => (string) Str::uuid(), 'outcome' => 'quarantine', 'refuse_reason' => null, 'refuse_note' => null, 'location_public_id' => $w['location']->public_id, 'batch_public_id' => $batch, 'batches' => $batches];
    }

    /**
     * @param  array<string, mixed>  $w
     * @param  array<string, mixed>  $payload
     */
    private function check(array $w, DispensaryReturn $return, array $payload): DispensaryReturnCheck
    {
        return app(DispensaryReturnService::class)->check($w['supervisor'], $w['case'], $return->public_id, $payload);
    }

    /** @return array<string, int> */
    private function stock(): array
    {
        return ['movements' => DB::table('stock_movements')->count(), 'balance' => (int) DB::table('inventory_stock_balances')->sum(DB::raw('quantity * 1000')), 'checks' => DB::table('dispensary_return_checks')->count()];
    }

    public function test_a_return_splits_across_two_batches_with_one_movement_each(): void
    {
        $w = $this->twoBatchLine();
        $return = $this->record($w, '4.5');
        $this->get(route('dispensary.show', $w['case']))->assertInertia(fn (Assert $page) => $page
            // Earliest expiry first, each with what can still go back to it.
            ->where('returns.lines.0.batches.0.publicId', $w['f']['batch']->public_id)
            ->where('returns.lines.0.batches.0.available', '3')
            ->where('returns.lines.0.batches.1.publicId', $w['later']->public_id)
            ->where('returns.lines.0.batches.1.available', '2')
            ->where('returns.lines.0.quarantineAvailable', '5'));

        $this->post(route('dispensary.returns.check', [$w['case'], $return->public_id]), $this->payload($w, [
            ['batch_public_id' => $w['later']->public_id, 'quantity' => '1.5'],
            ['batch_public_id' => $w['f']['batch']->public_id, 'quantity' => '3'],
        ]))->assertRedirect(route('dispensary.show', $w['case']))->assertSessionHasNoErrors();
        // The commit-time rules hold (the test transaction never commits, so check them now).
        DB::statement('SET CONSTRAINTS dispensary_return_checks_split_shares, stock_movements_dispensary_return_pair IMMEDIATE');

        $check = DispensaryReturnCheck::query()->sole();
        $this->assertSame(['quarantine', $w['location']->id, null, null], [$check->outcome, $check->inventory_location_id, $check->inventory_batch_id, $check->stock_movement_id]);
        $shares = DispensaryReturnCheckBatch::query()->where('dispensary_return_check_id', $check->id)->orderBy('inventory_batch_id')->get();
        $this->assertSame([[$w['f']['batch']->id, '3.000'], [$w['later']->id, '1.500']], $shares->map(fn (DispensaryReturnCheckBatch $s): array => [$s->inventory_batch_id, (string) $s->quantity])->all());
        foreach ($shares as $share) {
            $movement = StockMovement::query()->findOrFail($share->stock_movement_id);
            $this->assertSame([StockMovement::TYPE_DISPENSARY_RETURN, 'dispensary_return_check', $check->public_id, (string) $share->quantity, $w['location']->id, $share->inventory_batch_id], [$movement->movement_type, $movement->reference_type, $movement->reference_public_id, (string) $movement->quantity, $movement->destination_location_id, $movement->inventory_batch_id]);
        }
        $this->assertSame('4.500', (string) DB::table('inventory_stock_balances')->where('inventory_location_id', $w['location']->id)->sum('quantity'));
        $metadata = AuditLog::query()->where('event', 'dispensary.return_checked')->sole()->metadata;
        $this->assertSame(['return_id', 'check_id', 'dispensary_case_id', 'outcome', 'stock_movement_ids', 'batch_count'], array_keys($metadata));
        $this->assertSame(2, $metadata['batch_count']);
        $this->get(route('dispensary.show', $w['case']))->assertInertia(fn (Assert $page) => $page
            ->where('returns.lines.0.returns.0.status', 'quarantined')
            ->where('returns.lines.0.returns.0.check.batches.0.quantity', '3')
            ->where('returns.lines.0.returns.0.check.batches.0.label', fn (string $label): bool => str_starts_with($label, $w['f']['batch']->batch_number.' · exp '))
            ->where('returns.lines.0.returns.0.check.batches.1.quantity', '1.5')
            ->where('returns.lines.0.batches.0.available', '0')
            ->where('returns.lines.0.batches.1.available', '0.5')
            ->where('returns.lines.0.quarantineAvailable', '0.5'));
    }

    public function test_a_share_over_its_batch_or_a_wrong_total_is_refused_and_nothing_moves(): void
    {
        $w = $this->twoBatchLine();
        $return = $this->record($w, '4');
        $before = $this->stock();
        $url = route('dispensary.returns.check', [$w['case'], $return->public_id]);
        $this->from(route('dispensary.show', $w['case']));

        // Only 2 were dispensed from the later batch; the message lands under its own box.
        $this->post($url, $this->payload($w, [['batch_public_id' => $w['f']['batch']->public_id, 'quantity' => '1'], ['batch_public_id' => $w['later']->public_id, 'quantity' => '3']]))
            ->assertSessionHasErrors(['batches.1.quantity' => 'At most 2 unit from this batch.']);
        $this->post($url, $this->payload($w, [['batch_public_id' => $w['f']['batch']->public_id, 'quantity' => '2'], ['batch_public_id' => $w['later']->public_id, 'quantity' => '1']]))
            ->assertSessionHasErrors(['batches' => 'The batches add up to 3 unit. They must add up to 4 unit.']);
        $this->post($url, $this->payload($w, [['batch_public_id' => $w['f']['batch']->public_id, 'quantity' => '2.0001'], ['batch_public_id' => $w['later']->public_id, 'quantity' => '2']]))
            ->assertSessionHasErrors(['batches.0.quantity' => 'Enter a number with up to 3 decimals, for example 2.5.']);
        $this->post($url, $this->payload($w, [['batch_public_id' => $w['f']['batch']->public_id, 'quantity' => '0'], ['batch_public_id' => $w['later']->public_id, 'quantity' => '0']]))
            ->assertSessionHasErrors(['batches' => 'Enter how many units went back to each batch.']);
        $this->post($url, $this->payload($w, [['batch_public_id' => $w['f']['batch']->public_id, 'quantity' => '2'], ['batch_public_id' => (string) Str::uuid(), 'quantity' => '2']]))
            ->assertSessionHasErrors(['batches' => 'Choose batches this line was dispensed from.']);
        $this->post($url, $this->payload($w, [['batch_public_id' => $w['f']['batch']->public_id, 'quantity' => '2'], ['batch_public_id' => $w['f']['batch']->public_id, 'quantity' => '2']]))
            ->assertSessionHasErrors('batches.1.batch_public_id');
        $this->post($url, $this->payload($w, [['batch_public_id' => $w['f']['batch']->public_id, 'quantity' => '4']], $w['later']->public_id))
            ->assertSessionHasErrors('batch_public_id');

        $this->assertSame($before, $this->stock());
        $this->assertSame(0, DispensaryReturnCheckBatch::query()->count());
    }

    public function test_one_share_is_stored_like_a_single_batch_quarantine(): void
    {
        $w = $this->twoBatchLine();
        $return = $this->record($w, '2');
        $this->check($w, $return, $this->payload($w, [['batch_public_id' => $w['later']->public_id, 'quantity' => '2'], ['batch_public_id' => $w['f']['batch']->public_id, 'quantity' => '0']]));

        $check = DispensaryReturnCheck::query()->sole();
        $this->assertSame($w['later']->id, $check->inventory_batch_id);
        $this->assertNotNull($check->stock_movement_id);
        $this->assertSame(0, DispensaryReturnCheckBatch::query()->count());
        $this->get(route('dispensary.show', $w['case']))->assertInertia(fn (Assert $page) => $page
            ->where('returns.lines.0.returns.0.check.batches.0.quantity', '2')
            ->where('returns.lines.0.returns.0.check.batches.0.label', fn (string $label): bool => str_starts_with($label, $w['later']->batch_number)));
    }

    public function test_single_checks_and_shares_count_together_per_batch(): void
    {
        $w = $this->twoBatchLine();
        $first = $this->record($w, '2');
        $second = $this->record($w, '2', 1);
        $third = $this->record($w, '1', 2);

        // 2 back to the earlier batch on the single-batch path leaves 1 there and 2 in the later one.
        $this->check($w, $first, $this->payload($w, null, $w['f']['batch']->public_id));
        try {
            $this->check($w, $second, $this->payload($w, [['batch_public_id' => $w['f']['batch']->public_id, 'quantity' => '2'], ['batch_public_id' => $w['later']->public_id, 'quantity' => '0']]));
            $this->fail('Only 1 can still go back to the earlier batch.');
        } catch (ValidationException $e) {
            $this->assertSame(['At most 1 unit from this batch.'], $e->errors()['batches.0.quantity']);
        }
        $this->check($w, $second, $this->payload($w, [['batch_public_id' => $w['f']['batch']->public_id, 'quantity' => '1'], ['batch_public_id' => $w['later']->public_id, 'quantity' => '1']]));

        // The shares count on the single-batch path too: the earlier batch is now full.
        try {
            $this->check($w, $third, $this->payload($w, null, $w['f']['batch']->public_id));
            $this->fail('The earlier batch has nothing left to take back.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('batch_public_id', $e->errors());
        }
        $this->check($w, $third, $this->payload($w, null, $w['later']->public_id));
        $this->assertSame('5.000', (string) DB::table('inventory_stock_balances')->where('inventory_location_id', $w['location']->id)->sum('quantity'));
    }

    public function test_postgres_repeats_the_split_rules_and_shares_are_append_only(): void
    {
        $w = $this->twoBatchLine();
        $return = $this->record($w, '2');
        $check = $this->check($w, $return, $this->payload($w, [['batch_public_id' => $w['f']['batch']->public_id, 'quantity' => '1'], ['batch_public_id' => $w['later']->public_id, 'quantity' => '1']]));
        $share = DispensaryReturnCheckBatch::query()->where('dispensary_return_check_id', $check->id)->firstOrFail();
        foreach ([fn () => $share->forceFill(['quantity' => '2'])->save(), fn () => $share->delete()] as $mutation) {
            try {
                $mutation();
                $this->fail('A batch share must not change.');
            } catch (LogicException) {
            }
        }
        foreach (['UPDATE dispensary_return_check_batches SET quantity = 2', 'DELETE FROM dispensary_return_check_batches', 'TRUNCATE dispensary_return_check_batches'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail('PostgreSQL must refuse: '.$sql);
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }

        // A split check whose shares do not add up is refused at commit.
        $other = $this->record($w, '1', 1);
        try {
            DB::transaction(function () use ($w, $other): void {
                DB::statement('SET CONSTRAINTS dispensary_return_checks_split_shares IMMEDIATE');
                DB::table('dispensary_return_checks')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $w['location']->organisation_id, 'branch_id' => $w['f']['visit']->branch_id, 'dispensary_return_id' => $other->id, 'outcome' => 'quarantine', 'inventory_location_id' => $w['location']->id, 'checked_by_user_id' => $w['supervisor']->id, 'checked_at' => now()->utc(), 'retry_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('0', 64)]);
            });
            $this->fail('PostgreSQL must refuse a split check without shares.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('two or more batch shares', $e->getMessage());
        }

        // A share must pair with its own movement.
        try {
            DB::transaction(fn () => DB::table('dispensary_return_check_batches')->insert(['organisation_id' => $share->organisation_id, 'dispensary_return_check_id' => $check->id, 'inventory_batch_id' => $w['later']->id, 'quantity' => '1.000', 'stock_movement_id' => $share->stock_movement_id]));
            $this->fail('PostgreSQL must refuse a share reusing a movement.');
        } catch (QueryException) {
        }
        // A share never belongs to a single-batch check.
        $single = $this->check($w, $other, $this->payload($w, null, $w['f']['batch']->public_id));
        try {
            DB::transaction(fn () => DB::table('dispensary_return_check_batches')->insert(['organisation_id' => $share->organisation_id, 'dispensary_return_check_id' => $single->id, 'inventory_batch_id' => $w['later']->id, 'quantity' => '1.000', 'stock_movement_id' => (int) $single->stock_movement_id]));
            $this->fail('PostgreSQL must refuse a share on a single-batch check.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('split quarantine check', $e->getMessage());
        }
    }
}

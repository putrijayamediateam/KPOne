<?php

namespace Tests\Feature\Clinical;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryReturn;
use App\Domain\Clinical\Dispensary\Models\DispensaryReturnCheck;
use App\Domain\Clinical\Dispensary\Services\DispensaryReturnService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\StockMovement;
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
 * DSP-7 step 2 (owner decisions 2026-11-05, TEAM.md decisions 20 and 21): a CA Supervisor who did not record a
 * return checks it once. Dispose and Refuse move no stock; Quarantine credits a patient returns location through
 * STK-11. A controlled medicine is never quarantined. Synthetic data only.
 */
class DispensaryReturnCheckTest extends VisitTestCase
{
    use OtcDispensaryFixtures;

    /** @return array{f: array<string, mixed>, case: DispensaryCase, item: string, return: DispensaryReturn, supervisor: User, location: InventoryLocation} */
    private function waiting(string $returned = '2', bool $controlled = false): array
    {
        $f = $this->fixture();
        if ($controlled) {
            DB::table('medicine_catalogue_items')->where('id', $f['medicine']->id)->update(['is_controlled' => true]);
        }
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case, '3.000'));
        $this->confirmAllergy($f, $case->refresh());
        $case = $this->complete($f, $case)->refresh();
        $item = $case->handoffs()->sole()->items()->sole()->public_id;
        $return = app(DispensaryReturnService::class)->record($f['ca'], $case, $item, ['expected_branch_id' => $f['visit']->branch_id, 'retry_key' => (string) Str::uuid(), 'expected_return_count' => 0, 'quantity' => $returned, 'reason' => 'not_used', 'note' => null, 'condition' => 'sealed_in_date']);
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor, $f['visit']->branch);
        $location = ReturnsLocationFixture::create($supervisor, $f['visit']->branch);
        $this->actingAs($supervisor);

        return ['f' => $f, 'case' => $case, 'item' => $item, 'return' => $return, 'supervisor' => $supervisor, 'location' => $location];
    }

    /** @return array<string, mixed> */
    private function payload(array $w, array $override = []): array
    {
        return ['expected_branch_id' => $w['f']['visit']->branch_id, 'retry_key' => (string) Str::uuid(), 'outcome' => 'dispose', 'refuse_reason' => null, 'refuse_note' => null, 'location_public_id' => null, 'batch_public_id' => null, ...$override];
    }

    /** @return array<string, int> */
    private function stock(): array
    {
        return ['movements' => DB::table('stock_movements')->count(), 'balance' => (int) DB::table('inventory_stock_balances')->sum(DB::raw('quantity * 1000'))];
    }

    public function test_dispose_moves_no_stock_and_shows_as_disposed(): void
    {
        $w = $this->waiting();
        $before = $this->stock();
        $this->post(route('dispensary.returns.check', [$w['case'], $w['return']->public_id]), $this->payload($w))
            ->assertRedirect(route('dispensary.show', $w['case']))->assertSessionHasNoErrors();

        $check = DispensaryReturnCheck::query()->sole();
        $this->assertSame(['dispose', $w['supervisor']->id, null], [$check->outcome, $check->checked_by_user_id, $check->stock_movement_id]);
        $this->assertSame($before, $this->stock());
        $this->assertSame(['return_id', 'check_id', 'dispensary_case_id', 'outcome'], array_keys(AuditLog::query()->where('event', 'dispensary.return_checked')->sole()->metadata));
        $this->get(route('dispensary.show', $w['case']))->assertInertia(fn (Assert $page) => $page
            ->where('returns.lines.0.returns.0.status', 'disposed')
            ->where('returns.lines.0.returns.0.canCheck', false)
            ->where('returns.lines.0.returned', '2')
            ->where('returns.lines.0.returns.0.check.outcome', 'dispose'));
    }

    public function test_quarantine_credits_the_returns_location_with_one_paired_movement(): void
    {
        $w = $this->waiting('1.5');
        $this->get(route('dispensary.show', $w['case']))->assertInertia(fn (Assert $page) => $page
            ->where('returns.canCheck', true)
            ->where('returns.returnsLocations.0.publicId', $w['location']->public_id)
            ->where('returns.lines.0.batches.0.publicId', $w['f']['batch']->public_id)
            ->where('returns.lines.0.returns.0.canCheck', true));

        $this->post(route('dispensary.returns.check', [$w['case'], $w['return']->public_id]), $this->payload($w, ['outcome' => 'quarantine', 'location_public_id' => $w['location']->public_id, 'batch_public_id' => $w['f']['batch']->public_id]))
            ->assertSessionHasNoErrors();

        $check = DispensaryReturnCheck::query()->sole();
        $movement = StockMovement::query()->findOrFail($check->stock_movement_id);
        $this->assertSame([StockMovement::TYPE_DISPENSARY_RETURN, 'dispensary_return_check', $check->public_id, '1.500', $w['location']->id, $w['f']['batch']->id], [$movement->movement_type, $movement->reference_type, $movement->reference_public_id, (string) $movement->quantity, $movement->destination_location_id, $movement->inventory_batch_id]);
        $this->assertSame('1.500', (string) DB::table('inventory_stock_balances')->where('inventory_location_id', $w['location']->id)->value('quantity'));
        $this->get(route('dispensary.show', $w['case']))->assertInertia(fn (Assert $page) => $page
            ->where('returns.lines.0.returns.0.status', 'quarantined')
            ->where('returns.lines.0.returns.0.check.locationName', $w['location']->name));
    }

    public function test_refuse_needs_a_fixed_reason_and_frees_the_quantity_again(): void
    {
        $w = $this->waiting('3');
        $url = route('dispensary.returns.check', [$w['case'], $w['return']->public_id]);
        $this->from(route('dispensary.show', $w['case']));
        $this->post($url, $this->payload($w, ['outcome' => 'refuse']))->assertSessionHasErrors('refuse_reason');
        $this->post($url, $this->payload($w, ['outcome' => 'refuse', 'refuse_reason' => 'other']))->assertSessionHasErrors('refuse_note');
        $this->post($url, $this->payload($w, ['outcome' => 'refuse', 'refuse_reason' => 'nonsense']))->assertSessionHasErrors('refuse_reason');
        $this->post($url, $this->payload($w, ['outcome' => 'dispose', 'refuse_reason' => 'recorded_twice']))->assertSessionHasErrors('refuse_reason');
        $this->assertSame(0, DispensaryReturnCheck::query()->count());

        $this->post($url, $this->payload($w, ['outcome' => 'refuse', 'refuse_reason' => 'other', 'refuse_note' => 'Synthetic wrong patient']))->assertSessionHasNoErrors();
        $check = DispensaryReturnCheck::query()->sole();
        $this->assertSame(['refuse', 'other', 'Synthetic wrong patient'], [$check->outcome, $check->refuse_reason, $check->refuse_note_encrypted]);
        $this->assertStringNotContainsString('Synthetic', json_encode(AuditLog::query()->where('event', 'dispensary.return_checked')->sole()->metadata, JSON_THROW_ON_ERROR));

        // The refused 3 no longer count, so the CA can record the full quantity again.
        $this->get(route('dispensary.show', $w['case']))->assertInertia(fn (Assert $page) => $page->where('returns.lines.0.returned', '0')->where('returns.lines.0.returnable', '3')->where('returns.lines.0.returns.0.status', 'refused'));
        app(DispensaryReturnService::class)->record($w['f']['ca'], $w['case'], $w['item'], ['expected_branch_id' => $w['f']['visit']->branch_id, 'retry_key' => (string) Str::uuid(), 'expected_return_count' => 1, 'quantity' => '3', 'reason' => 'not_used', 'note' => null, 'condition' => 'sealed_in_date']);
        $this->assertSame(2, DispensaryReturn::query()->count());
    }

    public function test_a_controlled_medicine_is_never_quarantined_but_may_be_refused(): void
    {
        $w = $this->waiting('1', controlled: true);
        $url = route('dispensary.returns.check', [$w['case'], $w['return']->public_id]);
        $this->get(route('dispensary.show', $w['case']))->assertInertia(fn (Assert $page) => $page->where('returns.lines.0.returns.0.allowedOutcomes', ['dispose', 'refuse']));
        $this->from(route('dispensary.show', $w['case']));
        $this->post($url, $this->payload($w, ['outcome' => 'quarantine', 'location_public_id' => $w['location']->public_id, 'batch_public_id' => $w['f']['batch']->public_id]))->assertSessionHasErrors('outcome');
        $this->assertSame(0, DispensaryReturnCheck::query()->count());
        $this->assertSame(0, StockMovement::query()->where('movement_type', StockMovement::TYPE_DISPENSARY_RETURN)->count());

        $this->post($url, $this->payload($w, ['outcome' => 'refuse', 'refuse_reason' => 'recorded_twice']))->assertSessionHasNoErrors();
        $this->assertSame('refuse', DispensaryReturnCheck::query()->sole()->outcome);
    }

    public function test_only_a_ca_supervisor_who_did_not_record_it_may_check(): void
    {
        $w = $this->waiting();
        $url = route('dispensary.returns.check', [$w['case'], $w['return']->public_id]);
        foreach (['ca', 'finance_officer', 'director', 'resident_doctor'] as $role) {
            $actor = $role === 'ca' ? $w['f']['ca'] : $this->actor($role);
            $this->selectBranch($actor, $w['f']['visit']->branch);
            $this->actingAs($actor)->post($url, $this->payload($w))->assertForbidden();
        }
        $this->assertSame(0, DispensaryReturnCheck::query()->count());

        // A CA Supervisor who recorded a return cannot check it; a second CA Supervisor must.
        $own = app(DispensaryReturnService::class)->record($w['supervisor'], $w['case'], $w['item'], ['expected_branch_id' => $w['f']['visit']->branch_id, 'retry_key' => (string) Str::uuid(), 'expected_return_count' => 1, 'quantity' => '1', 'reason' => 'not_used', 'note' => null, 'condition' => 'cannot_tell']);
        $ownUrl = route('dispensary.returns.check', [$w['case'], $own->public_id]);
        $this->actingAs($w['supervisor'])->from(route('dispensary.show', $w['case']))->post($ownUrl, $this->payload($w))->assertSessionHasErrors('outcome');
        $this->get(route('dispensary.show', $w['case']))->assertInertia(fn (Assert $page) => $page->where('returns.lines.0.returns.1.canCheck', false)->where('returns.lines.0.returns.1.recordedByMe', true));
        $this->assertSame(0, DispensaryReturnCheck::query()->count());

        $second = $this->actor('ca_supervisor');
        $this->selectBranch($second, $w['f']['visit']->branch);
        $this->actingAs($second)->post($ownUrl, $this->payload($w))->assertSessionHasNoErrors();
        $this->assertSame([$second->id], DispensaryReturnCheck::query()->pluck('checked_by_user_id')->all());
    }

    public function test_a_return_is_checked_once_and_a_retry_replays(): void
    {
        $w = $this->waiting();
        $payload = $this->payload($w);
        $service = app(DispensaryReturnService::class);
        $first = $service->check($w['supervisor'], $w['case'], $w['return']->public_id, $payload);
        $this->assertSame($first->id, $service->check($w['supervisor'], $w['case'], $w['return']->public_id, $payload)->id);
        try {
            $service->check($w['supervisor'], $w['case'], $w['return']->public_id, $this->payload($w, ['outcome' => 'refuse', 'refuse_reason' => 'wrong_quantity']));
            $this->fail('A checked return must not be checked again.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('state', $e->errors());
        }
        $this->assertSame(1, DispensaryReturnCheck::query()->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'dispensary.return_checked')->count());
    }

    public function test_checks_are_append_only_and_postgres_repeats_the_rules(): void
    {
        $w = $this->waiting();
        $check = app(DispensaryReturnService::class)->check($w['supervisor'], $w['case'], $w['return']->public_id, $this->payload($w));
        foreach ([fn () => $check->forceFill(['outcome' => 'refuse'])->save(), fn () => $check->delete()] as $mutation) {
            try {
                $mutation();
                $this->fail('A return check must not change.');
            } catch (LogicException) {
            }
        }
        foreach (["UPDATE dispensary_return_checks SET outcome = 'refuse'", 'DELETE FROM dispensary_return_checks', 'TRUNCATE dispensary_return_checks CASCADE'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail('PostgreSQL must refuse: '.$sql);
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }

        // The recorder may not check, even by a direct insert.
        $other = app(DispensaryReturnService::class)->record($w['f']['ca'], $w['case'], $w['item'], ['expected_branch_id' => $w['f']['visit']->branch_id, 'retry_key' => (string) Str::uuid(), 'expected_return_count' => 1, 'quantity' => '1', 'reason' => 'not_used', 'note' => null, 'condition' => 'cannot_tell']);
        $row = ['public_id' => (string) Str::uuid(), 'organisation_id' => $check->organisation_id, 'branch_id' => $check->branch_id, 'dispensary_return_id' => $other->id, 'outcome' => 'dispose', 'checked_by_user_id' => $w['f']['ca']->id, 'checked_at' => now()->utc(), 'retry_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('0', 64)];
        try {
            DB::transaction(fn () => DB::table('dispensary_return_checks')->insert($row));
            $this->fail('PostgreSQL must refuse a check by the recorder.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('someone other than its recorder', $e->getMessage());
        }
        // A dispensary_return movement with no paired check is refused at commit.
        $this->assertSame(1, DispensaryReturnCheck::query()->count());
        try {
            DB::transaction(function () use ($w): void {
                // The pairing rule is deferred to commit; the test transaction never commits, so check it now.
                DB::statement('SET CONSTRAINTS stock_movements_dispensary_return_pair IMMEDIATE');
                DB::table('stock_movements')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $w['location']->organisation_id, 'inventory_sku_id' => $w['f']['sku']->id, 'inventory_batch_id' => $w['f']['batch']->id, 'source_location_id' => null, 'destination_location_id' => $w['location']->id, 'quantity' => '1.000', 'movement_type' => 'dispensary_return', 'reference_type' => 'dispensary_return_check', 'reference_public_id' => (string) Str::uuid(), 'actor_user_id' => $w['supervisor']->id, 'occurred_at' => now()->utc(), 'created_at' => now()->utc(), 'updated_at' => now()->utc()]);
            });
            $this->fail('PostgreSQL must refuse an unpaired dispensary_return movement.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('needs its quarantine check', $e->getMessage());
        }
        $this->assertSame(0, MedicineCatalogueItem::query()->where('is_controlled', true)->count());
    }
}

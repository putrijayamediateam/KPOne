<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Organisation\Inventory\Models\InventoryItem;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Services\InventoryReadinessService;
use App\Domain\Organisation\Inventory\Services\InventoryStaffNames;
use App\Domain\Organisation\Inventory\Services\PackConversionReader;
use App\Domain\Organisation\Inventory\Services\PackConversionService;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\Feature\Visit\VisitTestCase;

/** STK-52: a checked pack conversion per SKU ("1 bottle = 60 ml"); the blind check by a CA Supervisor or the Director. */
class PackConversionTest extends VisitTestCase
{
    private User $proposer;

    private User $checker;

    private InventorySku $sku;

    protected function setUp(): void
    {
        parent::setUp();
        $this->proposer = $this->actor('director');
        $this->checker = $this->actor('ca_supervisor');
        $this->sku = $this->syrup(verified: true);
    }

    private function syrup(bool $verified, string $code = 'SYN-SYRUP'): InventorySku
    {
        $item = new InventoryItem;
        $item->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'code' => $code, 'generic_name' => 'Synthetic paracetamol syrup', 'is_active' => true])->save();
        $sku = new InventorySku;
        $sku->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'inventory_item_id' => $item->id, 'sku_code' => $code, 'pack_size' => '60.000', 'purchase_unit' => 'bottle', 'stock_unit' => 'ml', 'dispensing_unit' => 'ml', 'unit_conversion' => '1.000', 'is_active' => true])->save();
        if ($verified) {
            $this->selectBranch($this->proposer);
            app(InventoryReadinessService::class)->verify($this->proposer, 'unit', $sku->public_id, ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'retry_key' => (string) Str::uuid(), 'base_unit' => 'ml']);
        }

        return $sku->refresh();
    }

    /** @param array<string, mixed> $extra */
    private function propose(int $basePerPack = 60, string $unit = 'bottle', ?User $by = null, ?InventorySku $sku = null, array $extra = []): string
    {
        $sku ??= $this->sku;
        $latest = (int) DB::table('inventory_pack_conversions')->where('inventory_sku_id', $sku->id)->max('version');

        return app(PackConversionService::class)->propose($by ?? $this->proposer, $sku->public_id, [
            'expected_version' => $latest, 'idempotency_key' => (string) Str::uuid(), 'pack_unit' => $unit, 'base_per_pack' => $basePerPack, ...$extra,
        ]);
    }

    private function check(string $conversion, ?int $typed, ?User $by = null): string
    {
        return app(PackConversionService::class)->check($by ?? $this->checker, $conversion, ['idempotency_key' => (string) Str::uuid(), 'checked_base_per_pack' => $typed]);
    }

    /** @return array<string, mixed> */
    private function block(User $viewer): array
    {
        $this->selectBranch($viewer);
        $rows = $this->get(route('inventory.skus', ['q' => $this->sku->sku_code]))->assertOk()->viewData('page')['props']['skus']['rows'];

        return $rows[0]['packConversion'];
    }

    private function refused(callable $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('PostgreSQL accepted the write.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_exactly_ca_supervisor_and_director_may_check_and_the_grant_is_additive(): void
    {
        $holders = collect(PermissionCatalogue::roles())->filter(fn ($p) => in_array(PackConversionService::VERIFY, $p, true))->keys()->sort()->values()->all();
        $this->assertSame(['ca_supervisor', 'director'], $holders);
        $m = require database_path('migrations/2026_11_07_003300_grant_pack_conversion_verify_permission.php');
        $m->up();
        $m->up();
        $m->down();
        foreach (array_keys(PermissionCatalogue::roles()) as $role) {
            $this->assertSame(in_array($role, $holders, true), Role::findByName($role)->hasPermissionTo(PackConversionService::VERIFY), $role);
        }
    }

    public function test_a_blind_check_that_matches_puts_the_conversion_in_use_for_dispensary(): void
    {
        $this->assertNull(app(PackConversionReader::class)->checkedFor($this->organisation->id, $this->sku->id));
        $id = $this->propose();
        $this->assertNull(app(PackConversionReader::class)->checkedFor($this->organisation->id, $this->sku->id), 'a waiting change is not in use');
        $this->assertSame('checked', $this->check($id, 60));
        $current = app(PackConversionReader::class)->checkedFor($this->organisation->id, $this->sku->id);
        $this->assertNotNull($current);
        $this->assertSame(['bottle', 60, 'ml', 1, $this->sku->id], [$current->packUnit, $current->basePerPack, $current->baseUnit, $current->version, $current->skuId]);
        $reader = app(PackConversionReader::class);
        $this->assertSame('120.000', $reader->toBase($current, 2));
        $this->assertSame('60000.000', $reader->toBase($current, 1000));
        foreach ([0, -1, 1001] as $bad) {
            try {
                $reader->toBase($current, $bad);
                $this->fail('toBase accepted '.$bad);
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $audit = DB::table('audit_logs')->where('event', 'like', 'inventory.pack_conversion.%')->orderBy('id')->pluck('metadata', 'event')->all();
        $this->assertSame(['inventory.pack_conversion.proposed', 'inventory.pack_conversion.checked'], array_keys($audit));
        foreach ($audit as $metadata) {
            $this->assertStringNotContainsString('bottle', (string) $metadata);
        }
    }

    public function test_a_wrong_blind_count_is_refused_and_the_message_never_repeats_the_number(): void
    {
        $id = $this->propose(60);
        $this->selectBranch($this->checker);
        $response = $this->post(route('inventory.pack-conversions.check', $id), ['idempotency_key' => (string) Str::uuid(), 'checked_base_per_pack' => 50]);
        $response->assertRedirect(route('inventory.skus'))->assertSessionHasErrors(['checked_base_per_pack' => PackConversionService::MISMATCH_MESSAGE]);
        // The list comes back filtered as it was, so the SKU and the message stay on screen.
        $this->post(route('inventory.skus.pack-conversions.store', $this->sku->public_id), ['expected_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'pack_unit' => 'bottle', 'base_per_pack' => 60, 'return_q' => 'SYN-SYRUP', 'return_status' => 'active'])
            ->assertRedirect(route('inventory.skus', ['q' => 'SYN-SYRUP', 'status' => 'active']));
        $this->assertStringNotContainsString('60', PackConversionService::MISMATCH_MESSAGE);
        $this->assertSame(['refused', 'wrong_count', 50], [
            ...array_values((array) DB::table('inventory_pack_conversion_outcomes')->sole(['outcome', 'reason'])),
            (int) DB::table('inventory_pack_conversion_outcomes')->value('checked_base_per_pack'),
        ]);
        $this->assertNull(app(PackConversionReader::class)->checkedFor($this->organisation->id, $this->sku->id));
    }

    public function test_the_checker_never_receives_the_waiting_number_and_the_proposer_and_a_third_person_do(): void
    {
        $refused = $this->propose(55);
        $this->check($refused, 54);
        $this->propose(60);
        $checker = $this->block($this->checker);
        $this->assertTrue($checker['waiting']['canCheck']);
        $this->assertArrayNotHasKey('basePerPack', $checker['waiting']);
        $this->assertArrayNotHasKey('packUnit', $checker['waiting']);
        // Refused numbers are hidden from a checker too, so they cannot be copied.
        $this->assertArrayNotHasKey('basePerPack', $checker['history'][0]);
        $json = json_encode($checker, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('60', $json);
        $this->assertStringNotContainsString('55', $json);

        $proposer = $this->block($this->proposer);
        $this->assertSame([60, true, true, false], [$proposer['waiting']['basePerPack'], $proposer['waiting']['mine'], $proposer['waiting']['canWithdraw'], $proposer['waiting']['canCheck']]);
        // A third person who cannot check (an Administrator manages references but holds no verify permission).
        $third = $this->block($this->actor('administrator'));
        $this->assertSame([60, false, false], [$third['waiting']['basePerPack'], $third['waiting']['canCheck'], $third['waiting']['canWithdraw']]);
        $this->assertSame(55, $third['history'][0]['basePerPack']);
    }

    public function test_only_a_ca_supervisor_or_director_checks_and_never_the_proposer(): void
    {
        $id = $this->propose(60, by: $this->actor('administrator'));
        foreach (['administrator', 'ca', 'finance_officer'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->post(route('inventory.pack-conversions.check', $id), ['idempotency_key' => (string) Str::uuid(), 'checked_base_per_pack' => 60])->assertForbidden();
        }
        $own = $this->propose(30, by: $this->checker, sku: $this->syrup(true, 'SYN-SYRUP-2'));
        try {
            $this->check($own, 30, $this->checker);
            $this->fail('The proposer checked their own change.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('pack_conversion', $e->errors());
        }
        $this->assertSame('checked', $this->check($id, 60, $this->proposer));
    }

    public function test_postgresql_refuses_a_self_check_another_organisation_and_any_change(): void
    {
        $id = (int) DB::table('inventory_pack_conversions')->where('public_id', $this->propose(60))->value('id');
        $row = ['organisation_id' => $this->organisation->id, 'pack_conversion_id' => $id, 'outcome' => 'checked', 'checked_base_per_pack' => 60, 'recorded_at' => now(), 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64)];
        $this->refused(fn () => DB::table('inventory_pack_conversion_outcomes')->insert([...$row, 'recorded_by_user_id' => $this->proposer->id]));
        $this->refused(fn () => DB::table('inventory_pack_conversion_outcomes')->insert([...$row, 'recorded_by_user_id' => $this->checker->id, 'checked_base_per_pack' => 59]));
        $other = Organisation::query()->whereKeyNot($this->organisation->id)->first() ?? Organisation::query()->create(['code' => 'SYN-OTHER', 'name' => 'Synthetic other organisation']);
        $this->refused(fn () => DB::table('inventory_pack_conversion_outcomes')->insert([...$row, 'organisation_id' => $other->id, 'recorded_by_user_id' => $this->checker->id]));
        $this->refused(fn () => DB::table('inventory_pack_conversion_outcomes')->insert([...$row, 'recorded_by_user_id' => $this->checker->id, 'outcome' => 'withdrawn', 'checked_base_per_pack' => null]));
        $this->refused(fn () => DB::table('inventory_pack_conversions')->where('id', $id)->update(['base_per_pack' => 61]));
        $this->refused(fn () => DB::table('inventory_pack_conversions')->where('id', $id)->delete());
        $this->refused(fn () => DB::statement('TRUNCATE inventory_pack_conversions CASCADE'));
        $this->refused(fn () => DB::table('inventory_pack_conversions')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'inventory_sku_id' => $this->sku->id,
            'version' => 2, 'pack_unit' => 'box', 'base_per_pack' => 10, 'base_unit' => 'ml', 'proposed_by_user_id' => $this->proposer->id, 'proposed_at' => now(), 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('b', 64)]));
        $this->refused(fn () => DB::table('inventory_pack_conversions')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'inventory_sku_id' => $this->syrup(true, 'SYN-SYRUP-3')->id,
            'version' => 1, 'pack_unit' => 'ml', 'base_per_pack' => 10, 'base_unit' => 'ml', 'proposed_by_user_id' => $this->proposer->id, 'proposed_at' => now(), 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('c', 64)]));
    }

    public function test_proposals_need_a_verified_unit_a_valid_count_a_current_page_and_permission(): void
    {
        $unverified = $this->syrup(false, 'SYN-SYRUP-RAW');
        $cases = [
            [fn () => $this->propose(60, sku: $unverified), 'pack_unit'],
            [fn () => $this->propose(1), 'base_per_pack'],
            [fn () => $this->propose(100001), 'base_per_pack'],
            [fn () => $this->propose(60, 'ML'), 'pack_unit'],
            [fn () => $this->propose(60, extra: ['expected_version' => 3]), 'expected_version'],
            [fn () => $this->propose(60, extra: ['stop' => true]), 'stop'],
        ];
        foreach ($cases as [$write, $field]) {
            try {
                $write();
                $this->fail('Accepted a proposal that should fail on '.$field);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }
        $this->expectException(AuthorizationException::class);
        $this->propose(60, by: $this->actor('ca'));
    }

    public function test_one_change_waits_at_a_time_withdraw_is_the_proposers_and_empty_changes_are_refused(): void
    {
        $waiting = $this->propose(60);
        try {
            $this->propose(50, by: $this->actor('administrator'));
            $this->fail('A second change waited at once.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('pack_unit', $e->errors());
        }
        try {
            app(PackConversionService::class)->withdraw($this->checker, $waiting, ['idempotency_key' => (string) Str::uuid()]);
            $this->fail('Someone else withdrew the change.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('pack_conversion', $e->errors());
        }
        app(PackConversionService::class)->withdraw($this->proposer, $waiting, ['idempotency_key' => (string) Str::uuid()]);
        $this->check($this->propose(60), 60);
        try {
            $this->propose(60);
            $this->fail('A proposal equal to the current conversion was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('base_per_pack', $e->errors());
        }
        // Stop using it: once checked, Dispensary has nothing to convert with.
        $this->check($this->propose(extra: ['stop' => true]), null);
        $this->assertNull(app(PackConversionReader::class)->checkedFor($this->organisation->id, $this->sku->id));
        $this->assertSame([1, 2, 3], DB::table('inventory_pack_conversions')->where('inventory_sku_id', $this->sku->id)->orderBy('version')->pluck('version')->map(fn ($v) => (int) $v)->all());
    }

    public function test_a_retry_with_the_same_key_changes_nothing_and_a_different_payload_is_refused(): void
    {
        $key = (string) Str::uuid();
        $service = app(PackConversionService::class);
        $input = ['expected_version' => 0, 'idempotency_key' => $key, 'pack_unit' => 'bottle', 'base_per_pack' => 60];
        $first = $service->propose($this->proposer, $this->sku->public_id, $input);
        $this->assertSame($first, $service->propose($this->proposer, $this->sku->public_id, $input));
        $this->assertSame(1, DB::table('inventory_pack_conversions')->count());
        $this->expectException(ValidationException::class);
        $service->propose($this->proposer, $this->sku->public_id, [...$input, 'base_per_pack' => 50]);
    }

    public function test_the_reader_returns_nothing_for_an_inactive_sku_or_a_changed_base_unit(): void
    {
        $this->check($this->propose(60), 60);
        $reader = app(PackConversionReader::class);
        DB::table('inventory_skus')->where('id', $this->sku->id)->update(['is_active' => false]);
        $this->assertNull($reader->checkedFor($this->organisation->id, $this->sku->id));
        DB::table('inventory_skus')->where('id', $this->sku->id)->update(['is_active' => true]);
        // A verified base unit cannot change at all (STK-1's trigger), so a base-unit mismatch is only a defence in depth.
        $this->refused(fn () => DB::table('inventory_skus')->where('id', $this->sku->id)->update(['stock_unit' => 'bottle']));
        $this->assertNotNull(DB::transaction(fn () => $reader->checkedForShare($this->organisation->id, $this->sku->id)));
    }

    public function test_pilot_readiness_lists_skus_waiting_for_a_check_and_names_follow_the_visibility_rule(): void
    {
        $this->propose(60);
        $this->selectBranch($this->checker);
        $props = $this->get(route('inventory.readiness'))->assertOk()->viewData('page')['props']['readiness'];
        $this->assertSame([$this->sku->sku_code], $props['packConversionsWaiting']);
        $block = $this->block($this->proposer);
        $this->assertSame('You', $block['waiting']['proposedBy']);
    }

    public function test_names_follow_the_visibility_rule_and_the_legacy_note_needs_a_checked_conversion(): void
    {
        $this->propose(50);
        $ca = $this->actor('ca');
        $waiting = $this->block($ca)['waiting'];
        // The shared staff-visibility rule (InventoryStaffNames): a name the viewer may not see reads "Staff member".
        $this->assertSame(app(InventoryStaffNames::class)->resolver($ca, [$this->proposer->id])($this->proposer->id), $waiting['proposedBy']);
        $this->assertNotSame('You', $waiting['proposedBy']);
        // While a change only waits, there is no legacy note (and so no legacy number) in the block.
        $this->assertSame([false, null], [$this->block($this->checker)['legacyDiffers'], $this->block($this->checker)['legacy']]);
        $this->check(DB::table('inventory_pack_conversions')->value('public_id'), 50);
        $after = $this->block($this->checker);
        $this->assertTrue($after['legacyDiffers']);
        $this->assertSame(['packSize' => '60.000', 'purchaseUnit' => 'bottle'], $after['legacy']);
    }

    public function test_postgresql_refuses_a_pack_unit_equal_to_the_base_unit_in_any_case_and_a_decision_on_an_old_version(): void
    {
        $this->refused(fn () => DB::table('inventory_pack_conversions')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'inventory_sku_id' => $this->sku->id,
            'version' => 1, 'pack_unit' => ' ML ', 'base_per_pack' => 10, 'base_unit' => 'ml', 'proposed_by_user_id' => $this->proposer->id, 'proposed_at' => now(), 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('d', 64)]));
        $first = (int) DB::table('inventory_pack_conversions')->where('public_id', $this->propose(60))->value('id');
        app(PackConversionService::class)->withdraw($this->proposer, DB::table('inventory_pack_conversions')->where('id', $first)->value('public_id'), ['idempotency_key' => (string) Str::uuid()]);
        $this->propose(50);
        $this->refused(fn () => DB::table('inventory_pack_conversion_outcomes')->insert(['organisation_id' => $this->organisation->id, 'pack_conversion_id' => $first, 'outcome' => 'refused', 'reason' => 'other',
            'recorded_by_user_id' => $this->checker->id, 'recorded_at' => now(), 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('e', 64)]));
    }

    public function test_the_migration_drops_and_restores_its_tables(): void
    {
        $m = require database_path('migrations/2026_11_07_003200_create_inventory_pack_conversions.php');
        $m->down();
        $this->assertFalse(Schema::hasTable('inventory_pack_conversions'));
        $m->up();
        $this->assertTrue(Schema::hasTable('inventory_pack_conversion_outcomes'));
    }
}

<?php

namespace Tests\Feature\Inventory;

use App\Domain\Organisation\Inventory\Models\InventorySupplierSkuTerm;
use App\Domain\Organisation\Inventory\Services\SupplierTermsService;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\SupplierReturnFixture;
use Tests\Support\VerifiedReceivingFixture;

/** STK-13: supplier price list and lead time per SKU (owner "a until g" approval and "agree all", 2026-11-11). */
final class SupplierTermsTest extends VisitTestCase
{
    /** @return array{0: array<string, mixed>, 1: callable(string): User} */
    private function setUpTerms(): array
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build('10.000');
        $actors = new SupplierReturnFixture($this->branch);

        return [$f, fn (string $role) => $actors->actor($role)];
    }

    /** @return array<string, mixed> */
    private function input(array $f, array $overrides = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(),
            'supplier_public_id' => $f['supplier']->public_id, 'sku_public_id' => $f['sku']->public_id,
            'pack_description' => 'Box of 100', 'pack_quantity' => '100', 'pack_price_sen' => 1234,
            'lead_time_days' => 7, 'minimum_order_packs' => 2, 'is_preferred' => true, ...$overrides];
    }

    public function test_a_finance_officer_records_an_exact_pack_price_and_every_change_is_kept(): void
    {
        [$f, $actor] = $this->setUpTerms();
        $finance = $actor('finance_officer');
        $service = app(SupplierTermsService::class);
        $input = $this->input($f);
        $term = $service->create($finance, $input);
        $this->assertSame(['Box of 100', '100.000', 1234, 7, 2, true, 1], [$term->pack_description, (string) $term->refresh()->pack_quantity, $term->pack_price_sen, $term->lead_time_days, $term->minimum_order_packs, $term->is_preferred, $term->lock_version]);

        // A retry with the same key returns the same row; the same key with other details is refused.
        $this->assertSame($term->id, $service->create($finance, $input)->id);
        $this->assertThrows(fn () => $service->create($finance, [...$input, 'lead_time_days' => 9]), ValidationException::class);

        $updated = $service->update($finance, $term->public_id, [...$this->input($f, ['lead_time_days' => 14, 'pack_price_sen' => 1300]), 'lock_version' => 1, 'is_active' => true]);
        $this->assertSame([14, 1300, 2], [$updated->lead_time_days, $updated->pack_price_sen, $updated->lock_version]);
        $this->assertThrows(fn () => $service->update($finance, $term->public_id, [...$this->input($f), 'lock_version' => 1, 'is_active' => true]), ValidationException::class);
        $this->assertSame([1, 2], DB::table('inventory_supplier_sku_term_revisions')->where('inventory_supplier_sku_term_id', $term->id)->orderBy('revision')->pluck('revision')->all());

        // The audit trail names the changed fields, never the price.
        $audit = DB::table('audit_logs')->where('event', 'inventory.supplier_terms.updated')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('1300', (string) $audit->metadata);
        $this->assertStringContainsString('pack_price', (string) $audit->metadata);
    }

    public function test_only_a_cost_holder_sees_or_sets_a_price(): void
    {
        [$f, $actor] = $this->setUpTerms();
        $service = app(SupplierTermsService::class);
        $administrator = $actor('administrator');

        try {
            $service->create($administrator, $this->input($f));
            $this->fail('An administrator set a supplier price');
        } catch (ValidationException $e) {
            $this->assertSame(['Only a Director or Finance Officer may set a supplier price.'], $e->errors()['pack_price_sen']);
        }
        $input = $this->input($f);
        unset($input['pack_price_sen']);
        $term = $service->create($administrator, $input);
        $this->assertNull($term->pack_price_sen);

        // A Director sets the price; a later edit by the administrator keeps it.
        $service->update($actor('director'), $term->public_id, [...$this->input($f, ['pack_price_sen' => 990]), 'lock_version' => 1, 'is_active' => true]);
        $service->update($administrator, $term->public_id, [...$input, 'idempotency_key' => (string) Str::uuid(), 'lead_time_days' => 3, 'lock_version' => 2, 'is_active' => true]);
        $this->assertSame([990, 3], [$term->refresh()->pack_price_sen, $term->lead_time_days]);

        // The price never reaches the page of someone without the cost permission.
        foreach (['administrator' => false, 'ca_supervisor' => false, 'finance_officer' => true] as $role => $sees) {
            $row = $this->actingAs($actor($role))->get(route('inventory.supplier-terms'))->assertOk()->viewData('page')['props']['terms']['rows'][0];
            $this->assertSame($sees, array_key_exists('packPriceSen', $row), $role);
        }
        $this->actingAs($actor('ca_supervisor'))->post(route('inventory.supplier-terms.store'), $this->input($f))->assertForbidden();
    }

    public function test_one_preferred_supplier_per_sku_and_rows_are_never_deleted(): void
    {
        [$f, $actor] = $this->setUpTerms();
        $finance = $actor('finance_officer');
        $service = app(SupplierTermsService::class);
        $first = $service->create($finance, $this->input($f));
        $other = DB::table('inventory_suppliers')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id,
            'code' => 'SYN-SUP-2', 'name' => 'Synthetic second supplier', 'is_active' => true, 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $second = $service->create($finance, $this->input($f, ['supplier_public_id' => DB::table('inventory_suppliers')->where('id', $other)->value('public_id')]));
        $this->assertSame([false, true], [$first->refresh()->is_preferred, $second->is_preferred]);
        $this->assertSame(2, $first->lock_version);

        try {
            DB::transaction(fn () => DB::table('inventory_supplier_sku_terms')->where('id', $first->id)->update(['is_preferred' => true, 'lock_version' => 3]));
            $this->fail('Two preferred suppliers were stored');
        } catch (QueryException $e) {
            $this->assertStringContainsString('supplier_sku_terms_one_preferred', $e->getMessage());
        }
        foreach ([
            fn () => DB::table('inventory_supplier_sku_terms')->where('id', $first->id)->delete(),
            fn () => DB::table('inventory_supplier_sku_term_revisions')->where('inventory_supplier_sku_term_id', $first->id)->delete(),
            fn () => DB::table('inventory_supplier_sku_terms')->where('id', $first->id)->update(['lead_time_days' => 1]),
            fn () => DB::table('inventory_supplier_sku_terms')->where('id', $first->id)->update(['pack_quantity' => '0', 'lock_version' => 3]),
        ] as $write) {
            try {
                DB::transaction($write);
                $this->fail('PostgreSQL accepted a forbidden price list write');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        $this->assertThrows(fn () => InventorySupplierSkuTerm::query()->findOrFail($first->id)->delete(), \LogicException::class);
    }

    public function test_review_fixes_bad_pack_size_kept_price_and_no_empty_saves(): void
    {
        [$f, $actor] = $this->setUpTerms();
        $finance = $actor('finance_officer');
        $service = app(SupplierTermsService::class);
        foreach (['0', '1.2345', 'abc'] as $bad) {
            try {
                $service->create($finance, $this->input($f, ['pack_quantity' => $bad]));
                $this->fail("Pack quantity {$bad} was accepted");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('pack_quantity', $e->errors(), $bad);
            }
        }
        $term = $service->create($finance, $this->input($f));

        // A cost holder who leaves the price out keeps it; an explicit null clears it.
        $input = $this->input($f, ['lead_time_days' => 9]);
        unset($input['pack_price_sen']);
        $service->update($finance, $term->public_id, [...$input, 'lock_version' => 1, 'is_active' => true]);
        $this->assertSame([1234, 9, 2], [$term->refresh()->pack_price_sen, $term->lead_time_days, $term->lock_version]);

        // Saving the same values writes no version, history or audit.
        $audits = DB::table('audit_logs')->count();
        $service->update($finance, $term->public_id, [...$input, 'idempotency_key' => (string) Str::uuid(), 'lock_version' => 2, 'is_active' => true]);
        $this->assertSame([2, 2, $audits], [$term->refresh()->lock_version, DB::table('inventory_supplier_sku_term_revisions')->where('inventory_supplier_sku_term_id', $term->id)->count(), DB::table('audit_logs')->count()]);

        $service->update($finance, $term->public_id, [...$this->input($f, ['pack_price_sen' => null, 'lead_time_days' => 9]), 'lock_version' => 2, 'is_active' => true]);
        $this->assertNull($term->refresh()->pack_price_sen);

        // A row cannot come back while its supplier is inactive.
        $service->update($finance, $term->public_id, [...$this->input($f, ['is_preferred' => false, 'lead_time_days' => 9, 'pack_price_sen' => null]), 'lock_version' => 3, 'is_active' => false]);
        DB::table('inventory_suppliers')->where('id', $f['supplier']->id)->update(['is_active' => false, 'lock_version' => DB::raw('lock_version + 1')]);
        $this->assertThrows(fn () => $service->update($finance, $term->public_id, [...$this->input($f, ['is_preferred' => false, 'lead_time_days' => 9, 'pack_price_sen' => null]), 'lock_version' => 4, 'is_active' => true]), ValidationException::class);
    }

    public function test_the_migration_replays_and_rolls_back(): void
    {
        $migration = require database_path('migrations/2026_11_07_000800_create_supplier_sku_terms.php');
        $migration->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('inventory_supplier_sku_terms'));
        $migration->up();
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('inventory_supplier_sku_term_revisions'));
    }
}

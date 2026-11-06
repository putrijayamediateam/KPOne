<?php

namespace Tests\Feature\Clinical;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Models\ClinicalServiceCatalogueItem;
use App\Domain\Clinical\Services\ClinicalServiceCatalogueAdministrationService;
use App\Domain\Clinical\Services\ClinicalServiceCategories;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * CAT-11 (B.E.R.N.I.S.S for the owner, 2026-11-06; names confirmed by Billing for BIL-9): a clinical service's category
 * is one of four fixed names or none. Older values stay as they are and readable; only a new service or a changed
 * category must use the list, in the service and in PostgreSQL.
 */
class ClinicalServiceCategoriesTest extends ClinicalTestCase
{
    public function test_the_list_is_the_four_names_billing_maps(): void
    {
        $this->assertSame(['Procedure(s)', 'Investigation(s)', 'Consultation-related', 'Other service'], ClinicalServiceCategories::NAMES);
    }

    public function test_a_new_service_takes_a_listed_category_or_none_and_refuses_anything_else(): void
    {
        $director = $this->actor('director');
        foreach ([...ClinicalServiceCategories::NAMES, null] as $index => $category) {
            $service = $this->service()->create($director, $this->attributes("CAT11-OK-{$index}", $category));
            $this->assertSame($category, $service->category);
        }

        foreach (['Procedure', 'procedure(s)', 'Test', 'Synthetic free text'] as $index => $category) {
            try {
                $this->service()->create($director, $this->attributes("CAT11-BAD-{$index}", $category));
                $this->fail("{$category} should be refused");
            } catch (ValidationException $exception) {
                $this->assertSame(['category' => ['Choose a category from the list.']], $exception->errors());
            }
        }
        $this->assertSame(0, ClinicalServiceCatalogueItem::query()->where('code', 'like', 'CAT11-BAD-%')->count());
    }

    public function test_an_older_free_text_category_is_kept_when_other_fields_change_and_refused_as_a_new_value(): void
    {
        $director = $this->actor('director');
        $legacy = $this->legacyService($director->organisation_id, 'Synthetic old category');

        $updated = $this->service()->update($director, $legacy, ['display_name' => 'Synthetic renamed service', 'category' => 'Synthetic old category']);
        $this->assertSame('Synthetic old category', $updated->category);
        $this->assertSame('Synthetic renamed service', $updated->display_name);

        try {
            $this->service()->update($director, $updated, ['category' => 'Another free text']);
            $this->fail('A new free-text category should be refused');
        } catch (ValidationException $exception) {
            $this->assertSame(['category' => ['Choose a category from the list.']], $exception->errors());
        }
        $this->assertSame('Synthetic old category', $updated->fresh()->category);

        $moved = $this->service()->update($director, $updated->fresh(), ['category' => 'Procedure(s)']);
        $this->assertSame('Procedure(s)', $moved->category);
        $cleared = $this->service()->update($director, $moved, ['category' => null]);
        $this->assertNull($cleared->category);
        $this->assertSame(3, AuditLog::query()->where('event', 'clinical_service_catalogue.updated')->where('subject_id', $legacy->id)->count());
    }

    public function test_the_http_store_route_refuses_a_free_text_category_with_a_field_error(): void
    {
        $this->actingAs($this->actor('director'));
        $this->post(route('clinical-services.store'), $this->attributes('CAT11-HTTP', 'Synthetic free text'))
            ->assertSessionHasErrors(['category' => 'Choose a category from the list.']);
        $this->assertFalse(ClinicalServiceCatalogueItem::query()->where('code', 'CAT11-HTTP')->exists());
    }

    public function test_the_page_offers_the_fixed_list(): void
    {
        $this->actingAs($this->actor('director'));
        $this->get(route('clinical-services.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('setup.serviceCategories', ClinicalServiceCategories::NAMES));
    }

    public function test_postgresql_refuses_an_unlisted_category_on_insert_and_on_change_but_keeps_older_rows(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The category trigger is PostgreSQL only.');
        }
        $director = $this->actor('director');
        $legacy = $this->legacyService($director->organisation_id, 'Synthetic old category');

        // An older row can still be updated as long as its category stays the same.
        DB::table('clinical_service_catalogue_items')->where('id', $legacy->id)->update(['display_name' => 'Synthetic direct rename']);
        $this->assertSame('Synthetic old category', DB::table('clinical_service_catalogue_items')->where('id', $legacy->id)->value('category'));

        $this->assertRefused(fn () => DB::table('clinical_service_catalogue_items')->where('id', $legacy->id)->update(['category' => 'Another free text']));
        $this->assertRefused(fn () => $this->insertRow($director->organisation_id, 'CAT11-PG-BAD', 'Test'));

        $this->insertRow($director->organisation_id, 'CAT11-PG-OK', 'Investigation(s)');
        $this->insertRow($director->organisation_id, 'CAT11-PG-NULL', null);
        $this->assertSame(2, DB::table('clinical_service_catalogue_items')->whereIn('code', ['CAT11-PG-OK', 'CAT11-PG-NULL'])->count());
    }

    /** @return array<string, mixed> */
    private function attributes(string $code, ?string $category): array
    {
        return ['code' => $code, 'display_name' => "Synthetic service {$code}", 'order_unit' => 'session', 'category' => $category];
    }

    /** A service saved before CAT-11, with a free-text category: written with the trigger off, as the old code could. */
    private function legacyService(int $organisationId, string $category): ClinicalServiceCatalogueItem
    {
        $service = $this->service()->create($this->actor('director'), $this->attributes('CAT11-LEGACY', null));
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE clinical_service_catalogue_items DISABLE TRIGGER clinical_service_catalogue_items_category');
        }
        DB::table('clinical_service_catalogue_items')->where('id', $service->id)->where('organisation_id', $organisationId)->update(['category' => $category]);
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE clinical_service_catalogue_items ENABLE TRIGGER clinical_service_catalogue_items_category');
        }

        return $service->fresh();
    }

    private function insertRow(int $organisationId, string $code, ?string $category): void
    {
        $template = (array) DB::table('clinical_service_catalogue_items')->where('organisation_id', $organisationId)->first();
        unset($template['id']);
        DB::table('clinical_service_catalogue_items')->insert([
            ...$template,
            'public_id' => (string) Str::uuid(),
            'code' => $code,
            'category' => $category,
        ]);
    }

    private function assertRefused(callable $write): void
    {
        DB::beginTransaction();
        try {
            $write();
            $this->fail('PostgreSQL should refuse this category');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Clinical service category must be one of the fixed names', $exception->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    private function service(): ClinicalServiceCatalogueAdministrationService
    {
        return app(ClinicalServiceCatalogueAdministrationService::class);
    }
}

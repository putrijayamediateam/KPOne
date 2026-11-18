<?php

namespace Tests\Feature\Clinical;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Services\MedicineAdministrationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Owner decision 2026-11-18: an optional "usual quantity" per medicine (in its order unit) that the doctor's treatment
 * plan can prefill. Blank means no prefill, zero is refused. Synthetic data only.
 */
class MedicineUsualQuantityTest extends ClinicalTestCase
{
    public function test_it_is_optional_stored_with_three_decimals_and_can_be_changed_and_cleared(): void
    {
        $director = $this->actor('director');
        $service = app(MedicineAdministrationService::class);

        $none = $service->create($director, $this->attributes());
        $this->assertNull($none->usual_quantity);

        $blank = $service->create($director, $this->attributes(['usual_quantity' => '  ']));
        $this->assertNull($blank->usual_quantity, 'blank means no prefill');

        $set = $service->create($director, $this->attributes(['usual_quantity' => '10']));
        $this->assertSame('10.000', $set->usual_quantity);

        $changed = $service->update($director, $set, ['usual_quantity' => '2.5']);
        $this->assertSame('2.500', $changed->usual_quantity);

        $untouched = $service->update($director, $changed, ['display_name' => 'Renamed']);
        $this->assertSame('2.500', $untouched->usual_quantity, 'an update that omits the key keeps the value');

        $cleared = $service->update($director, $untouched, ['usual_quantity' => '']);
        $this->assertNull($cleared->usual_quantity);
    }

    public function test_zero_negative_and_oversized_values_are_refused_by_the_service(): void
    {
        $director = $this->actor('director');
        $service = app(MedicineAdministrationService::class);

        foreach (['0', '0.000', '-1', '1000000.001', 'abc'] as $value) {
            try {
                $service->create($director, $this->attributes(['usual_quantity' => $value]));
                $this->fail('usual_quantity '.$value.' must be refused.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('usual_quantity', $e->errors());
            }
        }
        $this->assertSame(0, MedicineCatalogueItem::query()->count());
    }

    public function test_postgresql_refuses_a_zero_usual_quantity_written_directly(): void
    {
        $medicine = app(MedicineAdministrationService::class)->create($this->actor('director'), $this->attributes());

        $this->expectException(QueryException::class);
        DB::table('medicine_catalogue_items')->where('id', $medicine->id)->update(['usual_quantity' => 0]);
    }

    public function test_the_audit_names_the_field_and_never_the_value(): void
    {
        $director = $this->actor('director');
        $service = app(MedicineAdministrationService::class);
        $medicine = $service->create($director, $this->attributes());
        $service->update($director, $medicine, ['usual_quantity' => '7']);

        $audit = AuditLog::query()->where('event', 'medicine_catalogue.updated')->latest('id')->firstOrFail();
        $this->assertSame(['usual_quantity'], $audit->metadata['changed_fields']);
        $this->assertStringNotContainsString('7', json_encode($audit->metadata['changed_fields'], JSON_THROW_ON_ERROR));
    }

    public function test_the_catalogue_page_and_edit_form_expose_it_and_http_validation_refuses_zero(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->actingAs($director);
        $service = app(MedicineAdministrationService::class);
        $medicine = $service->create($director, $this->attributes(['usual_quantity' => '12']));

        $this->get(route('medicines.index'))->assertOk()->assertInertia(
            fn ($page) => $page->where('medicines.data.0.usualQuantity', '12.000'),
        );

        $this->getJson(route('medicines.setup', $medicine))->assertOk()->assertJsonPath('medicine.usual_quantity', '12.000');

        $this->patch(route('medicines.update', $medicine), ['usual_quantity' => '0'])
            ->assertSessionHasErrors('usual_quantity');
        $this->assertSame('12.000', $medicine->refresh()->usual_quantity);

        $this->patch(route('medicines.update', $medicine), ['usual_quantity' => '3'])
            ->assertRedirect(route('medicines.index'));
        $this->assertSame('3.000', $medicine->refresh()->usual_quantity);
    }

    /** @return array<string, mixed> */
    private function attributes(array $overrides = []): array
    {
        return [
            'code' => 'MED-'.Str::upper(Str::random(8)),
            'display_name' => 'Synthetic usual quantity medicine',
            'order_unit' => 'tablet',
            ...$overrides,
        ];
    }
}

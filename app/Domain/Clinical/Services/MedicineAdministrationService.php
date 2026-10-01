<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MedicineAdministrationService
{
    public const PERMISSION = 'medicines.manage.organisation';

    public function __construct(private AuditRecorder $audit) {}

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, array $attributes): MedicineCatalogueItem
    {
        $this->authorize($actor);
        $values = $this->createValues($attributes);

        return DB::transaction(function () use ($actor, $values): MedicineCatalogueItem {
            $this->lockOrganisation($actor);
            $this->assertUniqueCode($actor->organisation_id, $values['code']);

            $medicine = new MedicineCatalogueItem;
            $medicine->forceFill([
                'public_id' => (string) Str::uuid(),
                'organisation_id' => $actor->organisation_id,
                ...$values,
                'authorisation_class' => MedicineCatalogueItem::AUTHORISATION_DOCTOR_REQUIRED,
                'is_active' => true,
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ])->save();

            $this->audit->record('medicine_catalogue.created', $medicine, [
                'medicine_public_id' => $medicine->public_id,
                'changed_fields' => [...array_keys($values), 'authorisation_class', 'is_active'],
            ], $actor, organisationId: $actor->organisation_id);

            return $medicine;
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, MedicineCatalogueItem $medicine, array $attributes): MedicineCatalogueItem
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $medicine, $attributes): MedicineCatalogueItem {
            $this->lockOrganisation($actor);
            $locked = $this->lockMedicine($actor, $medicine);
            $values = $this->updateValues($locked, $attributes);
            $changes = array_filter(
                $values,
                fn (mixed $value, string $field): bool => $locked->getAttribute($field) !== $value,
                ARRAY_FILTER_USE_BOTH,
            );

            if ($changes === []) {
                return $locked;
            }

            if (array_key_exists('code', $changes)) {
                $this->assertIdentityEditable($locked, 'code');
                $this->assertUniqueCode($actor->organisation_id, $changes['code'], $locked->id);
            }
            if (array_key_exists('order_unit', $changes)) {
                $this->assertIdentityEditable($locked, 'order_unit');
            }

            $locked->forceFill([
                ...$changes,
                'updated_by_user_id' => $actor->id,
            ])->save();

            $this->audit->record('medicine_catalogue.updated', $locked, [
                'medicine_public_id' => $locked->public_id,
                'changed_fields' => array_keys($changes),
            ], $actor, organisationId: $actor->organisation_id);

            return $locked;
        }, 3);
    }

    public function activate(User $actor, MedicineCatalogueItem $medicine): MedicineCatalogueItem
    {
        return $this->setActive($actor, $medicine, true);
    }

    public function deactivate(User $actor, MedicineCatalogueItem $medicine): MedicineCatalogueItem
    {
        return $this->setActive($actor, $medicine, false);
    }

    private function setActive(User $actor, MedicineCatalogueItem $medicine, bool $active): MedicineCatalogueItem
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $medicine, $active): MedicineCatalogueItem {
            $this->lockOrganisation($actor);
            $locked = $this->lockMedicine($actor, $medicine);
            if ($locked->is_active === $active) {
                return $locked;
            }

            $locked->forceFill([
                'is_active' => $active,
                'updated_by_user_id' => $actor->id,
            ])->save();

            $this->audit->record(
                $active ? 'medicine_catalogue.activated' : 'medicine_catalogue.deactivated',
                $locked,
                [
                    'medicine_public_id' => $locked->public_id,
                    'changed_fields' => ['is_active'],
                ],
                $actor,
                organisationId: $actor->organisation_id,
            );

            return $locked;
        }, 3);
    }

    private function authorize(User $actor): void
    {
        Gate::forUser($actor)->authorize(self::PERMISSION);
        if (! $actor->is_active || ! $actor->organisation_id) {
            throw new AuthorizationException('You may not manage the Medicine Catalogue.');
        }
    }

    private function lockOrganisation(User $actor): Organisation
    {
        return Organisation::query()
            ->whereKey($actor->organisation_id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockMedicine(User $actor, MedicineCatalogueItem $medicine): MedicineCatalogueItem
    {
        return MedicineCatalogueItem::query()
            ->whereKey($medicine->id)
            ->where('organisation_id', $actor->organisation_id)
            ->lockForUpdate()
            ->firstOr(function (): never {
                throw new ModelNotFoundException;
            });
    }

    private function assertUniqueCode(int $organisationId, string $code, ?int $exceptId = null): void
    {
        $query = MedicineCatalogueItem::query()
            ->where('organisation_id', $organisationId)
            ->whereRaw('LOWER(code) = ?', [Str::lower($code)]);
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'code' => 'A Medicine with this code already exists in the organisation.',
            ]);
        }
    }

    private function assertIdentityEditable(MedicineCatalogueItem $medicine, string $field): void
    {
        foreach ([
            'treatment_plan_medicine_orders',
            'dispensary_items',
            'charge_definitions',
            'medicine_catalogue_inventory_skus',
        ] as $table) {
            if (DB::table($table)
                ->where('organisation_id', $medicine->organisation_id)
                ->where('medicine_catalogue_item_id', $medicine->id)
                ->exists()) {
                throw ValidationException::withMessages([
                    $field => $field === 'code'
                        ? 'The Medicine code cannot change after the Medicine has operational, pricing, or inventory dependencies.'
                        : 'The Medicine order unit cannot change after the Medicine has operational, pricing, or inventory dependencies.',
                ]);
            }
        }
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function createValues(array $attributes): array
    {
        return [
            'code' => $this->code($attributes['code'] ?? null),
            'display_name' => $this->requiredText($attributes['display_name'] ?? null, 'display_name', 500, collapseWhitespace: true),
            'strength_text' => $this->nullableText($attributes['strength_text'] ?? null, 'strength_text', 100),
            'dosage_form' => $this->nullableText($attributes['dosage_form'] ?? null, 'dosage_form', 100),
            'order_unit' => $this->requiredText($attributes['order_unit'] ?? null, 'order_unit', 100),
            'generic_name' => $this->nullableText($attributes['generic_name'] ?? null, 'generic_name', 300),
            'category' => $this->nullableText($attributes['category'] ?? null, 'category', 120),
            'group_name' => $this->nullableText($attributes['group_name'] ?? null, 'group_name', 120),
            'default_dosage_amount' => $this->nullableDecimal($attributes['default_dosage_amount'] ?? null, 'default_dosage_amount'),
            'default_dosage_unit' => $this->nullableText($attributes['default_dosage_unit'] ?? null, 'default_dosage_unit', 100),
            'default_instruction' => $this->nullableText($attributes['default_instruction'] ?? null, 'default_instruction', 200),
            'default_precaution' => $this->nullableText($attributes['default_precaution'] ?? null, 'default_precaution', 500),
            'default_frequency' => $this->nullableText($attributes['default_frequency'] ?? null, 'default_frequency', 200),
            'default_duration' => $this->nullableText($attributes['default_duration'] ?? null, 'default_duration', 100),
            'default_indication' => $this->nullableText($attributes['default_indication'] ?? null, 'default_indication', 300),
        ];
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function updateValues(MedicineCatalogueItem $medicine, array $attributes): array
    {
        if (array_key_exists('authorisation_class', $attributes)
            && $attributes['authorisation_class'] !== $medicine->authorisation_class) {
            throw ValidationException::withMessages([
                'authorisation_class' => 'Medicine authorisation class is not editable.',
            ]);
        }

        $values = [];
        foreach ([
            'code', 'display_name', 'strength_text', 'dosage_form', 'order_unit',
            'generic_name', 'category', 'group_name', 'default_dosage_amount',
            'default_dosage_unit', 'default_instruction', 'default_precaution',
            'default_frequency', 'default_duration', 'default_indication',
        ] as $field) {
            if (! array_key_exists($field, $attributes)) {
                continue;
            }
            $values[$field] = match ($field) {
                'code' => $this->updatedCode($medicine, $attributes[$field]),
                'display_name' => $this->requiredText($attributes[$field], $field, 500, collapseWhitespace: true),
                'strength_text', 'dosage_form' => $this->nullableText($attributes[$field], $field, 100),
                'order_unit' => $this->requiredText($attributes[$field], $field, 100),
                'generic_name' => $this->nullableText($attributes[$field], $field, 300),
                'category' => $this->nullableText($attributes[$field], $field, 120),
                'group_name' => $this->nullableText($attributes[$field], $field, 120),
                'default_dosage_amount' => $this->nullableDecimal($attributes[$field], $field),
                'default_dosage_unit' => $this->nullableText($attributes[$field], $field, 100),
                'default_instruction' => $this->nullableText($attributes[$field], $field, 200),
                'default_precaution' => $this->nullableText($attributes[$field], $field, 500),
                'default_frequency' => $this->nullableText($attributes[$field], $field, 200),
                'default_duration' => $this->nullableText($attributes[$field], $field, 100),
                'default_indication' => $this->nullableText($attributes[$field], $field, 300),
            };
        }

        return $values;
    }

    private function code(mixed $value): string
    {
        return Str::upper($this->requiredText($value, 'code', 64));
    }

    private function updatedCode(MedicineCatalogueItem $medicine, mixed $value): string
    {
        $normalized = $this->code($value);

        return Str::lower(trim((string) $medicine->code)) === Str::lower($normalized)
            ? $medicine->code
            : $normalized;
    }

    private function requiredText(
        mixed $value,
        string $field,
        int $maximum,
        bool $collapseWhitespace = false,
    ): string {
        if (! is_string($value)) {
            throw ValidationException::withMessages([$field => "Enter a valid {$field}."]);
        }
        $value = trim($value);
        if ($collapseWhitespace) {
            $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        }
        if ($value === '' || mb_strlen($value) > $maximum || preg_match('/[\p{C}]/u', $value) === 1) {
            throw ValidationException::withMessages([$field => "Enter a readable {$field} of {$maximum} characters or fewer."]);
        }

        return $value;
    }

    private function nullableText(mixed $value, string $field, int $maximum): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return $this->requiredText($value, $field, $maximum);
    }

    private function nullableDecimal(mixed $value, string $field): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (! is_numeric($value) || (float) $value <= 0 || (float) $value > 1000000) {
            throw ValidationException::withMessages([$field => 'Enter a positive amount.']);
        }

        return number_format((float) $value, 3, '.', '');
    }
}

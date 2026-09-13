<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\InventorySupplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SupplierAdministrationService
{
    public const PERMISSION = 'inventory.suppliers.manage.organisation';

    public function __construct(private InventoryAuthorityService $authority, private AuditRecorder $audit) {}

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, array $attributes): InventorySupplier
    {
        $values = $this->values($attributes, false);

        return DB::transaction(function () use ($actor, $values): InventorySupplier {
            $lockedActor = $this->authority->lockForOrganisation($actor, self::PERMISSION);
            $this->assertCodeAvailable($lockedActor->organisation_id, $values['code']);
            $supplier = new InventorySupplier;
            $supplier->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $lockedActor->organisation_id, ...$values])->save();
            $this->audit->record('inventory.supplier.created', $supplier, ['code' => $supplier->code, 'is_active' => $supplier->is_active], $lockedActor);

            return $supplier;
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, InventorySupplier $supplier, array $attributes): InventorySupplier
    {
        if (array_key_exists('is_active', $attributes)) {
            throw ValidationException::withMessages(['is_active' => 'Supplier lifecycle changes require the dedicated activation action.']);
        }
        $values = $this->values($attributes, true);

        return DB::transaction(function () use ($actor, $supplier, $values): InventorySupplier {
            $lockedActor = $this->authority->lockForOrganisation($actor, self::PERMISSION);
            $locked = InventorySupplier::query()->whereKey($supplier->id)->where('organisation_id', $lockedActor->organisation_id)->lockForUpdate()->firstOrFail();
            $changed = array_filter($values, fn (mixed $value, string $key): bool => $locked->getAttribute($key) !== $value, ARRAY_FILTER_USE_BOTH);
            if ($changed !== []) {
                $locked->forceFill($changed)->save();
                $this->audit->record('inventory.supplier.updated', $locked, ['changed_fields' => array_keys($changed)], $lockedActor);
            }

            return $locked->refresh();
        }, 3);
    }

    public function setActive(User $actor, InventorySupplier $supplier, bool $active): InventorySupplier
    {
        return DB::transaction(function () use ($actor, $supplier, $active): InventorySupplier {
            $lockedActor = $this->authority->lockForOrganisation($actor, self::PERMISSION);
            $locked = InventorySupplier::query()->whereKey($supplier->id)->where('organisation_id', $lockedActor->organisation_id)->lockForUpdate()->firstOrFail();
            if ($locked->is_active !== $active) {
                $locked->forceFill(['is_active' => $active])->save();
                $this->audit->record($active ? 'inventory.supplier.activated' : 'inventory.supplier.deactivated', $locked, ['is_active' => $active], $lockedActor);
            }

            return $locked->refresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function values(array $attributes, bool $partial): array
    {
        $rules = [
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/'],
            'name' => ['required', 'string', 'max:200'],
            'contact_name' => ['nullable', 'string', 'max:200'],
            'business_email' => ['nullable', 'email:rfc', 'max:254'],
            'business_phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+() .-]+$/'],
            'is_active' => ['sometimes', 'boolean'],
        ];
        if ($partial) {
            foreach ($rules as $field => $fieldRules) {
                $rules[$field] = ['sometimes', ...array_values(array_filter($fieldRules, fn (string $rule): bool => $rule !== 'required'))];
            }
            unset($rules['code']);
        }
        $values = validator($attributes, $rules)->validate();
        if (isset($values['code'])) {
            $values['code'] = Str::upper(trim($values['code']));
        }
        foreach (['name', 'contact_name', 'business_email', 'business_phone'] as $field) {
            if (array_key_exists($field, $values)) {
                $values[$field] = filled($values[$field]) ? trim((string) $values[$field]) : null;
            }
        }
        if (! $partial) {
            $values['is_active'] ??= true;
        }

        return $values;
    }

    private function assertCodeAvailable(int $organisationId, string $code): void
    {
        if (InventorySupplier::query()->where('organisation_id', $organisationId)->whereRaw('LOWER(code) = ?', [mb_strtolower($code)])->exists()) {
            throw ValidationException::withMessages(['code' => 'Supplier code is already in use.']);
        }
    }
}

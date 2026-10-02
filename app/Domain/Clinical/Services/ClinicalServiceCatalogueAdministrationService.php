<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Models\ClinicalServiceCatalogueItem;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ClinicalServiceCatalogueAdministrationService
{
    public const PERMISSION = 'clinical_services.manage.organisation';

    public function __construct(private AuditRecorder $audit) {}

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, array $attributes): ClinicalServiceCatalogueItem
    {
        $this->authorize($actor);
        $values = $this->createValues($attributes);

        return DB::transaction(function () use ($actor, $values): ClinicalServiceCatalogueItem {
            $this->lockOrganisation($actor);
            $this->assertUniqueCode($actor->organisation_id, $values['code']);

            $service = new ClinicalServiceCatalogueItem;
            $service->forceFill([
                'public_id' => (string) Str::uuid(),
                'organisation_id' => $actor->organisation_id,
                ...$values,
                'is_active' => true,
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ])->save();

            $this->audit->record('clinical_service_catalogue.created', $service, [
                'service_public_id' => $service->public_id,
                'changed_fields' => [...array_keys($values), 'is_active'],
            ], $actor, organisationId: $actor->organisation_id);

            return $service;
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, ClinicalServiceCatalogueItem $service, array $attributes): ClinicalServiceCatalogueItem
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $service, $attributes): ClinicalServiceCatalogueItem {
            $this->lockOrganisation($actor);
            $locked = $this->lockService($actor, $service);
            $values = $this->updateValues($attributes);
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

            $this->audit->record('clinical_service_catalogue.updated', $locked, [
                'service_public_id' => $locked->public_id,
                'changed_fields' => array_keys($changes),
            ], $actor, organisationId: $actor->organisation_id);

            return $locked;
        }, 3);
    }

    public function activate(User $actor, ClinicalServiceCatalogueItem $service): ClinicalServiceCatalogueItem
    {
        return $this->setActive($actor, $service, true);
    }

    public function deactivate(User $actor, ClinicalServiceCatalogueItem $service): ClinicalServiceCatalogueItem
    {
        return $this->setActive($actor, $service, false);
    }

    private function setActive(User $actor, ClinicalServiceCatalogueItem $service, bool $active): ClinicalServiceCatalogueItem
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $service, $active): ClinicalServiceCatalogueItem {
            $this->lockOrganisation($actor);
            $locked = $this->lockService($actor, $service);
            if ($locked->is_active === $active) {
                return $locked;
            }

            $locked->forceFill([
                'is_active' => $active,
                'updated_by_user_id' => $actor->id,
            ])->save();

            $this->audit->record(
                $active ? 'clinical_service_catalogue.activated' : 'clinical_service_catalogue.deactivated',
                $locked,
                [
                    'service_public_id' => $locked->public_id,
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
            throw new AuthorizationException('You may not manage the Clinical Service Catalogue.');
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

    private function lockService(User $actor, ClinicalServiceCatalogueItem $service): ClinicalServiceCatalogueItem
    {
        return ClinicalServiceCatalogueItem::query()
            ->whereKey($service->id)
            ->where('organisation_id', $actor->organisation_id)
            ->lockForUpdate()
            ->firstOr(function (): never {
                throw new ModelNotFoundException;
            });
    }

    private function assertUniqueCode(int $organisationId, string $code, ?int $exceptId = null): void
    {
        $query = ClinicalServiceCatalogueItem::query()
            ->where('organisation_id', $organisationId)
            ->whereRaw('LOWER(code) = ?', [Str::lower($code)]);
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'code' => 'A Clinical Service with this code already exists in the organisation.',
            ]);
        }
    }

    private function assertIdentityEditable(ClinicalServiceCatalogueItem $service, string $field): void
    {
        foreach ([
            'treatment_plan_service_orders',
            'charge_definitions',
        ] as $table) {
            if (DB::table($table)
                ->where('organisation_id', $service->organisation_id)
                ->where('clinical_service_catalogue_item_id', $service->id)
                ->exists()) {
                throw ValidationException::withMessages([
                    $field => $field === 'code'
                        ? 'The Clinical Service code cannot change after the service has operational or pricing dependents.'
                        : 'The Clinical Service order unit cannot change after the service has operational or pricing dependents.',
                ]);
            }
        }
    }

    /** @param array<string, mixed> $attributes
     * @return array{code:string,display_name:string,order_unit:string,category:?string}
     */
    private function createValues(array $attributes): array
    {
        return [
            'code' => $this->code($attributes['code'] ?? null),
            'display_name' => $this->requiredText($attributes['display_name'] ?? null, 'display_name', 500, collapseWhitespace: true),
            'order_unit' => $this->requiredText($attributes['order_unit'] ?? null, 'order_unit', 100),
            'category' => $this->nullableText($attributes['category'] ?? null, 'category', 120),
        ];
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, string|null>
     */
    private function updateValues(array $attributes): array
    {
        $values = [];
        foreach (['code', 'display_name', 'order_unit', 'category'] as $field) {
            if (! array_key_exists($field, $attributes)) {
                continue;
            }
            $values[$field] = match ($field) {
                'code' => $this->code($attributes[$field]),
                'display_name' => $this->requiredText($attributes[$field], $field, 500, collapseWhitespace: true),
                'order_unit' => $this->requiredText($attributes[$field], $field, 100),
                'category' => $this->nullableText($attributes[$field], $field, 120),
            };
        }

        return $values;
    }

    private function code(mixed $value): string
    {
        return Str::upper($this->requiredText($value, 'code', 64));
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
}

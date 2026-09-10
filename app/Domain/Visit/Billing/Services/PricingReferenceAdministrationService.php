<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Models\ClinicalServiceCatalogueItem;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PricingReferenceAdministrationService
{
    public const PERMISSION = 'pricing.references.manage.organisation';

    public function __construct(private AuditRecorder $audit) {}

    /** @param array<string, mixed> $attributes */
    public function createConsultationCharge(User $actor, array $attributes): ChargeDefinition
    {
        return $this->createCharge($actor, 'consultation', null, $attributes);
    }

    /** @param array<string, mixed> $attributes */
    public function createMedicineCharge(User $actor, MedicineCatalogueItem $medicine, array $attributes): ChargeDefinition
    {
        return $this->createCharge($actor, 'medicine', $medicine, $attributes);
    }

    /** @param array<string, mixed> $attributes */
    public function createServiceCharge(User $actor, ClinicalServiceCatalogueItem $service, array $attributes): ChargeDefinition
    {
        return $this->createCharge($actor, 'service', $service, $attributes);
    }

    /** @param array<string, mixed> $attributes */
    public function updateCharge(User $actor, ChargeDefinition $charge, array $attributes): ChargeDefinition
    {
        $this->authorize($actor);
        $this->assertOnly($attributes, ['display_name']);
        $displayName = array_key_exists('display_name', $attributes)
            ? $this->text($attributes['display_name'], 'display_name', 500, true)
            : null;

        return DB::transaction(function () use ($actor, $charge, $attributes, $displayName): ChargeDefinition {
            $this->lockOrganisation($actor);
            $locked = $this->lockOwned($actor, $charge);
            if (! array_key_exists('display_name', $attributes) || $locked->display_name === $displayName) {
                return $locked;
            }

            $locked->forceFill(['display_name' => $displayName])->save();
            $this->record('charge_definition.updated', $locked, $actor, ['display_name'], [
                'charge_type' => $locked->type,
            ]);

            return $locked;
        }, 3);
    }

    public function activateCharge(User $actor, ChargeDefinition $charge): ChargeDefinition
    {
        return $this->setChargeActive($actor, $charge, true);
    }

    public function deactivateCharge(User $actor, ChargeDefinition $charge): ChargeDefinition
    {
        return $this->setChargeActive($actor, $charge, false);
    }

    /** @param array<string, mixed> $attributes */
    public function createPriceBook(User $actor, ?Branch $branch, array $attributes): PriceBook
    {
        $this->authorize($actor);
        $this->assertOnly($attributes, ['name', 'currency']);
        $name = $this->text($attributes['name'] ?? null, 'name', 150, true);
        $currency = $this->currency($attributes['currency'] ?? 'MYR');

        return DB::transaction(function () use ($actor, $branch, $name, $currency): PriceBook {
            $this->lockOrganisation($actor);
            $lockedBranch = $branch === null ? null : $this->lockBranch($actor, $branch);
            $scopeKey = $lockedBranch === null ? 'organisation' : 'branch:'.$lockedBranch->id;
            if (PriceBook::query()->where('organisation_id', $actor->organisation_id)->where('scope_key', $scopeKey)->exists()) {
                $this->invalid('scope', 'A Price Book already exists for this scope. Reactivate or update the retained book instead.');
            }

            $book = new PriceBook;
            $book->forceFill([
                'public_id' => (string) Str::uuid(),
                'organisation_id' => $actor->organisation_id,
                'branch_id' => $lockedBranch?->id,
                'scope_key' => $scopeKey,
                'name' => $name,
                'currency' => $currency,
                'is_active' => true,
            ])->save();
            $this->record('price_book.created', $book, $actor, [
                'branch_id', 'scope_key', 'name', 'currency', 'is_active',
            ], ['scope' => $scopeKey, 'currency' => $currency]);

            return $book;
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function updatePriceBook(User $actor, PriceBook $book, array $attributes): PriceBook
    {
        $this->authorize($actor);
        $this->assertOnly($attributes, ['name']);
        $name = array_key_exists('name', $attributes)
            ? $this->text($attributes['name'], 'name', 150, true)
            : null;

        return DB::transaction(function () use ($actor, $book, $attributes, $name): PriceBook {
            $this->lockOrganisation($actor);
            $locked = $this->lockOwned($actor, $book);
            if (! array_key_exists('name', $attributes) || $locked->name === $name) {
                return $locked;
            }

            $locked->forceFill(['name' => $name])->save();
            $this->record('price_book.updated', $locked, $actor, ['name'], [
                'scope' => $locked->scope_key,
                'currency' => $locked->currency,
            ]);

            return $locked;
        }, 3);
    }

    public function activatePriceBook(User $actor, PriceBook $book): PriceBook
    {
        return $this->setPriceBookActive($actor, $book, true);
    }

    public function deactivatePriceBook(User $actor, PriceBook $book): PriceBook
    {
        return $this->setPriceBookActive($actor, $book, false);
    }

    /** @param array<string, mixed> $attributes */
    private function createCharge(
        User $actor,
        string $type,
        MedicineCatalogueItem|ClinicalServiceCatalogueItem|null $source,
        array $attributes,
    ): ChargeDefinition {
        $this->authorize($actor);
        $this->assertOnly($attributes, ['code', 'display_name']);
        $code = $this->code($attributes['code'] ?? null);
        $displayName = $this->text($attributes['display_name'] ?? null, 'display_name', 500, true);

        return DB::transaction(function () use ($actor, $type, $source, $code, $displayName): ChargeDefinition {
            $this->lockOrganisation($actor);
            $lockedSource = $source === null ? null : $this->lockOwned($actor, $source);
            if ($lockedSource !== null && ! $lockedSource->is_active) {
                $this->invalid('source', 'Select an active catalogue source.');
            }

            [$sourceKey, $unit, $medicineId, $serviceId, $sourcePublicId] = match ($type) {
                'consultation' => ['consultation', 'consultation', null, null, null],
                'medicine' => [
                    'medicine:'.$lockedSource->id,
                    $lockedSource->order_unit,
                    $lockedSource->id,
                    null,
                    $lockedSource->public_id,
                ],
                'service' => [
                    'service:'.$lockedSource->id,
                    $lockedSource->order_unit,
                    null,
                    $lockedSource->id,
                    $lockedSource->public_id,
                ],
                default => throw new \LogicException('Unsupported charge type.'),
            };

            $this->assertUniqueCharge($actor->organisation_id, $sourceKey, $code);
            $charge = new ChargeDefinition;
            $charge->forceFill([
                'public_id' => (string) Str::uuid(),
                'organisation_id' => $actor->organisation_id,
                'code' => $code,
                'type' => $type,
                'display_name' => $displayName,
                'unit' => $unit,
                'medicine_catalogue_item_id' => $medicineId,
                'clinical_service_catalogue_item_id' => $serviceId,
                'source_key' => $sourceKey,
                'is_active' => true,
            ])->save();
            $this->record('charge_definition.created', $charge, $actor, [
                'code', 'type', 'display_name', 'unit', 'source_key', 'is_active',
            ], [
                'charge_type' => $type,
                'source_public_id' => $sourcePublicId,
            ]);

            return $charge;
        }, 3);
    }

    private function setChargeActive(User $actor, ChargeDefinition $charge, bool $active): ChargeDefinition
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $charge, $active): ChargeDefinition {
            $this->lockOrganisation($actor);
            $locked = $this->lockOwned($actor, $charge);
            if ($locked->is_active === $active) {
                return $locked;
            }
            if ($active) {
                $this->assertChargeSourceStillValid($actor, $locked);
            }

            $locked->forceFill(['is_active' => $active])->save();
            $this->record(
                $active ? 'charge_definition.activated' : 'charge_definition.deactivated',
                $locked,
                $actor,
                ['is_active'],
                ['charge_type' => $locked->type],
            );

            return $locked;
        }, 3);
    }

    private function setPriceBookActive(User $actor, PriceBook $book, bool $active): PriceBook
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $book, $active): PriceBook {
            $this->lockOrganisation($actor);
            $locked = $this->lockOwned($actor, $book);
            if ($locked->is_active === $active) {
                return $locked;
            }
            if ($active && $locked->branch_id !== null) {
                Branch::query()
                    ->whereKey($locked->branch_id)
                    ->where('organisation_id', $actor->organisation_id)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->firstOr(function (): never {
                        throw new ModelNotFoundException;
                    });
            }

            $locked->forceFill(['is_active' => $active])->save();
            $this->record(
                $active ? 'price_book.activated' : 'price_book.deactivated',
                $locked,
                $actor,
                ['is_active'],
                ['scope' => $locked->scope_key, 'currency' => $locked->currency],
            );

            return $locked;
        }, 3);
    }

    private function assertChargeSourceStillValid(User $actor, ChargeDefinition $charge): void
    {
        if ($charge->type === 'consultation') {
            return;
        }

        $source = $charge->type === 'medicine'
            ? MedicineCatalogueItem::query()->whereKey($charge->medicine_catalogue_item_id)
            : ClinicalServiceCatalogueItem::query()->whereKey($charge->clinical_service_catalogue_item_id);
        $source = $source->where('organisation_id', $actor->organisation_id)->where('is_active', true)->lockForUpdate()->first();
        if (! $source || $source->order_unit !== $charge->unit) {
            $this->invalid('source', 'The active catalogue source and pricing unit must still match this Charge Definition.');
        }
    }

    private function assertUniqueCharge(int $organisationId, string $sourceKey, string $code): void
    {
        if (ChargeDefinition::query()->where('organisation_id', $organisationId)->where('source_key', $sourceKey)->exists()) {
            $this->invalid('source', 'A Charge Definition already exists for this source. Reactivate or update the retained definition instead.');
        }
        if (ChargeDefinition::query()->where('organisation_id', $organisationId)->whereRaw('LOWER(code) = ?', [Str::lower($code)])->exists()) {
            $this->invalid('code', 'A Charge Definition with this code already exists in the organisation.');
        }
    }

    private function authorize(User $actor): void
    {
        Gate::forUser($actor)->authorize(self::PERMISSION);
        if (! $actor->is_active || ! $actor->organisation_id) {
            throw new AuthorizationException('You may not manage pricing references.');
        }
    }

    private function lockOrganisation(User $actor): Organisation
    {
        return Organisation::query()
            ->whereKey($actor->organisation_id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->firstOr(function (): never {
                throw new ModelNotFoundException;
            });
    }

    private function lockBranch(User $actor, Branch $branch): Branch
    {
        return Branch::query()
            ->whereKey($branch->id)
            ->where('organisation_id', $actor->organisation_id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->firstOr(function (): never {
                throw new ModelNotFoundException;
            });
    }

    /** @template TModel of Model
     * @param  TModel  $model
     * @return TModel
     */
    private function lockOwned(User $actor, Model $model): Model
    {
        $owned = $model->newQuery()
            ->whereKey($model->getKey())
            ->where('organisation_id', $actor->organisation_id)
            ->lockForUpdate()
            ->first();
        if (! $owned instanceof $model) {
            throw new ModelNotFoundException;
        }

        return $owned;
    }

    /** @param array<string, mixed> $attributes
     * @param  list<string>  $allowed
     */
    private function assertOnly(array $attributes, array $allowed): void
    {
        $unsupported = array_diff(array_keys($attributes), $allowed);
        if ($unsupported !== []) {
            $this->invalid((string) reset($unsupported), 'This pricing reference field is not administratively editable.');
        }
    }

    private function code(mixed $value): string
    {
        return Str::upper($this->text($value, 'code', 64));
    }

    private function currency(mixed $value): string
    {
        if (! is_string($value) || Str::upper(trim($value)) !== 'MYR') {
            $this->invalid('currency', 'Pricing currency must be MYR.');
        }

        return 'MYR';
    }

    private function text(mixed $value, string $field, int $maximum, bool $collapseWhitespace = false): string
    {
        if (! is_string($value)) {
            $this->invalid($field, "Enter a valid {$field}.");
        }
        $value = trim($value);
        if ($collapseWhitespace) {
            $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        }
        if ($value === '' || mb_strlen($value) > $maximum || preg_match('/[\p{C}]/u', $value) === 1) {
            $this->invalid($field, "Enter a readable {$field} of {$maximum} characters or fewer.");
        }

        return $value;
    }

    /** @param list<string> $changedFields
     * @param  array<string, mixed>  $metadata
     */
    private function record(
        string $event,
        ChargeDefinition|PriceBook $subject,
        User $actor,
        array $changedFields,
        array $metadata = [],
    ): void {
        $this->audit->record($event, $subject, [
            'reference_public_id' => $subject->public_id,
            'changed_fields' => $changedFields,
            ...$metadata,
        ], $actor, organisationId: $actor->organisation_id);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

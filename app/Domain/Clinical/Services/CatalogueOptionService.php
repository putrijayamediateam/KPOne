<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Models\CatalogueOption;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CatalogueOptionService
{
    /** @var array<string, string> */
    private const OPTION_PERMISSIONS = [
        'medicine_category' => 'medicines.manage.organisation',
        'medicine_group' => 'medicines.manage.organisation',
        'dosage_form' => 'medicines.manage.organisation',
        'dosage_unit' => 'medicines.manage.organisation',
        'instruction' => 'medicines.manage.organisation',
        'precaution' => 'medicines.manage.organisation',
        'frequency' => 'medicines.manage.organisation',
        'duration' => 'medicines.manage.organisation',
        'indication' => 'medicines.manage.organisation',
        'order_unit' => 'medicines.manage.organisation',
        'route' => 'medicines.manage.organisation',
        'manufacturer' => 'medicines.manage.organisation',
        'service_category' => 'clinical_services.manage.organisation',
        'service_unit' => 'clinical_services.manage.organisation',
    ];

    public function __construct(private readonly AuditRecorder $audit) {}

    /** @return list<array{value:string,label:string}> */
    public function search(User $actor, string $type, ?string $query = null): array
    {
        $this->authorize($actor, $type);
        $builder = CatalogueOption::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('option_type', $type)
            ->where('is_active', true);
        if (filled($query)) {
            $needle = '%'.mb_strtolower(trim((string) $query)).'%';
            $builder->whereRaw('LOWER(label) LIKE ?', [$needle]);
        }

        return array_values($builder->orderBy('label')->limit(50)->get()
            ->map(fn (CatalogueOption $option): array => [
                'value' => $option->label,
                'label' => $option->label,
            ])->all());
    }

    public function create(User $actor, string $type, string $label): CatalogueOption
    {
        $this->authorize($actor, $type);
        $display = trim(preg_replace('/\s+/u', ' ', $label) ?? '');
        if ($display === '' || mb_strlen($display) > 200) {
            throw ValidationException::withMessages(['label' => 'Enter an option up to 200 characters.']);
        }
        $normalized = mb_strtolower($display);

        return DB::transaction(function () use ($actor, $type, $display, $normalized): CatalogueOption {
            Organisation::query()->whereKey($actor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
            $existing = CatalogueOption::query()
                ->where('organisation_id', $actor->organisation_id)
                ->where('option_type', $type)
                ->where('normalized_label', $normalized)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if (! $existing->is_active) {
                    throw ValidationException::withMessages(['label' => 'This option is inactive. Ask an administrator to reactivate it before reusing it.']);
                }

                return $existing;
            }
            $option = new CatalogueOption;
            $option->forceFill([
                'organisation_id' => $actor->organisation_id,
                'option_type' => $type,
                'label' => $display,
                'normalized_label' => $normalized,
                'is_active' => true,
                'created_by_user_id' => $actor->id,
            ])->save();
            $this->audit->record('catalogue_option.created', $option, [
                'option_type' => $type,
                'label' => $display,
            ], $actor, organisationId: $actor->organisation_id);

            return $option;
        }, 3);
    }

    private function authorize(User $actor, string $type): void
    {
        $permission = self::OPTION_PERMISSIONS[$type] ?? null;
        if ($permission === null) {
            throw ValidationException::withMessages(['type' => 'Select a supported catalogue option type.']);
        }
        Gate::forUser($actor)->authorize($permission);
        if (! $actor->is_active || ! $actor->organisation_id) {
            throw new AuthorizationException('You may not manage this catalogue option.');
        }
    }
}

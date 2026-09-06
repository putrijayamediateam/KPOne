<?php

namespace App\Domain\Visit\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Models\VisitReason;
use App\Domain\Visit\Models\VisitReasonAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VisitReasonService
{
    public function __construct(
        private AuditRecorder $audit,
        private BranchAccessService $branches,
    ) {}

    /** @return list<array{publicId:string,name:string}> */
    public function search(User $actor, string $query): array
    {
        Gate::forUser($actor)->authorize('create', Visit::class);
        $normalized = $this->normalize($query, allowEmpty: true);

        return array_values(VisitReason::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('is_active', true)
            ->when($normalized !== '', fn ($builder) => $builder->where('normalized_name', 'like', '%'.$this->escapeLike($normalized).'%'))
            ->orderBy('name')->limit(20)->get(['public_id', 'name'])
            ->map(fn (VisitReason $reason): array => ['publicId' => $reason->public_id, 'name' => $reason->name])
            ->all());
    }

    public function create(User $actor, string $name): VisitReason
    {
        Gate::forUser($actor)->authorize('create', Visit::class);
        $branch = $this->branches->activeBranch($actor);
        if (! $branch) {
            throw new AuthorizationException('You may not add Visit Reasons.');
        }
        $display = $this->displayName($name);
        $normalized = $this->normalize($display);

        try {
            return DB::transaction(function () use ($actor, $branch, $display, $normalized): VisitReason {
                $lockedActor = $this->lockActor($actor, $branch);

                $existing = VisitReason::query()->where('organisation_id', $actor->organisation_id)
                    ->where('normalized_name', $normalized)->lockForUpdate()->first();
                if ($existing) {
                    return $existing;
                }

                $reason = new VisitReason;
                $reason->forceFill([
                    'public_id' => (string) Str::uuid(),
                    'organisation_id' => $actor->organisation_id,
                    'name' => $display,
                    'normalized_name' => $normalized,
                    'is_active' => true,
                    'created_by_user_id' => $actor->id,
                ])->save();
                $this->audit->record('visit_reason.created', $reason, ['active' => true], $lockedActor, organisationId: $actor->organisation_id);

                return $reason;
            }, 3);
        } catch (QueryException $exception) {
            if (! in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw $exception;
            }

            return VisitReason::query()->where('organisation_id', $actor->organisation_id)
                ->where('normalized_name', $normalized)->firstOrFail();
        }
    }

    /**
     * @return Collection<int, VisitReason>
     */
    public function resolve(User $actor, mixed $publicIds, bool $required, ?Visit $retainingFor = null): Collection
    {
        if (! is_array($publicIds)) {
            $publicIds = [];
        }
        if ($required && $publicIds === []) {
            throw ValidationException::withMessages(['visit_reason_public_ids' => 'Select at least one Visit Reason.']);
        }
        if (count($publicIds) > 5) {
            throw ValidationException::withMessages(['visit_reason_public_ids' => 'A Visit can have up to 5 reasons.']);
        }
        $ids = [];
        foreach ($publicIds as $publicId) {
            if (! is_string($publicId) || ! Str::isUuid($publicId) || in_array($publicId, $ids, true)) {
                throw ValidationException::withMessages(['visit_reason_public_ids' => 'Select distinct valid Visit Reasons.']);
            }
            $ids[] = $publicId;
        }
        if ($ids === []) {
            return collect();
        }

        $reasons = VisitReason::query()->where('organisation_id', $actor->organisation_id)
            ->where(function ($query) use ($retainingFor): void {
                $query->where('is_active', true);
                if ($retainingFor) {
                    $query->orWhereHas('assignments', fn ($assignments) => $assignments
                        ->where('visit_id', $retainingFor->id));
                }
            })
            ->whereIn('public_id', $ids)->lockForUpdate()->get()->keyBy('public_id');
        if ($reasons->count() !== count($ids)) {
            throw ValidationException::withMessages(['visit_reason_public_ids' => 'One or more Visit Reasons are unavailable.']);
        }

        return collect($ids)->map(fn (string $id): VisitReason => $reasons->get($id));
    }

    /** @param Collection<int, VisitReason> $reasons */
    public function assign(Visit $visit, Collection $reasons): void
    {
        foreach ($reasons->values() as $index => $reason) {
            $assignment = new VisitReasonAssignment;
            $assignment->forceFill([
                'organisation_id' => $visit->organisation_id,
                'branch_id' => $visit->branch_id,
                'visit_id' => $visit->id,
                'visit_reason_catalogue_item_id' => $reason->id,
                'label_snapshot' => $reason->name,
                'position' => $index + 1,
            ])->save();
        }
    }

    /** @param Collection<int, VisitReason> $reasons */
    public function replace(Visit $visit, Collection $reasons): ?string
    {
        $existingSnapshots = VisitReasonAssignment::query()
            ->where('visit_id', $visit->id)
            ->get(['visit_reason_catalogue_item_id', 'label_snapshot'])
            ->mapWithKeys(fn (VisitReasonAssignment $assignment): array => [
                (int) $assignment->visit_reason_catalogue_item_id => $assignment->label_snapshot,
            ])->all();
        VisitReasonAssignment::query()->where('visit_id', $visit->id)->delete();
        $primarySnapshot = null;
        foreach ($reasons->values() as $index => $reason) {
            $snapshot = $existingSnapshots[$reason->id] ?? $reason->name;
            $assignment = new VisitReasonAssignment;
            $assignment->forceFill([
                'organisation_id' => $visit->organisation_id,
                'branch_id' => $visit->branch_id,
                'visit_id' => $visit->id,
                'visit_reason_catalogue_item_id' => $reason->id,
                'label_snapshot' => $snapshot,
                'position' => $index + 1,
            ])->save();
            $primarySnapshot ??= $snapshot;
        }

        return $primarySnapshot;
    }

    /** @return array{primary:?string,additional:list<string>,legacy:?string,structured:list<array{publicId:string,label:string,position:int}>} */
    public function presentation(Visit $visit): array
    {
        $visit->loadMissing('reasonAssignments');
        $structured = [];
        foreach ($visit->reasonAssignments->sortBy('position') as $item) {
            $structured[] = [
                'publicId' => $item->reason->public_id,
                'label' => $item->label_snapshot,
                'position' => (int) $item->position,
            ];
        }

        return [
            'primary' => $structured[0]['label'] ?? null,
            'additional' => array_map(fn (array $item): string => $item['label'], array_slice($structured, 1)),
            'legacy' => $structured === [] ? $visit->visit_reason : null,
            'structured' => $structured,
        ];
    }

    public function summary(Visit $visit): ?string
    {
        $presentation = $this->presentation($visit);
        if ($presentation['primary'] === null) {
            return $presentation['legacy'];
        }

        return collect([$presentation['primary'], ...$presentation['additional']])->join(' · ');
    }

    private function displayName(string $name): string
    {
        $display = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
        if ($display === '' || mb_strlen($display) > 120 || preg_match('/[\p{C}]/u', $display) === 1) {
            throw ValidationException::withMessages(['name' => 'Enter a readable Visit Reason of 120 characters or fewer.']);
        }

        return $display;
    }

    private function normalize(string $name, bool $allowEmpty = false): string
    {
        $display = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
        if (! $allowEmpty && $display === '') {
            throw ValidationException::withMessages(['name' => 'Enter a Visit Reason.']);
        }

        return Str::lower($display);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function lockActor(User $actor, Branch $branch): User
    {
        $locked = User::query()->whereKey($actor->id)
            ->where('organisation_id', $branch->organisation_id)
            ->lockForUpdate()->firstOrFail();
        $profile = StaffProfile::query()->where('user_id', $locked->id)->lockForUpdate()->first();
        if ($profile) {
            StaffBranchAssignment::query()->where('staff_profile_id', $profile->id)
                ->orderBy('id')->lockForUpdate()->get();
        }
        $roleIds = DB::table('model_has_roles')->where('model_id', $locked->id)
            ->where('model_type', $locked->getMorphClass())->orderBy('role_id')->lockForUpdate()->pluck('role_id');
        DB::table('roles')->whereIn('id', $roleIds)->orderBy('id')->lockForUpdate()->get();
        DB::table('role_has_permissions')->whereIn('role_id', $roleIds)
            ->orderBy('role_id')->orderBy('permission_id')->lockForUpdate()->get();
        DB::table('model_has_permissions')->where('model_id', $locked->id)
            ->where('model_type', $locked->getMorphClass())->orderBy('permission_id')->lockForUpdate()->get();
        $locked->load(['roles.permissions', 'permissions']);
        if (! $locked->is_active || ! $locked->can('visits.create.branch') || ! $this->branches->canSelect($locked, $branch)) {
            throw new AuthorizationException('You may not add Visit Reasons.');
        }

        return $locked;
    }
}

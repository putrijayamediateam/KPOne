<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Models\DiagnosisPreset;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class EncounterDiagnosisPresetDirectoryService
{
    public function __construct(private TransactionalActorAuthority $authority) {}

    /** @return list<array{id:int,name:string}> */
    public function search(User $actor, Visit $visit, int $expectedBranch, string $query): array
    {
        $branch = $this->authority->branch($actor, $expectedBranch);

        return DB::transaction(function () use ($actor, $visit, $branch, $query): array {
            $actor = $this->authority->lock($actor, $branch, 'encounters.update.own');
            $visit = Visit::query()->whereKey($visit->id)->where('organisation_id', $actor->organisation_id)
                ->where('branch_id', $branch->id)->lockForUpdate()->firstOrFail();
            $queue = QueueEntry::query()->where('visit_id', $visit->id)->lockForUpdate()->firstOrFail();
            $encounter = ClinicalEncounter::query()->where('visit_id', $visit->id)->where('attending_clinician_user_id', $actor->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('view', $encounter);
            Gate::forUser($actor)->authorize('update', $encounter);
            abort_unless($visit->assigned_doctor_user_id === $actor->id && $visit->visit_type === 'consultation'
                && $visit->status === Visit::STATUS_REGISTERED && $queue->status === QueueEntry::STATUS_SERVING
                && $encounter->status === ClinicalEncounter::STATUS_IN_PROGRESS
                && ! $encounter->holds()->whereNull('resumed_at')->exists(), 403);
            $presets = DiagnosisPreset::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true);
            if ($query !== '') {
                $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($query)).'%';
                $presets->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$needle]);
            }

            return array_values($presets->orderBy('name')->orderBy('id')->limit(50)->get(['id', 'name'])
                ->map(fn (DiagnosisPreset $preset): array => ['id' => $preset->id, 'name' => $preset->name])->all());
        });
    }
}

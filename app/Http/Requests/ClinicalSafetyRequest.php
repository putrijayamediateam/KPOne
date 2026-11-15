<?php

namespace App\Http\Requests;

use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

abstract class ClinicalSafetyRequest extends FormRequest
{
    protected function authorizeCurrentCare(string $permission, string $ability): bool
    {
        $actor = $this->user();
        $visit = $this->route('visit');
        if (! $actor || ! $visit instanceof Visit || ! $actor->can($permission)) {
            return false;
        }

        $encounter = ClinicalEncounter::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('branch_id', $visit->branch_id)
            ->where('visit_id', $visit->id)
            ->where('attending_clinician_user_id', $actor->id)
            ->first();

        return $encounter !== null
            && Gate::forUser($actor)->allows($ability, $encounter)
            && $encounter->status === ClinicalEncounter::STATUS_IN_PROGRESS
            && $visit->organisation_id === $actor->organisation_id
            && $visit->status === Visit::STATUS_REGISTERED
            && $visit->visit_type === 'consultation'
            && $visit->assigned_doctor_user_id === $actor->id
            && $visit->queueEntry?->status === QueueEntry::STATUS_SERVING;
    }

    protected function failedAuthorization(): void
    {
        abort(404);
    }
}

<?php

namespace App\Domain\Patient\Services;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Models\Visit;
use App\Models\User;

final class VisitWhatsAppEligibility
{
    // The patient relation is batch eager-loaded by each board. Never query per row.
    public static function allows(User $actor, Visit $visit, Branch $branch): bool
    {
        return $actor->is_active
            && $actor->can('patients.view.organisation')
            && $actor->organisation_id === $branch->organisation_id
            && $visit->organisation_id === $actor->organisation_id
            && $visit->branch_id === $branch->id
            && $visit->status !== Visit::STATUS_CANCELLED
            && $visit->patient->organisation_id === $actor->organisation_id
            && WhatsAppNumber::normalize($visit->patient->mobile_phone) !== null;
    }
}

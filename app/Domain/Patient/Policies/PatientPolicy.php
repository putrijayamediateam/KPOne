<?php

namespace App\Domain\Patient\Policies;

use App\Domain\Patient\Models\Patient;
use App\Models\User;

class PatientPolicy
{
    public function search(User $actor): bool
    {
        return $actor->can('patients.search.organisation');
    }

    public function viewAny(User $actor): bool
    {
        return $this->search($actor);
    }

    public function view(User $actor, Patient $patient): bool
    {
        return $actor->organisation_id === $patient->organisation_id
            && $actor->can('patients.view.organisation');
    }

    public function create(User $actor): bool
    {
        return $actor->can('patients.create.organisation');
    }

    public function update(User $actor, Patient $patient): bool
    {
        return $actor->organisation_id === $patient->organisation_id
            && $actor->can('patients.update.organisation');
    }

    public function manageIdentifiers(User $actor, Patient $patient): bool
    {
        return $actor->organisation_id === $patient->organisation_id
            && $actor->can('patients.identifiers.manage.organisation');
    }
}

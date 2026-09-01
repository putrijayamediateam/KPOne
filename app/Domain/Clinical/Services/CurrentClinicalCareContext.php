<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Models\Patient;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;

final readonly class CurrentClinicalCareContext
{
    public function __construct(
        public User $actor,
        public Branch $branch,
        public Patient $patient,
        public Visit $visit,
        public QueueEntry $queue,
        public ClinicalEncounter $encounter,
    ) {}
}

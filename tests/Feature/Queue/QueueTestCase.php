<?php

namespace Tests\Feature\Queue;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Tests\Feature\Visit\VisitTestCase;

abstract class QueueTestCase extends VisitTestCase
{
    protected function doctor(?Branch $branch = null): User
    {
        return $this->actor('resident_doctor', $branch ?? $this->branch);
    }

    protected function consultationVisit(
        User $actor,
        User $doctor,
        array $overrides = [],
    ): Visit {
        $patient = $this->patient($actor);

        return $this->register($actor, $patient, [
            'visit_type' => 'consultation',
            'assigned_doctor_user_id' => $doctor->id,
            'visit_reason' => 'Synthetic Queue reason',
            ...$overrides,
        ]);
    }

    protected function send(User $actor, Visit $visit, array $overrides = []): QueueEntry
    {
        $this->selectBranch($actor, $visit->branch);

        return app(QueueEntryService::class)->enter($actor, $visit, [
            'expected_branch_id' => $visit->branch_id,
            'visit_lock_version' => $visit->lock_version,
            ...$overrides,
        ]);
    }
}

<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\AuditRecorder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class StaffAdministrationService
{
    public function __construct(private AuditRecorder $audit) {}

    public function setActive(User $subject, bool $active, ?User $actor = null): User
    {
        if ($subject->is_active === $active) {
            return $subject;
        }

        return DB::transaction(function () use ($subject, $active, $actor) {
            $before = $subject->is_active;
            $subject->forceFill([
                'is_active' => $active,
                'deactivated_at' => $active ? null : now(),
            ])->save();

            $this->audit->record(
                $active ? 'staff.activated' : 'staff.deactivated',
                $subject,
                ['before' => ['is_active' => $before], 'after' => ['is_active' => $active]],
                $actor,
            );

            return $subject->refresh();
        });
    }
}

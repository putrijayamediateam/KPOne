<?php

namespace Tests\Support;

use App\Domain\Visit\Models\Visit;
use App\Models\User;

/**
 * Test fixture: marks a visit completed as a synthetic initial state. The completed state carries the
 * terminal fields PostgreSQL requires (visits_terminal_fields_check); the deferred completion-evidence
 * trigger only fires on commit, which these rolled-back tests never do.
 */
final class SyntheticVisitCompletion
{
    public static function complete(Visit $visit): Visit
    {
        $userId = (int) User::query()->where('organisation_id', $visit->organisation_id)->orderBy('id')->value('id');
        $visit->forceFill([
            'status' => Visit::STATUS_COMPLETED, 'completed_at' => now()->utc(), 'completed_by_user_id' => $userId,
            'completion_evidence' => ['synthetic' => true],
        ])->save();

        return $visit->refresh();
    }
}

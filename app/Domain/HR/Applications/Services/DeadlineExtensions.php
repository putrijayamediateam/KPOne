<?php

namespace App\Domain\HR\Applications\Services;

use App\Domain\HR\Applications\ApplicationPermissions;
use App\Domain\HR\Records\Services\RecordSupport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR-17c follow-up (b) (owner, 2026-11-11, "go with your suggestions for both"): HR gives an application that waits for
 * the daily auto-reject 3 more working days (the auto-reject setting's number). The count restarts from the press: the
 * reminder moves to the third working day after today and the closing to the fourth. Waiting means New or Phone
 * screening, or Scheduling while the current round has no picks. Each press is one append-only event and one audit row;
 * nothing is sent to the candidate (their private page shows the new "Choose by" date at Scheduling). A reminder already
 * sent for this stage or round is not sent again.
 */
final class DeadlineExtensions
{
    public function __construct(private RecordSupport $support, private HiringDeadlines $deadlines) {}

    /** Whether the application is waiting for the daily auto-reject, so HR may give it more time. */
    public static function waiting(\stdClass $application): bool
    {
        if ($application->purged_at !== null) {
            return false;
        }
        if (in_array($application->status, HiringDeadlines::WAITING, true)) {
            return true;
        }

        return $application->status === HiringDeadlines::AWAITING_PICKS
            && ! DB::table('hr_job_interview_picks')->where('application_id', $application->id)->where('round', $application->interview_round)->exists();
    }

    /** @param  array<string, mixed>  $input */
    public function extend(User $actor, string $applicationId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        DB::transaction(function () use ($actor, $applicationId, $input, $key): void {
            $actor = $this->support->lock($actor, ApplicationPermissions::MANAGE);
            $hash = $this->support->hash([$actor->id, 'job_deadline_extend', $applicationId]);
            if ($this->support->replay($actor, $key, 'job_deadline_extend', $hash) !== null) {
                return;
            }
            if (! Str::isUuid($applicationId)) {
                abort(404);
            }
            $application = DB::table('hr_job_applications')->where('organisation_id', $actor->organisation_id)->where('public_id', $applicationId)->lockForUpdate()->first();
            abort_if(! $application instanceof \stdClass, 404);
            $this->support->assertLock($application, $input);
            if (! self::waiting($application) || $this->deadlines->deadlines($application) === null) {
                throw ValidationException::withMessages(['extend' => 'This application is not waiting to close. Reload the page.']);
            }
            $now = now()->utc();
            DB::table('hr_job_applications')->where('id', $application->id)->update(['lock_version' => (int) $application->lock_version + 1, 'updated_at' => $now]);
            DB::table('hr_job_application_events')->insert(['organisation_id' => $actor->organisation_id, 'application_id' => $application->id,
                'action' => 'deadline_extended', 'from_status' => $application->status, 'to_status' => $application->status,
                'actor_user_id' => $actor->id, 'created_at' => $now]);
            $deadlines = $this->deadlines->deadlines($application);
            $this->support->remember($actor, $key, 'job_deadline_extend', $hash, $applicationId);
            $this->support->audit->record('hr.applications.deadline_extended', null, ['application_id' => (int) $application->id,
                'stage' => (string) $application->status, 'closes_on' => $deadlines['reject']], $actor, null, $actor->organisation_id);
        }, 3);
    }
}

<?php

namespace App\Domain\HR\Applications\Services;

use App\Domain\HR\Applications\ApplicationPermissions;
use App\Domain\HR\Records\RecordPermissions;
use App\Domain\HR\Records\Services\LocumSignIns;
use App\Domain\HR\Records\Services\RecordSupport;
use App\Domain\HR\Records\Services\StaffFileService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * HR-15d (owner, via B.E.R.N.I.S.S, 18 Nov 2026): a passed candidate for a Locum position becomes a locum in one step.
 * KPOne makes the HR-4a locum file from the application (name and phone) and opens the HR-15a sign-in request with the
 * email they applied with. The account itself is still created by whoever passes the staff-creation check, through
 * StaffProvisioningService, from the Staff page: nothing here creates a user, a role or a branch assignment, and an
 * unknown identity never becomes a user.
 *
 * Needs both hiring (applications) and staff-file (records) management, which today are the same two roles.
 */
final class LocumHires
{
    /** A fixed namespace for the two retry keys derived from this step's own key. */
    private const NAMESPACE = '8a6b1a52-6d1f-4c39-9f2b-6b2a8c0e1d15';

    public function __construct(
        private readonly RecordSupport $support,
        private readonly StaffFileService $files,
        private readonly LocumSignIns $signIns,
    ) {}

    /** @param array<string, mixed> $input expected_lock_version, idempotency_key */
    public function make(User $actor, string $applicationId, array $input): void
    {
        $key = $this->support->key($input['idempotency_key'] ?? null);
        abort_if(! Str::isUuid($applicationId), 404);

        DB::transaction(function () use ($actor, $applicationId, $input, $key): void {
            $actor = $this->support->lock($actor, ApplicationPermissions::MANAGE);
            if (! $actor->can(RecordPermissions::MANAGE)) {
                throw new AuthorizationException;
            }
            $hash = $this->support->hash([$actor->id, 'locum_hire', $applicationId]);
            if ($this->support->replay($actor, $key, 'locum_hire', $hash) !== null) {
                return;
            }
            $application = DB::table('hr_job_applications')->where('organisation_id', $actor->organisation_id)->where('public_id', $applicationId)->lockForUpdate()->first();
            abort_if(! $application instanceof \stdClass, 404);
            $this->support->assertLock($application, $input);
            $type = DB::table('hr_job_positions')->where('id', $application->position_id)->value('position_type');
            if ($type !== 'locum') {
                throw ValidationException::withMessages(['application' => 'Only a candidate for a Locum position becomes a locum file.']);
            }
            if ($application->status !== 'passed' || $application->purged_at !== null) {
                throw ValidationException::withMessages(['application' => 'A locum file can be made only for a candidate who passed.']);
            }
            if (DB::table('hr_locum_hires')->where('application_id', $application->id)->exists()) {
                throw ValidationException::withMessages(['application' => 'A locum file was already made for this candidate.']);
            }
            if (DB::table('hr_staff_account_requests')->where('application_id', $application->id)->where('status', 'open')->exists()) {
                throw ValidationException::withMessages(['application' => 'A staff account was already asked for. Cancel it first to make a locum file instead.']);
            }

            try {
                $name = Crypt::decryptString((string) $application->name_encrypted);
                $phone = Crypt::decryptString((string) $application->phone_encrypted);
                $email = Crypt::decryptString((string) $application->email_encrypted);
            } catch (DecryptException) {
                throw ValidationException::withMessages(['application' => 'This application cannot be read. Ask the technical team.']);
            }

            // The two steps keep their own checks, events and audit; their retry keys come from this one, so a retry of
            // this step never makes a second file or request.
            $worker = $this->files->addLocum($actor, ['display_name' => mb_substr(trim($name), 0, 120), 'phone' => $phone,
                'idempotency_key' => $this->derived($key, 'file')]);
            $this->signIns->request($actor, $worker->public_id, ['email' => $email, 'idempotency_key' => $this->derived($key, 'sign_in')]);
            $signInId = (int) DB::table('hr_locum_sign_ins')->where('worker_id', $worker->id)->where('status', 'open')->value('id');

            $now = now()->utc();
            DB::table('hr_locum_hires')->insert(['organisation_id' => $actor->organisation_id, 'application_id' => $application->id,
                'worker_id' => $worker->id, 'sign_in_request_id' => $signInId, 'made_by_user_id' => $actor->id, 'created_at' => $now]);
            DB::table('hr_job_application_events')->insert(['organisation_id' => $actor->organisation_id, 'application_id' => $application->id,
                'action' => 'locum_file_made', 'actor_user_id' => $actor->id, 'created_at' => $now]);
            $this->support->remember($actor, $key, 'locum_hire', $hash, $worker->public_id);
            $this->support->audit->record('hr.applications.locum_file_made', $worker, ['application_id' => (int) $application->id, 'sign_in_request_id' => $signInId],
                $actor, null, $actor->organisation_id);
        }, 3);
    }

    /**
     * For the application sheet: the locum file made from this application, if any, with its sign-in state.
     *
     * @return array{workerId: string, name: string, signIn: string}|null
     */
    public static function forApplication(int $applicationId): ?array
    {
        $row = DB::table('hr_locum_hires as h')->join('hr_workers as w', 'w.id', '=', 'h.worker_id')->join('hr_locum_sign_ins as s', 's.id', '=', 'h.sign_in_request_id')
            ->where('h.application_id', $applicationId)->first(['w.public_id', 'w.display_name', 's.status']);

        return $row === null ? null : ['workerId' => (string) $row->public_id, 'name' => (string) $row->display_name,
            'signIn' => match ((string) $row->status) {
                'open' => 'Sign-in asked for, waiting on the Staff page',
                'done' => 'Sign-in made',
                default => 'Sign-in request closed',
            }];
    }

    private function derived(string $key, string $part): string
    {
        return Uuid::uuid5(self::NAMESPACE, "{$key}:{$part}")->toString();
    }
}

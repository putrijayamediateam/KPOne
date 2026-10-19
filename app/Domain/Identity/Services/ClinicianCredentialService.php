<?php

namespace App\Domain\Identity\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\StaffAuthorityService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Identity\Models\ClinicianProfessionalProfile;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ClinicianCredentialService
{
    public const PERMISSION = 'clinician_credentials.manage.organisation';

    public function __construct(private TransactionalActorAuthority $authority, private StaffAuthorityService $staffAuthority, private AuditRecorder $audit) {}

    /** @return array<string, mixed> */
    public function page(User $actor, bool $own, int $page): array
    {
        $branch = $this->authority->branch($actor, app(BranchAccessService::class)->activeBranch($actor)?->id);

        return DB::transaction(function () use ($actor, $own, $page, $branch): array {
            $actor = $this->lockActor($actor, $branch, $own ? 'staff.view.own' : self::PERMISSION);
            $query = User::query()->where('organisation_id', $actor->organisation_id)->whereHas('staffProfile');
            if ($own) {
                $query->whereKey($actor->id);
            } else {
                $query->permission('encounters.start.own');
            }
            $users = $query->orderBy('id')->paginate(25, ['id', 'name', 'is_active'], 'page', $page);
            $profiles = ClinicianProfessionalProfile::query()->where('organisation_id', $actor->organisation_id)->whereIn('user_id', $users->pluck('id'))->get()->keyBy('user_id');

            return ['branchId' => $branch->id, 'canManage' => ! $own, 'clinicians' => $users->map(function (User $user) use ($profiles): array {
                $p = $profiles->get($user->id);

                return ['userId' => $user->id, 'accountName' => $user->name, 'active' => $user->is_active,
                    'professionalName' => $p->professional_name ?? '', 'mmcNumber' => $p->mmc_number ?? '',
                    'verified' => $p->verified ?? false, 'lockVersion' => $p->lock_version ?? 0];
            })->all(), 'pagination' => ['currentPage' => $users->currentPage(), 'lastPage' => $users->lastPage()]];
        });
    }

    /** @param array<string, mixed> $attributes */
    public function save(User $actor, int $subjectId, array $attributes): void
    {
        $data = Validator::make($attributes, [
            'expected_branch_id' => ['required', 'integer'], 'lock_version' => ['required', 'integer', 'min:0'],
            'idempotency_key' => ['required', 'uuid'], 'professional_name' => ['required', 'string', 'max:200', 'not_regex:/[<>\x00-\x1F\x7F]/u'],
            'mmc_number' => ['required', 'string', 'regex:/^[0-9]{1,30}$/D'], 'verified' => ['required', 'boolean'],
        ])->validate();
        $data['professional_name'] = trim($data['professional_name']);
        if ($data['professional_name'] === '') {
            throw ValidationException::withMessages(['professional_name' => 'Enter the registered professional name.']);
        }
        $branch = $this->authority->branch($actor, $data['expected_branch_id']);
        $hash = hash_hmac('sha256', json_encode([$subjectId, (int) $data['expected_branch_id'], (int) $data['lock_version'], $data['professional_name'], $data['mmc_number'], (bool) $data['verified']], JSON_THROW_ON_ERROR), (string) config('app.key'));
        DB::transaction(function () use ($actor, $subjectId, $data, $branch, $hash): void {
            $actor = $this->lockActor($actor, $branch, self::PERMISSION);
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$data['idempotency_key']]);
            $subject = User::query()->whereKey($subjectId)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
            $staff = StaffProfile::query()->where('user_id', $subject->id)->lockForUpdate()->first();
            $subject->load(['roles.permissions', 'permissions']);
            abort_unless($actor->isNot($subject) && $staff && $subject->is_active && $subject->getAllPermissions()->contains('name', 'encounters.start.own') && $this->staffAuthority->canManage($actor, $subject), 403);
            $command = DB::table('clinician_credential_commands')->where('idempotency_key', $data['idempotency_key'])->first();
            if ($command) {
                if ($command->organisation_id !== $actor->organisation_id || $command->actor_user_id !== $actor->id || ! hash_equals($command->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This retry key has already been used for another command.']);
                }

                return;
            }
            $profile = ClinicianProfessionalProfile::query()->where('user_id', $subjectId)->lockForUpdate()->first();
            if (($profile->lock_version ?? 0) !== (int) $data['lock_version']) {
                throw ValidationException::withMessages(['lock_version' => 'These credentials changed. Reload and review before saving.']);
            }
            // Serialize uniqueness checks within the organisation, including initial profile creation.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['clinician-credentials:'.$actor->organisation_id]);
            if (ClinicianProfessionalProfile::query()->where('organisation_id', $actor->organisation_id)->where('mmc_number', $data['mmc_number'])->where('user_id', '!=', $subjectId)->exists()) {
                throw ValidationException::withMessages(['mmc_number' => 'This MMC number is already assigned in this organisation.']);
            }
            $profile ??= new ClinicianProfessionalProfile;
            $profile->forceFill(['organisation_id' => $actor->organisation_id, 'user_id' => $subjectId, 'staff_profile_id' => $staff->id,
                'professional_name' => $data['professional_name'], 'mmc_number' => $data['mmc_number'], 'verified' => (bool) $data['verified']]);
            if ($profile->isDirty()) {
                $fields = array_values(array_intersect(['professional_name', 'mmc_number', 'verified'], array_keys($profile->getDirty())));
                $profile->forceFill(['verified_at' => $data['verified'] ? now()->utc() : null, 'verified_by_user_id' => $data['verified'] ? $actor->id : null,
                    'updated_by_user_id' => $actor->id, 'lock_version' => ($profile->lock_version ?? 0) + 1])->save();
                $this->audit->record('clinician_credentials.updated', $profile, ['fields' => $fields, 'record_version' => $profile->lock_version], $actor, $branch);
            }
            DB::table('clinician_credential_commands')->insert(['organisation_id' => $actor->organisation_id, 'actor_user_id' => $actor->id, 'subject_user_id' => $subjectId,
                'idempotency_key' => $data['idempotency_key'], 'payload_hash' => $hash, 'created_at' => now()->utc()->format('Y-m-d H:i:s.uP')]);
        }, 3);
    }

    private function lockActor(User $actor, Branch $branch, string $permission): User
    {
        $actor = $this->authority->lock($actor, $branch, $permission);
        // Use the freshly loaded grant rows, including after a same-request raw revocation.
        if (! $actor->getAllPermissions()->contains('name', $permission)) {
            throw new AuthorizationException('You may not perform this operation.');
        }

        return $actor;
    }
}

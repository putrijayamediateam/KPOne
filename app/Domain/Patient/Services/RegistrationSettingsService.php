<?php

namespace App\Domain\Patient\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Patient\Models\PublicIntakeSession;
use App\Domain\Patient\Models\RegistrationSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class RegistrationSettingsService
{
    public const PERMISSION = 'catalogue.settings.manage.organisation';

    public function __construct(private TransactionalActorAuthority $authority, private BranchAccessService $branches, private AuditRecorder $audit) {}

    /** @return array{requireDateOfBirth:bool,requireNationality:bool,requireEmail:bool,requireAddress:bool,lockVersion:int} */
    public function policy(int $organisationId): array
    {
        $settings = RegistrationSetting::query()->where('organisation_id', $organisationId)->first();

        return ['requireDateOfBirth' => $settings->require_date_of_birth ?? false,
            'requireNationality' => $settings->require_nationality ?? false, 'requireEmail' => $settings->require_email ?? false, 'requireAddress' => $settings->require_address ?? false, 'lockVersion' => $settings->lock_version ?? 0];
    }

    /** @return array{requireDateOfBirth:bool,requireNationality:bool,requireEmail:bool,requireAddress:bool,lockVersion:int} */
    public function sessionPolicy(PublicIntakeSession $session): array
    {
        $policy = $session->registration_policy;

        return ['requireDateOfBirth' => (bool) ($policy['requireDateOfBirth'] ?? false),
            'requireNationality' => (bool) ($policy['requireNationality'] ?? false), 'requireEmail' => (bool) ($policy['requireEmail'] ?? false), 'requireAddress' => (bool) ($policy['requireAddress'] ?? false), 'lockVersion' => (int) ($policy['lockVersion'] ?? 0)];
    }

    /** @return array<string,mixed> */
    public function index(User $actor): array
    {
        return DB::transaction(function () use ($actor): array {
            $branch = $this->branches->activeBranch($actor);
            abort_unless($branch !== null, 404);
            $this->authority->lock($actor, $branch, self::PERMISSION);

            return ['branchId' => $branch->id, 'requirements' => $this->policy($actor->organisation_id)];
        });
    }

    /** @param array<string,mixed> $attributes */
    public function save(User $actor, array $attributes): void
    {
        $data = Validator::make($attributes, [
            'expected_branch_id' => ['required', 'integer', 'min:1'], 'lock_version' => ['required', 'integer', 'min:0'],
            'idempotency_key' => ['required', 'uuid'], 'require_date_of_birth' => ['required', 'boolean'], 'require_nationality' => ['required', 'boolean'], 'require_email' => ['sometimes', 'boolean'], 'require_address' => ['sometimes', 'boolean'],
        ])->validate();
        $values = ['require_date_of_birth' => (bool) $data['require_date_of_birth'], 'require_nationality' => (bool) $data['require_nationality']];
        foreach (['require_email', 'require_address'] as $field) {
            if (array_key_exists($field, $data)) {
                $values[$field] = (bool) $data[$field];
            }
        }
        $hash = hash('sha256', json_encode([(int) $data['expected_branch_id'], (int) $data['lock_version'], $values], JSON_THROW_ON_ERROR));
        DB::transaction(function () use ($actor, $data, $values, $hash): void {
            $branch = $this->authority->branch($actor, $data['expected_branch_id']);
            Organisation::query()->whereKey($actor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
            $actor = $this->authority->lock($actor, $branch, self::PERMISSION);
            $command = DB::table('registration_setting_commands')->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($command !== null) {
                if ($command->payload_hash !== $hash) {
                    throw ValidationException::withMessages(['settings' => 'This request key was already used with different details. Reload before saving.']);
                }

                return;
            }
            $settings = RegistrationSetting::query()->where('organisation_id', $actor->organisation_id)->lockForUpdate()->first();
            if (($settings->lock_version ?? 0) !== (int) $data['lock_version']) {
                throw ValidationException::withMessages(['lock_version' => 'Registration settings changed after this page was opened. Reload and review the latest settings before saving.']);
            }
            $changed = array_keys(array_filter($values, fn (bool $value, string $key): bool => $value !== ($settings->$key ?? false), ARRAY_FILTER_USE_BOTH));
            $settings ??= new RegistrationSetting;
            if (! $settings->exists || $changed !== []) {
                $settings->forceFill(['organisation_id' => $actor->organisation_id, ...$values, 'lock_version' => ($settings->lock_version ?? 0) + 1])->save();
                $this->audit->record('registration_settings.updated', $settings, ['changed_fields' => $changed, 'record_version' => $settings->lock_version], $actor, $branch);
            }
            DB::table('registration_setting_commands')->insert(['organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id,
                'idempotency_key' => $data['idempotency_key'], 'payload_hash' => $hash, 'created_at' => now()->utc()]);
        }, 3);
    }

    /** @param array<string,mixed> $attributes
     * @param  array{requireDateOfBirth:bool,requireNationality:bool,requireEmail:bool,requireAddress:bool,lockVersion:int}  $policy
     */
    public function validateNewPatient(array $attributes, array $policy, bool $publicIntake = false): void
    {
        Validator::make($attributes, [
            'date_of_birth' => [$publicIntake || $policy['requireDateOfBirth'] ? 'required' : 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'nationality_code' => [$policy['requireNationality'] ? 'required' : 'nullable', 'string', 'regex:/\A[A-Za-z]{2}\z/'],
            'email' => [$policy['requireEmail'] ? 'required' : 'nullable', 'email', 'max:255'],
            'address_line_1' => [$policy['requireAddress'] ? 'required' : 'nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => [$policy['requireAddress'] ? 'required' : 'nullable', 'string', 'max:100'],
            'state' => [$policy['requireAddress'] ? 'required' : 'nullable', 'string', 'max:100'],
            'postcode' => [$policy['requireAddress'] ? 'required' : 'nullable', 'string', 'max:20'],
            'country_code' => [$policy['requireAddress'] ? 'required' : 'nullable', 'string', 'regex:/\A[A-Za-z]{2}\z/'],
        ])->validate();
    }
}

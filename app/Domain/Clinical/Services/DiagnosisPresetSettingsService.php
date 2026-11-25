<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\StaffAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Models\DiagnosisPreset;
use App\Domain\Clinical\Suggestions\SuggestionPermissions;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class DiagnosisPresetSettingsService
{
    public const PERMISSION = 'catalogue.settings.manage.organisation';

    public function __construct(private TransactionalActorAuthority $authority, private BranchAccessService $branches, private AuditRecorder $audit, private StaffAccessService $staff) {}

    /** @return array<string, mixed> */
    public function index(User $actor, string $status, string $search, int $page): array
    {
        abort_unless(in_array($status, ['all', 'active', 'archived'], true), 404);

        return DB::transaction(function () use ($actor, $status, $search, $page): array {
            $branch = $this->branches->activeBranch($actor);
            abort_unless($branch !== null, 404);
            $actor = $this->authority->lock($actor, $branch, self::PERMISSION);
            $query = DiagnosisPreset::query()->where('organisation_id', $actor->organisation_id);
            if ($status !== 'all') {
                $query->where('is_active', $status === 'active');
            }
            if ($search !== '') {
                $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
                $query->where(fn ($q) => $q->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$needle])
                    ->orWhereRaw("LOWER(COALESCE(icd10_code, '')) LIKE ? ESCAPE '!'", [$needle]));
            }
            $paginator = $query->orderBy('name')->orderBy('id')->paginate(25, ['*'], 'page', $page);

            return ['branchId' => $branch->id, 'status' => $status, 'q' => $search,
                'canCheckoutSettings' => $actor->can('panels.manage.organisation'),
                'presets' => $paginator->getCollection()->map(fn (DiagnosisPreset $preset): array => [
                    'id' => $preset->id, 'name' => $preset->name, 'icd10Code' => $preset->icd10_code, 'status' => $preset->status,
                    'active' => $preset->is_active, 'lockVersion' => $preset->lock_version,
                ])->all(), 'pagination' => ['currentPage' => $paginator->currentPage(), 'lastPage' => $paginator->lastPage(), 'total' => $paginator->total()]];
        });
    }

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, array $attributes): void
    {
        $data = Validator::make($attributes, [
            ...$this->retryRules(), 'names' => ['required', 'array', 'min:1', 'max:20'],
            'names.*' => ['required', 'string', 'max:500'],
            'codes' => ['sometimes', 'array', 'max:20'],
            'codes.*' => ['nullable', 'string', 'max:20'],
        ])->validate();
        $names = [];
        foreach (array_values($data['names']) as $index => $name) {
            $names[] = $this->name($name, 'names.'.$index);
        }
        $codes = [];
        foreach (array_keys($names) as $index) {
            $codes[] = $this->code($data['codes'][$index] ?? null, 'codes.'.$index);
        }
        $normalized = array_map(mb_strtolower(...), $names);
        if (count(array_unique($normalized)) !== count($normalized)) {
            throw ValidationException::withMessages(['names' => 'Duplicate names in this batch are not allowed.']);
        }
        $hash = $this->hash('create', $data, [$names, $codes]);
        DB::transaction(function () use ($actor, $data, $names, $codes, $normalized, $hash): void {
            [$actor, $branchId] = $this->context($actor, $data['expected_branch_id']);
            if ($this->replay($actor, $data['idempotency_key'], $hash)) {
                return;
            }
            foreach ($normalized as $index => $name) {
                $this->unique($actor, $name, 'names.'.$index);
            }
            foreach ($names as $index => $name) {
                $preset = new DiagnosisPreset;
                $preset->forceFill(['organisation_id' => $actor->organisation_id, 'name' => $name,
                    'normalized_name' => $normalized[$index], 'icd10_code' => $codes[$index], 'is_active' => true,
                    'source' => 'clinic', 'status' => 'draft', 'approved_at' => null,
                    'lock_version' => 1, 'created_by_user_id' => $actor->id])->save();
                $this->audit->record('diagnosis_preset.created', $preset, ['lock_version' => 1], $actor);
            }
            $this->remember($actor, $branchId, $data['idempotency_key'], $hash);
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, int $id, array $attributes, bool $rename): void
    {
        $data = Validator::make($attributes, [
            ...$this->retryRules(), 'lock_version' => ['required', 'integer', 'min:1'],
            ...($rename ? ['name' => ['required', 'string', 'max:500'], 'icd10_code' => ['sometimes', 'nullable', 'string', 'max:20']] : ['active' => ['required', 'boolean']]),
        ])->validate();
        $value = $rename ? $this->name($data['name'], 'name') : filter_var($data['active'], FILTER_VALIDATE_BOOLEAN);
        $codeGiven = $rename && array_key_exists('icd10_code', $data);
        $code = $codeGiven ? $this->code($data['icd10_code'], 'icd10_code') : null;
        $hash = $this->hash($rename ? 'rename' : 'status', $data, [$id, (int) $data['lock_version'], $value, $codeGiven, $code]);
        DB::transaction(function () use ($actor, $id, $data, $value, $rename, $hash, $codeGiven, $code): void {
            [$actor, $branchId] = $this->context($actor, $data['expected_branch_id']);
            $preset = DiagnosisPreset::query()->whereKey($id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
            if ($this->replay($actor, $data['idempotency_key'], $hash)) {
                return;
            }
            if ($preset->lock_version !== (int) $data['lock_version']) {
                throw ValidationException::withMessages(['preset' => 'This preset has changed. Reload before continuing.']);
            }
            if ($rename) {
                $this->unique($actor, mb_strtolower((string) $value), 'name', $id);
                $preset->forceFill(['name' => $value, 'normalized_name' => mb_strtolower((string) $value)]);
                if ($codeGiven) {
                    $preset->forceFill(['icd10_code' => $code]);
                }
                if ($preset->isDirty(['name', 'icd10_code'])) {
                    // A changed wording or code needs the doctor's review again.
                    $preset->forceFill(['status' => 'draft', 'approved_by_user_id' => null, 'approved_at' => null, 'edited_by_user_id' => $actor->id]);
                }
            } else {
                $preset->forceFill(['is_active' => $value]);
            }
            if ($preset->isDirty()) {
                $preset->forceFill(['lock_version' => $preset->lock_version + 1])->save();
                $event = $rename ? 'diagnosis_preset.renamed' : ($value ? 'diagnosis_preset.restored' : 'diagnosis_preset.archived');
                $this->audit->record($event, $preset, ['lock_version' => $preset->lock_version], $actor);
            }
            $this->remember($actor, $branchId, $data['idempotency_key'], $hash);
        }, 3);
    }

    /**
     * SUG-1a: the doctor's review. Waiting drafts for a reviewer (active presets only, newest last).
     *
     * @return array<string, mixed>
     */
    public function reviewIndex(User $actor): array
    {
        return DB::transaction(function () use ($actor): array {
            $branch = $this->branches->activeBranch($actor);
            abort_unless($branch !== null, 404);
            $actor = $this->authority->lock($actor, $branch, SuggestionPermissions::APPROVE);
            $drafts = DiagnosisPreset::query()->where('organisation_id', $actor->organisation_id)
                ->where('status', 'draft')->where('is_active', true)->orderBy('name')->orderBy('id')->limit(100)->get();

            // Who and when: names follow the staff-directory visibility rule (others read "Staff member").
            $people = $drafts->map(fn (DiagnosisPreset $preset): int => (int) ($preset->edited_by_user_id ?? $preset->created_by_user_id))->unique()->all();
            $names = $this->staff->visibleUsers($actor)->whereIn('id', $people)->pluck('name', 'id');

            return ['branchId' => $branch->id, 'drafts' => $drafts->map(function (DiagnosisPreset $preset) use ($actor, $names, $branch): array {
                $changed = $preset->edited_by_user_id !== null;
                $by = (int) ($preset->edited_by_user_id ?? $preset->created_by_user_id);
                $at = $changed ? $preset->updated_at : $preset->created_at;

                return [
                    'id' => $preset->id, 'name' => $preset->name, 'icd10Code' => $preset->icd10_code, 'lockVersion' => $preset->lock_version,
                    'ownEntry' => in_array($actor->id, [$preset->created_by_user_id, $preset->edited_by_user_id], true),
                    'enteredLabel' => ($changed ? 'Changed by ' : 'Entered by ').($names[$by] ?? 'Staff member').', '.$at?->setTimezone($branch->timezone)->format('j M Y'),
                ];
            })->all()];
        });
    }

    /** @param array<string, mixed> $attributes */
    public function review(User $actor, int $id, array $attributes): void
    {
        $data = Validator::make($attributes, [
            ...$this->retryRules(), 'lock_version' => ['required', 'integer', 'min:1'],
            'decision' => ['required', 'string', 'in:approve,return'],
        ])->validate();
        $hash = $this->hash('review', $data, [$id, (int) $data['lock_version'], $data['decision']]);
        DB::transaction(function () use ($actor, $id, $data, $hash): void {
            [$actor, $branchId] = $this->context($actor, $data['expected_branch_id'], SuggestionPermissions::APPROVE);
            $preset = DiagnosisPreset::query()->whereKey($id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
            if ($this->replay($actor, $data['idempotency_key'], $hash)) {
                return;
            }
            if ($preset->lock_version !== (int) $data['lock_version']) {
                throw ValidationException::withMessages(['preset' => 'This item has changed. Reload before continuing.']);
            }
            if ($data['decision'] === 'approve') {
                if ($preset->status !== 'draft') {
                    throw ValidationException::withMessages(['preset' => 'This item is already approved.']);
                }
                if (in_array($actor->id, [$preset->created_by_user_id, $preset->edited_by_user_id], true)) {
                    throw ValidationException::withMessages(['preset' => 'Someone other than the person who entered or changed this item must review it.']);
                }
                $preset->forceFill(['status' => 'approved', 'approved_by_user_id' => $actor->id, 'approved_at' => now()->utc()]);
            } else {
                if ($preset->status !== 'approved') {
                    throw ValidationException::withMessages(['preset' => 'This item is already a draft.']);
                }
                $preset->forceFill(['status' => 'draft', 'approved_by_user_id' => null, 'approved_at' => null]);
            }
            $preset->forceFill(['lock_version' => $preset->lock_version + 1])->save();
            $this->audit->record($data['decision'] === 'approve' ? 'diagnosis_preset.approved' : 'diagnosis_preset.returned_to_draft', $preset, ['lock_version' => $preset->lock_version], $actor);
            $this->remember($actor, $branchId, $data['idempotency_key'], $hash);
        }, 3);
    }

    /** An optional ICD-10 code in the usual form (for example R50.9); stored upper case. */
    private function code(mixed $value, string $field): ?string
    {
        $code = strtoupper(trim((string) $value));
        if ($code === '') {
            return null;
        }
        if (preg_match('/^[A-Z][0-9]{2}(\.[0-9A-Z]{1,4})?$/', $code) !== 1) {
            throw ValidationException::withMessages([$field => 'Enter an ICD-10 code such as R50.9, or leave it blank.']);
        }

        return $code;
    }

    private function name(string $name, string $field): string
    {
        $display = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($display === '') {
            throw ValidationException::withMessages([$field => 'Enter a diagnosis name.']);
        }

        return $display;
    }

    /** @return array<string, list<string>> */
    private function retryRules(): array
    {
        return ['expected_branch_id' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['required', 'uuid']];
    }

    /** @param array<string, mixed> $data
     * @param  list<mixed>  $payload
     */
    private function hash(string $operation, array $data, array $payload): string
    {
        return hash('sha256', json_encode([$operation, (int) $data['expected_branch_id'], $payload], JSON_THROW_ON_ERROR));
    }

    /** @return array{User, int} */
    private function context(User $actor, mixed $expected, string $permission = self::PERMISSION): array
    {
        $branch = $this->authority->branch($actor, $expected);
        Organisation::query()->whereKey($actor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
        $actor = $this->authority->lock($actor, $branch, $permission);

        return [$actor, $branch->id];
    }

    private function unique(User $actor, string $normalized, string $field, ?int $except = null): void
    {
        $query = DiagnosisPreset::query()->where('organisation_id', $actor->organisation_id)->where('normalized_name', $normalized);
        if ($except !== null) {
            $query->where('id', '!=', $except);
        }
        if ($query->lockForUpdate()->exists()) {
            throw ValidationException::withMessages([$field => 'This diagnosis name already exists, including archived presets.']);
        }
    }

    private function replay(User $actor, string $key, string $hash): bool
    {
        $command = DB::table('diagnosis_preset_commands')->where('organisation_id', $actor->organisation_id)
            ->where('idempotency_key', $key)->lockForUpdate()->first();
        if ($command === null) {
            return false;
        }
        if ($command->payload_hash !== $hash) {
            throw ValidationException::withMessages(['preset' => 'This request key was already used with different details.']);
        }

        return true;
    }

    private function remember(User $actor, int $branchId, string $key, string $hash): void
    {
        DB::table('diagnosis_preset_commands')->insert(['organisation_id' => $actor->organisation_id, 'branch_id' => $branchId,
            'idempotency_key' => $key, 'payload_hash' => $hash, 'created_at' => now()->utc()]);
    }
}

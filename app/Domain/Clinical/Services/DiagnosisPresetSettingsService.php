<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Models\DiagnosisPreset;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class DiagnosisPresetSettingsService
{
    public const PERMISSION = 'catalogue.settings.manage.organisation';

    public function __construct(private TransactionalActorAuthority $authority, private BranchAccessService $branches, private AuditRecorder $audit) {}

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
                $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$needle]);
            }
            $paginator = $query->orderBy('name')->orderBy('id')->paginate(25, ['*'], 'page', $page);

            return ['branchId' => $branch->id, 'status' => $status, 'q' => $search,
                'canCheckoutSettings' => $actor->can('panels.manage.organisation'),
                'presets' => $paginator->getCollection()->map(fn (DiagnosisPreset $preset): array => [
                    'id' => $preset->id, 'name' => $preset->name, 'active' => $preset->is_active, 'lockVersion' => $preset->lock_version,
                ])->all(), 'pagination' => ['currentPage' => $paginator->currentPage(), 'lastPage' => $paginator->lastPage(), 'total' => $paginator->total()]];
        });
    }

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, array $attributes): void
    {
        $data = Validator::make($attributes, [
            ...$this->retryRules(), 'names' => ['required', 'array', 'min:1', 'max:20'],
            'names.*' => ['required', 'string', 'max:500'],
        ])->validate();
        $names = [];
        foreach (array_values($data['names']) as $index => $name) {
            $names[] = $this->name($name, 'names.'.$index);
        }
        $normalized = array_map(mb_strtolower(...), $names);
        if (count(array_unique($normalized)) !== count($normalized)) {
            throw ValidationException::withMessages(['names' => 'Duplicate names in this batch are not allowed.']);
        }
        $hash = $this->hash('create', $data, $names);
        DB::transaction(function () use ($actor, $data, $names, $normalized, $hash): void {
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
                    'normalized_name' => $normalized[$index], 'is_active' => true,
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
            ...($rename ? ['name' => ['required', 'string', 'max:500']] : ['active' => ['required', 'boolean']]),
        ])->validate();
        $value = $rename ? $this->name($data['name'], 'name') : filter_var($data['active'], FILTER_VALIDATE_BOOLEAN);
        $hash = $this->hash($rename ? 'rename' : 'status', $data, [$id, (int) $data['lock_version'], $value]);
        DB::transaction(function () use ($actor, $id, $data, $value, $rename, $hash): void {
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
    private function context(User $actor, mixed $expected): array
    {
        $branch = $this->authority->branch($actor, $expected);
        Organisation::query()->whereKey($actor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
        $actor = $this->authority->lock($actor, $branch, self::PERMISSION);

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

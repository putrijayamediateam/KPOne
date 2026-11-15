<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Models\CatalogueOption;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class MedicineOptionSettingsService
{
    public const PERMISSION = 'catalogue.settings.manage.organisation';

    /** @var array<string, string> */
    public const TABS = [
        'medicine_group' => 'Drug group', 'instruction' => 'Instruction', 'precaution' => 'Precaution',
        'frequency' => 'Frequency', 'duration' => 'Duration', 'dosage_unit' => 'Dosage unit', 'indication' => 'Indication',
    ];

    public function __construct(private TransactionalActorAuthority $authority, private BranchAccessService $branches, private AuditRecorder $audit) {}

    /** @return array<string, mixed> */
    public function index(User $actor, string $type, string $search, int $page): array
    {
        $this->type($type);

        return DB::transaction(function () use ($actor, $type, $search, $page): array {
            $branch = $this->branches->activeBranch($actor);
            abort_unless($branch !== null, 404);
            $actor = $this->authority->lock($actor, $branch, self::PERMISSION);
            $query = CatalogueOption::query()->where('organisation_id', $actor->organisation_id)->where('option_type', $type);
            if ($search !== '') {
                $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
                $query->whereRaw("LOWER(label) LIKE ? ESCAPE '!'", [$needle]);
            }
            $paginator = $query->orderBy('label')->orderBy('id')->paginate(25, ['*'], 'page', $page);

            return ['branchId' => $branch->id, 'type' => $type, 'tabs' => self::TABS, 'q' => $search,
                'options' => $paginator->getCollection()->map(fn (CatalogueOption $option): array => [
                    'id' => $option->id, 'label' => $option->label, 'active' => $option->is_active, 'lockVersion' => $option->lock_version,
                ])->all(), 'pagination' => ['currentPage' => $paginator->currentPage(), 'lastPage' => $paginator->lastPage(), 'total' => $paginator->total()]];
        });
    }

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, string $type, array $attributes): void
    {
        $this->type($type);
        $data = Validator::make($attributes, [
            ...$this->retryRules(), 'labels' => ['required', 'array', 'min:1', 'max:'.(in_array($type, ['precaution', 'indication'], true) ? 20 : 1)],
            'labels.*' => ['required', 'string', 'max:200'],
        ])->validate();
        $labels = array_map(fn (string $label): string => $this->label($label), array_values($data['labels']));
        $normalized = array_map(mb_strtolower(...), $labels);
        if (count(array_unique($normalized)) !== count($normalized)) {
            throw ValidationException::withMessages(['labels' => 'Duplicate names in this batch are not allowed.']);
        }
        $hash = $this->hash('create', $type, $data, $labels);
        DB::transaction(function () use ($actor, $type, $data, $labels, $normalized, $hash): void {
            [$actor, $branchId] = $this->context($actor, $data['expected_branch_id']);
            if ($this->replay($actor, $data['idempotency_key'], $hash)) {
                return;
            }
            foreach ($normalized as $index => $name) {
                $this->unique($actor, $type, $name, 'labels.'.$index);
            }
            foreach ($labels as $index => $label) {
                $option = new CatalogueOption;
                $option->forceFill(['organisation_id' => $actor->organisation_id, 'option_type' => $type,
                    'label' => $label, 'normalized_label' => $normalized[$index], 'is_active' => true,
                    'lock_version' => 1, 'created_by_user_id' => $actor->id])->save();
                $this->audit->record('catalogue_option.created', $option, ['option_type' => $type, 'lock_version' => 1], $actor);
            }
            $this->remember($actor, $branchId, $data['idempotency_key'], $hash);
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, string $type, int $id, array $attributes, bool $rename): void
    {
        $this->type($type);
        $data = Validator::make($attributes, [
            ...$this->retryRules(), 'lock_version' => ['required', 'integer', 'min:1'],
            ...($rename ? ['label' => ['required', 'string', 'max:200']] : ['active' => ['required', 'boolean']]),
        ])->validate();
        $value = $rename ? $this->label($data['label'], 'label') : filter_var($data['active'], FILTER_VALIDATE_BOOLEAN);
        $hash = $this->hash($rename ? 'rename' : 'status', $type, $data, [$id, (int) $data['lock_version'], $value]);
        DB::transaction(function () use ($actor, $type, $id, $data, $value, $rename, $hash): void {
            [$actor, $branchId] = $this->context($actor, $data['expected_branch_id']);
            // Check ownership even on a replay. Returned success never grants access to another tenant.
            $option = CatalogueOption::query()->whereKey($id)->where('organisation_id', $actor->organisation_id)
                ->where('option_type', $type)->lockForUpdate()->firstOrFail();
            if ($this->replay($actor, $data['idempotency_key'], $hash)) {
                return;
            }
            if ($option->lock_version !== (int) $data['lock_version']) {
                throw ValidationException::withMessages(['option' => 'This option has changed. Reload before continuing.']);
            }
            if ($rename) {
                $this->unique($actor, $type, mb_strtolower((string) $value), 'label', $id);
                $option->forceFill(['label' => $value, 'normalized_label' => mb_strtolower((string) $value)]);
            } else {
                $option->forceFill(['is_active' => $value]);
            }
            if ($option->isDirty()) {
                $option->forceFill(['lock_version' => $option->lock_version + 1])->save();
                $event = $rename ? 'catalogue_option.renamed' : ($value ? 'catalogue_option.restored' : 'catalogue_option.archived');
                $this->audit->record($event, $option, ['option_type' => $type, 'lock_version' => $option->lock_version], $actor);
            }
            $this->remember($actor, $branchId, $data['idempotency_key'], $hash);
        }, 3);
    }

    private function type(string $type): void
    {
        abort_unless(array_key_exists($type, self::TABS), 404);
    }

    private function label(string $label, string $field = 'labels'): string
    {
        // Match the existing inline creator's whitespace normalization.
        $display = trim(preg_replace('/\s+/u', ' ', $label) ?? '');
        if ($display === '') {
            throw ValidationException::withMessages([$field => 'Enter a name.']);
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
    private function hash(string $operation, string $type, array $data, array $payload): string
    {
        return hash('sha256', json_encode([$operation, $type, (int) $data['expected_branch_id'], $payload], JSON_THROW_ON_ERROR));
    }

    /** @return array{User, int} */
    private function context(User $actor, mixed $expected): array
    {
        $branch = $this->authority->branch($actor, $expected);
        Organisation::query()->whereKey($actor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
        $actor = $this->authority->lock($actor, $branch, self::PERMISSION);

        return [$actor, $branch->id];
    }

    private function unique(User $actor, string $type, string $normalized, string $field, ?int $except = null): void
    {
        $query = CatalogueOption::query()->where('organisation_id', $actor->organisation_id)->where('option_type', $type)
            ->where('normalized_label', $normalized);
        if ($except !== null) {
            $query->where('id', '!=', $except);
        }
        if ($query->lockForUpdate()->exists()) {
            throw ValidationException::withMessages([$field => 'This name already exists in this tab, including archived options.']);
        }
    }

    private function replay(User $actor, string $key, string $hash): bool
    {
        $command = DB::table('medicine_option_settings_commands')->where('organisation_id', $actor->organisation_id)
            ->where('idempotency_key', $key)->lockForUpdate()->first();
        if ($command === null) {
            return false;
        }
        if ($command->payload_hash !== $hash) {
            throw ValidationException::withMessages(['option' => 'This request key was already used with different details.']);
        }

        return true;
    }

    private function remember(User $actor, int $branchId, string $key, string $hash): void
    {
        DB::table('medicine_option_settings_commands')->insert(['organisation_id' => $actor->organisation_id, 'branch_id' => $branchId,
            'idempotency_key' => $key, 'payload_hash' => $hash, 'created_at' => now()->utc()]);
    }
}

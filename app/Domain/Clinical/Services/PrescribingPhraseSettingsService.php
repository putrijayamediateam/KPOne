<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\StaffAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Models\PrescribingPhrase;
use App\Domain\Clinical\Suggestions\SuggestionPermissions;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * SUG-1b: the clinic's standard dosing and instruction phrases (English and Bahasa Melayu) behind the consultation
 * recommendation popup. Drafted with the existing settings permission; a doctor other than the person who entered or last
 * changed a phrase approves it before it is ever offered. Every write: fresh permission and branch under lock, one
 * transaction, the row lock, lock_version, a retry key and an audit row with ids only.
 */
final class PrescribingPhraseSettingsService
{
    public const PERMISSION = 'catalogue.settings.manage.organisation';

    public const MAX = 60;

    public function __construct(private TransactionalActorAuthority $authority, private BranchAccessService $branches, private AuditRecorder $audit, private StaffAccessService $staff) {}

    /** @return array<string, mixed> */
    public function index(User $actor, string $status, string $kind, string $search, int $page): array
    {
        abort_unless(in_array($status, ['all', 'active', 'archived'], true), 404);
        abort_unless($kind === 'all' || array_key_exists($kind, PrescribingPhrase::KINDS), 404);

        return DB::transaction(function () use ($actor, $status, $kind, $search, $page): array {
            $branch = $this->branches->activeBranch($actor);
            abort_unless($branch !== null, 404);
            $actor = $this->authority->lock($actor, $branch, self::PERMISSION);
            $query = PrescribingPhrase::query()->where('organisation_id', $actor->organisation_id);
            if ($status !== 'all') {
                $query->where('is_active', $status === 'active');
            }
            if ($kind !== 'all') {
                $query->where('kind', $kind);
            }
            if ($search !== '') {
                $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
                $query->where(fn ($q) => $q->whereRaw("LOWER(label_en) LIKE ? ESCAPE '!'", [$needle])
                    ->orWhereRaw("LOWER(label_ms) LIKE ? ESCAPE '!'", [$needle]));
            }
            $paginator = $query->orderBy('kind')->orderBy('label_en')->orderBy('id')->paginate(25, ['*'], 'page', $page);

            return ['branchId' => $branch->id, 'status' => $status, 'kind' => $kind, 'q' => $search,
                'kinds' => collect(PrescribingPhrase::KINDS)->map(fn (string $label, string $key): array => ['value' => $key, 'label' => $label])->values()->all(),
                'phrases' => $paginator->getCollection()->map(fn (PrescribingPhrase $phrase): array => [
                    'id' => $phrase->id, 'kind' => $phrase->kind, 'kindLabel' => PrescribingPhrase::KINDS[$phrase->kind], 'labelEn' => $phrase->label_en, 'labelMs' => $phrase->label_ms,
                    'status' => $phrase->status, 'active' => $phrase->is_active, 'lockVersion' => $phrase->lock_version,
                ])->all(), 'pagination' => ['currentPage' => $paginator->currentPage(), 'lastPage' => $paginator->lastPage(), 'total' => $paginator->total()]];
        });
    }

    /**
     * Draft phrases waiting for a doctor's review (active only, at most 100).
     *
     * @return array<int, array<string, mixed>>
     */
    public function reviewDrafts(User $actor): array
    {
        return DB::transaction(function () use ($actor): array {
            $branch = $this->branches->activeBranch($actor);
            abort_unless($branch !== null, 404);
            $actor = $this->authority->lock($actor, $branch, SuggestionPermissions::APPROVE);
            $drafts = PrescribingPhrase::query()->where('organisation_id', $actor->organisation_id)
                ->where('status', 'draft')->where('is_active', true)->orderBy('kind')->orderBy('label_en')->orderBy('id')->limit(100)->get();
            $people = $drafts->map(fn (PrescribingPhrase $phrase): int => (int) ($phrase->edited_by_user_id ?? $phrase->created_by_user_id))->unique()->all();
            $names = $this->staff->visibleUsers($actor)->whereIn('id', $people)->pluck('name', 'id');

            return $drafts->map(function (PrescribingPhrase $phrase) use ($actor, $names, $branch): array {
                $changed = $phrase->edited_by_user_id !== null;
                $by = (int) ($phrase->edited_by_user_id ?? $phrase->created_by_user_id);
                $at = $changed ? ($phrase->edited_at ?? $phrase->updated_at) : $phrase->created_at;
                $day = $at === null ? '' : CarbonImmutable::parse($at)->setTimezone($branch->timezone)->format('j M Y');

                return [
                    'id' => $phrase->id, 'kindLabel' => PrescribingPhrase::KINDS[$phrase->kind], 'labelEn' => $phrase->label_en, 'labelMs' => $phrase->label_ms,
                    'lockVersion' => $phrase->lock_version,
                    'ownEntry' => in_array($actor->id, [$phrase->created_by_user_id, $phrase->edited_by_user_id], true),
                    'enteredLabel' => ($changed ? 'Changed by ' : 'Entered by ').($names[$by] ?? 'Staff member').', '.$day,
                ];
            })->values()->all();
        });
    }

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, array $attributes): void
    {
        $data = Validator::make($attributes, [
            ...$this->retryRules(), 'kind' => ['required', 'string', 'in:'.implode(',', array_keys(PrescribingPhrase::KINDS))],
            'label_en' => ['required', 'string', 'max:300'], 'label_ms' => ['required', 'string', 'max:300'],
        ])->validate();
        $en = $this->text($data['label_en'], 'label_en', 'English');
        $ms = $this->text($data['label_ms'], 'label_ms', 'Bahasa Melayu');
        $hash = $this->hash('create', $data, [$actor->id, $data['kind'], $en, $ms]);
        DB::transaction(function () use ($actor, $data, $en, $ms, $hash): void {
            [$actor, $branchId] = $this->context($actor, $data['expected_branch_id']);
            if ($this->replay($actor, $data['idempotency_key'], $hash)) {
                return;
            }
            $this->unique($actor, $data['kind'], mb_strtolower($en), 'label_en');
            $phrase = new PrescribingPhrase;
            $phrase->forceFill(['organisation_id' => $actor->organisation_id, 'kind' => $data['kind'], 'label_en' => $en, 'label_ms' => $ms,
                'normalized_en' => mb_strtolower($en), 'is_active' => true, 'source' => 'clinic', 'status' => 'draft',
                'lock_version' => 1, 'created_by_user_id' => $actor->id])->save();
            $this->audit->record('prescribing_phrase.created', $phrase, ['lock_version' => 1, 'kind' => $phrase->kind], $actor);
            $this->remember($actor, $branchId, $data['idempotency_key'], $hash);
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, int $id, array $attributes, bool $edit): void
    {
        $data = Validator::make($attributes, [
            ...$this->retryRules(), 'lock_version' => ['required', 'integer', 'min:1'],
            ...($edit ? ['label_en' => ['required', 'string', 'max:300'], 'label_ms' => ['required', 'string', 'max:300']] : ['active' => ['required', 'boolean']]),
        ])->validate();
        $en = $edit ? $this->text($data['label_en'], 'label_en', 'English') : null;
        $ms = $edit ? $this->text($data['label_ms'], 'label_ms', 'Bahasa Melayu') : null;
        $active = $edit ? null : filter_var($data['active'], FILTER_VALIDATE_BOOLEAN);
        $hash = $this->hash($edit ? 'edit' : 'status', $data, [$actor->id, $id, (int) $data['lock_version'], $en, $ms, $active]);
        DB::transaction(function () use ($actor, $id, $data, $edit, $en, $ms, $active, $hash): void {
            [$actor, $branchId] = $this->context($actor, $data['expected_branch_id']);
            $phrase = PrescribingPhrase::query()->whereKey($id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
            if ($this->replay($actor, $data['idempotency_key'], $hash)) {
                return;
            }
            if ($phrase->lock_version !== (int) $data['lock_version']) {
                throw ValidationException::withMessages(['phrase' => 'This phrase has changed. Reload before continuing.']);
            }
            if ($edit) {
                $this->unique($actor, $phrase->kind, mb_strtolower((string) $en), 'label_en', $id);
                $phrase->forceFill(['label_en' => $en, 'label_ms' => $ms, 'normalized_en' => mb_strtolower((string) $en)]);
                if ($phrase->isDirty(['label_en', 'label_ms'])) {
                    // A changed wording needs the doctor's review again.
                    $phrase->forceFill(['status' => 'draft', 'approved_by_user_id' => null, 'approved_at' => null, 'edited_by_user_id' => $actor->id, 'edited_at' => now()->utc()]);
                }
            } else {
                $phrase->forceFill(['is_active' => $active]);
            }
            if ($phrase->isDirty()) {
                $phrase->forceFill(['lock_version' => $phrase->lock_version + 1])->save();
                $event = $edit ? 'prescribing_phrase.edited' : ($active ? 'prescribing_phrase.restored' : 'prescribing_phrase.archived');
                $this->audit->record($event, $phrase, ['lock_version' => $phrase->lock_version, 'kind' => $phrase->kind], $actor);
            }
            $this->remember($actor, $branchId, $data['idempotency_key'], $hash);
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function review(User $actor, int $id, array $attributes): void
    {
        $data = Validator::make($attributes, [
            ...$this->retryRules(), 'lock_version' => ['required', 'integer', 'min:1'],
            'decision' => ['required', 'string', 'in:approve,return'],
        ])->validate();
        $hash = $this->hash('review', $data, [$actor->id, $id, (int) $data['lock_version'], $data['decision']]);
        DB::transaction(function () use ($actor, $id, $data, $hash): void {
            [$actor, $branchId] = $this->context($actor, $data['expected_branch_id'], SuggestionPermissions::APPROVE);
            $phrase = PrescribingPhrase::query()->whereKey($id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
            if ($this->replay($actor, $data['idempotency_key'], $hash)) {
                return;
            }
            if ($phrase->lock_version !== (int) $data['lock_version']) {
                throw ValidationException::withMessages(['phrase' => 'This item has changed. Reload before continuing.']);
            }
            if ($data['decision'] === 'approve') {
                if ($phrase->status !== 'draft') {
                    throw ValidationException::withMessages(['phrase' => 'This item is already approved.']);
                }
                if (! $phrase->is_active) {
                    throw ValidationException::withMessages(['phrase' => 'Restore this item in Consultation settings before it can be approved.']);
                }
                if (in_array($actor->id, [$phrase->created_by_user_id, $phrase->edited_by_user_id], true)) {
                    throw ValidationException::withMessages(['phrase' => 'Someone other than the person who entered or changed this item must review it.']);
                }
                $phrase->forceFill(['status' => 'approved', 'approved_by_user_id' => $actor->id, 'approved_at' => now()->utc()]);
            } else {
                if ($phrase->status !== 'approved') {
                    throw ValidationException::withMessages(['phrase' => 'This item is already a draft.']);
                }
                $phrase->forceFill(['status' => 'draft', 'approved_by_user_id' => null, 'approved_at' => null]);
            }
            $phrase->forceFill(['lock_version' => $phrase->lock_version + 1])->save();
            $this->audit->record($data['decision'] === 'approve' ? 'prescribing_phrase.approved' : 'prescribing_phrase.returned_to_draft', $phrase, ['lock_version' => $phrase->lock_version, 'kind' => $phrase->kind], $actor);
            $this->remember($actor, $branchId, $data['idempotency_key'], $hash);
        }, 3);
    }

    private function text(string $value, string $field, string $language): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($text === '') {
            throw ValidationException::withMessages([$field => "Enter the {$language} wording."]);
        }
        if (mb_strlen($text) > self::MAX) {
            throw ValidationException::withMessages([$field => 'Keep each wording to '.self::MAX.' characters so it fits the drug label.']);
        }

        return $text;
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

    private function unique(User $actor, string $kind, string $normalized, string $field, ?int $except = null): void
    {
        $query = PrescribingPhrase::query()->where('organisation_id', $actor->organisation_id)->where('kind', $kind)->where('normalized_en', $normalized);
        if ($except !== null) {
            $query->where('id', '!=', $except);
        }
        if ($query->lockForUpdate()->exists()) {
            throw ValidationException::withMessages([$field => 'This English wording already exists for this kind, including archived phrases.']);
        }
    }

    private function replay(User $actor, string $key, string $hash): bool
    {
        $command = DB::table('prescribing_phrase_commands')->where('organisation_id', $actor->organisation_id)
            ->where('idempotency_key', $key)->lockForUpdate()->first();
        if ($command === null) {
            return false;
        }
        if ($command->payload_hash !== $hash) {
            throw ValidationException::withMessages(['phrase' => 'This request key was already used with different details.']);
        }

        return true;
    }

    private function remember(User $actor, int $branchId, string $key, string $hash): void
    {
        DB::table('prescribing_phrase_commands')->insert(['organisation_id' => $actor->organisation_id, 'branch_id' => $branchId,
            'idempotency_key' => $key, 'payload_hash' => $hash, 'created_at' => now()->utc()]);
    }
}

<?php

namespace App\Domain\HR\Credentials\Services;

use App\Domain\HR\Credentials\CredentialPermissions;
use App\Domain\HR\Credentials\Models\CredentialRecord;
use App\Domain\HR\Records\Models\EmploymentPeriod;
use App\Domain\HR\Records\Models\Worker;
use App\Domain\HR\Records\RecordPermissions;
use App\Domain\HR\Records\Services\RecordSupport;
use App\Domain\HR\Roster\Services\RosterRules;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * HR-5a page props for /hr/credentials: My credentials (everyone), the register and the type settings (Director and HR
 * Manager). Read only. References reach the page masked to their last four; only Show (audited) releases one. Health
 * items show only Done or Not done and a date, and only to the person and to managers.
 */
final class CredentialsPageService
{
    public function __construct(private RecordSupport $support, private CredentialService $credentials) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function page(User $actor, array $query): array
    {
        $canOwn = $actor->can(CredentialPermissions::VIEW_OWN);
        $canManage = $actor->can(CredentialPermissions::MANAGE);
        $tabs = array_keys(array_filter(['my' => $canOwn, 'register' => $canManage, 'settings' => $canManage]));
        $tab = in_array($query['tab'] ?? null, $tabs, true) ? (string) $query['tab'] : ($tabs[0] ?? 'my');
        $today = $this->credentials->today($actor->organisation_id);
        $types = $this->types($actor->organisation_id);

        return [
            'tab' => $tab,
            'tabs' => $tabs,
            'today' => $today,
            'canManage' => $canManage,
            'setUp' => $types->where('isActive', true)->isNotEmpty(),
            'options' => [
                'roles' => collect(RosterRules::ROLE_LABELS)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
                'rejectReasons' => collect(CredentialService::REJECT_REASONS)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
                'suggestedWarnDays' => CredentialService::SUGGESTED_WARN_DAYS,
            ],
            'my' => $canOwn && $tab === 'my' ? $this->my($actor, $types, $today) : null,
            'register' => $canManage && $tab === 'register' ? $this->register($actor, $types, $today, $query) : null,
            'settings' => $canManage && $tab === 'settings' ? $this->settings($actor, $types) : null,
        ];
    }

    /**
     * One line per credential of yours that is expiring or expired, for My roster and My attendance (12.2). Inside
     * KPOne only; nothing is sent.
     *
     * @return list<string>
     */
    public function reminders(User $actor): array
    {
        if (! $actor->can(CredentialPermissions::VIEW_OWN)) {
            return [];
        }
        $worker = Worker::query()->where('organisation_id', $actor->organisation_id)->where('user_id', $actor->id)->first();
        if ($worker === null) {
            return [];
        }
        $today = $this->credentials->today($actor->organisation_id);
        $lines = [];
        foreach ($this->cards($worker, $actor->roles->pluck('name')->all(), $this->types($actor->organisation_id), $today) as $card) {
            if (in_array($card['status']['code'], ['expiring', 'expired'], true)) {
                $words = $card['status']['words'];
                $lines[] = 'Your '.$card['name'].' '.lcfirst($words).'.';
            }
        }

        return $lines;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $types
     * @return array<string, mixed>
     */
    private function my(User $actor, Collection $types, string $today): array
    {
        $worker = Worker::query()->where('organisation_id', $actor->organisation_id)->where('user_id', $actor->id)->first();
        $mmc = $actor->hasRole('resident_doctor') ? DB::table('clinician_professional_profiles')->where('organisation_id', $actor->organisation_id)
            ->where('user_id', $actor->id)->first(['mmc_number', 'verified']) : null;
        $base = ['mmc' => $actor->hasRole('resident_doctor') ? ['number' => $mmc?->mmc_number, 'verified' => (bool) ($mmc->verified ?? false)] : null];
        if ($worker === null) {
            return [...$base, 'hasFile' => false];
        }

        return [...$base, 'hasFile' => true, 'workerId' => $worker->public_id, 'canOpenDocuments' => $actor->can(RecordPermissions::VIEW_OWN),
            'cards' => $this->cards($worker, $actor->roles->pluck('name')->all(), $types, $today)];
    }

    /**
     * The person's cards: every active type their roles need, plus any active type they hold anyway.
     *
     * @param  list<string>  $roles
     * @param  Collection<int, array<string, mixed>>  $types
     * @return list<array<string, mixed>>
     */
    private function cards(Worker $worker, array $roles, Collection $types, string $today): array
    {
        $records = CredentialRecord::query()->where('worker_id', $worker->id)->orderByDesc('version')->get()->groupBy('type_id');
        $names = $this->names($records->flatten()->pluck('entered_by_user_id')->all());
        $out = [];
        foreach ($types->where('isActive', true) as $type) {
            /** @var Collection<int, CredentialRecord> $own */
            $own = $records->get($type['id'], collect());
            $required = array_intersect($roles, $type['roles']) !== [];
            if (! $required && $own->isEmpty()) {
                continue;
            }
            $inForce = $own->firstWhere('status', 'accepted');
            $waiting = $own->firstWhere('status', 'waiting');
            $latest = $own->first();
            $out[] = [...$this->typeHead($type), 'required' => $required,
                'status' => CredentialStatus::of($type['healthItem'], $type['expires'], $type['warnFromDays'], $inForce, $today),
                'inForce' => $inForce === null ? null : $this->recordView($inForce),
                'waiting' => $waiting === null ? null : [...$this->recordView($waiting), 'enteredBy' => $names[$waiting->entered_by_user_id] ?? 'Staff member',
                    'enteredAt' => $waiting->created_at->toIso8601String()],
                'rejected' => $latest !== null && $latest->status === 'rejected' ? ['reason' => CredentialService::REJECT_REASONS[$latest->decision_reason] ?? 'Other',
                    'at' => $latest->decided_at?->toIso8601String()] : null,
            ];
        }

        return $out;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $types
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function register(User $actor, Collection $types, string $today, array $query): array
    {
        $active = $types->where('isActive', true)->values();
        $workers = Worker::query()->where('organisation_id', $actor->organisation_id)->with('user.roles')->orderBy('id')->get();
        $periods = EmploymentPeriod::query()->whereIn('worker_id', $workers->pluck('id')->all())->orderByDesc('starts_on')->get()->groupBy('worker_id');
        // People who have left are not on the register.
        $workers = $workers->filter(function (Worker $w) use ($periods, $today): bool {
            $latest = $periods->get($w->id)?->first();

            return $latest === null || $latest->ends_on === null || $latest->ends_on->toDateString() >= $today;
        })->values();
        $ids = $workers->pluck('id')->all();
        $records = CredentialRecord::query()->whereIn('worker_id', $ids)->whereIn('status', ['accepted', 'waiting'])->get()->groupBy('worker_id');
        $branches = $this->primaryBranches(array_values(array_filter($workers->pluck('user_id')->all(), fn ($id): bool => is_int($id))), $today);
        $mmc = DB::table('clinician_professional_profiles')->where('organisation_id', $actor->organisation_id)->pluck('verified', 'user_id');
        $names = $this->names($records->flatten()->pluck('entered_by_user_id')->all());

        $summary = ['expiring' => 0, 'expired' => 0, 'not_recorded' => 0, 'waiting' => 0];
        $waitingList = [];
        $rows = [];
        foreach ($workers as $w) {
            $roles = $w->user?->roles->pluck('name')->all() ?? [];
            /** @var Collection<int, CredentialRecord> $own */
            $own = $records->get($w->id, collect());
            $refusal = $w->user !== null ? $this->support->refusalFor($actor, $w->user) : null;
            $cells = [];
            foreach ($active as $type) {
                $inForce = $own->first(fn (CredentialRecord $r): bool => $r->type_id === $type['id'] && $r->status === 'accepted');
                $waiting = $own->first(fn (CredentialRecord $r): bool => $r->type_id === $type['id'] && $r->status === 'waiting');
                $required = array_intersect($roles, $type['roles']) !== [];
                if (! $required && $inForce === null && $waiting === null) {
                    $cells[$type['publicId']] = null;

                    continue;
                }
                $status = CredentialStatus::of($type['healthItem'], $type['expires'], $type['warnFromDays'], $inForce, $today);
                if (! $required && $status['code'] === 'not_recorded') {
                    $status = ['code' => 'not_required', 'words' => 'Not required', 'short' => '—'];
                }
                $cells[$type['publicId']] = ['status' => $status, 'waiting' => $waiting !== null, 'required' => $required,
                    'expiresOn' => CredentialStatus::day($inForce?->expires_on), 'doneOn' => $type['healthItem'] ? CredentialStatus::day($inForce?->issued_on) : null];
                if (in_array($status['code'], ['expiring', 'expired', 'not_recorded'], true)) {
                    $summary[$status['code']]++;
                }
                if ($waiting !== null) {
                    $summary['waiting']++;
                    $waitingList[] = [...$this->typeHead($type), 'person' => $w->name(), 'workerId' => $w->public_id,
                        'recorded' => $inForce === null ? null : $this->recordView($inForce), 'new' => $this->recordView($waiting),
                        'enteredBy' => $names[$waiting->entered_by_user_id] ?? 'Staff member', 'enteredAt' => $waiting->created_at->toIso8601String(),
                        'decideRefusal' => $refusal ?? ($waiting->entered_by_user_id === $actor->id ? 'Someone else checks this. You entered it.' : null)];
                }
            }
            $branch = $w->user_id !== null ? ($branches[$w->user_id] ?? null) : null;
            $rows[] = ['id' => $w->public_id, 'name' => $w->name(), 'role' => $this->roleLabel($roles, $w->kind), 'roles' => $roles,
                'branchId' => $branch['id'] ?? null, 'branch' => $branch['name'] ?? null,
                'mmc' => in_array('resident_doctor', $roles, true) ? ($mmc->has($w->user_id) ? ((bool) $mmc[$w->user_id] ? 'verified' : 'not_verified') : 'none') : null,
                'canEnter' => $refusal === null, 'cells' => $cells];
        }

        $branch = filter_var($query['branch'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $role = is_string($query['role'] ?? null) && array_key_exists($query['role'], RosterRules::ROLE_LABELS) ? $query['role'] : null;
        $type = is_string($query['type'] ?? null) && $active->contains('publicId', $query['type']) ? $query['type'] : null;
        $status = in_array($query['status'] ?? null, ['expiring', 'expired', 'not_recorded', 'waiting'], true) ? $query['status'] : null;
        $filtered = array_values(array_filter($rows, function (array $r) use ($branch, $role, $type, $status): bool {
            if (($branch !== false && $r['branchId'] !== $branch) || ($role !== null && ! in_array($role, $r['roles'], true))) {
                return false;
            }
            $cells = $type === null ? $r['cells'] : [$type => $r['cells'][$type] ?? null];

            return $status === null || collect($cells)->contains(fn (?array $c): bool => $c !== null && ($status === 'waiting' ? $c['waiting'] : $c['status']['code'] === $status));
        }));
        usort($filtered, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return [
            'filters' => ['branch' => $branch === false ? null : $branch, 'role' => $role, 'type' => $type, 'status' => $status],
            'types' => $active->map(fn (array $t): array => $this->typeHead($t))->values()->all(),
            'rows' => array_map(function (array $r): array {
                unset($r['roles']);

                return $r;
            }, $filtered),
            'total' => count($rows),
            'summary' => $summary,
            'waiting' => $waitingList,
            'branches' => Branch::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Branch $b): array => ['id' => $b->id, 'name' => $b->name])->all(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $types
     * @return array<string, mixed>
     */
    private function settings(User $actor, Collection $types): array
    {
        $versions = DB::table('hr_credential_type_versions as v')->join('users as u', 'u.id', '=', 'v.set_by_user_id')
            ->whereIn('v.type_id', $types->pluck('id')->all())->orderByDesc('v.version')->get(['v.id', 'v.type_id', 'v.version', 'v.name', 'v.warn_from_days', 'v.created_at', 'u.name as by']);
        $roles = DB::table('hr_credential_type_roles')->whereIn('type_version_id', $versions->pluck('id')->all())->get()->groupBy('type_version_id');

        return [
            'types' => $types->map(fn (array $t): array => [...$this->typeHead($t), 'isActive' => $t['isActive'], 'lockVersion' => $t['lockVersion'],
                'versions' => $versions->where('type_id', $t['id'])->map(fn (object $v): array => ['version' => (int) $v->version, 'name' => (string) $v->name,
                    'warnFromDays' => (int) $v->warn_from_days, 'roles' => $roles->get($v->id, collect())->pluck('role')->sort()->values()->all(),
                    'by' => (string) $v->by, 'at' => (string) $v->created_at])->values()->all(),
            ])->sortBy(fn (array $t): string => ($t['isActive'] ? '0' : '1').mb_strtolower($t['name']))->values()->all(),
        ];
    }

    /** @return Collection<int, array<string, mixed>> every type with its current version, keyed by internal id */
    private function types(int $organisationId): Collection
    {
        $rows = DB::table('hr_credential_types as t')->join('hr_credential_type_versions as v', fn ($j) => $j->on('v.type_id', '=', 't.id')->on('v.version', '=', 't.current_version'))
            ->where('t.organisation_id', $organisationId)->orderBy('v.name')
            ->get(['t.id', 't.public_id', 't.code', 't.expires', 't.health_item', 't.is_active', 't.lock_version', 't.current_version', 'v.id as version_id', 'v.name', 'v.warn_from_days']);
        $roles = DB::table('hr_credential_type_roles')->whereIn('type_version_id', $rows->pluck('version_id')->all())->get()->groupBy('type_version_id');

        return $rows->mapWithKeys(fn (object $r): array => [(int) $r->id => ['id' => (int) $r->id, 'publicId' => (string) $r->public_id, 'code' => (string) $r->code,
            'name' => (string) $r->name, 'expires' => (bool) $r->expires, 'healthItem' => (bool) $r->health_item, 'isActive' => (bool) $r->is_active,
            'warnFromDays' => (int) $r->warn_from_days, 'version' => (int) $r->current_version, 'lockVersion' => (int) $r->lock_version,
            'roles' => $roles->get($r->version_id, collect())->pluck('role')->all()]]);
    }

    /**
     * @param  array<string, mixed>  $type
     * @return array<string, mixed>
     */
    private function typeHead(array $type): array
    {
        return ['typeId' => $type['publicId'], 'code' => $type['code'], 'name' => $type['name'], 'expires' => $type['expires'], 'healthItem' => $type['healthItem'],
            'warnFromDays' => $type['warnFromDays'], 'roles' => $type['roles'], 'version' => $type['version']];
    }

    /** @return array<string, mixed> masked: the reference shows only its last four */
    private function recordView(CredentialRecord $r): array
    {
        return ['id' => $r->public_id, 'version' => $r->version, 'issuedOn' => $r->issued_on?->toDateString(), 'expiresOn' => $r->expires_on?->toDateString(),
            'issuingBody' => $r->issuing_body, 'referenceLast4' => $r->reference_last4, 'healthDone' => $r->health_done,
            'documentId' => $r->document_id === null ? null : DB::table('hr_documents')->where('id', $r->document_id)->where('status', 'active')->value('public_id'),
            'lockVersion' => $r->lock_version];
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, array{id: int, name: string}>
     */
    private function primaryBranches(array $userIds, string $today): array
    {
        if ($userIds === []) {
            return [];
        }
        $out = [];
        DB::table('staff_branch_assignments as a')->join('staff_profiles as p', 'p.id', '=', 'a.staff_profile_id')->join('branches as b', 'b.id', '=', 'a.branch_id')
            ->whereIn('p.user_id', $userIds)->where('a.is_primary', true)->whereDate('a.valid_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('a.valid_until')->orWhereDate('a.valid_until', '>=', $today))
            ->orderBy('a.valid_from')->get(['p.user_id', 'b.id', 'b.name'])
            ->each(function (object $r) use (&$out): void {
                $out[(int) $r->user_id] = ['id' => (int) $r->id, 'name' => (string) $r->name];
            });

        return $out;
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    private function names(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($id): ?int => is_numeric($id) ? (int) $id : null, $ids))));

        return $ids === [] ? [] : User::query()->whereIn('id', $ids)->pluck('name', 'id')->map(fn ($n): string => (string) $n)->all();
    }

    /** @param list<string> $roles */
    private function roleLabel(array $roles, string $kind): string
    {
        if ($kind === 'locum') {
            return 'Locum';
        }
        foreach (RosterRules::ROLE_LABELS as $role => $label) {
            if (in_array($role, $roles, true)) {
                return $label;
            }
        }

        return '';
    }
}

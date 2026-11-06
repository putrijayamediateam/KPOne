<?php

namespace App\Domain\HR\Records\Services;

use App\Domain\HR\Records\Models\BankDetail;
use App\Domain\HR\Records\Models\ChecklistItem;
use App\Domain\HR\Records\Models\Document;
use App\Domain\HR\Records\Models\EmploymentPeriod;
use App\Domain\HR\Records\Models\PersonalDetails;
use App\Domain\HR\Records\Models\RecordCorrection;
use App\Domain\HR\Records\Models\Worker;
use App\Domain\HR\Records\RecordPermissions;
use App\Domain\HR\Roster\RosterPermissions;
use App\Domain\HR\Roster\Services\RosterRules;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * HR-4a page props for /hr/records (My HR details, Staff files) and /hr/records/{worker}. Read only. Lists carry names,
 * branch, type and status only; sensitive values reach a manager masked, and only Show (audited) releases one.
 */
final class RecordsPageService
{
    public const PROBATION_ALERT_DAYS = 14;

    public function __construct(private RecordSupport $support) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function page(User $actor, array $query): array
    {
        $canOwn = $actor->can(RecordPermissions::VIEW_OWN);
        $canFiles = $actor->can(RecordPermissions::VIEW_ORGANISATION);
        $canManage = $actor->can(RecordPermissions::MANAGE);
        $tabs = array_keys(array_filter(['my' => $canOwn, 'files' => $canFiles]));
        $tab = in_array($query['tab'] ?? null, $tabs, true) ? (string) $query['tab'] : ($tabs[0] ?? 'my');

        return [
            'tab' => $tab,
            'tabs' => $tabs,
            'options' => $this->options($actor),
            'canManage' => $actor->can(RecordPermissions::MANAGE),
            // Your own unmasked values load only on your own tab (Suresh, M9).
            'my' => $canOwn && $tab === 'my' ? $this->my($actor) : null,
            'files' => $canFiles && $tab === 'files' ? $this->files($actor, $query) : null,
            // HR-4b settings (retention and checklist items) sit under Staff files, for managers.
            'settings' => $canManage && $tab === 'files' ? $this->settings($actor) : null,
        ];
    }

    /** @return array<string, mixed> */
    public function file(User $actor, Worker $worker, mixed $tab): array
    {
        $tab = in_array($tab, ['employment', 'personal', 'bank', 'documents', 'checklist'], true) ? $tab : 'employment';
        $today = $this->today($actor);
        $periods = EmploymentPeriod::query()->where('worker_id', $worker->id)->orderByDesc('starts_on')->get();
        $personal = PersonalDetails::query()->where('worker_id', $worker->id)->first();
        $banks = BankDetail::query()->where('worker_id', $worker->id)->whereIn('status', ['confirmed', 'waiting'])->get();
        $confirmed = $banks->firstWhere('status', 'confirmed');
        $waiting = $banks->firstWhere('status', 'waiting');
        $corrections = RecordCorrection::query()->where('worker_id', $worker->id)->where('status', 'pending')->orderBy('id')->get();
        $names = $this->names([$waiting?->entered_by_user_id, ...array_map(fn ($id): int => (int) $id, $corrections->pluck('asked_by_user_id')->all())]);
        $self = $worker->user_id === $actor->id;

        return [
            'tab' => $tab,
            'options' => $this->options($actor),
            // The same rule 3 predicate as the services, so no button invites a refusal (Suresh, S4).
            'canManage' => $actor->can(RecordPermissions::MANAGE) && $this->support->refusal($actor, $worker->user_id) === null,
            'manageRefusal' => $actor->can(RecordPermissions::MANAGE) && ! $self ? $this->support->refusal($actor, $worker->user_id) : null,
            'isSelf' => $self,
            // For "Reports to": everyone else with a staff file (names only).
            'workers' => Worker::query()->where('organisation_id', $worker->organisation_id)->whereKeyNot($worker->id)->with('user')->get()
                ->map(fn (Worker $w): array => ['id' => $w->public_id, 'name' => $w->name()])->sortBy('name')->values()->all(),
            'worker' => [...$this->header($worker, $periods, $today), 'lockVersion' => $worker->lock_version],
            'attention' => $this->attention($periods, $waiting !== null, $corrections->isNotEmpty(), $today),
            'periods' => $periods->map(fn (EmploymentPeriod $p): array => [
                'id' => $p->public_id, 'type' => $p->type, 'typeLabel' => StaffFileService::TYPES[$p->type] ?? $p->type,
                'startsOn' => $p->starts_on->toDateString(), 'probationEndsOn' => $p->probation_ends_on?->toDateString(), 'confirmedOn' => $p->confirmed_on?->toDateString(),
                'endsOn' => $p->ends_on?->toDateString(), 'endReason' => $p->end_reason, 'endReasonLabel' => $p->end_reason === null ? null : (StaffFileService::END_REASONS[$p->end_reason] ?? $p->end_reason),
                'hasNote' => $p->note_encrypted !== null, 'lockVersion' => $p->lock_version,
            ])->values()->all(),
            'positions' => DB::table('hr_positions as p')->leftJoin('departments as d', 'd.id', '=', 'p.department_id')
                ->leftJoin('hr_workers as w', 'w.id', '=', 'p.reports_to_worker_id')->leftJoin('users as u', 'u.id', '=', 'w.user_id')
                ->where('p.worker_id', $worker->id)->orderByDesc('p.from_date')
                ->get(['p.title', 'p.from_date', 'd.name as department', 'u.name as reports_user', 'w.display_name as reports_locum'])
                ->map(fn (object $r): array => ['title' => (string) $r->title, 'fromDate' => CarbonImmutable::parse((string) $r->from_date)->toDateString(),
                    'department' => $r->department, 'reportsTo' => $r->reports_user ?? $r->reports_locum])->values()->all(),
            'personal' => $this->maskedPersonal($personal),
            'bank' => [
                'confirmed' => $confirmed === null ? null : $this->maskedBank($confirmed, $names),
                'waiting' => $waiting === null ? null : [...$this->maskedBank($waiting, $names), 'enteredByMe' => $waiting->entered_by_user_id === $actor->id],
            ],
            'corrections' => $corrections->map(fn (RecordCorrection $c): array => [
                'id' => $c->public_id, 'kind' => $c->kind, 'kindLabel' => RecordCorrectionService::KINDS[$c->kind] ?? $c->kind,
                'reasonLabel' => RecordCorrectionService::REASONS[$c->reason_code] ?? $c->reason_code, 'hasNote' => $c->note_encrypted !== null,
                'recorded' => $this->recordedMask($c->kind, $personal, $confirmed), 'new' => $this->newMask($c),
                'askedBy' => $names[$c->asked_by_user_id] ?? 'Staff member', 'askedAt' => $c->created_at->toIso8601String(), 'lockVersion' => $c->lock_version,
            ])->values()->all(),
            'canOpenDocuments' => $actor->can(RecordPermissions::DOCUMENTS_VIEW),
            'documents' => $this->documents($worker, $today),
            'checklist' => $tab === 'checklist' ? $this->checklist($worker, $periods) : null,
            'changes' => DB::table('hr_record_changes as c')->join('users as u', 'u.id', '=', 'c.changed_by_user_id')->where('c.worker_id', $worker->id)
                ->orderByDesc('c.id')->limit(20)->get(['c.area', 'c.fields', 'c.reason_code', 'c.created_at', 'u.name'])
                ->map(fn (object $r): array => ['area' => (string) $r->area, 'fields' => json_decode((string) $r->fields, true), 'reason' => $r->reason_code,
                    'by' => (string) $r->name, 'at' => CarbonImmutable::parse((string) $r->created_at)->toIso8601String()])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function my(User $actor): array
    {
        $worker = Worker::query()->where('organisation_id', $actor->organisation_id)->where('user_id', $actor->id)->first();
        if ($worker === null) {
            return ['hasFile' => false];
        }
        $today = $this->today($actor);
        $periods = EmploymentPeriod::query()->where('worker_id', $worker->id)->orderByDesc('starts_on')->get();
        $personal = PersonalDetails::query()->where('worker_id', $worker->id)->first();
        $bank = BankDetail::query()->where('worker_id', $worker->id)->where('status', 'confirmed')->first();
        $position = DB::table('hr_positions as p')->leftJoin('departments as d', 'd.id', '=', 'p.department_id')->where('p.worker_id', $worker->id)
            ->whereDate('p.from_date', '<=', $today->toDateString())->orderByDesc('p.from_date')->first(['p.title', 'd.name as department']);
        $address = $personal?->address_encrypted;
        $emergency = $personal?->emergency_contact_encrypted;

        return [
            'hasFile' => true,
            'worker' => $this->header($worker, $periods, $today),
            'position' => $position === null ? null : ['title' => (string) $position->title, 'department' => $position->department],
            'periods' => $periods->map(fn (EmploymentPeriod $p): array => ['typeLabel' => StaffFileService::TYPES[$p->type] ?? $p->type, 'startsOn' => $p->starts_on->toDateString(),
                'probationEndsOn' => $p->probation_ends_on?->toDateString(), 'confirmedOn' => $p->confirmed_on?->toDateString(), 'endsOn' => $p->ends_on?->toDateString()])->values()->all(),
            // The person's own values, unmasked (HR-PLAN 11.2).
            'personal' => [
                'legalName' => $personal?->legal_name_encrypted,
                'preferredName' => $personal?->preferred_name,
                'identity' => $personal?->identity_encrypted === null ? null : ['kind' => $personal->identity_kind, 'number' => $personal->identity_encrypted, 'country' => $personal->passport_country],
                'dateOfBirth' => $personal?->date_of_birth_encrypted,
                'nationality' => $personal?->nationality,
                'address' => $address === null ? null : array_merge(['line1' => '', 'line2' => '', 'city' => '', 'state' => '', 'postcode' => '', 'country' => ''], $address),
                'personalPhone' => $personal?->personal_phone_encrypted,
                'emergencyContact' => $emergency === null ? null : array_merge(['name' => '', 'relationship' => '', 'phone' => ''], $emergency),
                'lockVersion' => $personal !== null ? $personal->lock_version : 0,
            ],
            'bank' => $bank === null ? null : ['bankName' => $bank->bank_name_encrypted, 'holder' => $bank->holder_encrypted, 'account' => $bank->account_encrypted],
            'workerId' => $worker->public_id,
            'documents' => $this->documents($worker, $today),
            'corrections' => RecordCorrection::query()->where('worker_id', $worker->id)->orderByDesc('id')->limit(20)->get()->map(fn (RecordCorrection $c): array => [
                'id' => $c->public_id, 'kind' => $c->kind, 'kindLabel' => RecordCorrectionService::KINDS[$c->kind] ?? $c->kind, 'status' => $c->status,
                'reasonLabel' => RecordCorrectionService::REASONS[$c->reason_code] ?? $c->reason_code,
                'decisionLabel' => $c->decision_reason === null ? null : (RecordCorrectionService::REJECT_REASONS[$c->decision_reason] ?? $c->decision_reason),
                'askedAt' => $c->created_at->toIso8601String(), 'lockVersion' => $c->lock_version,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function files(User $actor, array $query): array
    {
        $today = $this->today($actor);
        $workers = Worker::query()->where('organisation_id', $actor->organisation_id)->with('user.roles')->orderBy('id')->get();
        $ids = $workers->pluck('id')->all();
        $periods = EmploymentPeriod::query()->whereIn('worker_id', $ids)->orderByDesc('starts_on')->get()->groupBy('worker_id');
        $bankWaiting = BankDetail::query()->whereIn('worker_id', $ids)->where('status', 'waiting')->pluck('worker_id')->flip();
        $correctionWaiting = RecordCorrection::query()->whereIn('worker_id', $ids)->where('status', 'pending')->pluck('worker_id')->flip();
        $branches = $this->primaryBranches(array_values(array_map(fn ($id): int => (int) $id, array_filter($workers->pluck('user_id')->all()))), $today);

        $rows = $workers->map(function (Worker $w) use ($periods, $bankWaiting, $correctionWaiting, $branches, $today): array {
            /** @var Collection<int, EmploymentPeriod> $own */
            $own = $periods->get($w->id, collect());
            $branch = $w->user_id !== null ? ($branches[$w->user_id] ?? null) : null;

            return [...$this->header($w, $own, $today), 'branchId' => $branch['id'] ?? null, 'branch' => $branch['name'] ?? null,
                'attention' => $this->attention($own, $bankWaiting->has($w->id), $correctionWaiting->has($w->id), $today)];
        });

        $q = is_string($query['q'] ?? null) ? mb_strtolower(trim($query['q'])) : '';
        $branch = filter_var($query['branch'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $type = is_string($query['type'] ?? null) && array_key_exists($query['type'], StaffFileService::TYPES) ? $query['type'] : null;
        $status = in_array($query['status'] ?? null, ['active', 'probation', 'ended', 'none'], true) ? $query['status'] : null;
        $filtered = $rows->filter(fn (array $r): bool => ($q === '' || str_contains(mb_strtolower($r['name']), $q))
            && ($branch === false || $r['branchId'] === $branch) && ($type === null || $r['type'] === $type) && ($status === null || $r['status'] === $status))
            ->sortBy(fn (array $r): string => mb_strtolower($r['name']))->values();
        $withFile = $workers->pluck('user_id')->filter()->all();

        return [
            'filters' => ['q' => $q, 'branch' => $branch === false ? null : $branch, 'type' => $type, 'status' => $status],
            'rows' => $filtered->all(),
            'total' => $rows->count(),
            'attentionCount' => $rows->filter(fn (array $r): bool => $r['attention'] !== [])->count(),
            'branches' => Branch::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Branch $b): array => ['id' => $b->id, 'name' => $b->name])->all(),
            // Accounts with no staff file yet, for "Open a staff file". The TV account never has one.
            'withoutFile' => User::query()->where('organisation_id', $actor->organisation_id)->whereNotIn('id', $withFile)->where('is_active', true)
                ->whereHas('roles', fn ($r) => $r->whereIn('name', RosterPermissions::HUMAN_ROLES))->with('roles')->orderBy('name')->get(['id', 'name'])
                ->filter(fn (User $u): bool => $this->support->refusalFor($actor, $u) === null)
                ->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function options(User $actor): array
    {
        $pairs = fn (array $map): array => collect($map)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all();

        return [
            'types' => $pairs(StaffFileService::TYPES),
            'endReasons' => $pairs(StaffFileService::END_REASONS),
            'editReasons' => $pairs(StaffFileService::EDIT_REASONS),
            'correctionKinds' => $pairs(RecordCorrectionService::KINDS),
            'correctionReasons' => $pairs(RecordCorrectionService::REASONS),
            'correctionRejectReasons' => $pairs(RecordCorrectionService::REJECT_REASONS),
            'bankRejectReasons' => $pairs(BankDetailService::REJECT_REASONS),
            'documentCategories' => $pairs(DocumentService::CATEGORIES),
            'documentRemovalReasons' => $pairs(DocumentService::REMOVAL_REASONS),
            'departments' => DB::table('departments')->where('organisation_id', $actor->organisation_id)->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn (object $d): array => ['id' => (int) $d->id, 'name' => (string) $d->name])->all(),
        ];
    }

    /**
     * @param  Collection<int, EmploymentPeriod>  $periods  newest first
     * @return array<string, mixed>
     */
    private function header(Worker $worker, Collection $periods, CarbonImmutable $today): array
    {
        $latest = $this->current($periods, $today);
        $status = 'none';
        if ($latest !== null) {
            $status = $latest->ends_on !== null && $latest->ends_on->lessThan($today) ? 'ended'
                : ($this->onProbation($latest, $today) ? 'probation' : 'active');
        }

        return [
            'id' => $worker->public_id,
            'name' => $worker->name(),
            'kind' => $worker->kind,
            'role' => $this->roleLabel($worker->user),
            'type' => $latest?->type,
            'typeLabel' => $latest === null ? null : (StaffFileService::TYPES[$latest->type] ?? $latest->type),
            'status' => $status,
            'statusLabel' => ['active' => 'Active', 'probation' => 'On probation', 'ended' => 'Left', 'none' => 'No employment period'][$status],
            'probationEndsOn' => $status === 'probation' ? $latest->probation_ends_on?->toDateString() : null,
            'hasLocumPhone' => $worker->kind === 'locum' && $worker->locum_phone_encrypted !== null,
        ];
    }

    /**
     * Reasons in words (Daniel): never colour alone, never a tooltip.
     *
     * @param  Collection<int, EmploymentPeriod>  $periods
     * @return list<string>
     */
    private function attention(Collection $periods, bool $bankWaiting, bool $correctionWaiting, CarbonImmutable $today): array
    {
        $out = [];
        $latest = $this->current($periods, $today);
        if ($latest !== null && $latest->probation_ends_on !== null && $this->onProbation($latest, $today)
            && $latest->probation_ends_on->lessThanOrEqualTo($today->addDays(self::PROBATION_ALERT_DAYS))) {
            $days = (int) $today->diffInDays($latest->probation_ends_on);
            $out[] = $days === 0 ? 'Probation ends today' : ($days === 1 ? 'Probation ends tomorrow' : "Probation ends in {$days} days");
        }
        if ($bankWaiting) {
            $out[] = 'Bank change waiting';
        }
        if ($correctionWaiting) {
            $out[] = 'Correction waiting';
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> Active documents, newest first; names and dates only, never content. */
    private function documents(Worker $worker, CarbonImmutable $today): array
    {
        $docs = Document::query()->where('worker_id', $worker->id)->where('status', 'active')->orderByDesc('id')->get();
        $names = $this->names(array_map(fn ($id): int => (int) $id, $docs->pluck('uploaded_by_user_id')->all()));

        return $docs->map(fn (Document $d): array => [
            'id' => $d->public_id, 'category' => $d->category, 'categoryLabel' => DocumentService::CATEGORIES[$d->category] ?? $d->category,
            'issuedOn' => $d->issued_on?->toDateString(), 'expiresOn' => $d->expires_on?->toDateString(), 'expiry' => $this->expiry($d->expires_on, $today),
            'kind' => $d->kind, 'size' => $this->size($d->bytes), 'uploadedBy' => $names[$d->uploaded_by_user_id] ?? 'Staff member',
            'uploadedAt' => $d->created_at->toIso8601String(), 'lockVersion' => $d->lock_version,
        ])->values()->all();
    }

    /** "Expires in 30 days", "Expires today", "Expired 3 days ago" (Daniel); only within 30 days or after. */
    private function expiry(?CarbonImmutable $expires, CarbonImmutable $today): ?string
    {
        if ($expires === null) {
            return null;
        }
        $days = (int) $today->diffInDays($expires->startOfDay(), false);
        if ($days < 0) {
            return abs($days) === 1 ? 'Expired yesterday' : 'Expired '.abs($days).' days ago';
        }
        if ($days === 0) {
            return 'Expires today';
        }

        return $days <= 30 ? ($days === 1 ? 'Expires tomorrow' : "Expires in {$days} days") : null;
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1024 * 1024 ? number_format($bytes / 1024 / 1024, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';
    }

    /**
     * Each period offers the onboarding list, and the offboarding list once it has an end date. Archived items show only
     * where they were already ticked.
     *
     * @param  Collection<int, EmploymentPeriod>  $periods
     * @return array<int, array<string, mixed>>
     */
    private function checklist(Worker $worker, Collection $periods): array
    {
        $items = ChecklistItem::query()->where('organisation_id', $worker->organisation_id)->orderBy('position')->get();
        $ticks = DB::table('hr_checklist_ticks as t')->join('users as u', 'u.id', '=', 't.ticked_by_user_id')
            ->whereIn('t.period_id', $periods->pluck('id')->all())->get(['t.period_id', 't.item_id', 't.ticked_at', 'u.name'])
            ->groupBy(fn (object $t): string => $t->period_id.':'.$t->item_id);
        $list = function (EmploymentPeriod $p, string $which) use ($items, $ticks): array {
            return $items->where('list', $which)->filter(fn (ChecklistItem $i): bool => $i->is_active || $ticks->has($p->id.':'.$i->id))
                ->map(function (ChecklistItem $i) use ($p, $ticks): array {
                    $tick = $ticks->get($p->id.':'.$i->id)?->first();

                    return ['id' => $i->public_id, 'label' => $i->label, 'aboutAccount' => $i->about_account,
                        'ticked' => $tick === null ? null : ['by' => (string) $tick->name, 'at' => CarbonImmutable::parse((string) $tick->ticked_at)->toIso8601String()]];
                })->values()->all();
        };

        return $periods->map(fn (EmploymentPeriod $p): array => [
            'periodId' => $p->public_id, 'typeLabel' => StaffFileService::TYPES[$p->type] ?? $p->type, 'startsOn' => $p->starts_on->toDateString(),
            'endsOn' => $p->ends_on?->toDateString(), 'onboarding' => $list($p, 'onboarding'), 'offboarding' => $p->ends_on === null ? null : $list($p, 'offboarding'),
        ])->values()->all();
    }

    /** @return array<string, mixed> Retention per category and the checklist items (HR-4b settings). */
    private function settings(User $actor): array
    {
        $retentions = DB::table('hr_document_retentions')->where('organisation_id', $actor->organisation_id)->get()->keyBy('category');

        return [
            'retentions' => collect(DocumentService::CATEGORIES)->map(fn (string $label, string $code): array => ['category' => $code, 'label' => $label,
                'days' => isset($retentions[$code]) ? (int) $retentions[$code]->retention_days : null,
                'lockVersion' => isset($retentions[$code]) ? (int) $retentions[$code]->lock_version : 0])->values()->all(),
            'items' => ChecklistItem::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)->orderBy('position')->get()
                ->map(fn (ChecklistItem $i): array => ['id' => $i->public_id, 'list' => $i->list, 'label' => $i->label, 'aboutAccount' => $i->about_account,
                    'lockVersion' => $i->lock_version])->values()->all(),
        ];
    }

    /**
     * The period in force today, or the next one when none has started (Suresh, M4). Periods come newest first.
     *
     * @param  Collection<int, EmploymentPeriod>  $periods
     */
    private function current(Collection $periods, CarbonImmutable $today): ?EmploymentPeriod
    {
        return $periods->first(fn (EmploymentPeriod $p): bool => $p->starts_on->lessThanOrEqualTo($today)) ?? $periods->last();
    }

    private function onProbation(EmploymentPeriod $p, CarbonImmutable $today): bool
    {
        return $p->probation_ends_on !== null && $p->confirmed_on === null && $p->probation_ends_on->greaterThanOrEqualTo($today)
            && ($p->ends_on === null || $p->ends_on->greaterThanOrEqualTo($today));
    }

    /** @return array<string, mixed> */
    private function maskedPersonal(?PersonalDetails $p): array
    {
        $identity = null;
        if ($p?->identity_kind !== null) {
            $identity = ['kind' => $p->identity_kind, 'last4' => $p->identity_last4, 'country' => $p->passport_country,
                'mask' => ($p->identity_kind === 'ic' ? 'IC •••• •••• ' : 'Passport •••• ').$p->identity_last4];
        }

        return [
            'legalName' => $p?->legal_name_encrypted !== null,
            'preferredName' => $p?->preferred_name,
            'identity' => $identity,
            'dateOfBirth' => $p?->date_of_birth_encrypted !== null,
            'nationality' => $p?->nationality,
            'address' => $p?->address_encrypted !== null,
            'personalPhone' => $p?->personal_phone_encrypted !== null,
            'emergencyContact' => $p?->emergency_contact_encrypted !== null,
            'lockVersion' => $p !== null ? $p->lock_version : 0,
        ];
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function maskedBank(BankDetail $bank, array $names): array
    {
        return ['id' => $bank->public_id, 'bankName' => $bank->bank_name_encrypted, 'mask' => 'Account •••• '.$bank->account_last4,
            'enteredBy' => $names[$bank->entered_by_user_id] ?? 'Staff member', 'enteredAt' => $bank->created_at->toIso8601String(), 'lockVersion' => $bank->lock_version];
    }

    private function recordedMask(string $kind, ?PersonalDetails $p, ?BankDetail $bank): string
    {
        return match ($kind) {
            'identity' => $p?->identity_kind === null ? 'Not given' : ($p->identity_kind === 'ic' ? 'IC •••• •••• ' : 'Passport •••• ').$p->identity_last4,
            'bank' => $bank === null ? 'Not given' : $bank->bank_name_encrypted.' •••• '.$bank->account_last4,
            'nationality' => $p !== null ? ($p->nationality ?? 'Not given') : 'Not given',
            'legal_name' => $p?->legal_name_encrypted === null ? 'Not given' : 'Hidden',
            default => $p?->date_of_birth_encrypted === null ? 'Not given' : 'Hidden',
        };
    }

    private function newMask(RecordCorrection $c): string
    {
        $v = $c->new_value_encrypted;

        return match ($c->kind) {
            'identity' => (($v['kind'] ?? '') === 'ic' ? 'IC •••• •••• ' : 'Passport •••• ').RecordSupport::last4((string) ($v['number'] ?? '')),
            'bank' => ($v['bank_name'] ?? '').' •••• '.RecordSupport::last4((string) ($v['account'] ?? '')),
            'nationality' => (string) ($v['value'] ?? ''),
            default => 'Hidden',
        };
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, array{id: int, name: string}>
     */
    private function primaryBranches(array $userIds, CarbonImmutable $today): array
    {
        if ($userIds === []) {
            return [];
        }
        $day = $today->toDateString();
        $out = [];
        DB::table('staff_branch_assignments as a')->join('staff_profiles as p', 'p.id', '=', 'a.staff_profile_id')->join('branches as b', 'b.id', '=', 'a.branch_id')
            ->whereIn('p.user_id', $userIds)->where('a.is_primary', true)->whereDate('a.valid_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('a.valid_until')->orWhereDate('a.valid_until', '>=', $day))
            ->orderBy('a.valid_from')->get(['p.user_id', 'b.id', 'b.name'])
            ->each(function (object $r) use (&$out): void {
                $out[(int) $r->user_id] = ['id' => (int) $r->id, 'name' => (string) $r->name];
            });

        return $out;
    }

    /**
     * @param  array<int, int|null>  $ids
     * @return array<int, string>
     */
    private function names(array $ids): array
    {
        $ids = array_values(array_filter($ids, fn ($id): bool => is_int($id)));

        return $ids === [] ? [] : User::query()->whereIn('id', $ids)->pluck('name', 'id')->map(fn ($n): string => (string) $n)->all();
    }

    private function roleLabel(?User $user): string
    {
        if ($user === null) {
            return 'Locum';
        }
        $roles = $user->roles->pluck('name')->all();
        foreach (array_keys(RosterRules::ROLE_LABELS) as $role) {
            if (in_array($role, $roles, true)) {
                return RosterRules::ROLE_LABELS[$role];
            }
        }

        return '';
    }

    private function today(User $actor): CarbonImmutable
    {
        $zone = Branch::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)->orderBy('id')->value('timezone');

        return CarbonImmutable::now(is_string($zone) ? $zone : 'UTC')->startOfDay();
    }
}

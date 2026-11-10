<?php

namespace App\Domain\HR\Applications\Services;

use App\Domain\HR\Applications\ApplicationPermissions;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * HR-13 page props for /hr/applications, with HR-17a's position types, form answers, phone screening and call
 * checklist (Director, HR Manager): the applications (25 a page, newest first, filtered by
 * step and position), the positions and the retention setting. Read only. A purged application shows its step and
 * dates only. The public form's state (on or off, notice version) is shown so HR knows whether anyone can apply.
 */
final class ApplicationsPageService
{
    public const PER_PAGE = 25;

    /** "Needs you" lists at most this many rows per reason; the count is always the full number. */
    public const NEEDS_YOU_ROWS = 8;

    /** @var array<string, string> the words for a message's outcome on the HR view */
    public const MESSAGE_STATUSES = [
        'sending' => 'Sending',
        'sent' => 'Emailed',
        'failed' => 'Email failed: shown in KPOne only',
        'off' => 'Email off: shown in KPOne only',
    ];

    public function __construct(private HiringDeadlines $deadlines) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function page(User $actor, array $query): array
    {
        abort_unless($actor->can(ApplicationPermissions::MANAGE), 403);
        $tabs = ['applications', 'positions', 'settings'];
        $tab = in_array($query['tab'] ?? null, $tabs, true) ? (string) $query['tab'] : 'applications';
        $zone = JobApplicationService::zone($actor->organisation_id);
        $positions = DB::table('hr_job_positions as p')->leftJoin('branches as b', 'b.id', '=', 'p.branch_id')
            ->where('p.organisation_id', $actor->organisation_id)
            ->orderByDesc('p.is_open')->orderBy('p.title')->orderBy('p.id')
            ->get(['p.id', 'p.public_id', 'p.title', 'p.position_type', 'p.branch_id', 'b.name as branch', 'p.is_open', 'p.lock_version']);
        $open = implode(', ', array_map(fn (string $s): string => "'{$s}'", JobApplicationService::OPEN));
        $counts = DB::table('hr_job_applications')->where('organisation_id', $actor->organisation_id)
            ->selectRaw("position_id, count(*) filter (where status in ({$open})) as open_count, count(*) as total")
            ->groupBy('position_id')->get()->keyBy('position_id');

        return [
            'tab' => $tab,
            'tabs' => $tabs,
            'form' => [
                'enabled' => (bool) config('hr-applications.enabled', false),
                'state' => JobApplicationIntake::state(),
                'url' => route('hr-applications.show'),
                'privacyNoticeVersion' => JobApplicationIntake::privacyVersion(),
            ],
            'options' => [
                'statuses' => collect(JobApplicationService::STATUSES)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
                'branches' => DB::table('branches')->where('organisation_id', $actor->organisation_id)->where('is_active', true)->orderBy('name')
                    ->get(['id', 'name'])->map(fn (object $b): array => ['value' => (string) $b->id, 'label' => (string) $b->name])->values()->all(),
                'positions' => $positions->map(fn (object $p): array => ['value' => (string) $p->public_id, 'label' => (string) $p->title])->values()->all(),
                'graceDays' => JobApplicationService::GRACE_DAYS,
                'rejectReasons' => collect(JobApplicationService::REJECT_REASONS)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
                'types' => collect(JobApplicationService::TYPES)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
            ],
            'positions' => $positions->map(fn (object $p): array => [
                'id' => (string) $p->public_id,
                'title' => (string) $p->title,
                'type' => (string) $p->position_type,
                'typeLabel' => JobApplicationService::TYPES[$p->position_type] ?? (string) $p->position_type,
                'branchId' => $p->branch_id === null ? null : (string) $p->branch_id,
                'branch' => $p->branch === null ? null : (string) $p->branch,
                'isOpen' => (bool) $p->is_open,
                'openApplications' => (int) ($counts[$p->id]->open_count ?? 0),
                'applications' => (int) ($counts[$p->id]->total ?? 0),
                'lockVersion' => (int) $p->lock_version,
            ])->values()->all(),
            'applications' => $tab === 'applications' ? $this->applications($actor, $query, $zone) : null,
            'settings' => $this->settings($actor, $zone),
            'checklist' => $this->checklist($actor),
            'needsYou' => $this->needsYou($actor, $zone),
            'email' => ['enabled' => HiringMessenger::emailEnabled(), 'mailbox' => HiringMessenger::mailbox() !== null],
        ];
    }

    /**
     * HR-17b "Needs you" (HR-PLAN 19.9): new applications, and those the daily check closes at the start of the next
     * working day. Interview slots and interviews today come with HR-17c. Each row says why it is listed.
     *
     * @return array<string, mixed>
     */
    private function needsYou(User $actor, string $zone): array
    {
        $waiting = DB::table('hr_job_applications as a')->join('hr_job_positions as p', 'p.id', '=', 'a.position_id')
            ->where('a.organisation_id', $actor->organisation_id)->whereIn('a.status', HiringDeadlines::WAITING)->whereNull('a.purged_at')
            ->orderBy('a.stage_entered_at')->orderBy('a.id')->limit(500)
            ->get(['a.id', 'a.public_id', 'a.organisation_id', 'a.position_id', 'a.status', 'a.stage_entered_at', 'a.name_encrypted', 'p.title', 'p.branch_id']);
        $row = fn (\stdClass $a, string $why): array => ['id' => (string) $a->public_id, 'status' => (string) $a->status, 'name' => self::plain($a->name_encrypted) ?? 'Applicant',
            'position' => (string) $a->title, 'why' => $why];
        $new = [];
        $closing = [];
        $noDays = [];
        foreach ($waiting as $a) {
            $deadlines = $this->deadlines->deadlines($a);
            $today = $this->deadlines->today($a);
            if ($deadlines === null) {
                $noDays[(string) ($a->branch_id ?? 'any')] = true;
            } elseif ($today !== null && $today >= $deadlines['remind']) {
                $closing[] = $row($a, $a->status === 'new'
                    ? 'No first look yet: closes as Auto-rejected at the start of the next working day.'
                    : 'No call recorded yet: closes as Auto-rejected at the start of the next working day.');

                continue;
            }
            if ($a->status === 'new') {
                $new[] = $row($a, 'New application, sent '.self::local($a->stage_entered_at, $zone).'.');
            }
        }

        return [
            'closing' => ['count' => count($closing), 'rows' => array_slice($closing, 0, self::NEEDS_YOU_ROWS)],
            'new' => ['count' => count($new), 'rows' => array_slice($new, 0, self::NEEDS_YOU_ROWS)],
            // A branch with no working days set never auto-rejects (HR-PLAN 19.13).
            'noWorkingDays' => $noDays !== [],
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function applications(User $actor, array $query, string $zone): array
    {
        $status = is_string($query['status'] ?? null) && (isset(JobApplicationService::STATUSES[$query['status']]) || $query['status'] === 'open') ? $query['status'] : 'open';
        $position = is_string($query['position'] ?? null) ? $query['position'] : '';
        // "Needs you" opens one application: the list shows just that one (HR-17b).
        $focus = is_string($query['focus'] ?? null) && preg_match('/\A[0-9a-f-]{36}\z/', $query['focus']) === 1 ? $query['focus'] : '';
        $page = max(1, (int) filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['default' => 1]]));

        $base = DB::table('hr_job_applications as a')->join('hr_job_positions as p', 'p.id', '=', 'a.position_id')
            ->where('a.organisation_id', $actor->organisation_id)
            ->when($focus !== '', fn ($q) => $q->where('a.public_id', $focus))
            ->when($focus === '' && $status === 'open', fn ($q) => $q->whereIn('a.status', JobApplicationService::OPEN))
            ->when($focus === '' && $status !== 'open', fn ($q) => $q->where('a.status', $status))
            ->when($position !== '', fn ($q) => $q->where('p.public_id', $position));
        $total = (clone $base)->count();
        $rows = $base->orderByDesc('a.submitted_at')->orderByDesc('a.id')->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)
            ->get(['a.id', 'a.public_id', 'a.status', 'a.name_encrypted', 'a.phone_encrypted', 'a.email_encrypted', 'a.message_encrypted', 'a.details_encrypted',
                'a.cv_path', 'a.cv_bytes', 'a.privacy_notice_version', 'a.submitted_at', 'a.closed_at', 'a.purged_at', 'a.lock_version',
                'p.title', 'p.position_type', 'a.organisation_id', 'a.position_id', 'a.stage_entered_at']);
        $messages = DB::table('hr_job_application_messages')->whereIn('application_id', $rows->pluck('id'))->orderByDesc('created_at')->orderByDesc('id')
            ->get()->groupBy('application_id');
        $screenings = DB::table('hr_job_screenings as s')->leftJoin('users as u', 'u.id', '=', 's.actor_user_id')
            ->whereIn('s.application_id', $rows->pluck('id'))->get(['s.application_id', 's.checklist_version', 's.ticked', 's.rating', 's.note_encrypted', 's.outcome', 's.reject_reason', 's.created_at', 'u.name'])
            ->keyBy('application_id');
        $lists = DB::table('hr_job_screening_checklists')->where('organisation_id', $actor->organisation_id)->get(['version', 'items'])->keyBy('version');
        $branches = collect(JobApplicationIntake::branches($actor->organisation_id))->pluck('label', 'value');

        return [
            'filters' => ['status' => $status, 'position' => $position, 'focus' => $focus],
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'total' => $total,
            'rows' => $rows->map(fn (\stdClass $a): array => [
                'id' => (string) $a->public_id,
                'position' => (string) $a->title,
                'status' => (string) $a->status,
                'statusLabel' => JobApplicationService::STATUSES[$a->status] ?? (string) $a->status,
                'next' => collect(JobApplicationService::NEXT[$a->status] ?? [])->map(fn (string $s): array => ['value' => $s, 'label' => JobApplicationService::STATUSES[$s]])->values()->all(),
                'name' => self::plain($a->name_encrypted),
                'phone' => self::plain($a->phone_encrypted),
                'email' => self::plain($a->email_encrypted),
                'message' => self::plain($a->message_encrypted),
                'type' => (string) $a->position_type,
                'details' => self::details(self::plain($a->details_encrypted), $branches->all()),
                'screening' => isset($screenings[$a->id]) ? self::screening($screenings[$a->id], $lists->all(), $zone) : null,
                'messages' => array_map(fn (\stdClass $m): array => [...HiringMessenger::shown($m, (string) $a->public_id, HiringMessenger::positionTitle((int) $a->position_id)),
                    'statusLabel' => self::MESSAGE_STATUSES[$m->status] ?? (string) $m->status, 'atLabel' => self::local($m->created_at, $zone)],
                    ($messages[$a->id] ?? collect())->all()),
                'autoRejectOn' => $this->autoRejectOn($a),
                'hasCv' => $a->cv_path !== null,
                'cvKb' => (int) ceil((int) $a->cv_bytes / 1024),
                'privacyNoticeVersion' => (string) $a->privacy_notice_version,
                'submittedAt' => self::local($a->submitted_at, $zone),
                'closedAt' => $a->closed_at === null ? null : self::local($a->closed_at, $zone),
                'purged' => $a->purged_at !== null,
                'lockVersion' => (int) $a->lock_version,
            ])->values()->all(),
        ];
    }

    /** The branch-local date the daily check closes a waiting application, as words, or null. */
    private function autoRejectOn(\stdClass $a): ?string
    {
        if (! in_array($a->status, HiringDeadlines::WAITING, true) || $a->purged_at !== null) {
            return null;
        }
        $deadlines = $this->deadlines->deadlines($a);

        return $deadlines === null ? null : CarbonImmutable::parse($deadlines['reject'])->format('D j M Y');
    }

    /** @return array<string, mixed> */
    private function settings(User $actor, string $zone): array
    {
        $today = JobApplicationService::today($actor->organisation_id);
        $latest = DB::table('hr_job_application_settings')->where('organisation_id', $actor->organisation_id)->orderByDesc('version')->first();
        $inForce = JobApplicationService::retentionOn($actor->organisation_id, $today);

        return [
            'version' => $latest === null ? 0 : (int) $latest->version,
            'retentionDays' => $inForce,
            'pending' => $latest !== null && (string) $latest->effective_from > $today
                ? ['days' => $latest->retention_days === null ? null : (int) $latest->retention_days, 'from' => CarbonImmutable::parse((string) $latest->effective_from)->format('j M Y')]
                : null,
        ];
    }

    /** @return array<string, mixed> */
    private function checklist(User $actor): array
    {
        $latest = DB::table('hr_job_screening_checklists')->where('organisation_id', $actor->organisation_id)->orderByDesc('version')->first();

        return ['version' => $latest === null ? 0 : (int) $latest->version, 'items' => $latest === null ? [] : array_values((array) json_decode((string) $latest->items, true))];
    }

    /**
     * The form's other answers as labelled lines for HR, in the order the form asks them.
     *
     * @param  array<string, string>  $branches
     * @return list<array{label:string, value:string}>
     */
    private static function details(?string $json, array $branches): array
    {
        $d = $json === null ? null : json_decode($json, true);
        if (! is_array($d)) {
            return [];
        }
        $date = fn (mixed $v): string => is_string($v) && $v !== '' ? CarbonImmutable::parse($v)->format('j M Y') : '—';
        $lines = [
            ['Preferred branch', ($d['preferred_branch'] ?? '') === 'any' ? 'Any branch' : (string) ($branches[$d['preferred_branch'] ?? ''] ?? ($d['preferred_branch'] ?? '—'))],
            ['Earliest start', $date($d['start_date'] ?? null)],
            ['Highest education', strtok((string) (JobApplicationIntake::EDUCATION[$d['education'] ?? ''] ?? '—'), '/') ?: '—'],
            ['Years of relevant work', isset($d['years_experience']) ? (string) $d['years_experience'] : '—'],
            ['Languages', implode(', ', array_map(fn (string $l): string => trim((string) strtok(JobApplicationIntake::LANGUAGES[$l] ?? $l, '/')), (array) ($d['languages'] ?? [])))],
        ];
        $extra = match ($d['type'] ?? 'ca') {
            'intern' => [['Institution', (string) ($d['institution'] ?? '')], ['Programme', (string) ($d['programme'] ?? '')],
                ['Internship', $date($d['intern_from'] ?? null).' to '.$date($d['intern_to'] ?? null)]],
            'locum' => [['Usually available', (string) ($d['availability'] ?? '')]],
            'resident_doctor' => [['MMC number (as stated)', (string) ($d['mmc_number'] ?? '')], ['APC year', (string) ($d['apc_year'] ?? '')],
                ['APC expires', $date($d['apc_expiry'] ?? null)]],
            default => [],
        };

        return array_map(fn (array $l): array => ['label' => $l[0], 'value' => $l[1] === '' ? '—' : $l[1]], [...$lines, ...$extra]);
    }

    /**
     * @param  array<int|string, \stdClass>  $lists
     * @return array<string, mixed>
     */
    private static function screening(\stdClass $s, array $lists, string $zone): array
    {
        $items = isset($lists[$s->checklist_version]) ? (array) json_decode((string) $lists[$s->checklist_version]->items, true) : [];
        $ticked = (array) json_decode((string) $s->ticked, true);

        return [
            'outcome' => (string) $s->outcome,
            'reason' => $s->reject_reason === null ? null : (JobApplicationService::REJECT_REASONS[$s->reject_reason] ?? (string) $s->reject_reason),
            'rating' => (int) $s->rating,
            'note' => self::plain($s->note_encrypted),
            'items' => array_map(fn (int $i, mixed $text): array => ['text' => (string) $text, 'ticked' => in_array($i, $ticked, true)], array_keys($items), $items),
            'by' => $s->name === null ? 'Staff member' : (string) $s->name,
            'at' => self::local($s->created_at, $zone),
        ];
    }

    private static function plain(mixed $encrypted): ?string
    {
        if (! is_string($encrypted)) {
            return null;
        }
        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            return null;
        }
    }

    private static function local(mixed $at, string $zone): string
    {
        return CarbonImmutable::parse((string) $at)->setTimezone($zone)->format('j M Y, g:i A');
    }
}

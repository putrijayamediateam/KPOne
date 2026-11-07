<?php

namespace App\Domain\HR\Records\Services;

use App\Domain\HR\Records\Models\EmploymentPeriod;
use App\Domain\HR\Records\Models\Worker;
use App\Domain\HR\Records\RecordPermissions;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Shared\Services\ReportCsv;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR-12 export for an outside HR product (HR-PLAN 15.1; the owner's "start the HR-12 build" and answers, 2026-11-07).
 * There is no named product or API yet, so KPOne gives a generic CSV, one way: the staff list, approved leave and
 * approved overtime. Never an identity number, bank detail, document, salary or reason. The Director or the HR Manager
 * downloads it; each download is audited (kind, month and row count) before its bytes are returned, and no file is
 * stored. Cells are formula-safe and the 5,000-row cap refuses rather than cuts (ReportCsv).
 */
final class OutsideHrExportService
{
    public const KINDS = ['staff' => 'Staff list', 'leave' => 'Approved leave', 'overtime' => 'Approved overtime'];

    public const DAY_TYPES = ['normal' => 'Normal day', 'rest_day' => 'Rest day', 'public_holiday' => 'Public holiday'];

    public function __construct(private RecordSupport $support) {}

    /**
     * @param  array<string, mixed>  $input  `month` (YYYY-MM) for leave and overtime
     * @return array{name: string, csv: string}
     */
    public function download(User $actor, string $kind, array $input): array
    {
        if (! array_key_exists($kind, self::KINDS)) {
            abort(404);
        }
        $month = null;
        if ($kind !== 'staff') {
            $raw = $input['month'] ?? null;
            if (! is_string($raw) || preg_match('/\A(20\d\d)-(0[1-9]|1[0-2])\z/', $raw) !== 1) {
                throw ValidationException::withMessages(['month' => 'Choose a month.']);
            }
            $month = CarbonImmutable::parse($raw.'-01')->startOfMonth();
        }

        return DB::transaction(function () use ($actor, $kind, $month): array {
            $actor = $this->support->lock($actor, RecordPermissions::MANAGE);
            $today = $this->today($actor);
            $period = $month ?? $today;
            [$headings, $rows] = match ($kind) {
                'staff' => $this->staff($actor, $today),
                'leave' => $this->leave($actor, $period),
                default => $this->overtime($actor, $period),
            };
            $title = $kind === 'staff' ? 'As of '.$today->format('j M Y') : $period->format('F Y');
            $csv = ReportCsv::encode($headings, $rows, [
                ['KPOne HR export', self::KINDS[$kind].' · '.$title],
                ['Note', 'Synthetic data only until the PDPA review. No identity numbers, bank details or documents.'],
            ]);
            // Audited before the bytes leave, with no names or values (HR-PLAN 15.1).
            $this->support->audit->record('hr.outside_export.downloaded', null, ['kind' => $kind, 'month' => $month?->format('Y-m'), 'rows' => count($rows)],
                $actor, null, $actor->organisation_id);

            return ['name' => 'kpone-hr-'.$kind.($month === null ? '-'.$today->format('Y-m-d') : '-'.$month->format('Y-m')).'.csv', 'csv' => $csv];
        });
    }

    /** @return array{0: list<string>, 1: list<list<string>>} */
    private function staff(User $actor, CarbonImmutable $today): array
    {
        $workers = Worker::query()->where('organisation_id', $actor->organisation_id)->with('user')->orderBy('id')->get();
        $ids = $workers->pluck('id')->all();
        $periods = EmploymentPeriod::query()->whereIn('worker_id', $ids)->orderByDesc('starts_on')->get()->groupBy('worker_id');
        $positions = DB::table('hr_positions')->whereIn('worker_id', $ids)->where('from_date', '<=', $today->toDateString())
            ->orderByDesc('from_date')->get(['worker_id', 'title'])->groupBy('worker_id');
        $branches = $this->primaryBranches(array_values(array_map('intval', array_filter($workers->pluck('user_id')->all()))), $today);
        $date = fn (?CarbonImmutable $d): string => $d === null ? '' : $d->toDateString();

        $rows = $workers->map(function (Worker $w) use ($periods, $positions, $branches, $today, $date): array {
            /** @var EmploymentPeriod|null $p */
            $p = $periods->get($w->id, collect())->first(fn (EmploymentPeriod $p): bool => $p->starts_on->lessThanOrEqualTo($today))
                ?? $periods->get($w->id, collect())->last();
            $status = $p === null ? 'No employment period' : ($p->starts_on->greaterThan($today) ? 'Starting'
                : ($p->ends_on !== null && $p->ends_on->lessThan($today) ? 'Ended' : 'Active'));

            return [(string) $w->public_id, $w->name(), $w->kind === 'locum' ? 'Locum' : 'Staff', (string) ($positions->get($w->id)?->first()->title ?? ''),
                $p === null ? '' : (StaffFileService::TYPES[$p->type] ?? (string) $p->type),
                $date($p?->starts_on === null ? null : CarbonImmutable::parse($p->starts_on)), $date($p?->probation_ends_on === null ? null : CarbonImmutable::parse($p->probation_ends_on)),
                $date($p?->confirmed_on === null ? null : CarbonImmutable::parse($p->confirmed_on)), $date($p?->ends_on === null ? null : CarbonImmutable::parse($p->ends_on)),
                $w->user_id === null ? '' : ($branches[(int) $w->user_id] ?? ''), $status];
        })->sortBy(fn (array $r): string => mb_strtolower($r[1]))->values()->all();

        return [['KPOne ID', 'Name', 'Kind', 'Position', 'Employment type', 'Starts on', 'Probation ends on', 'Confirmed on', 'Ends on', 'Primary branch', 'Status'],
            array_values($rows)];
    }

    /** @return array{0: list<string>, 1: list<list<string>>} approved leave overlapping the month, never its reason */
    private function leave(User $actor, CarbonImmutable $month): array
    {
        $rows = DB::table('hr_leave_requests as r')->join('hr_leave_types as t', 't.id', '=', 'r.leave_type_id')->join('users as u', 'u.id', '=', 'r.user_id')
            ->leftJoin('hr_workers as w', 'w.user_id', '=', 'r.user_id')
            ->where('r.organisation_id', $actor->organisation_id)->where('r.status', 'approved')
            ->where('r.start_date', '<=', $month->endOfMonth()->toDateString())->where('r.end_date', '>=', $month->toDateString())
            ->orderBy('r.start_date')->orderBy('r.id')
            ->get(['w.public_id', 'u.name', 't.name as type', 't.is_paid', 'r.start_date', 'r.end_date', 'r.start_part', 'r.end_part', 'r.halves'])
            ->map(fn (\stdClass $r): array => [(string) ($r->public_id ?? ''), (string) $r->name, (string) $r->type,
                CarbonImmutable::parse((string) $r->start_date)->toDateString().($r->start_part === 'full' ? '' : ' ('.$r->start_part.')'),
                CarbonImmutable::parse((string) $r->end_date)->toDateString().($r->end_part === 'full' ? '' : ' ('.$r->end_part.')'),
                intdiv((int) $r->halves, 2).((int) $r->halves % 2 === 1 ? '.5' : ''), $r->is_paid ? 'Yes' : 'No'])->values()->all();
        $rows = array_values($rows);

        return [['KPOne ID', 'Name', 'Leave type', 'From', 'To', 'Days', 'Paid'], $rows];
    }

    /** @return array{0: list<string>, 1: list<list<string>>} approved overtime in the month, minutes only, never pay */
    private function overtime(User $actor, CarbonImmutable $month): array
    {
        $rows = DB::table('hr_overtime_requests as o')->join('users as u', 'u.id', '=', 'o.user_id')->leftJoin('hr_workers as w', 'w.user_id', '=', 'o.user_id')
            ->where('o.organisation_id', $actor->organisation_id)->where('o.status', 'approved')
            ->whereBetween('o.local_date', [$month->toDateString(), $month->endOfMonth()->toDateString()])
            ->orderBy('o.local_date')->orderBy('o.id')->get(['w.public_id', 'u.name', 'o.local_date', 'o.day_type', 'o.minutes_approved'])
            ->map(fn (\stdClass $o): array => [(string) ($o->public_id ?? ''), (string) $o->name, CarbonImmutable::parse((string) $o->local_date)->toDateString(),
                self::DAY_TYPES[$o->day_type] ?? (string) $o->day_type, (string) (int) $o->minutes_approved])->values()->all();
        $rows = array_values($rows);

        return [['KPOne ID', 'Name', 'Date', 'Day type', 'Approved minutes'], $rows];
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, string>
     */
    private function primaryBranches(array $userIds, CarbonImmutable $today): array
    {
        if ($userIds === []) {
            return [];
        }
        $day = $today->toDateString();
        $out = [];
        foreach (DB::table('staff_branch_assignments as a')->join('staff_profiles as p', 'p.id', '=', 'a.staff_profile_id')->join('branches as b', 'b.id', '=', 'a.branch_id')
            ->whereIn('p.user_id', $userIds)->where('a.is_primary', true)->whereDate('a.valid_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('a.valid_until')->orWhereDate('a.valid_until', '>=', $day))->orderBy('a.valid_from')->get(['p.user_id', 'b.name']) as $r) {
            $out[(int) $r->user_id] = (string) $r->name;
        }

        return $out;
    }

    private function today(User $actor): CarbonImmutable
    {
        $zone = Branch::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)->orderBy('id')->value('timezone');

        return CarbonImmutable::now(is_string($zone) ? $zone : 'UTC')->startOfDay();
    }
}

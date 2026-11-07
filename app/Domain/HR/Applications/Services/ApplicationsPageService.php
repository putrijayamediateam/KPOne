<?php

namespace App\Domain\HR\Applications\Services;

use App\Domain\HR\Applications\ApplicationPermissions;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * HR-13 page props for /hr/applications (Director, HR Manager): the applications (25 a page, newest first, filtered by
 * step and position), the positions and the retention setting. Read only. A purged application shows its step and
 * dates only. The public form's state (on or off, notice version) is shown so HR knows whether anyone can apply.
 */
final class ApplicationsPageService
{
    public const PER_PAGE = 25;

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
            ->get(['p.id', 'p.public_id', 'p.title', 'p.branch_id', 'b.name as branch', 'p.is_open', 'p.lock_version']);
        $counts = DB::table('hr_job_applications')->where('organisation_id', $actor->organisation_id)
            ->selectRaw("position_id, count(*) filter (where status in ('new', 'shortlisted', 'interview')) as open_count, count(*) as total")
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
            ],
            'positions' => $positions->map(fn (object $p): array => [
                'id' => (string) $p->public_id,
                'title' => (string) $p->title,
                'branchId' => $p->branch_id === null ? null : (string) $p->branch_id,
                'branch' => $p->branch === null ? null : (string) $p->branch,
                'isOpen' => (bool) $p->is_open,
                'openApplications' => (int) ($counts[$p->id]->open_count ?? 0),
                'applications' => (int) ($counts[$p->id]->total ?? 0),
                'lockVersion' => (int) $p->lock_version,
            ])->values()->all(),
            'applications' => $tab === 'applications' ? $this->applications($actor, $query, $zone) : null,
            'settings' => $this->settings($actor, $zone),
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
        $page = max(1, (int) filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['default' => 1]]));

        $base = DB::table('hr_job_applications as a')->join('hr_job_positions as p', 'p.id', '=', 'a.position_id')
            ->where('a.organisation_id', $actor->organisation_id)
            ->when($status === 'open', fn ($q) => $q->whereIn('a.status', JobApplicationService::OPEN))
            ->when($status !== 'open', fn ($q) => $q->where('a.status', $status))
            ->when($position !== '', fn ($q) => $q->where('p.public_id', $position));
        $total = (clone $base)->count();
        $rows = $base->orderByDesc('a.submitted_at')->orderByDesc('a.id')->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)
            ->get(['a.public_id', 'a.status', 'a.name_encrypted', 'a.phone_encrypted', 'a.email_encrypted', 'a.message_encrypted', 'a.cv_path',
                'a.cv_bytes', 'a.privacy_notice_version', 'a.submitted_at', 'a.closed_at', 'a.purged_at', 'a.lock_version', 'p.title']);

        return [
            'filters' => ['status' => $status, 'position' => $position],
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'total' => $total,
            'rows' => $rows->map(fn (object $a): array => [
                'id' => (string) $a->public_id,
                'position' => (string) $a->title,
                'status' => (string) $a->status,
                'statusLabel' => JobApplicationService::STATUSES[$a->status] ?? (string) $a->status,
                'next' => collect(JobApplicationService::NEXT[$a->status] ?? [])->map(fn (string $s): array => ['value' => $s, 'label' => JobApplicationService::STATUSES[$s]])->values()->all(),
                'name' => self::plain($a->name_encrypted),
                'phone' => self::plain($a->phone_encrypted),
                'email' => self::plain($a->email_encrypted),
                'message' => self::plain($a->message_encrypted),
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

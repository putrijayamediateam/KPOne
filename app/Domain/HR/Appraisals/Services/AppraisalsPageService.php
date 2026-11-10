<?php

namespace App\Domain\HR\Appraisals\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\Appraisals\AppraisalPermissions;
use App\Domain\HR\Records\Models\Worker;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * HR-10b page props. /hr/appraisals: My appraisals (sent and signed-off, for the person), and Cycles and Criteria for
 * the Director and HR Manager. /hr/appraisals/{id}: one appraisal, for its manager or, once sent, its person. Lists
 * carry no rating or comment; those are returned only on the appraisal's own page.
 */
final class AppraisalsPageService
{
    public const STATUS = ['draft' => 'Not sent yet', 'sent' => 'Sent', 'signed_off' => 'Signed off'];

    public function __construct(private AppraisalService $appraisals, private AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function page(User $actor, array $query): array
    {
        $canManage = $actor->can(AppraisalPermissions::MANAGE);
        $tabs = array_values(array_filter([$actor->can(AppraisalPermissions::VIEW_OWN) ? 'mine' : null, $canManage ? 'cycles' : null, $canManage ? 'criteria' : null]));
        $tab = is_string($query['tab'] ?? null) && in_array($query['tab'], $tabs, true) ? $query['tab'] : ($canManage ? 'cycles' : ($tabs[0] ?? 'mine'));
        $zone = AppraisalService::zone((int) $actor->organisation_id);
        $criteria = DB::table('hr_appraisal_criteria_versions')->where('organisation_id', $actor->organisation_id)->orderByDesc('version')->first();

        return [
            'tab' => $tab,
            'tabs' => $tabs,
            'canManage' => $canManage,
            'mine' => $actor->can(AppraisalPermissions::VIEW_OWN) ? $this->mine($actor, $zone) : [],
            'criteria' => $canManage && $criteria !== null ? ['version' => (int) $criteria->version, 'items' => json_decode((string) $criteria->criteria, true) ?: []] : null,
            'maxCriteria' => AppraisalService::MAX_CRITERIA,
            'cycles' => $canManage ? $this->cycles($actor) : [],
            'cycle' => $canManage ? $this->cycle($actor, $query['cycle'] ?? null) : null,
            'workers' => $canManage ? $this->workers($actor) : [],
            'today' => CarbonImmutable::now($zone)->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    public function appraisal(User $actor, string $appraisalId): array
    {
        abort_if(! Str::isUuid($appraisalId), 404);
        $a = DB::table('hr_appraisals')->where('organisation_id', $actor->organisation_id)->where('public_id', $appraisalId)->first();
        abort_if(! $a instanceof \stdClass, 404);
        $own = (int) $a->user_id === $actor->id && $actor->can(AppraisalPermissions::VIEW_OWN);
        $refusal = null;
        if ($own) {
            abort_if($a->status === 'draft', 404); // a draft is the manager's until it is sent
        } else {
            abort_unless($actor->can(AppraisalPermissions::MANAGE), 404);
            $subject = User::query()->whereKey($a->user_id)->first();
            $refusal = $subject === null ? 'This appraisal cannot be opened.' : $this->appraisals->refusalFor($actor, $subject);
            if ($refusal !== null) {
                throw new AuthorizationException($refusal);
            }
        }
        $cycle = DB::table('hr_appraisal_cycles')->where('id', $a->cycle_id)->first();
        abort_if(! $cycle instanceof \stdClass, 404);
        // Ratings, comments and the summary are sensitive: every open is recorded, ids only (Suresh m3).
        $this->audit->record('hr.appraisals.opened', null, ['appraisal_id' => (int) $a->id, 'own' => $own], $actor, null, $actor->organisation_id);
        $criteria = AppraisalService::criteriaOf((int) $cycle->criteria_version_id);
        $zone = AppraisalService::zone((int) $actor->organisation_id);
        $names = DB::table('users')->whereIn('id', array_filter([$a->user_id, $a->appraiser_user_id, $a->signed_off_by_user_id]))->pluck('name', 'id');
        $summary = $a->status === 'draft' ? $this->appraisals->summary((int) $actor->organisation_id, (int) $a->user_id, $cycle)
            : json_decode((string) AppraisalService::decrypt($a->summary_encrypted), true);
        $editable = ! $own && $a->status === 'draft' && $cycle->status === 'open';

        return [
            'id' => (string) $a->public_id,
            'own' => $own,
            'person' => (string) ($names[$a->user_id] ?? 'Staff member'),
            'cycle' => ['id' => (string) $cycle->public_id, 'name' => (string) $cycle->name, 'period' => $this->period($cycle), 'status' => (string) $cycle->status],
            'status' => (string) $a->status,
            'statusLabel' => self::STATUS[$a->status] ?? (string) $a->status,
            'criteria' => $criteria,
            'ratings' => AppraisalService::decryptRatings($a->ratings_encrypted, count($criteria)),
            'summary' => is_array($summary) ? $summary : null,
            'summaryLive' => $a->status === 'draft',
            'appraiser' => $a->appraiser_user_id === null ? null : (string) ($names[$a->appraiser_user_id] ?? 'Staff member'),
            'sentOn' => $this->day($a->sent_at, $zone),
            'staffComment' => AppraisalService::decrypt($a->staff_comment_encrypted),
            'staffCommentedOn' => $this->day($a->staff_commented_at, $zone),
            'signedOffBy' => $a->signed_off_by_user_id === null ? null : (string) ($names[$a->signed_off_by_user_id] ?? 'Staff member'),
            'signedOffOn' => $this->day($a->signed_off_at, $zone),
            'signOffFrom' => $a->status === 'sent' && $a->staff_commented_at === null && ! AppraisalService::canSignOffNow($a) ? AppraisalService::signOffFrom($a) : null,
            'commentDays' => AppraisalService::COMMENT_DAYS,
            'lockVersion' => (int) $a->lock_version,
            'maxComment' => AppraisalService::MAX_COMMENT,
            'can' => [
                'edit' => $editable,
                'signOff' => ! $own && $a->status === 'sent' && $actor->hasRole('director') && AppraisalService::canSignOffNow($a),
                'comment' => $own && $a->status === 'sent' && $a->staff_commented_at === null,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function mine(User $actor, string $zone): array
    {
        return array_values(DB::table('hr_appraisals as a')->join('hr_appraisal_cycles as c', 'c.id', '=', 'a.cycle_id')
            ->where('a.organisation_id', $actor->organisation_id)->where('a.user_id', $actor->id)->where('a.status', '<>', 'draft')
            ->orderByDesc('c.period_end')->limit(50)->get(['a.public_id', 'a.status', 'a.sent_at', 'a.staff_commented_at', 'c.name', 'c.period_start', 'c.period_end'])
            ->map(fn (\stdClass $r): array => ['id' => (string) $r->public_id, 'cycle' => (string) $r->name, 'period' => $this->period($r),
                'status' => (string) $r->status, 'statusLabel' => self::STATUS[$r->status] ?? (string) $r->status, 'sentOn' => $this->day($r->sent_at, $zone),
                'commented' => $r->staff_commented_at !== null])->all());
    }

    /** @return list<array<string, mixed>> */
    private function cycles(User $actor): array
    {
        $counts = DB::table('hr_appraisals')->where('organisation_id', $actor->organisation_id)->groupBy('cycle_id', 'status')
            ->selectRaw('cycle_id, status, count(*) as n')->get()->groupBy('cycle_id');

        return array_values(DB::table('hr_appraisal_cycles')->where('organisation_id', $actor->organisation_id)->orderByRaw("status = 'closed'")
            ->orderByDesc('period_end')->limit(50)->get()->map(function (\stdClass $c) use ($counts): array {
                $n = collect($counts->get($c->id, []))->pluck('n', 'status');

                return ['id' => (string) $c->public_id, 'name' => (string) $c->name, 'period' => $this->period($c), 'status' => (string) $c->status,
                    'lockVersion' => (int) $c->lock_version, 'draft' => (int) ($n['draft'] ?? 0), 'sent' => (int) ($n['sent'] ?? 0), 'signedOff' => (int) ($n['signed_off'] ?? 0)];
            })->all());
    }

    /** @return array<string, mixed>|null */
    private function cycle(User $actor, mixed $cycleId): ?array
    {
        if (! is_string($cycleId) || ! Str::isUuid($cycleId)) {
            return null;
        }
        $cycle = DB::table('hr_appraisal_cycles')->where('organisation_id', $actor->organisation_id)->where('public_id', $cycleId)->first();
        if (! $cycle instanceof \stdClass) {
            return null;
        }
        $rows = DB::table('hr_appraisals')->where('cycle_id', $cycle->id)->orderBy('id')->get();
        $users = User::query()->with('roles')->whereIn('id', $rows->pluck('user_id')->all())->get()->keyBy('id');

        return ['id' => (string) $cycle->public_id, 'name' => (string) $cycle->name, 'period' => $this->period($cycle), 'status' => (string) $cycle->status,
            'people' => array_values($rows->map(function (\stdClass $a) use ($users, $actor): array {
                $u = $users->get($a->user_id);

                return ['id' => (string) $a->public_id, 'name' => $u instanceof User ? $u->name : 'Staff member', 'status' => (string) $a->status,
                    'statusLabel' => self::STATUS[$a->status] ?? (string) $a->status, 'commented' => $a->staff_commented_at !== null,
                    'refusal' => $u instanceof User ? $this->appraisals->refusalFor($actor, $u) : 'This appraisal cannot be opened.'];
            })->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all())];
    }

    /** @return list<array{id:string, name:string}> */
    private function workers(User $actor): array
    {
        return array_values(Worker::query()->with('user')->where('organisation_id', $actor->organisation_id)->whereNotNull('user_id')->limit(500)->get()
            ->filter(fn (Worker $w): bool => $w->user !== null && $w->user->is_active)
            ->map(fn (Worker $w): array => ['id' => (string) $w->public_id, 'name' => $w->name()])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all());
    }

    private function period(\stdClass $c): string
    {
        return CarbonImmutable::parse((string) $c->period_start)->format('j M Y').' to '.CarbonImmutable::parse((string) $c->period_end)->format('j M Y');
    }

    private function day(mixed $at, string $zone): ?string
    {
        return $at === null ? null : CarbonImmutable::parse((string) $at, 'UTC')->setTimezone($zone)->format('j M Y');
    }
}

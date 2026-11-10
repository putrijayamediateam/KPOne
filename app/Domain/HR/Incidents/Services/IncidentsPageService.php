<?php

namespace App\Domain\HR\Incidents\Services;

use App\Domain\HR\Discipline\DisciplinePermissions;
use App\Domain\HR\Discipline\Services\DisciplineService;
use App\Domain\HR\Incidents\IncidentPermissions;
use App\Domain\HR\Records\Models\Worker;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * HR-10d page props. /hr/incidents: My reports (every human role) and, for the Director and HR Manager, Open reports,
 * Needs clinical review (patient-care reports, listed apart) and Closed. Lists carry no description, note or outcome
 * text. The report page shows the reporter their own report and its status only; a manager also sees notes, actions,
 * the outcome and, if they may handle it, the linked discipline case.
 */
final class IncidentsPageService
{
    public function __construct(private IncidentService $incidents, private DisciplineService $discipline) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function page(User $actor, array $query): array
    {
        $canManage = $actor->can(IncidentPermissions::MANAGE);
        $tabs = array_values(array_filter(['mine', $canManage ? 'open' : null, $canManage ? 'clinical' : null, $canManage ? 'closed' : null]));
        $tab = is_string($query['tab'] ?? null) && in_array($query['tab'], $tabs, true) ? $query['tab'] : ($canManage ? 'open' : 'mine');
        $zone = IncidentService::zone((int) $actor->organisation_id);
        $branches = Branch::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)->orderBy('name')->get();
        $names = $branches->pluck('name', 'id');

        $mine = DB::table('hr_incidents')->where('organisation_id', $actor->organisation_id)->where('reporter_user_id', $actor->id)
            ->orderByDesc('occurred_at')->limit(100)->get();
        $managed = collect();
        $counts = ['open' => 0, 'clinical' => 0, 'closed' => 0];
        if ($canManage) {
            $all = DB::table('hr_incidents')->where('organisation_id', $actor->organisation_id)->orderByDesc('occurred_at')->limit(500)->get();
            $counts = ['open' => $all->where('status', '<>', 'closed')->where('category', '<>', 'patient_care')->count(),
                'clinical' => $all->where('status', '<>', 'closed')->where('category', 'patient_care')->count(),
                'closed' => $all->where('status', 'closed')->count()];
            $managed = match ($tab) {
                'open' => $all->where('status', '<>', 'closed')->where('category', '<>', 'patient_care'),
                'clinical' => $all->where('status', '<>', 'closed')->where('category', 'patient_care'),
                'closed' => $all->where('status', 'closed'),
                default => collect(),
            };
        }
        $reporters = DB::table('users')->whereIn('id', $managed->pluck('reporter_user_id')->unique()->all())->pluck('name', 'id');
        $row = fn (\stdClass $r, bool $withReporter): array => ['id' => (string) $r->public_id, 'category' => (string) $r->category,
            'categoryLabel' => IncidentService::CATEGORIES[$r->category] ?? (string) $r->category, 'branch' => (string) ($names[$r->branch_id] ?? 'Branch'),
            'occurredAt' => $this->when($r->occurred_at, $zone), 'status' => (string) $r->status, 'statusLabel' => IncidentService::STATUS[$r->status] ?? (string) $r->status,
            'needsClinicalReview' => $r->category === 'patient_care', 'reporter' => $withReporter ? (string) ($reporters[$r->reporter_user_id] ?? 'Staff member') : null,
            'own' => (int) $r->reporter_user_id === $actor->id];

        return [
            'tab' => $tab,
            'tabs' => $tabs,
            'canManage' => $canManage,
            'canReport' => $actor->can(IncidentPermissions::REPORT),
            'counts' => [...$counts, 'mine' => $mine->count()],
            'mine' => array_values($mine->map(fn (\stdClass $r): array => $row($r, false))->all()),
            'reports' => array_values($managed->map(fn (\stdClass $r): array => $row($r, true))->all()),
            'branches' => array_values($branches->map(fn (Branch $b): array => ['id' => (string) $b->code, 'name' => (string) $b->name])->all()),
            'categories' => array_map(fn (string $code, string $label): array => ['value' => $code, 'label' => $label], array_keys(IncidentService::CATEGORIES), IncidentService::CATEGORIES),
            'maxText' => IncidentService::MAX_TEXT,
            'maxPhotos' => IncidentService::MAX_PHOTOS,
            'today' => CarbonImmutable::now($zone)->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    public function show(User $actor, string $incidentId): array
    {
        [$r, $manager] = $this->incidents->view($actor, $incidentId); // a manager's open is audited first
        $zone = IncidentService::zone((int) $actor->organisation_id);
        $photos = DB::table('hr_incident_photos')->where('incident_id', $r->id)->orderBy('id')->get(['public_id', 'kind', 'bytes']);
        $branch = DB::table('branches')->where('id', $r->branch_id)->value('name');
        $base = [
            'id' => (string) $r->public_id,
            'manager' => $manager,
            'category' => (string) $r->category,
            'categoryLabel' => IncidentService::CATEGORIES[$r->category] ?? (string) $r->category,
            'needsClinicalReview' => $r->category === 'patient_care',
            'branch' => (string) ($branch ?? 'Branch'),
            'occurredAt' => $this->when($r->occurred_at, $zone),
            'reportedOn' => $this->when($r->created_at, $zone),
            'description' => IncidentService::decrypt($r->description_encrypted),
            'photos' => array_values($photos->map(fn (\stdClass $p): array => ['id' => (string) $p->public_id, 'kind' => (string) $p->kind, 'bytes' => (int) $p->bytes])->all()),
            'status' => (string) $r->status,
            'statusLabel' => IncidentService::STATUS[$r->status] ?? (string) $r->status,
            'acknowledgedOn' => $this->when($r->acknowledged_at, $zone),
            'closedOn' => $this->when($r->closed_at, $zone),
            'lockVersion' => (int) $r->lock_version,
        ];
        if (! $manager) {
            return $base; // the reporter sees the status only: no notes, actions, outcome or link
        }
        $notes = DB::table('hr_incident_notes')->where('incident_id', $r->id)->orderBy('id')->get();
        $actions = DB::table('hr_incident_actions')->where('incident_id', $r->id)->orderBy('id')->get();
        $userIds = [...$notes->pluck('author_user_id')->all(), ...$actions->pluck('owner_user_id')->all(), ...$actions->pluck('done_by_user_id')->filter()->all(),
            $r->reporter_user_id, $r->acknowledged_by_user_id, $r->closed_by_user_id];
        $names = DB::table('users')->whereIn('id', array_filter($userIds))->pluck('name', 'id');
        $today = CarbonImmutable::now($zone)->toDateString();
        $own = (int) $r->reporter_user_id === $actor->id;

        return [...$base,
            'reporter' => (string) ($names[$r->reporter_user_id] ?? 'Staff member'),
            'acknowledgedBy' => $r->acknowledged_by_user_id === null ? null : (string) ($names[$r->acknowledged_by_user_id] ?? 'Staff member'),
            'closedBy' => $r->closed_by_user_id === null ? null : (string) ($names[$r->closed_by_user_id] ?? 'Staff member'),
            'outcome' => IncidentService::decrypt($r->outcome_encrypted),
            'notes' => array_values($notes->map(fn (\stdClass $n): array => ['body' => IncidentService::decrypt($n->body_encrypted),
                'by' => (string) ($names[$n->author_user_id] ?? 'Staff member'), 'on' => $this->when($n->created_at, $zone)])->all()),
            'actions' => array_values($actions->map(fn (\stdClass $a): array => ['id' => (string) $a->public_id, 'description' => IncidentService::decrypt($a->description_encrypted),
                'owner' => (string) ($names[$a->owner_user_id] ?? 'Staff member'), 'dueOn' => CarbonImmutable::parse((string) $a->due_on)->format('j M Y'),
                'overdue' => $a->done_at === null && (string) $a->due_on < $today, 'done' => $a->done_at !== null, 'doneOn' => $this->when($a->done_at, $zone),
                'doneBy' => $a->done_by_user_id === null ? null : (string) ($names[$a->done_by_user_id] ?? 'Staff member'), 'lockVersion' => (int) $a->lock_version])->all()),
            'owners' => array_values(User::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)->whereHas('roles', fn ($q) => $q->where('name', '<>', 'queue_display'))
                ->orderBy('name')->limit(300)->get(['id', 'name'])->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name])->all()),
            'link' => $this->link($actor, $r),
            'can' => ['handle' => ! $own, 'acknowledge' => ! $own && $r->status === 'reported', 'add' => ! $own && $r->status !== 'closed',
                'close' => ! $own && $r->status === 'acknowledged'],
            'ownRefusal' => $own ? 'This is your own report. Someone else handles it.' : null,
            'maxNote' => IncidentService::MAX_NOTE,
            'today' => $today,
        ];
    }

    /** @return array{linked:bool, caseId:?string, options:list<array{value:string, label:string}>, canLink:bool} the discipline link; the case id only for someone who may handle it */
    private function link(User $actor, \stdClass $r): array
    {
        $canDiscipline = $actor->can(DisciplinePermissions::MANAGE) && (int) $r->reporter_user_id !== $actor->id;
        if ($r->discipline_case_id !== null) {
            $case = DB::table('hr_discipline_cases')->where('id', $r->discipline_case_id)->first();
            $subject = $case instanceof \stdClass && $case->subject_user_id !== null ? User::query()->with('roles')->whereKey($case->subject_user_id)->first() : null;
            $may = $canDiscipline && $case instanceof \stdClass && ($case->subject_user_id === null || $subject !== null) && $this->discipline->refusalFor($actor, $subject) === null;

            return ['linked' => true, 'caseId' => $may ? (string) $case->public_id : null, 'options' => [], 'canLink' => false];
        }
        if (! $canDiscipline || $r->category !== 'staff_conduct' || $r->status === 'closed') {
            return ['linked' => false, 'caseId' => null, 'options' => [], 'canLink' => false];
        }
        $cases = DB::table('hr_discipline_cases')->where('organisation_id', $actor->organisation_id)->where('status', 'open')->orderByDesc('opened_at')->limit(100)->get();
        $subjects = User::query()->with('roles')->whereIn('id', $cases->pluck('subject_user_id')->filter()->all())->get()->keyBy('id');
        $workers = Worker::query()->with('user')->whereIn('id', $cases->pluck('worker_id')->all())->get()->keyBy('id');
        $options = $cases->filter(fn (\stdClass $c): bool => ($c->subject_user_id === null || $subjects->has($c->subject_user_id))
            && $this->discipline->refusalFor($actor, $c->subject_user_id === null ? null : $subjects->get($c->subject_user_id)) === null)
            ->map(function (\stdClass $c) use ($workers): array {
                $worker = $workers->get($c->worker_id);

                return ['value' => (string) $c->public_id, 'label' => ($worker instanceof Worker ? $worker->name() : 'Staff member').', opened '
                    .CarbonImmutable::parse((string) $c->opened_at, 'UTC')->format('j M Y')];
            });

        return ['linked' => false, 'caseId' => null, 'options' => array_values($options->all()), 'canLink' => true];
    }

    private function when(mixed $at, string $zone): ?string
    {
        return $at === null ? null : CarbonImmutable::parse((string) $at, 'UTC')->setTimezone($zone)->format('j M Y, g:i A');
    }
}

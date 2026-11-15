<?php

namespace App\Domain\HR\Discipline\Services;

use App\Domain\HR\Discipline\DisciplinePermissions;
use App\Domain\HR\Records\Models\Worker;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * HR-10c page props. /hr/discipline lists the cases the viewer may handle (a case about someone they may not handle is
 * left out entirely, so its existence is not shown). The list carries no allegation or note text; that is returned only
 * on the case's own page, after the view is audited.
 */
final class DisciplinePageService
{
    public const KINDS = ['opened' => 'Case opened', 'note' => 'Note', 'show_cause' => 'Show-cause letter', 'reply' => 'Reply received',
        'file' => 'File added', 'outcome' => 'Outcome'];

    public function __construct(private DisciplineService $discipline) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function page(User $actor, array $query): array
    {
        $tab = ($query['tab'] ?? null) === 'closed' ? 'closed' : 'open';
        $zone = DisciplineService::zone((int) $actor->organisation_id);
        // Who is visible is worked out per person first, so the counts are exact and the 300-row limit applies to the tab
        // after that filter, never before it.
        $subjectIds = DB::table('hr_discipline_cases')->where('organisation_id', $actor->organisation_id)->whereNotNull('subject_user_id')->distinct()->pluck('subject_user_id');
        $visibleIds = User::query()->with('roles')->whereIn('id', $subjectIds->all())->get()
            ->filter(fn (User $u): bool => $this->discipline->refusalFor($actor, $u) === null)->pluck('id')->all();
        $noSubjectVisible = $this->discipline->refusalFor($actor, null) === null;
        $scope = fn () => DB::table('hr_discipline_cases')->where('organisation_id', $actor->organisation_id)
            ->where(fn ($q) => $q->whereIn('subject_user_id', $visibleIds)->when($noSubjectVisible, fn ($q) => $q->orWhereNull('subject_user_id')));
        $counts = $scope()->groupBy('status')->selectRaw('status, count(*) as n')->pluck('n', 'status');
        $visible = $scope()->where('status', $tab)->orderByDesc('opened_at')->orderByDesc('id')->limit(300)->get();
        $workers = Worker::query()->with('user')->whereIn('id', $visible->pluck('worker_id')->all())->get()->keyBy('id');
        $entries = DB::table('hr_discipline_events')->whereIn('case_id', $visible->pluck('id')->all())->groupBy('case_id')->selectRaw('case_id, count(*) as n')->pluck('n', 'case_id');

        return [
            'tab' => $tab,
            'counts' => ['open' => (int) ($counts['open'] ?? 0), 'closed' => (int) ($counts['closed'] ?? 0)],
            'cases' => array_values($visible->map(function (\stdClass $c) use ($workers, $zone, $entries): array {
                $worker = $workers->get($c->worker_id);

                return ['id' => (string) $c->public_id, 'person' => $worker instanceof Worker ? $worker->name() : 'Staff member',
                    'incidentOn' => CarbonImmutable::parse((string) $c->incident_on)->format('j M Y'), 'openedOn' => $this->day($c->opened_at, $zone),
                    'status' => (string) $c->status, 'outcome' => $c->outcome === null ? null : DisciplineService::OUTCOMES[$c->outcome] ?? (string) $c->outcome,
                    'closedOn' => $this->day($c->closed_at, $zone), 'entries' => (int) ($entries[$c->id] ?? 0)];
            })->all()),
            'workers' => $this->workers($actor),
            'maxAllegation' => DisciplineService::MAX_ALLEGATION,
            'today' => CarbonImmutable::now($zone)->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    public function show(User $actor, string $caseId): array
    {
        $case = $this->discipline->view($actor, $caseId); // audited before anything is read
        $zone = DisciplineService::zone((int) $actor->organisation_id);
        $worker = Worker::query()->whereKey($case->worker_id)->first();
        $events = DB::table('hr_discipline_events')->where('case_id', $case->id)->orderBy('id')->get();
        $letters = DB::table('hr_letters')->where('organisation_id', $actor->organisation_id)->where('worker_id', $case->worker_id)
            ->whereIn('type', ['show_cause', 'warning'])->orderByDesc('issued_at')->limit(100)->get();
        $files = DB::table('hr_discipline_files')->where('case_id', $case->id)->get()->keyBy('id');
        $names = DB::table('users')->whereIn('id', [...$events->pluck('actor_user_id')->all(), $case->opened_by_user_id, $case->closed_by_user_id])->pluck('name', 'id');
        $linked = $events->where('kind', 'show_cause')->pluck('letter_id')->all();
        $byId = $letters->keyBy('id');
        $letterRef = fn (?int $id): ?array => $id === null || ! $byId->has($id) ? null
            : ['id' => (string) $byId[$id]->public_id, 'reference' => (string) $byId[$id]->reference, 'void' => $byId[$id]->voided_at !== null];

        return [
            'id' => (string) $case->public_id,
            'person' => $worker instanceof Worker ? $worker->name() : 'Staff member',
            'status' => (string) $case->status,
            'allegation' => DisciplineService::decrypt($case->allegation_encrypted),
            'incidentOn' => CarbonImmutable::parse((string) $case->incident_on)->format('j M Y'),
            'openedBy' => (string) ($names[$case->opened_by_user_id] ?? 'Staff member'),
            'openedOn' => $this->day($case->opened_at, $zone),
            'outcome' => $case->outcome === null ? null : ['code' => (string) $case->outcome, 'label' => DisciplineService::OUTCOMES[$case->outcome] ?? (string) $case->outcome,
                'letter' => $letterRef($case->outcome_letter_id === null ? null : (int) $case->outcome_letter_id), 'note' => DisciplineService::decrypt($case->outcome_note_encrypted),
                'by' => (string) ($names[$case->closed_by_user_id] ?? 'Staff member'), 'on' => $this->day($case->closed_at, $zone)],
            'timeline' => array_values($events->map(fn (\stdClass $e): array => ['kind' => (string) $e->kind, 'label' => self::KINDS[$e->kind] ?? (string) $e->kind,
                'body' => $e->kind === 'outcome' ? null : DisciplineService::decrypt($e->body_encrypted),
                'occurredOn' => $e->occurred_on === null ? null : CarbonImmutable::parse((string) $e->occurred_on)->format('j M Y'),
                'letter' => $letterRef($e->letter_id === null ? null : (int) $e->letter_id),
                'file' => $e->file_id === null || ! $files->has($e->file_id) ? null : ['id' => (string) $files[$e->file_id]->public_id, 'kind' => (string) $files[$e->file_id]->kind,
                    'bytes' => (int) $files[$e->file_id]->bytes],
                'by' => (string) ($names[$e->actor_user_id] ?? 'Staff member'), 'on' => $this->day($e->created_at, $zone)])->all()),
            'showCauseLetters' => array_values($letters->filter(fn (\stdClass $l): bool => $l->type === 'show_cause' && $l->voided_at === null && ! in_array($l->id, $linked, true))
                ->map(fn (\stdClass $l): array => ['id' => (string) $l->public_id, 'reference' => (string) $l->reference, 'issuedOn' => $this->day($l->issued_at, $zone)])->all()),
            'warningLetters' => array_values($letters->filter(fn (\stdClass $l): bool => $l->type === 'warning' && $l->voided_at === null)
                ->map(fn (\stdClass $l): array => ['id' => (string) $l->public_id, 'reference' => (string) $l->reference, 'issuedOn' => $this->day($l->issued_at, $zone)])->all()),
            'outcomes' => array_map(fn (string $code, string $label): array => ['code' => $code, 'label' => $label, 'needsLetter' => in_array($code, DisciplineService::LETTER_OUTCOMES, true)],
                array_keys(DisciplineService::OUTCOMES), DisciplineService::OUTCOMES),
            'fileCount' => $files->count(),
            'limits' => ['note' => DisciplineService::MAX_NOTE, 'reply' => DisciplineService::MAX_REPLY, 'outcomeNote' => DisciplineService::MAX_OUTCOME_NOTE,
                'files' => DisciplineService::MAX_FILES],
            'lockVersion' => (int) $case->lock_version,
            'today' => CarbonImmutable::now($zone)->toDateString(),
        ];
    }

    /** @return list<array{id:string, name:string}> staff files the viewer may open a case for (locum files included) */
    private function workers(User $actor): array
    {
        if (! $actor->can(DisciplinePermissions::MANAGE)) {
            return [];
        }

        return array_values(Worker::query()->with(['user.roles'])->where('organisation_id', $actor->organisation_id)->limit(500)->get()
            ->filter(fn (Worker $w): bool => $w->user_id === null || ($w->user !== null && $this->discipline->refusalFor($actor, $w->user) === null))
            ->map(fn (Worker $w): array => ['id' => (string) $w->public_id, 'name' => $w->name()])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all());
    }

    private function day(mixed $at, string $zone): ?string
    {
        return $at === null ? null : CarbonImmutable::parse((string) $at, 'UTC')->setTimezone($zone)->format('j M Y');
    }
}

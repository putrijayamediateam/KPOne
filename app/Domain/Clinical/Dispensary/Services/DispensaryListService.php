<?php

namespace App\Domain\Clinical\Dispensary\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Dispensary\Labels\DrugLabelService;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryHandoff;
use App\Domain\Clinical\Dispensary\Models\DispensaryItem;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Models\OtcQueueEntry;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\QueueNumberFormat;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * DSP-27 (owner, 25 Nov 2026, Clean Look gap list B1 and B2): the branch's Dispensary list in three status tabs, with
 * counts: To prepare (pending and dispensing, consultation and medicine-only), Waiting for the doctor's check, and
 * Done today (completed since branch-local midnight). Read only: no lock, no write, no audit; the case page does the
 * work. The same read gate as the case page (DispensaryDirectoryService::canView), at the active branch only.
 */
final class DispensaryListService
{
    public const TAB_PREPARE = 'prepare';

    public const TAB_CHECK = 'check';

    public const TAB_DONE = 'done';

    public const TABS = [self::TAB_PREPARE, self::TAB_CHECK, self::TAB_DONE];

    public const PER_PAGE = 25;

    public function __construct(private BranchAccessService $branches) {}

    /** @return array<string, mixed> */
    public function page(User $actor, string $tab, int $page, string $search, string $caseType = 'all'): array
    {
        $branch = $this->branches->activeBranch($actor);
        abort_unless($branch !== null && DispensaryDirectoryService::canView($actor), 404);
        $tab = in_array($tab, self::TABS, true) ? $tab : self::TAB_PREPARE;
        $caseType = $tab === self::TAB_PREPARE && in_array($caseType, [DispensaryCase::TYPE_CONSULTATION, DispensaryCase::TYPE_OTC], true) ? $caseType : 'all';
        $search = Str::limit(trim($search), 80, '');
        [$from, $to] = $this->today($branch);

        $counts = [];
        foreach (self::TABS as $name) {
            $counts[$name] = $this->tabQuery($actor, $branch, $name, $from, $to, $caseType)->count();
        }

        $query = $this->tabQuery($actor, $branch, $tab, $from, $to, $caseType);
        $this->search($query, $search);
        $query->with(['visit.patient:id,patient_number,full_name', 'visit.assignedDoctor:id,name', 'handoffs' => fn ($q) => $q->orderByDesc('attempt_number')->withCount(['items as given_count' => fn ($i) => $i->where('status', '!=', DispensaryItem::STATUS_NOT_DISPENSED)])]);
        $tab === self::TAB_DONE ? $query->orderByDesc('completed_at')->orderByDesc('id') : $query->orderBy('received_at')->orderBy('id');
        // A refresh can move cases between tabs; a page past the end falls back to the last page.
        $lastPage = max(1, (int) ceil((clone $query)->count() / self::PER_PAGE));
        $paginator = $query->paginate(self::PER_PAGE, page: min(max(1, $page), $lastPage));

        $cases = $paginator->getCollection();
        $numbers = $this->queueNumbers(array_values($cases->map(fn (DispensaryCase $case): int => (int) $case->visit_id)->all()));
        $mayPrint = $actor->can(DrugLabelService::PERMISSION);
        $mayPreview = $mayPrint || $actor->can(DrugLabelService::VIEW_PERMISSION);
        $now = now()->utc();

        return [
            'branchName' => $branch->name,
            'tab' => $tab,
            'search' => $search,
            'caseType' => $caseType,
            'counts' => $counts,
            'canPrintLabels' => $mayPrint,
            'rows' => $cases->map(function (DispensaryCase $case) use ($numbers, $branch, $mayPrint, $mayPreview, $now): array {
                $handoff = $case->handoffs->first();
                $received = CarbonImmutable::parse($case->received_at);
                $completed = $case->completed_at === null ? null : CarbonImmutable::parse($case->completed_at);
                $labels = $completed !== null && $handoff?->status === DispensaryHandoff::STATUS_COMPLETED && $mayPreview;

                return [
                    'publicId' => $case->public_id,
                    'queueNumber' => $numbers[$case->visit_id] ?? null,
                    'patientName' => $case->visit->patient->full_name,
                    'patientNumber' => $case->visit->patient->patient_number,
                    'visitNumber' => $case->visit->visit_number,
                    'caseType' => $case->case_type,
                    'status' => $case->status,
                    'doctorName' => $case->visit->assignedDoctor?->name,
                    'medicineCount' => (int) ($handoff?->getAttribute('given_count') ?? 0),
                    'receivedAt' => $received->setTimezone($branch->timezone)->format('g:i A'),
                    'waitingMinutes' => $completed === null ? max(0, (int) $received->diffInMinutes($now)) : null,
                    'completedAt' => $completed?->setTimezone($branch->timezone)->format('g:i A'),
                    'caseUrl' => route('dispensary.show', $case),
                    // A finished case's labels: the CA reprints from the preview; the doctor's preview is view only.
                    'labelUrl' => $labels ? route('dispensary.labels', $case) : null,
                    'labelAction' => $labels ? ($mayPrint ? 'reprint' : 'view') : null,
                ];
            })->values()->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
        ];
    }

    /** @return Builder<DispensaryCase> */
    private function tabQuery(User $actor, Branch $branch, string $tab, CarbonImmutable $from, CarbonImmutable $to, string $caseType = 'all'): Builder
    {
        $query = DispensaryCase::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id);
        if ($tab === self::TAB_PREPARE && $caseType !== 'all') {
            $query->where('case_type', $caseType);
        }

        return match ($tab) {
            self::TAB_CHECK => $query->where('status', DispensaryCase::STATUS_AWAITING_DOCTOR_CHECK),
            self::TAB_DONE => $query->where('status', DispensaryCase::STATUS_COMPLETED)->where('completed_at', '>=', $from)->where('completed_at', '<', $to),
            default => $query->whereIn('status', [DispensaryCase::STATUS_PENDING, DispensaryCase::STATUS_DISPENSING]),
        };
    }

    /**
     * Patient name, KP number or visit number; the same name match the Registration board uses.
     *
     * @param  Builder<DispensaryCase>  $query
     */
    private function search(Builder $query, string $term): void
    {
        if ($term === '') {
            return;
        }
        if (preg_match('/\AKP-\d{8}\z/i', $term) === 1) {
            $query->whereHas('visit.patient', fn ($q) => $q->where('patient_number', Str::upper($term)));

            return;
        }
        if (preg_match('/\AKPV-\d+\z/i', $term) === 1) {
            $query->whereHas('visit', fn ($q) => $q->where('visit_number', Str::upper($term)));

            return;
        }
        if (preg_match('/\A([AB])-(\d{3,19})\z/i', $term, $matches) === 1) {
            $number = filter_var(ltrim($matches[2], '0'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($number !== false) {
                $consultation = strtoupper($matches[1]) === QueueNumberFormat::CONSULTATION;
                $query->where('case_type', $consultation ? DispensaryCase::TYPE_CONSULTATION : DispensaryCase::TYPE_OTC)
                    ->whereHas($consultation ? 'visit.queueEntry' : 'visit.otcQueueEntry', fn ($q) => $q->where('queue_number', $number));

                return;
            }
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], Str::lower($term)).'%';
        $query->whereHas('visit.patient', fn ($q) => $q->whereRaw("search_name LIKE ? ESCAPE '\\'", [$like]));
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} branch-local today, in UTC */
    private function today(Branch $branch): array
    {
        $start = CarbonImmutable::now($branch->timezone)->startOfDay();

        return [$start->utc(), $start->addDay()->utc()];
    }

    /**
     * The A-/B- number of each visit, from the same queue records the Registration board shows.
     *
     * @param  list<int>  $visitIds
     * @return array<int, string>
     */
    private function queueNumbers(array $visitIds): array
    {
        if ($visitIds === []) {
            return [];
        }
        $out = [];
        foreach (QueueEntry::query()->whereIn('visit_id', $visitIds)->get(['visit_id', 'queue_number']) as $entry) {
            $out[(int) $entry->visit_id] = QueueNumberFormat::format((int) $entry->queue_number, QueueNumberFormat::CONSULTATION);
        }
        foreach (OtcQueueEntry::query()->whereIn('visit_id', $visitIds)->get(['visit_id', 'queue_number']) as $entry) {
            $out[(int) $entry->visit_id] = QueueNumberFormat::format((int) $entry->queue_number, QueueNumberFormat::OTC);
        }

        return $out;
    }
}

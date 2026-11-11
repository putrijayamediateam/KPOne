<?php

namespace App\Domain\DepartmentSpace;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Dispensary\Services\DispensaryDirectoryService;
use App\Domain\Clinical\Dispensary\Services\DispensaryReturnService;
use App\Domain\Organisation\Inventory\Services\InventoryStockReviewService;
use App\Domain\Queue\Display\ShortDisplayName;
use App\Domain\Queue\Models\OtcQueueEntry;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\QueueNumberFormat;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * DS-3: the Pharmacy space home. Every widget calls the page's own service, which keeps its own permission and
 * branch checks; a widget the person cannot see on its page is simply left out (never greyed, never a count).
 * A patient shows as a queue number and a first name with an initial, never the full name.
 */
final class PharmacySpace
{
    public const LIST_LIMIT = 5;

    public function __construct(private BranchAccessService $branches, private DispensaryDirectoryService $dispensary, private DispensaryReturnService $returns, private InventoryStockReviewService $stockReview) {}

    /**
     * @return array{branchName: string|null, asOf: string|null, toDispense: array<string, mixed>|null, returnsToCheck: array<string, mixed>|null, stockWatch: array<string, mixed>|null, links: list<array{title: string, href: string}>}
     */
    public function home(User $actor): array
    {
        $branch = $this->branches->activeBranch($actor);
        $toDispense = $this->toDispense($actor);
        $returnsToCheck = $this->returnsToCheck($actor);
        // The Stock review's own counts, for anyone who can open that page (counts only; no item names here).
        $stockWatch = $this->stockWatch($actor);

        $links = [];
        if ($toDispense !== null) {
            $links[] = ['title' => 'Open Dispensary list', 'href' => route('registration.index', absolute: false)];
        }
        if ($returnsToCheck !== null) {
            $links[] = ['title' => 'Returns to check', 'href' => route('dispensary.returns-to-check', absolute: false)];
        }
        if ($stockWatch !== null) {
            $links[] = ['title' => 'Stock review', 'href' => route('inventory.stock-review', absolute: false)];
        }

        return [
            'branchName' => $branch?->name,
            'asOf' => $branch ? now()->setTimezone($branch->timezone)->format('g:i A') : null,
            'toDispense' => $toDispense,
            'returnsToCheck' => $returnsToCheck,
            'stockWatch' => $stockWatch,
            'links' => $links,
        ];
    }

    /**
     * @param  array<string, mixed>  $board
     * @return list<array<string, mixed>>
     */
    private function rowsOf(array $board): array
    {
        $source = $board['data'];

        return array_values($source instanceof Collection ? $source->all() : (array) $source);
    }

    /**
     * The Dispensary list's own rows for the longest-waiting patients, or null when this person cannot open that list.
     *
     * @return array<string, mixed>|null
     */
    public function toDispense(User $actor): ?array
    {
        $branch = $this->branches->activeBranch($actor);
        if ($branch === null || ! $actor->can('dispensary.view.branch') || ! $actor->hasAnyRole(['ca', 'ca_supervisor'])) {
            return null;
        }
        $board = $this->dispensary->board($actor, []);
        $data = $this->rowsOf($board);
        // The board lists newest first in pages of 25, so the longest waiters sit on its last pages: read those
        // too (at most two more pages) and show the longest-waiting patients, not the newest.
        $lastPage = (int) $board['lastPage'];
        for ($page = $lastPage, $reads = 0; $page > 1 && $reads < 2; $page--, $reads++) {
            $data = array_merge($data, $this->rowsOf($this->dispensary->board($actor, ['page' => $page])));
        }
        $unique = [];
        foreach ($data as $row) {
            $unique[$row['visitNumber']] = $row;
        }
        $data = array_values($unique);
        usort($data, fn (array $a, array $b): int => $b['durationMinutes'] <=> $a['durationMinutes']);
        $shown = array_slice($data, 0, self::LIST_LIMIT);
        $numbers = $this->queueNumbers($branch->organisation_id, $branch->id, array_column($shown, 'visitNumber'));
        $toDispense = [
            'total' => (int) $board['total'],
            'limit' => self::LIST_LIMIT,
            'seeAllHref' => route('registration.index', absolute: false),
            'rows' => array_map(fn (array $r): array => [
                'visitNumber' => $r['visitNumber'],
                'queueNumber' => $numbers[$r['visitNumber']] ?? null,
                'patient' => $this->shortName((string) $r['patientName']),
                'minutes' => (int) $r['durationMinutes'],
                'medicineCount' => $r['medicineCount'],
                'href' => $r['dispensaryUrl'],
            ], $shown),
        ];

        return $toDispense;
    }

    /**
     * The Stock review's own counts (counts only, no item names), or null when this person cannot open Stock review.
     *
     * @return array<string, mixed>|null
     */
    public function stockWatch(User $actor): ?array
    {
        if ($this->branches->activeBranch($actor) === null || ! $actor->can('inventory.view.branch')) {
            return null;
        }
        $page = $this->stockReview->page($actor, []);

        return [
            'lowStock' => (int) $page['lists']['low_stock']['total'],
            'expiring' => (int) $page['lists']['expiring']['total'],
            'expired' => (int) $page['lists']['expired']['total'],
            'windowDays' => (int) $page['windowDays'],
        ];
    }

    /** @return array<string, mixed>|null */
    public function returnsToCheck(User $actor): ?array
    {
        $branch = $this->branches->activeBranch($actor);
        if ($branch === null || ! $this->returns->canCheckReturns($actor)) {
            return null;
        }

        return ['total' => $this->returns->waitingList($actor, 1)['total']];
    }

    /** "Ahmad I." from "Ahmad Ismail bin Ali": the first name and the initial of the next, never the full name. */
    private function shortName(string $fullName): string
    {
        $words = explode(' ', (string) ShortDisplayName::from($fullName));
        if (count($words) < 2 || $words[1] === '') {
            return $words[0] !== '' ? $words[0] : 'Patient';
        }

        return $words[0].' '.mb_strtoupper(mb_substr($words[1], 0, 1)).'.';
    }

    /**
     * The A-/B- number of each shown visit, read from the same queue records the Registration board shows.
     *
     * @param  list<string>  $visitNumbers
     * @return array<string, string>
     */
    private function queueNumbers(int $organisationId, int $branchId, array $visitNumbers): array
    {
        if ($visitNumbers === []) {
            return [];
        }
        $visits = Visit::query()->where('organisation_id', $organisationId)->where('branch_id', $branchId)->whereIn('visit_number', $visitNumbers)->pluck('visit_number', 'id');
        $out = [];
        foreach (QueueEntry::query()->whereIn('visit_id', $visits->keys())->get(['visit_id', 'queue_number']) as $entry) {
            $out[$visits[$entry->visit_id]] = QueueNumberFormat::format((int) $entry->queue_number, QueueNumberFormat::CONSULTATION);
        }
        foreach (OtcQueueEntry::query()->whereIn('visit_id', $visits->keys())->get(['visit_id', 'queue_number']) as $entry) {
            $out[$visits[$entry->visit_id]] = QueueNumberFormat::format((int) $entry->queue_number, QueueNumberFormat::OTC);
        }

        return $out;
    }
}

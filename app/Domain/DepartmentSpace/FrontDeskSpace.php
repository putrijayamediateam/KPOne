<?php

namespace App\Domain\DepartmentSpace;

use App\Domain\Access\BranchAccessService;
use App\Domain\Patient\Services\PublicIntakeReviewService;
use App\Domain\Visit\Services\VisitDirectoryService;
use App\Models\User;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * DS-4: the Front desk space home. Every widget calls the page's own service (the Registration board and the QR
 * intake review), which keeps its own permission and branch checks; a widget the person cannot open on its page is
 * left out. A patient shows as a queue number and a first name with an initial, never the full name.
 */
final class FrontDeskSpace
{
    public const LIST_LIMIT = 5;

    public function __construct(private BranchAccessService $branches, private VisitDirectoryService $visits, private PublicIntakeReviewService $intakes) {}

    /**
     * @return array{branchName: string|null, asOf: string|null, waiting: array<string, mixed>|null, glance: array<string, int>|null, qrPending: array<string, mixed>|null, primaryAction: array{title: string, href: string}|null, links: list<array{title: string, href: string}>}
     */
    public function home(User $actor): array
    {
        $branch = $this->branches->activeBranch($actor);
        $waiting = $this->waiting($actor);
        $links = [];
        $primary = $branch !== null && $actor->can('visits.create.branch')
            ? ['title' => 'Register a visit', 'href' => route('registration.create', absolute: false)] : null;
        if ($branch !== null && $actor->can('visits.view.branch')) {
            $links[] = ['title' => 'Open Registration', 'href' => route('registration.index', absolute: false)];
        }
        if ($branch !== null && $actor->can('patients.search.organisation')) {
            $links[] = ['title' => 'Patient directory', 'href' => route('patients.index', absolute: false)];
        }

        return [
            'branchName' => $branch?->name,
            'asOf' => $branch ? now()->setTimezone($branch->timezone)->format('g:i A') : null,
            'waiting' => $waiting === null ? null : ['total' => $waiting['total'], 'limit' => self::LIST_LIMIT, 'seeAllHref' => route('registration.index', absolute: false), 'rows' => $waiting['rows']],
            'glance' => $waiting['counts'] ?? null,
            'qrPending' => $this->qrPending($actor),
            'primaryAction' => $primary,
            'links' => $links,
        ];
    }

    /**
     * The Registration board's own Waiting rows, longest first (the board lists newest first in pages of 25, so the
     * longest waiters sit on its last pages: read those too), plus its tab counts.
     *
     * @return array{total: int, counts: array<string, int>, rows: list<array<string, mixed>>, all: list<array<string, mixed>>}|null
     */
    public function waiting(User $actor): ?array
    {
        if ($this->branches->activeBranch($actor) === null || ! $actor->can('visits.view.branch')) {
            return null;
        }
        $first = $this->visits->search($actor, ['board_status' => 'waiting']);
        $data = $this->rowsOf($first);
        $lastPage = (int) $first['lastPage'];
        for ($page = $lastPage, $reads = 0; $page > 1 && $reads < 2; $page--, $reads++) {
            $data = array_merge($data, $this->rowsOf($this->visits->search($actor, ['board_status' => 'waiting', 'page' => $page])));
        }
        $unique = [];
        foreach ($data as $row) {
            $unique[$row['visitNumber']] = $row;
        }
        $data = array_values($unique);
        usort($data, fn (array $a, array $b): int => (int) $b['durationMinutes'] <=> (int) $a['durationMinutes']);
        $all = array_map(fn (array $r): array => [
            'visitNumber' => $r['visitNumber'],
            'queueNumber' => $r['queueNumber'],
            'patient' => SpaceDisplay::shortName((string) $r['patientName']),
            'minutes' => (int) $r['durationMinutes'],
            'href' => route('visits.show', ['visit' => $r['visitNumber']], absolute: false),
            'notReady' => is_array($r['callHold'] ?? null) && ($r['callHold']['active'] ?? false) ? (string) ($r['callHold']['reason'] ?? 'Not ready') : null,
        ], $data);
        $rows = array_map(fn (array $r): array => array_diff_key($r, ['notReady' => 1]), array_slice($all, 0, self::LIST_LIMIT));
        /** @var array<string, int> $counts */
        $counts = $first['tabCounts'];

        return ['total' => (int) $first['total'], 'counts' => array_map('intval', array_intersect_key($counts, array_flip(['waiting', 'serving', 'completed', 'billing']))), 'rows' => $rows, 'all' => $all];
    }

    /**
     * The visits awaiting billing, from the Registration board's own Billing tab: only for people who can open it.
     *
     * @return array{total: int, rows: list<array{queueNumber: string|null, patient: string, registeredAt: string}>}|null
     */
    public function awaitingBilling(User $actor): ?array
    {
        if ($this->branches->activeBranch($actor) === null || ! $actor->can('visits.view.branch') || ! $actor->can('billing.view.branch')) {
            return null;
        }
        $result = $this->visits->search($actor, ['board_status' => 'billing']);

        return ['total' => (int) $result['total'], 'rows' => array_map(fn (array $r): array => [
            'queueNumber' => $r['queueNumber'],
            'patient' => SpaceDisplay::shortName((string) $r['patientName']),
            'registeredAt' => (string) $r['registeredAt'],
        ], array_slice($this->rowsOf($result), 0, 10))];
    }

    /**
     * The QR check-ins waiting for review: the count only, from the same review service the QR intake tab uses.
     *
     * @return array{total: int, href: string}|null
     */
    public function qrPending(User $actor): ?array
    {
        if ($this->branches->activeBranch($actor) === null || ! $actor->can('public_intakes.review.branch')) {
            return null;
        }
        try {
            $listing = $this->intakes->listing($actor);
        } catch (HttpExceptionInterface) {
            return null;
        }

        return ['total' => (int) $listing['pendingCount'], 'href' => route('registration.index', ['tab' => 'qr-intake'], absolute: false)];
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
}

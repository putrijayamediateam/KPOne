<?php

namespace App\Domain\DepartmentSpace;

use App\Domain\Access\BranchAccessService;
use App\Domain\Visit\Billing\Services\PanelClaimDirectoryService;
use App\Domain\Visit\Billing\Services\PanelClaimFollowUpService;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * DS-7: the Panel space home. Every widget calls the Panel page's own service (the Panel Claims list and the
 * Follow-up list), which keeps its own permission and branch checks; a widget the person cannot open on its page is
 * left out. A patient shows as a first name with an initial, never the full name. The Panel summary report is not
 * read here: every run of that report is audited and capped, so the space only links to it.
 */
final class PanelSpace
{
    public const LIST_LIMIT = 5;

    /** Pages of the Panel Claims list read for a per-Panel answer (20 rows a page). */
    private const PAGES = 5;

    public function __construct(private BranchAccessService $branches, private PanelClaimDirectoryService $claims, private PanelClaimFollowUpService $followUp) {}

    /**
     * @return array<string, mixed>
     */
    public function home(User $actor): array
    {
        $branch = $this->branches->activeBranch($actor);
        $toClaim = $this->toClaim($actor);
        $follow = $this->followUp($actor);
        $links = [];
        if ($branch !== null && $actor->can('panel.remittances.record.branch')) {
            $links[] = ['title' => 'Panel payments', 'href' => route('panel-remittances.index', absolute: false)];
        }
        if ($branch !== null && $actor->can('panel.claim_payments.reverse.branch')) {
            $links[] = ['title' => 'Correct a Panel payment', 'href' => route('panel-payment-corrections.index', absolute: false)];
        }
        $summary = $branch !== null && $actor->can('reports.panel_summary.view.branch');
        $handler = $branch !== null && $actor->can('panel.claims.manage.branch');
        if ($summary && $handler) {
            $links[] = ['title' => 'Panel summary', 'href' => route('reports.panel-summary.index', absolute: false)];
        }
        $primary = null;
        if ($branch !== null && $actor->can('panel.claims.manage.branch')) {
            $primary = ['title' => 'Open Panel Claims', 'href' => route('panel-claims.index', absolute: false)];
        } elseif ($summary) {
            $primary = ['title' => 'Open Panel summary', 'href' => route('reports.panel-summary.index', absolute: false)];
        }

        return [
            'branchName' => $branch?->name,
            'asOf' => $branch ? now()->setTimezone($branch->timezone)->format('g:i A') : null,
            'toClaim' => $toClaim === null ? null : [
                'pending' => $toClaim['pending'], 'overdue' => $toClaim['overdue'],
                'limit' => self::LIST_LIMIT, 'seeAllHref' => route('panel-claims.index', ['tab' => 'overdue'], absolute: false),
                'rows' => array_slice($toClaim['rows'], 0, self::LIST_LIMIT),
                'total' => $toClaim['pending'] + $toClaim['overdue'],
            ],
            'followUp' => $follow === null ? null : [
                'total' => $follow['total'], 'limit' => self::LIST_LIMIT, 'seeAllHref' => route('panel-claims.follow-up', absolute: false),
                'rows' => array_slice($follow['rows'], 0, self::LIST_LIMIT),
            ],
            'primaryAction' => $primary,
            'note' => $handler ? null : 'Claims are handled by Panel Officers. This space shows reports and payment corrections.',
            'links' => $links,
        ];
    }

    /**
     * Approved Panel invoices that still need a claim, overdue first, from the Panel Claims list's own invoice rows
     * (at most five pages of 20 are read), with the list's own pending and overdue counts.
     *
     * @return array{pending: int, overdue: int, complete: bool, rows: list<array<string, mixed>>, all: list<array<string, mixed>>}|null
     */
    public function toClaim(User $actor): ?array
    {
        if ($this->branches->activeBranch($actor) === null || ! $actor->can('panel.claims.manage.branch')) {
            return null;
        }
        $all = [];
        $first = null;
        for ($page = 1; $page <= self::PAGES; $page++) {
            $result = $this->claims->search($actor, $page, 1, ['tab' => 'all']);
            $first ??= $result;
            $all = array_merge($all, array_values((array) $result['eligible']['data']));
            if ($page >= (int) $result['eligible']['lastPage']) {
                break;
            }
        }
        /** @var array<string, int> $tabs */
        $tabs = $first['tabs'] ?? [];
        usort($all, fn (array $a, array $b): int => [(int) ! $a['overdue'], $a['visitDateIso']] <=> [(int) ! $b['overdue'], $b['visitDateIso']]);
        $tz = $this->branches->activeBranch($actor)->timezone;
        $today = CarbonImmutable::now($tz)->startOfDay();
        $rows = array_map(function (array $r) use ($tz, $today): array {
            $due = CarbonImmutable::parse((string) $r['dueDate'], $tz)->startOfDay();
            $days = $due->lessThan($today) ? (int) $due->diffInDays($today) : 0;

            return [
                'invoiceNumber' => (string) $r['invoiceNumber'],
                'patient' => SpaceDisplay::shortName((string) $r['patientName']),
                'panel' => (string) $r['panelName'],
                'amountText' => SpaceDisplay::rm((int) $r['amountSen']),
                'dueText' => SpaceDisplay::dueText($due->format('j M Y'), (bool) $r['overdue'] ? max(1, $days) : 0),
                'overdue' => (bool) $r['overdue'],
                'panelCode' => (string) $r['panelCode'],
                'amountSen' => (int) $r['amountSen'],
                'href' => (string) $r['invoiceUrl'],
            ];
        }, $all);

        return [
            'pending' => (int) ($tabs['pending'] ?? 0), 'overdue' => (int) ($tabs['overdue'] ?? 0),
            'complete' => (int) ($first['eligible']['total'] ?? 0) <= count($all),
            'rows' => $rows, 'all' => $rows,
        ];
    }

    /**
     * Claims with no new action for their Panel's follow-up days, from the Follow-up list's own first page.
     *
     * @return array{total: int, rows: list<array<string, mixed>>}|null
     */
    public function followUp(User $actor): ?array
    {
        if ($this->branches->activeBranch($actor) === null || ! $actor->can('panel.claims.manage.branch')) {
            return null;
        }
        $page = $this->followUp->page($actor, []);
        $data = array_values((array) $page['claims']['data']);

        return ['total' => (int) $page['claims']['total'], 'rows' => array_map(fn (array $r): array => [
            'claimNo' => (string) $r['claimNo'],
            'patient' => SpaceDisplay::shortName((string) $r['patientName']),
            'panel' => (string) $r['panelName'],
            'status' => (string) $r['status'],
            'sinceText' => 'No action for '.(int) $r['daysSinceAction'].((int) $r['daysSinceAction'] === 1 ? ' day' : ' days'),
            'href' => (string) $r['showUrl'],
        ], $data)];
    }
}

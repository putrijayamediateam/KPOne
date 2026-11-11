<?php

namespace App\Domain\DepartmentSpace;

use App\Domain\Access\BillingWorkAccess;
use App\Domain\Access\BranchAccessService;
use App\Domain\Visit\Billing\Services\CashClosingService;
use App\Domain\Visit\Billing\Services\PayLaterCollectionService;
use App\Models\User;

/**
 * DS-5: the Billing space home. Every widget calls the page's own service (pay later to collect, daily cash closing,
 * the Registration board's Billing tab), which keeps its own permission and branch checks; a widget the person cannot
 * open on its page is left out. Patients show as a first name with an initial; money shows as the page shows it.
 */
final class BillingSpace
{
    public const LIST_LIMIT = 5;

    private const STATUS_WORDS = [
        'approved' => 'Approved',
        'waiting' => 'Waiting for approval',
        'returned' => 'Returned',
        'not_counted' => 'Not counted',
        'open' => 'Open for today',
        'before_start' => 'Not in use yet',
    ];

    public function __construct(private BranchAccessService $branches, private PayLaterCollectionService $payLater, private CashClosingService $closings, private FrontDeskSpace $frontDesk, private BillingWorkAccess $work) {}

    /**
     * @return array<string, mixed>
     */
    public function home(User $actor): array
    {
        $branch = $this->branches->activeBranch($actor);
        $payLater = $this->payLater($actor);
        $closing = $this->cashClosing($actor);
        $awaiting = $this->frontDesk->awaitingBilling($actor);

        $primary = null;
        if ($closing !== null) {
            $primary = ['title' => 'Open cash closing', 'href' => route('cash-closings.index', absolute: false)];
        } elseif ($branch !== null && $this->work->finance($actor)) {
            $primary = ['title' => 'Financial work', 'href' => route('billing.work', absolute: false)];
        }
        $links = [];
        if ($closing !== null) {
            $links[] = ['title' => 'Cash closing, this month', 'href' => route('cash-closings.month', absolute: false)];
        }
        if ($branch !== null && $this->work->finance($actor) && $primary !== null && $primary['title'] !== 'Financial work') {
            $links[] = ['title' => 'Financial work', 'href' => route('billing.work', absolute: false)];
        }

        return [
            'branchName' => $branch?->name,
            'asOf' => $branch ? now()->setTimezone($branch->timezone)->format('g:i A') : null,
            'payLater' => $payLater,
            'cashClosing' => $closing,
            'awaiting' => $awaiting === null ? null : [
                'total' => $awaiting['total'],
                'limit' => self::LIST_LIMIT,
                'seeAllHref' => route('registration.index', absolute: false),
                'rows' => array_slice($awaiting['rows'], 0, self::LIST_LIMIT),
            ],
            'primaryAction' => $primary,
            'links' => $links,
        ];
    }

    /**
     * The pay-later list's own totals and most overdue rows, or null when this person cannot open that page.
     *
     * @return array<string, mixed>|null
     */
    public function payLater(User $actor): ?array
    {
        if ($this->branches->activeBranch($actor) === null || ! $actor->can('outstanding.view.branch')) {
            return null;
        }
        $page = $this->payLater->page($actor, 1, false);

        return [
            'count' => (int) $page['all']['count'],
            'totalText' => SpaceDisplay::rm((int) $page['all']['totalSen']),
            'overdueCount' => (int) $page['all']['overdueCount'],
            'overdueText' => SpaceDisplay::rm((int) $page['all']['overdueSen']),
            'limit' => self::LIST_LIMIT,
            'seeAllHref' => route('billing.pay-later-collections', absolute: false),
            'rows' => $this->payLaterRows($page, self::LIST_LIMIT),
        ];
    }

    /**
     * The overdue pay-later rows (same fields as the page) and their totals, for the quick questions.
     *
     * @return array{count: int, sen: int, rows: list<array<string, mixed>>}|null
     */
    public function overdue(User $actor): ?array
    {
        if ($this->branches->activeBranch($actor) === null || ! $actor->can('outstanding.view.branch')) {
            return null;
        }
        $page = $this->payLater->page($actor, 1, true);

        return ['count' => (int) $page['shown']['count'], 'sen' => (int) $page['shown']['totalSen'], 'rows' => $this->payLaterRows($page, 10)];
    }

    /**
     * Pay-later bills as the pay-later page lists them (oldest due first), read page by page, at most four pages.
     * `complete` is false when more bills exist than were read.
     *
     * @return array{rows: list<array<string, mixed>>, complete: bool}|null
     */
    public function payLaterRead(User $actor): ?array
    {
        if ($this->branches->activeBranch($actor) === null || ! $actor->can('outstanding.view.branch')) {
            return null;
        }
        $rows = [];
        $complete = true;
        for ($n = 1; $n <= 4; $n++) {
            $page = $this->payLater->page($actor, $n, false);
            $rows = array_merge($rows, $this->payLaterRows($page, PHP_INT_MAX));
            if ($n >= (int) $page['lastPage']) {
                break;
            }
            if ($n === 4) {
                $complete = false;
            }
        }

        return ['rows' => $rows, 'complete' => $complete];
    }

    /**
     * Today's cash closing, as the daily page shows it, or null when this person cannot open that page.
     *
     * @return array<string, mixed>|null
     */
    public function cashClosing(User $actor): ?array
    {
        if ($this->branches->activeBranch($actor) === null || ! $actor->can('payments.reconcile.branch')) {
            return null;
        }
        $page = $this->closings->page($actor, null);

        return [
            'status' => (string) $page['status'],
            'statusText' => self::STATUS_WORDS[$page['status']] ?? (string) $page['status'],
            'expectedText' => SpaceDisplay::rm((int) $page['expected']['cashSen']),
            'receiptCount' => (int) $page['expected']['receiptCount'],
            'changedSinceApproval' => (bool) $page['changedSinceApproval'],
            'href' => route('cash-closings.index', absolute: false),
        ];
    }

    /**
     * The days of this month by cash closing status, from the monthly page's own rows (dates only).
     *
     * @return list<array{date: string, status: string}>|null
     */
    public function monthDays(User $actor): ?array
    {
        if ($this->branches->activeBranch($actor) === null || ! $actor->can('payments.reconcile.branch')) {
            return null;
        }
        $month = $this->closings->month($actor, null);

        return array_values(array_map(fn (array $r): array => ['date' => (string) $r['date'], 'status' => (string) $r['status']], $month['rows']));
    }

    /**
     * @param  array<string, mixed>  $page
     * @return list<array<string, mixed>>
     */
    private function payLaterRows(array $page, int $limit): array
    {
        /** @var list<array<string, mixed>> $data */
        $data = array_values($page['data']);

        return array_map(fn (array $r): array => [
            'invoiceNumber' => (string) $r['invoiceNumber'],
            'patient' => SpaceDisplay::shortName((string) $r['patientName']),
            'amountText' => SpaceDisplay::rm((int) $r['remainingSen']),
            'remainingSen' => (int) $r['remainingSen'],
            'dueText' => $this->dueText((string) $r['dueDate'], (int) $r['daysOverdue']),
            'tone' => (int) $r['daysOverdue'] >= 30 ? 'red' : ((int) $r['daysOverdue'] > 0 ? 'amber' : 'normal'),
            'daysOverdue' => (int) $r['daysOverdue'],
            'href' => (string) $r['url'],
        ], array_slice($data, 0, $limit));
    }

    /** "Due 3 Nov 2026, 9 days overdue": the words as well as the colour. */
    public function dueText(string $dueDate, int $daysOverdue): string
    {
        if ($daysOverdue <= 0) {
            return 'Due '.$dueDate;
        }

        return 'Due '.$dueDate.', '.$daysOverdue.($daysOverdue === 1 ? ' day' : ' days').' overdue'.($daysOverdue >= 30 ? ' (very overdue)' : '');
    }
}

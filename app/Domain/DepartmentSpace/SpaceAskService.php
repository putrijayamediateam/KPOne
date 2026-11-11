<?php

namespace App\Domain\DepartmentSpace;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Models\User;

/**
 * DS-3a: answers one preset space question. It offers a question only to someone who holds its permission and whose
 * own page would show the answer, re-checks that on every call (403 otherwise, 404 for an unknown key), reads nothing
 * but the page's own service, and stores nothing except a structural audit row (question key and branch id).
 */
final class SpaceAskService
{
    /** The page that holds the full list behind each answer: route name and query. @var array<string, array{0: string, 1: array<string, string>}> */
    private const FULL_LIST = [
        'pharmacy.longest_waiting' => ['registration.index', []],
        'pharmacy.returns_waiting' => ['dispensary.returns-to-check', []],
        'pharmacy.stock_attention' => ['inventory.stock-review', []],
        'front_desk.waited_over_30' => ['registration.index', []],
        'front_desk.not_ready' => ['registration.index', []],
        'front_desk.awaiting_billing' => ['registration.index', []],
        'front_desk.qr_pending' => ['registration.index', ['tab' => 'qr-intake']],
        'billing.overdue' => ['billing.pay-later-collections', []],
        'billing.overdue_total' => ['billing.pay-later-collections', []],
        'billing.closings_waiting' => ['cash-closings.month', []],
        'billing.closings_missing' => ['cash-closings.month', []],
    ];

    public function __construct(private BranchAccessService $branches, private PharmacySpace $pharmacy, private FrontDeskSpace $frontDesk, private BillingSpace $billing, private AuditRecorder $audit) {}

    /**
     * The questions this person may ask in a space. A question they cannot ask is absent, never greyed.
     *
     * @return list<array{key: string, label: string}>
     */
    public function offered(User $actor, string $space): array
    {
        $out = [];
        foreach (SpaceQuestions::forSpace($space) as $key => $question) {
            if ($this->allowed($actor, $key)) {
                $out[] = ['key' => $key, 'label' => $question['label']];
            }
        }

        return $out;
    }

    /** @return array{key: string, label: string, sentence: string, columns: list<string>, rows: list<list<string>>, asOf: string, href: string} */
    public function ask(User $actor, string $space, string $key): array
    {
        $question = SpaceQuestions::find($key);
        abort_if($question === null || $question['space'] !== $space, 404);
        abort_unless($this->allowed($actor, $key), 403);
        $branch = $this->branches->activeBranch($actor);
        abort_if($branch === null, 403);

        $answer = $this->{$question['builder']}($actor);
        $this->audit->record('space_ask.answered', null, ['question' => $key], $actor, $branch, $actor->organisation_id);

        return [
            'key' => $key,
            'label' => $question['label'],
            'sentence' => $answer['sentence'],
            'columns' => $answer['columns'],
            'rows' => $answer['rows'],
            'asOf' => now()->setTimezone($branch->timezone)->format('g:i A'),
            'href' => route(self::FULL_LIST[$key][0], self::FULL_LIST[$key][1], absolute: false),
        ];
    }

    private function allowed(User $actor, string $key): bool
    {
        if (! SpaceQuestions::holdsPermission($actor, $key) || $this->branches->activeBranch($actor) === null) {
            return false;
        }

        return match ($key) {
            'pharmacy.longest_waiting' => $this->pharmacy->toDispense($actor) !== null,
            'pharmacy.returns_waiting' => $this->pharmacy->returnsToCheck($actor) !== null,
            'pharmacy.stock_attention' => $this->pharmacy->stockWatch($actor) !== null,
            'front_desk.waited_over_30', 'front_desk.not_ready' => $this->frontDesk->waiting($actor) !== null,
            'front_desk.awaiting_billing' => $this->frontDesk->awaitingBilling($actor) !== null,
            'front_desk.qr_pending' => $this->frontDesk->qrPending($actor) !== null,
            'billing.overdue', 'billing.overdue_total' => $this->billing->overdue($actor) !== null,
            'billing.closings_waiting', 'billing.closings_missing' => $this->billing->monthDays($actor) !== null,
            default => false,
        };
    }

    /** @return array{sentence: string, columns: list<string>, rows: list<list<string>>} */
    private function longestWaiting(User $actor): array
    {
        $list = $this->pharmacy->toDispense($actor);
        abort_if($list === null, 403);
        /** @var list<array<string, mixed>> $shown */
        $shown = array_slice($list['rows'], 0, 3);
        if ($shown === []) {
            return ['sentence' => 'Nobody is waiting at Dispensary.', 'columns' => [], 'rows' => []];
        }
        $rows = [];
        foreach ($shown as $row) {
            $rows[] = [(string) ($row['queueNumber'] ?? ''), (string) $row['patient'], $this->pharmacy->waitText((int) $row['minutes'])];
        }
        $first = $shown[0];

        return [
            'sentence' => $first['patient'].($first['queueNumber'] ? ' ('.$first['queueNumber'].')' : '').' has waited longest: '.$this->pharmacy->waitText((int) $first['minutes']).'.',
            'columns' => ['Number', 'Patient', 'Waiting'],
            'rows' => $rows,
        ];
    }

    /** @return array{sentence: string, columns: list<string>, rows: list<list<string>>} */
    private function returnsWaiting(User $actor): array
    {
        $returns = $this->pharmacy->returnsToCheck($actor);
        abort_if($returns === null, 403);
        $total = (int) $returns['total'];

        return [
            'sentence' => $total === 0 ? 'Nothing is waiting for a check.' : ($total === 1 ? '1 return is waiting for a check.' : $total.' returns are waiting for a check.'),
            'columns' => [],
            'rows' => [],
        ];
    }

    /** @return array{sentence: string, columns: list<string>, rows: list<list<string>>} */
    private function stockAttention(User $actor): array
    {
        $watch = $this->pharmacy->stockWatch($actor);
        abort_if($watch === null, 403);
        $low = (int) $watch['lowStock'];
        $expiring = (int) $watch['expiring'];
        $expired = (int) $watch['expired'];

        return [
            'sentence' => $low + $expiring + $expired === 0
                ? 'No stock needs attention.'
                : "$low below reorder level, $expiring expiring in {$watch['windowDays']} days, $expired expired on hand.",
            'columns' => [],
            'rows' => [],
        ];
    }

    /** @return array{sentence: string, columns: list<string>, rows: list<list<string>>} */
    private function frontDeskWaitedOver30(User $actor): array
    {
        $waiting = $this->frontDesk->waiting($actor);
        abort_if($waiting === null, 403);
        $over = array_values(array_filter($waiting['all'], fn (array $r): bool => (int) $r['minutes'] >= SpaceDisplay::WAIT_LONG_MINUTES));
        if ($over === []) {
            return ['sentence' => 'Nobody has waited over 30 minutes.', 'columns' => [], 'rows' => []];
        }
        $rows = [];
        foreach (array_slice($over, 0, 10) as $row) {
            $rows[] = [(string) ($row['queueNumber'] ?? ''), (string) $row['patient'], SpaceDisplay::waitText((int) $row['minutes'])];
        }
        $n = count($over);

        return [
            'sentence' => ($n === 1 ? '1 patient has' : $n.' patients have').' waited over 30 minutes.'.($n > count($rows) ? ' The '.count($rows).' longest are shown.' : ''),
            'columns' => ['Number', 'Patient', 'Waiting'],
            'rows' => $rows,
        ];
    }

    /** @return array{sentence: string, columns: list<string>, rows: list<list<string>>} */
    private function frontDeskNotReady(User $actor): array
    {
        $waiting = $this->frontDesk->waiting($actor);
        abort_if($waiting === null, 403);
        $flagged = array_values(array_filter($waiting['all'], fn (array $r): bool => $r['notReady'] !== null));
        if ($flagged === []) {
            return ['sentence' => 'No patient is marked not ready to call.', 'columns' => [], 'rows' => []];
        }
        $rows = [];
        foreach (array_slice($flagged, 0, 10) as $row) {
            $rows[] = [(string) ($row['queueNumber'] ?? ''), (string) $row['patient'], (string) $row['notReady']];
        }
        $n = count($flagged);

        return ['sentence' => ($n === 1 ? '1 patient is' : $n.' patients are').' not ready to call.', 'columns' => ['Number', 'Patient', 'Reason'], 'rows' => $rows];
    }

    /** @return array{sentence: string, columns: list<string>, rows: list<list<string>>} */
    private function frontDeskAwaitingBilling(User $actor): array
    {
        $billing = $this->frontDesk->awaitingBilling($actor);
        abort_if($billing === null, 403);
        $visits = $billing['rows'];
        if ($visits === []) {
            return ['sentence' => 'No visit is awaiting billing.', 'columns' => [], 'rows' => []];
        }
        $rows = array_map(fn (array $v): array => [(string) ($v['queueNumber'] ?? '—'), $v['patient'], 'Registered '.$v['registeredAt']], $visits);
        $n = $billing['total'];

        return ['sentence' => ($n === 1 ? '1 visit is' : $n.' visits are').' awaiting billing.'.($n > count($rows) ? ' The first '.count($rows).' are shown.' : ''), 'columns' => ['Number', 'Patient', 'Registered'], 'rows' => $rows];
    }

    /** @return array{sentence: string, columns: list<string>, rows: list<list<string>>} */
    private function frontDeskQrPending(User $actor): array
    {
        $qr = $this->frontDesk->qrPending($actor);
        abort_if($qr === null, 403);
        $total = (int) $qr['total'];

        return [
            'sentence' => $total === 0 ? 'No QR check-ins are waiting for review.' : ($total === 1 ? '1 QR check-in is waiting for review.' : $total.' QR check-ins are waiting for review.'),
            'columns' => [],
            'rows' => [],
        ];
    }

    /** @return array{sentence: string, columns: list<string>, rows: list<list<string>>} */
    private function billingOverdue(User $actor): array
    {
        $overdue = $this->billing->overdue($actor);
        abort_if($overdue === null, 403);
        if ($overdue['rows'] === []) {
            return ['sentence' => 'No bill is overdue.', 'columns' => [], 'rows' => []];
        }
        $rows = [];
        foreach ($overdue['rows'] as $row) {
            $rows[] = [(string) $row['invoiceNumber'], (string) $row['patient'], (string) $row['amountText'], (string) $row['dueText']];
        }
        $n = $overdue['count'];

        return [
            'sentence' => ($n === 1 ? '1 bill is' : $n.' bills are').' overdue.'.($n > count($rows) ? ' The '.count($rows).' most overdue are shown.' : ''),
            'columns' => ['Invoice', 'Patient', 'Remaining', 'Due'],
            'rows' => $rows,
        ];
    }

    /** @return array{sentence: string, columns: list<string>, rows: list<list<string>>} */
    private function billingOverdueTotal(User $actor): array
    {
        $overdue = $this->billing->overdue($actor);
        abort_if($overdue === null, 403);
        $n = $overdue['count'];

        return [
            'sentence' => $n === 0 ? 'Nothing is overdue.' : SpaceDisplay::rm($overdue['sen']).' is overdue across '.($n === 1 ? '1 bill.' : $n.' bills.'),
            'columns' => [],
            'rows' => [],
        ];
    }

    /** @return array{sentence: string, columns: list<string>, rows: list<list<string>>} */
    private function billingClosingsWaiting(User $actor): array
    {
        $days = $this->billing->monthDays($actor);
        abort_if($days === null, 403);
        $dates = array_values(array_map(fn (array $d): string => $d['date'], array_filter($days, fn (array $d): bool => $d['status'] === 'waiting')));

        return [
            'sentence' => $dates === [] ? 'No cash closing is waiting for approval this month.' : (count($dates) === 1 ? '1 cash closing is' : count($dates).' cash closings are').' waiting for approval: '.implode(', ', $dates).'.',
            'columns' => [],
            'rows' => [],
        ];
    }

    /** @return array{sentence: string, columns: list<string>, rows: list<list<string>>} */
    private function billingClosingsMissing(User $actor): array
    {
        $days = $this->billing->monthDays($actor);
        abort_if($days === null, 403);
        $dates = array_values(array_map(fn (array $d): string => $d['date'], array_filter($days, fn (array $d): bool => $d['status'] === 'not_counted')));

        return [
            'sentence' => $dates === [] ? 'Every day this month has a cash count.' : (count($dates) === 1 ? '1 day has' : count($dates).' days have').' no cash count: '.implode(', ', $dates).'.',
            'columns' => [],
            'rows' => [],
        ];
    }
}

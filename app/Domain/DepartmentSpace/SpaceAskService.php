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
        'consultation.longest_waiting' => ['queue.index', []],
        'consultation.not_ready' => ['queue.index', []],
        'panel.most_unclaimed' => ['panel-claims.index', []],
        'panel.overdue_amount' => ['panel-claims.index', ['tab' => 'overdue']],
        'stock.held_batches' => ['inventory.batch-holds', []],
        'stock.written_off' => ['inventory.governed-writeoffs', []],
        'stock.no_order' => ['inventory.reorder-suggestions', []],
        'hr.away_this_week' => ['hr.leave.index', ['tab' => 'calendar']],
        'hr.short_days' => ['hr.roster.index', []],
        'management.documents_attention' => ['business-details.index', []],
        'management.inactive_staff' => ['staff.index', ['status' => 'inactive']],
        'billing.over_60' => ['billing.pay-later-collections', []],
        'billing.owes_most' => ['billing.pay-later-collections', []],
        'billing.closings_waiting' => ['cash-closings.month', []],
        'billing.closings_missing' => ['cash-closings.month', []],
    ];

    public function __construct(private BranchAccessService $branches, private PharmacySpace $pharmacy, private FrontDeskSpace $frontDesk, private BillingSpace $billing, private ConsultationSpace $consultation, private PanelSpace $panel, private StockSpace $stock, private HrSpace $hr, private ManagementSpace $management, private AuditRecorder $audit) {}

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
            'billing.over_60', 'billing.owes_most' => $this->billing->payLaterRead($actor) !== null,
            'billing.closings_waiting', 'billing.closings_missing' => $this->billing->monthDays($actor) !== null,
            'consultation.longest_waiting', 'consultation.not_ready' => $this->consultation->snapshot($actor) !== null,
            'panel.most_unclaimed', 'panel.overdue_amount' => $this->panel->toClaim($actor) !== null,
            'stock.held_batches' => $this->stock->heldBatches($actor) !== null,
            'stock.written_off' => $this->stock->writtenOffThisMonth($actor) !== null,
            'stock.no_order' => $this->stock->reorderNeeded($actor) !== null,
            'hr.away_this_week' => $this->hr->away($actor) !== null,
            'hr.short_days' => $this->hr->rosterWeek($actor) !== null,
            'management.documents_attention' => $this->management->documentSummary($actor) !== null,
            'management.inactive_staff' => $this->management->inactiveStaff($actor) !== null,
            default => false,
        };
    }

    /** @return array{sentence: string, columns: list<string>, rows: list<list<string>>} */
    private function managementDocuments(User $actor): array
    {
        $docs = $this->management->documentSummary($actor);
        abort_if($docs === null, 403);
        if ($docs['attention'] === []) {
            return ['sentence' => 'Every branch is ready for its letters and has a footer.', 'columns' => [], 'rows' => []];
        }
        $rows = [];
        foreach ($docs['attention'] as $r) {
            $rows[] = [$r['name'], $r['text']];
        }

        return ['sentence' => $docs['text'], 'columns' => ['Branch', 'What is needed'], 'rows' => $rows];
    }

    /** @return array{sentence: string, columns: list<string>, rows: list<list<string>>} */
    private function managementInactiveStaff(User $actor): array
    {
        $staff = $this->management->inactiveStaff($actor);
        abort_if($staff === null, 403);
        if ($staff === []) {
            return ['sentence' => 'No staff account is inactive.', 'columns' => [], 'rows' => []];
        }
        $rows = [];
        foreach ($staff as $r) {
            $rows[] = [$r['name'], $r['role'], $r['branch']];
        }
        $n = count($rows);

        return ['sentence' => ($n === 1 ? '1 staff account is' : $n.' staff accounts are').' inactive.'.($n >= 10 ? ' The first 10 are shown.' : ''), 'columns' => ['Staff', 'Role', 'Branch'], 'rows' => $rows];
    }

    /** @return array{sentence: string, columns: list<string>, rows: list<list<string>>} */
    private function hrAwayThisWeek(User $actor): array
    {
        $away = $this->hr->away($actor);
        abort_if($away === null, 403);
        if ($away['week'] === []) {
            return ['sentence' => 'Nobody is away this week.', 'columns' => [], 'rows' => []];
        }
        $rows = [];
        foreach (array_slice($away['week'], 0, 10) as $r) {
            $rows[] = [$r['name'], $r['text']];
        }
        $n = count($away['week']);

        return ['sentence' => ($n === 1 ? '1 person is' : $n.' people are').' away this week.', 'columns' => ['Staff', 'Away'], 'rows' => $rows];
    }

    /** @return array{sentence: string, columns: list<string>, rows: list<list<string>>} */
    private function hrShortDays(User $actor): array
    {
        $week = $this->hr->rosterWeek($actor);
        abort_if($week === null, 403);
        $n = $week['shortDays'];

        return ['sentence' => 'The roster for '.$week['label'].' is '.strtolower($week['status']).'. '.($n === 0 ? 'No day is short.' : ($n === 1 ? '1 day is' : $n.' days are').' short of the minimum staffing.'), 'columns' => [], 'rows' => []];
    }

    /** @return array{sentence: string, columns: list<string>, rows: list<list<string>>} */
    private function stockHeldBatches(User $actor): array
    {
        $held = $this->stock->heldBatches($actor);
        abort_if($held === null, 403);
        if ($held['rows'] === []) {
            return ['sentence' => 'No batch is on hold.'.($held['complete'] ? '' : ' Only the first 100 batches were checked.'), 'columns' => [], 'rows' => []];
        }
        $rows = [];
        foreach (array_slice($held['rows'], 0, 10) as $r) {
            $rows[] = [$r['sku'], 'Batch '.$r['batch'], ($r['since'] !== '' ? 'On hold since '.$r['since'].' · ' : '').$r['reason']];
        }
        $n = count($held['rows']);

        return ['sentence' => ($n === 1 ? '1 batch is' : $n.' batches are').' on hold.'.($held['complete'] ? '' : ' Only the first 100 batches were checked.'), 'columns' => ['SKU', 'Batch', 'Reason'], 'rows' => $rows];
    }

    /** @return array{sentence: string, columns: list<string>, rows: list<list<string>>} */
    private function stockWrittenOff(User $actor): array
    {
        $off = $this->stock->writtenOffThisMonth($actor);
        abort_if($off === null, 403);
        if ($off['total'] === 0) {
            return ['sentence' => 'Nothing was written off in '.$off['month'].'.', 'columns' => [], 'rows' => []];
        }
        arsort($off['byReason']);
        $rows = [];
        foreach ($off['byReason'] as $reason => $n) {
            $rows[] = [ucfirst(str_replace('_', ' ', (string) $reason)), $n.($n === 1 ? ' write-off' : ' write-offs')];
        }

        return ['sentence' => $off['total'].($off['total'] === 1 ? ' write-off was' : ' write-offs were').' posted in '.$off['month'].'.'.($off['complete'] ? '' : ' Only the latest 100 cases were checked.'), 'columns' => ['Reason', 'Write-offs'], 'rows' => $rows];
    }

    /** @return array{sentence: string, columns: list<string>, rows: list<list<string>>} */
    private function stockNoOrder(User $actor): array
    {
        $need = $this->stock->reorderNeeded($actor);
        abort_if($need === null, 403);
        $none = array_values(array_filter($need['all'], fn (array $r): bool => (float) $r['onOrder'] <= 0 && (float) $r['incoming'] <= 0));
        if ($none === []) {
            return ['sentence' => 'Every low-stock item needing an order already has one on its way.', 'columns' => [], 'rows' => []];
        }
        $rows = [];
        foreach (array_slice($none, 0, 10) as $r) {
            $rows[] = [(string) $r['skuCode'], (string) $r['location'], SpaceDisplay::quantityText((string) $r['onHand'], (string) ($r['stockUnit'] ?? '')).' on hand, level '.rtrim(rtrim(number_format((float) $r['reorderLevel'], 3, '.', ''), '0'), '.')];
        }
        $n = count($none);

        return ['sentence' => ($n === 1 ? '1 low-stock item has' : $n.' low-stock items have').' nothing on order.'.($need['total'] > count($need['all']) ? ' Only the first page of suggestions was checked.' : ''), 'columns' => ['SKU', 'Location', 'Stock'], 'rows' => $rows];
    }

    /** @return array{sentence: string, columns: list<string>, rows: list<list<string>>} */
    private function panelMostUnclaimed(User $actor): array
    {
        $claim = $this->panel->toClaim($actor);
        abort_if($claim === null, 403);
        if ($claim['all'] === []) {
            return ['sentence' => 'No approved Panel invoice is waiting for a claim.', 'columns' => [], 'rows' => []];
        }
        $by = [];
        foreach ($claim['all'] as $r) {
            $by[$r['panel']]['count'] = ($by[$r['panel']]['count'] ?? 0) + 1;
            $by[$r['panel']]['sen'] = ($by[$r['panel']]['sen'] ?? 0) + $r['amountSen'];
        }
        uasort($by, fn (array $a, array $b): int => $b['sen'] <=> $a['sen']);
        $rows = [];
        foreach (array_slice($by, 0, 3, true) as $name => $v) {
            $rows[] = [(string) $name, $v['count'].($v['count'] === 1 ? ' invoice' : ' invoices'), SpaceDisplay::rm($v['sen'])];
        }

        return ['sentence' => 'The Panels with the most unclaimed.'.($claim['complete'] ? '' : ' Only the oldest 100 invoices were checked.'), 'columns' => ['Panel', 'Invoices', 'Unclaimed'], 'rows' => $rows];
    }

    /** @return array{sentence: string, columns: list<string>, rows: list<list<string>>} */
    private function panelOverdueAmount(User $actor): array
    {
        $claim = $this->panel->toClaim($actor);
        abort_if($claim === null, 403);
        $by = [];
        foreach ($claim['all'] as $r) {
            if ($r['overdue']) {
                $by[$r['panel']]['count'] = ($by[$r['panel']]['count'] ?? 0) + 1;
                $by[$r['panel']]['sen'] = ($by[$r['panel']]['sen'] ?? 0) + $r['amountSen'];
            }
        }
        if ($by === []) {
            return ['sentence' => 'No Panel has overdue claims to make.', 'columns' => [], 'rows' => []];
        }
        uasort($by, fn (array $a, array $b): int => $b['sen'] <=> $a['sen']);
        $rows = [];
        foreach (array_slice($by, 0, 10, true) as $name => $v) {
            $rows[] = [(string) $name, $v['count'].($v['count'] === 1 ? ' invoice' : ' invoices'), SpaceDisplay::rm($v['sen'])];
        }

        return ['sentence' => 'The overdue amount per Panel.'.($claim['complete'] ? '' : ' Only the oldest 100 invoices were checked.'), 'columns' => ['Panel', 'Invoices', 'Overdue'], 'rows' => $rows];
    }

    /** @return array{sentence: string, columns: list<string>, rows: list<list<string>>} */
    private function consultationLongest(User $actor): array
    {
        $snap = $this->consultation->snapshot($actor);
        abort_if($snap === null, 403);
        $waiting = $snap['waiting'];
        if ($waiting === []) {
            return ['sentence' => 'Nobody is waiting for you.', 'columns' => [], 'rows' => []];
        }
        usort($waiting, fn (array $a, array $b): int => (int) $b['minutes'] <=> (int) $a['minutes']);
        $rows = [];
        foreach (array_slice($waiting, 0, 3) as $r) {
            $rows[] = [(string) $r['queueNumber'], (string) $r['patient'], SpaceDisplay::waitText((int) $r['minutes'])];
        }

        return ['sentence' => 'Your longest waiting '.(count($rows) === 1 ? 'patient is' : 'patients are').' shown.', 'columns' => ['Number', 'Patient', 'Waiting'], 'rows' => $rows];
    }

    /** @return array{sentence: string, columns: list<string>, rows: list<list<string>>} */
    private function consultationNotReady(User $actor): array
    {
        $snap = $this->consultation->snapshot($actor);
        abort_if($snap === null, 403);
        $hits = array_values(array_filter($snap['waiting'], fn (array $r): bool => $r['notReady'] !== null));
        if ($hits === []) {
            return ['sentence' => 'None of your waiting patients is marked not ready to call.', 'columns' => [], 'rows' => []];
        }
        $rows = [];
        foreach (array_slice($hits, 0, 10) as $r) {
            $rows[] = [(string) $r['queueNumber'], (string) $r['patient'], (string) $r['notReady']];
        }
        $n = count($hits);

        return ['sentence' => ($n === 1 ? '1 patient is' : $n.' patients are').' marked not ready to call.', 'columns' => ['Number', 'Patient', 'Reason'], 'rows' => $rows];
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
    private function billingOver60(User $actor): array
    {
        $read = $this->billing->payLaterRead($actor);
        abort_if($read === null, 403);
        $hits = array_values(array_filter($read['rows'], fn (array $r): bool => (int) $r['daysOverdue'] > 60));
        if ($hits === []) {
            return ['sentence' => 'No pay-later bill is over 60 days.', 'columns' => [], 'rows' => []];
        }

        return $this->billingTable($hits, count($hits) === 1 ? '1 pay-later bill is' : count($hits).' pay-later bills are', ' over 60 days.', $read['complete']);
    }

    /** @return array{sentence: string, columns: list<string>, rows: list<list<string>>} */
    private function billingOwesMost(User $actor): array
    {
        $read = $this->billing->payLaterRead($actor);
        abort_if($read === null, 403);
        if ($read['rows'] === []) {
            return ['sentence' => 'No pay-later bill is open.', 'columns' => [], 'rows' => []];
        }
        $rows = $read['rows'];
        usort($rows, fn (array $a, array $b): int => $b['remainingSen'] <=> $a['remainingSen']);

        return $this->billingTable(array_slice($rows, 0, 5), 'The 5 largest open pay-later bills', ' are shown.', $read['complete']);
    }

    /**
     * @param  list<array<string, mixed>>  $source
     * @return array{sentence: string, columns: list<string>, rows: list<list<string>>}
     */
    private function billingTable(array $source, string $lead, string $tail, bool $complete): array
    {
        $rows = [];
        foreach (array_slice($source, 0, 10) as $row) {
            $rows[] = [(string) $row['invoiceNumber'], (string) $row['patient'], (string) $row['amountText'], (string) $row['dueText']];
        }

        return [
            'sentence' => $lead.$tail.($complete ? '' : ' Only the oldest 100 bills were checked.'),
            'columns' => ['Invoice', 'Patient', 'Remaining', 'Due'],
            'rows' => $rows,
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

<?php

namespace App\Domain\Agents\Assistant;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Dato' Arif's preset questions (AG-5, owner decision 2026-11-06), in one place: adding a question is a
 * small change to QUESTIONS and one method below. Every answer is computed in PHP from the aggregate data
 * sources and explained by a sentence from a fixed template; nothing is generated text.
 *
 * Each question names its group, label, data source, date input (`date`: one local date, up to today;
 * `today`: today only; `week`: the Monday to Sunday week holding the chosen date; `none`), and any report
 * permission the Director also needs (the stricter daily summary rules of contract section 14).
 *
 * @phpstan-type Cell array{value: int|string|null, kind: string}
 * @phpstan-type Built array{sentence: string, columns: list<array{key: string, label: string, align: 'left'|'right'}>, rows: list<list<Cell>>, totals: list<Cell>|null}
 */
final class QuickQuestions
{
    public const GROUPS = ['clinic' => 'Clinic today', 'money' => 'Money', 'panel' => 'Panel', 'stock' => 'Stock'];

    /** Rows shown in one answer; the sentence and totals still cover every row. */
    public const MAX_ROWS = 100;

    /** @var array<string, array{group: string, label: string, tool: string, date: 'date'|'today'|'week'|'none', alsoNeeds: string|null}> */
    public const QUESTIONS = [
        'clinic.patients_seen' => ['group' => 'clinic', 'label' => 'Patients seen, per branch', 'tool' => 'clinic.day_summary', 'date' => 'date', 'alsoNeeds' => null],
        'clinic.waiting_now' => ['group' => 'clinic', 'label' => 'Waiting now and average wait, per branch', 'tool' => 'clinic.day_summary', 'date' => 'today', 'alsoNeeds' => null],
        'clinic.seen_per_doctor' => ['group' => 'clinic', 'label' => 'Patients seen, per doctor', 'tool' => 'clinic.day_summary', 'date' => 'date', 'alsoNeeds' => null],
        'money.day' => ['group' => 'money', 'label' => "The day's money, per branch", 'tool' => 'money.day_summary', 'date' => 'date', 'alsoNeeds' => 'reports.daily_summary.view.branch'],
        'money.week' => ['group' => 'money', 'label' => 'Sales this week against last week', 'tool' => 'insights.sales_summary', 'date' => 'week', 'alsoNeeds' => null],
        'money.receipts_by_method' => ['group' => 'money', 'label' => 'Receipts by payment method', 'tool' => 'money.day_summary', 'date' => 'date', 'alsoNeeds' => 'reports.daily_summary.view.branch'],
        'panel.unclaimed' => ['group' => 'panel', 'label' => 'Approved Panel invoices not yet claimed, per Panel', 'tool' => 'panel.claims_summary', 'date' => 'none', 'alsoNeeds' => 'reports.panel_summary.view.branch'],
        'panel.overdue' => ['group' => 'panel', 'label' => 'Overdue unclaimed invoices, per Panel', 'tool' => 'panel.claims_summary', 'date' => 'none', 'alsoNeeds' => 'reports.panel_summary.view.branch'],
        'panel.accepted_unpaid' => ['group' => 'panel', 'label' => 'Accepted but not yet paid, per Panel', 'tool' => 'panel.claims_summary', 'date' => 'none', 'alsoNeeds' => 'reports.panel_summary.view.branch'],
        'stock.low' => ['group' => 'stock', 'label' => 'Items at or below reorder level', 'tool' => 'inventory.stock_review', 'date' => 'none', 'alsoNeeds' => null],
        'stock.expiring' => ['group' => 'stock', 'label' => 'Batches expiring within 90 days', 'tool' => 'inventory.stock_review', 'date' => 'none', 'alsoNeeds' => null],
        'stock.expired' => ['group' => 'stock', 'label' => 'Expired stock still on hand', 'tool' => 'inventory.stock_review', 'date' => 'none', 'alsoNeeds' => null],
    ];

    /** @return array{group: string, label: string, tool: string, date: 'date'|'today'|'week'|'none', alsoNeeds: string|null} */
    public static function get(string $key): array
    {
        return self::QUESTIONS[$key] ?? throw new InvalidArgumentException('Unknown question.');
    }

    /**
     * A `.branch` data source, or a question that also needs a `.branch` report permission, reads one branch at a
     * time, so "all branches" means each branch the Director works at.
     */
    public static function readsEachBranch(string $key): bool
    {
        $question = self::get($key);

        return $question['tool'] === 'inventory.stock_review' || $question['alsoNeeds'] !== null;
    }

    /**
     * Build the answer from the data source results.
     *
     * @param  list<array<string, mixed>>  $results  one result per read, in order
     * @param  array<string, string>  $scope  branchLabel, and date or from and to
     * @return Built
     */
    public static function build(string $key, array $results, array $scope): array
    {
        return match ($key) {
            'clinic.patients_seen' => self::patientsSeen($results[0], $scope),
            'clinic.waiting_now' => self::waitingNow($results[0], $scope),
            'clinic.seen_per_doctor' => self::seenPerDoctor($results[0], $scope),
            'money.day' => self::moneyDay($results[0], $scope),
            'money.week' => self::moneyWeek($results[0], $results[1], $scope),
            'money.receipts_by_method' => self::receiptsByMethod($results[0], $scope),
            'panel.unclaimed' => self::panel($results[0], 'unclaimedInvoices', 'unclaimedSen', 'Invoices not claimed', ['approved Panel invoice worth %s is not yet claimed', 'approved Panel invoices worth %s are not yet claimed']),
            'panel.overdue' => self::panel($results[0], 'overdueUnclaimedInvoices', 'overdueUnclaimedSen', 'Overdue invoices', ['approved Panel invoice worth %s is past its claim due date and not yet claimed', 'approved Panel invoices worth %s are past their claim due date and not yet claimed']),
            'panel.accepted_unpaid' => self::panel($results[0], 'acceptedUnpaidClaims', 'acceptedOutstandingSen', 'Claims accepted, unpaid', ['accepted Panel claim still has %s unpaid', 'accepted Panel claims still have %s unpaid']),
            'stock.low' => self::stock($results, 'lowStockLocations', null, 'Locations', ['item is at or below its reorder level', 'items are at or below their reorder level']),
            'stock.expiring' => self::stock($results, 'expiringBatches', 'expiringQuantity', 'Batches', ['item is expiring within 90 days', 'items are expiring within 90 days']),
            'stock.expired' => self::stock($results, 'expiredBatches', 'expiredQuantity', 'Batches', ['item is expired and still on hand', 'items are expired and still on hand']),
            default => throw new InvalidArgumentException('Unknown question.'),
        };
    }

    /**
     * Words for people: branch codes become branch names (in the branch column and the sentence) and dates in the
     * sentence read "6 Nov 2026". Codes stay the building blocks inside the templates.
     *
     * @param  Built  $built
     * @param  array<string, string>  $names  branch name by code
     * @return Built
     */
    public static function present(array $built, array $names): array
    {
        $branchColumn = array_search('branch', array_column($built['columns'], 'key'), true);
        if ($branchColumn !== false) {
            $built['rows'] = array_map(function (array $row) use ($branchColumn, $names): array {
                $code = (string) $row[$branchColumn]['value'];
                $row[$branchColumn] = self::cell($names[$code] ?? $code);

                return $row;
            }, $built['rows']);
        }
        $built['sentence'] = self::words($built['sentence'], $names);

        return $built;
    }

    /** @param array<string, string> $names */
    public static function words(string $text, array $names): string
    {
        $text = (string) preg_replace_callback('/\b(\d{4})-(\d{2})-(\d{2})\b/', fn (array $m): string => CarbonImmutable::createFromFormat('!Y-m-d', $m[0])?->format('j M Y') ?? $m[0], $text);

        // Whole upper-case words only, so a code is never replaced inside another word.
        return (string) preg_replace_callback('/\b[A-Z][A-Z0-9_]*\b/', fn (array $m): string => $names[$m[0]] ?? $m[0], $text);
    }

    /**
     * The Monday to Sunday week holding $date, ending today at the latest.
     *
     * @return array{from: string, to: string, previousFrom: string, previousTo: string}
     */
    public static function week(string $date, string $today): array
    {
        $from = CarbonImmutable::parse($date)->startOfWeek(CarbonImmutable::MONDAY);
        $to = min($from->addDays(6)->toDateString(), $today);
        $days = (int) $from->diffInDays(CarbonImmutable::parse($to));

        return ['from' => $from->toDateString(), 'to' => $to, 'previousFrom' => $from->subDays(7)->toDateString(), 'previousTo' => $from->subDays(7)->addDays($days)->toDateString()];
    }

    /** Whole sen as ringgit text, with no floating point. */
    public static function ringgit(int $sen): string
    {
        $sign = $sen < 0 ? '-' : '';
        $sen = abs($sen);

        return $sign.'RM '.number_format(intdiv($sen, 100)).'.'.str_pad((string) ($sen % 100), 2, '0', STR_PAD_LEFT);
    }

    /** @return array{value: int|string|null, kind: string} */
    private static function cell(int|string|null $value, string $kind = 'text'): array
    {
        return ['value' => $value, 'kind' => $kind];
    }

    /** @return array{key: string, label: string, align: 'left'|'right'} */
    private static function column(string $key, string $label, bool $number = false): array
    {
        return ['key' => $key, 'label' => $label, 'align' => $number ? 'right' : 'left'];
    }

    private static function plural(int $count, string $one, ?string $many = null): string
    {
        return number_format($count).' '.($count === 1 ? $one : ($many ?? $one.'s'));
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    private static function branches(array $result): array
    {
        return array_values(array_filter((array) ($result['branches'] ?? []), 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, string>  $scope
     * @return Built
     */
    private static function patientsSeen(array $result, array $scope): array
    {
        $rows = [];
        $seen = 0;
        $registered = 0;
        foreach (self::branches($result) as $branch) {
            $rows[] = [self::cell((string) $branch['branch']), self::cell((int) $branch['registered'], 'count'), self::cell((int) $branch['served'], 'count')];
            $seen += (int) $branch['served'];
            $registered += (int) $branch['registered'];
        }

        return [
            'sentence' => sprintf('%s seen on %s at %s (%s registered).', self::plural($seen, 'patient'), $scope['date'], $scope['branchLabel'], number_format($registered)),
            'columns' => [self::column('branch', 'Branch'), self::column('registered', 'Registered', true), self::column('seen', 'Seen', true)],
            'rows' => $rows,
            'totals' => count($rows) > 1 ? [self::cell('All branches'), self::cell($registered, 'count'), self::cell($seen, 'count')] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, string>  $scope
     * @return Built
     */
    private static function waitingNow(array $result, array $scope): array
    {
        $rows = [];
        $waiting = 0;
        $longest = null;
        foreach (self::branches($result) as $branch) {
            $rows[] = [self::cell((string) $branch['branch']), self::cell((int) $branch['waitingNow'], 'count'), self::cell((int) $branch['averageWaitMinutes'], 'minutes'), self::cell((int) $branch['averageConsultationMinutes'], 'minutes')];
            $waiting += (int) $branch['waitingNow'];
            if ($longest === null || (int) $branch['averageWaitMinutes'] > $longest[1]) {
                $longest = [(string) $branch['branch'], (int) $branch['averageWaitMinutes']];
            }
        }
        $sentence = sprintf('%s waiting now at %s.', self::plural($waiting, 'patient is', 'patients are'), $scope['branchLabel']);
        if ($longest !== null && $longest[1] > 0) {
            $sentence .= count($rows) > 1
                ? sprintf(' The longest average wait today is %s at %s.', self::plural($longest[1], 'minute'), $longest[0])
                : sprintf(' The average wait today is %s.', self::plural($longest[1], 'minute'));
        }

        return [
            'sentence' => $sentence,
            'columns' => [self::column('branch', 'Branch'), self::column('waiting', 'Waiting now', true), self::column('wait', 'Average wait today', true), self::column('consultation', 'Average consultation', true)],
            'rows' => $rows,
            'totals' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, string>  $scope
     * @return Built
     */
    private static function seenPerDoctor(array $result, array $scope): array
    {
        $doctors = [];
        foreach (self::branches($result) as $branch) {
            foreach ((array) $branch['doctors'] as $doctor) {
                $doctors[] = [(string) $branch['branch'], (string) $doctor['doctor'], (int) $doctor['seen'], (int) $doctor['averageConsultationMinutes'], (int) ($doctor['doctorId'] ?? 0)];
            }
        }
        usort($doctors, fn (array $a, array $b): int => [$b[2], $a[1], $a[0]] <=> [$a[2], $b[1], $b[0]]);
        $seen = array_sum(array_column($doctors, 2));
        $people = count(array_unique(array_column($doctors, 4)));

        return [
            'sentence' => sprintf('%s seen by %s on %s at %s.', self::plural($seen, 'patient'), self::plural($people, 'doctor'), $scope['date'], $scope['branchLabel']),
            'columns' => [self::column('branch', 'Branch'), self::column('doctor', 'Doctor'), self::column('seen', 'Seen', true), self::column('consultation', 'Average consultation', true)],
            'rows' => array_map(fn (array $d): array => [self::cell($d[0]), self::cell($d[1]), self::cell($d[2], 'count'), self::cell($d[3], 'minutes')], $doctors),
            'totals' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, string>  $scope
     * @return Built
     */
    private static function moneyDay(array $result, array $scope): array
    {
        $keys = ['netSalesSen', 'cashNetSen', 'nonCashNetSen', 'totalReceivedSen', 'panelApprovedSen', 'payLaterDueSen'];
        $sum = array_fill_keys($keys, 0);
        $rows = [];
        foreach (self::branches($result) as $branch) {
            $row = [self::cell((string) $branch['branch'])];
            foreach ($keys as $k) {
                $row[] = self::cell((int) $branch[$k], 'money');
                $sum[$k] += (int) $branch[$k];
            }
            $rows[] = $row;
        }

        return [
            'sentence' => sprintf('Net sales on %s at %s: %s. Received %s (cash %s, non-cash %s); Panel %s and pay later %s are not received yet.',
                $scope['date'], $scope['branchLabel'], self::ringgit($sum['netSalesSen']), self::ringgit($sum['totalReceivedSen']), self::ringgit($sum['cashNetSen']),
                self::ringgit($sum['nonCashNetSen']), self::ringgit($sum['panelApprovedSen']), self::ringgit($sum['payLaterDueSen'])),
            'columns' => [self::column('branch', 'Branch'), self::column('net', 'Net sales', true), self::column('cash', 'Cash', true), self::column('noncash', 'Non-cash', true),
                self::column('received', 'Total received', true), self::column('panel', 'Panel', true), self::column('paylater', 'Pay later', true)],
            'rows' => $rows,
            'totals' => count($rows) > 1 ? [self::cell('All branches'), ...array_map(fn (string $k): array => self::cell($sum[$k], 'money'), $keys)] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $now
     * @param  array<string, mixed>  $before
     * @param  array<string, string>  $scope
     * @return Built
     */
    private static function moneyWeek(array $now, array $before, array $scope): array
    {
        $measures = [['Net sales', 'netSen', 'money'], ['Gross item sales', 'grossSen', 'money'], ['Discounts', 'discountsSen', 'money'], ['Surcharges', 'surchargesSen', 'money'], ['Distinct patients', 'uniqueVisitors', 'count'], ['Net sales per patient', 'perVisitorSen', 'money']];
        $rows = array_values(array_map(fn (array $m): array => [self::cell($m[0]), self::cell((int) $now[$m[1]], $m[2]), self::cell((int) $before[$m[1]], $m[2]), self::cell((int) $now[$m[1]] - (int) $before[$m[1]], $m[2])], $measures));
        $difference = (int) $now['netSen'] - (int) $before['netSen'];
        $trend = match (true) {
            $difference > 0 => self::ringgit($difference).' more than',
            $difference < 0 => self::ringgit(-$difference).' less than',
            default => 'the same as',
        };

        return [
            'sentence' => sprintf('Net sales at %s from %s to %s: %s, %s the same days last week (%s to %s, %s).', $scope['branchLabel'], $scope['from'], $scope['to'],
                self::ringgit((int) $now['netSen']), $trend, (string) $before['from'], (string) $before['to'], self::ringgit((int) $before['netSen'])),
            'columns' => [self::column('measure', 'Measure'), self::column('this', 'This week', true), self::column('last', 'Same days last week', true), self::column('change', 'Change', true)],
            'rows' => $rows,
            'totals' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, string>  $scope
     * @return Built
     */
    private static function receiptsByMethod(array $result, array $scope): array
    {
        $methods = [];
        foreach (self::branches($result) as $branch) {
            foreach ((array) $branch['receiptsByMethod'] as $method) {
                $key = (string) $method['methodCode'];
                $methods[$key] ??= ['method' => (string) $method['method'], 'cash' => (bool) $method['cash'], 'receipts' => 0, 'reversed' => 0, 'net' => 0];
                $methods[$key]['receipts'] += (int) $method['receiptsSen'];
                $methods[$key]['reversed'] += (int) $method['reversedSen'];
                $methods[$key]['net'] += (int) $method['netSen'];
            }
        }
        $methods = array_values(array_filter($methods, fn (array $m): bool => $m['receipts'] !== 0 || $m['reversed'] !== 0));
        usort($methods, fn (array $a, array $b): int => [$b['net'], $a['method']] <=> [$a['net'], $b['method']]);
        $net = array_sum(array_column($methods, 'net'));
        $cash = array_sum(array_map(fn (array $m): int => $m['cash'] ? $m['net'] : 0, $methods));

        return [
            'sentence' => sprintf('%s received on %s at %s through %s; %s of it in cash.', self::ringgit($net), $scope['date'], $scope['branchLabel'], self::plural(count($methods), 'payment method'), self::ringgit($cash)),
            'columns' => [self::column('method', 'Payment method'), self::column('type', 'Type'), self::column('receipts', 'Receipts', true), self::column('reversed', 'Reversed', true), self::column('net', 'Net', true)],
            'rows' => array_map(fn (array $m): array => [self::cell($m['method']), self::cell($m['cash'] ? 'Cash' : 'Non-cash'), self::cell($m['receipts'], 'money'), self::cell($m['reversed'], 'money'), self::cell($m['net'], 'money')], $methods),
            'totals' => count($methods) > 1 ? [self::cell('All methods'), self::cell(null), self::cell(array_sum(array_column($methods, 'receipts')), 'money'), self::cell(array_sum(array_column($methods, 'reversed')), 'money'), self::cell($net, 'money')] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array{0: string, 1: string}  $template  singular and plural sentence parts
     * @return Built
     */
    private static function panel(array $result, string $countKey, string $senKey, string $countLabel, array $template): array
    {
        $panels = [];
        foreach (self::branches($result) as $branch) {
            foreach ((array) $branch['panels'] as $panel) {
                $name = (string) $panel['panel'];
                $panels[$name] ??= [0, 0];
                $panels[$name][0] += (int) $panel[$countKey];
                $panels[$name][1] += (int) $panel[$senKey];
            }
        }
        $panels = array_filter($panels, fn (array $p): bool => $p[0] > 0 || $p[1] > 0);
        uksort($panels, fn (string $a, string $b): int => [$panels[$b][1], $a] <=> [$panels[$a][1], $b]);
        $count = array_sum(array_column($panels, 0));
        $sen = array_sum(array_column($panels, 1));
        $rows = [];
        foreach ($panels as $name => [$n, $amount]) {
            $rows[] = [self::cell($name), self::cell($n, 'count'), self::cell($amount, 'money')];
        }

        return [
            'sentence' => $rows === [] ? 'Nothing to show: every Panel is up to date.' : sprintf('%s %s, across %s.', number_format($count), sprintf($template[$count === 1 ? 0 : 1], self::ringgit($sen)), self::plural(count($rows), 'Panel')),
            'columns' => [self::column('panel', 'Panel'), self::column('count', $countLabel, true), self::column('amount', 'Amount', true)],
            'rows' => $rows,
            'totals' => count($rows) > 1 ? [self::cell('All Panels'), self::cell($count, 'count'), self::cell($sen, 'money')] : null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @param  array{0: string, 1: string}  $phrase  singular and plural sentence parts
     * @return Built
     */
    private static function stock(array $results, string $countKey, ?string $quantityKey, string $countLabel, array $phrase): array
    {
        $rows = [];
        $branches = [];
        foreach ($results as $result) {
            foreach ((array) $result['skus'] as $sku) {
                if ((int) $sku[$countKey] <= 0) {
                    continue;
                }
                $branches[(string) $result['branch']] = true;
                $row = [self::cell((string) $result['branch']), self::cell((string) $sku['item']), self::cell((string) $sku['skuCode']), self::cell((int) $sku[$countKey], 'count')];
                if ($quantityKey !== null) {
                    $row[] = self::cell((string) $sku[$quantityKey].' '.(string) $sku['stockUnit'], 'quantity');
                }
                $rows[] = $row;
            }
        }
        $columns = [self::column('branch', 'Branch'), self::column('item', 'Item'), self::column('sku', 'SKU'), self::column('count', $countLabel, true)];
        if ($quantityKey !== null) {
            $columns[] = self::column('quantity', 'Quantity', true);
        }
        $where = $branches === [] ? '' : ' at '.implode(', ', array_keys($branches));

        return [
            'sentence' => $rows === [] ? 'Nothing to show: no '.$phrase[0].'.' : sprintf('%s %s%s.', number_format(count($rows)), $phrase[count($rows) === 1 ? 0 : 1], $where),
            'columns' => $columns,
            'rows' => $rows,
            'totals' => null,
        ];
    }
}

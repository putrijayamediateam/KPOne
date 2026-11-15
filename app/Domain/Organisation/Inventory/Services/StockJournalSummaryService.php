<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Shared\Services\ReportCsv;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * STK-29: the monthly journal summary, a CSV of plain journal lines for one branch or every branch and one calendar
 * month, from the same STK-27 weighted average cost figures as the STK-28 report. Download only: nothing is posted to
 * any accounting system. Director only (`inventory.valuation.view.organisation`). The lines and accounts are KPOne
 * defaults set at the owner's instruction (B.E.R.N.I.S.S acting as the clinic's accountant, 2026-11-15), not
 * professional accounting advice.
 *
 * Every movement that changes the value of stock in scope is on a line, so opening stock value + debits to Inventory −
 * credits to Inventory = closing stock value exactly (the check line). For All branches the stock value includes stock
 * in transit between branches, so transfers between branches net to zero and are left out (a note says so).
 */
final class StockJournalSummaryService
{
    private const INVENTORY = 'Inventory';

    private const COGS = 'Cost of goods sold';

    private const LOSSES = 'Stock losses';

    private const PAYABLE = 'Accounts payable (goods received)';

    /** Line key => [description, account debited when stock comes in or goes out against it]. */
    private const LINES = [
        'purchases' => ['Purchases received at cost', self::PAYABLE],
        'opening' => ['Opening balances recorded this month', 'Opening stock (to be confirmed by accountant)'],
        'transfers_in' => ['Transfers in', 'Inter-branch transfers'],
        'patient_returns' => ['Patient returns back into stock (at average cost)', self::COGS],
        'count_gains' => ['Count gains and adjustments in', self::LOSSES],
        'other_in' => ['Other stock coming in', 'Other stock movements'],
        'cogs_dispensed' => ['Cost of goods sold, dispensed', self::COGS],
        'cogs_treatment' => ['Cost of goods sold, treatment-room use', self::COGS],
        'expiry' => ['Expiry write-offs', self::LOSSES],
        'writeoff' => ['Other write-offs', self::LOSSES],
        'waste' => ['Treatment-room waste', self::LOSSES],
        'count_losses' => ['Count losses and adjustments out', self::LOSSES],
        'supplier_returns' => ['Supplier returns dispatched (at cost)', self::PAYABLE],
        'transfers_out' => ['Transfers out', 'Inter-branch transfers'],
        'other_out' => ['Other stock going out', 'Other stock movements'],
    ];

    public function __construct(private WeightedAverageCostService $cost, private AuditRecorder $audit) {}

    /**
     * The journal for one branch (or all) and one month, as data.
     *
     * @param  array<string, mixed>  $query  branch ('all' or a branch id) and month (YYYY-MM)
     * @return array<string, mixed>
     */
    public function summary(User $actor, Branch $active, array $query): array
    {
        abort_unless($actor->can(StockValuationReportService::PERMISSION), 403);
        $organisationId = (int) $actor->organisation_id;
        $branches = Branch::query()->where('organisation_id', $organisationId)->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $branchIds = array_values($branches->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $zone = (string) ($active->timezone ?: config('app.timezone'));
        $data = Validator::make($query, ['branch' => ['required', 'string', 'max:20'], 'month' => ['required', 'date_format:Y-m']],
            ['month.required' => 'Choose a month.', 'month.date_format' => 'Choose a month.'])->validate();
        $scope = (string) $data['branch'];
        if ($scope !== 'all' && ! in_array((int) $scope, $branchIds, true)) {
            throw ValidationException::withMessages(['branch' => 'Choose a branch, or All branches.']);
        }
        $first = CarbonImmutable::parse($data['month'].'-01 00:00:00', $zone);
        if ($first->toDateString() > CarbonImmutable::now($zone)->toDateString()) {
            throw ValidationException::withMessages(['month' => 'Choose a month up to this month.']);
        }
        $start = $first->utc();
        $end = $first->endOfMonth()->utc();
        $end = $end->greaterThan(CarbonImmutable::now('UTC')) ? CarbonImmutable::now('UTC') : $end;
        $all = $scope === 'all';
        $keys = $all ? [0, ...$branchIds] : [(int) $scope];

        $opening = $this->cost->replay($organisationId, $start->subSecond());
        $closing = $this->cost->replay($organisationId, $end);
        $value = function (array $replay) use ($keys, $all): int {
            $total = 0;
            foreach ($replay['positions'] as $p) {
                if (in_array($p['branch_key'], $keys, true)) {
                    $total += (int) $p['value_milli_sen'];
                }
            }

            return $total + ($all ? (int) array_sum(array_column($replay['inTransit'], 'value_milli_sen')) : 0);
        };
        $openingValue = $value($opening);
        $closingValue = $value($closing);

        $lines = array_fill_keys(array_keys(self::LINES), 0);
        $period = DB::table('stock_movements')->where('organisation_id', $organisationId)
            ->where('occurred_at', '>=', $start->toDateTimeString())->where('occurred_at', '<=', $end->toDateTimeString())->orderBy('id')->get(['id', 'movement_type']);
        foreach ($period as $row) {
            $m = $closing['movements'][(int) $row->id] ?? null;
            if ($m === null) {
                continue;
            }
            $out = $m['out_branch'] !== null && in_array($m['out_branch'], $keys, true);
            $in = $m['in_branch'] !== null && in_array($m['in_branch'], $keys, true);
            if ($all && in_array($row->movement_type, [StockMovement::TYPE_TRANSFER_DISPATCH, StockMovement::TYPE_TRANSFER_RECEIPT], true)) {
                // Into or out of the in-transit pool, which All branches counts as stock: no change in value.
                continue;
            }
            if ($in === $out) {
                continue; // inside the scope, or nothing to do with it
            }
            $lines[$this->line((string) $row->movement_type, $in)] += (int) $m['value_milli_sen'];
        }

        // Supplier credit difference: credit notes recorded this month against the cost the returned stock left at. It
        // does not touch Inventory (a credit note does not move the average): Stock losses against Accounts payable.
        $credit = $this->creditDifference($organisationId, $keys, $start, $end, $closing['movements']);

        $debits = $lines['purchases'] + $lines['opening'] + $lines['transfers_in'] + $lines['patient_returns'] + $lines['count_gains'] + $lines['other_in'];
        $credits = $lines['cogs_dispensed'] + $lines['cogs_treatment'] + $lines['expiry'] + $lines['writeoff'] + $lines['waste'] + $lines['count_losses']
            + $lines['supplier_returns'] + $lines['transfers_out'] + $lines['other_out'];
        $uncosted = 0;
        foreach ($closing['positions'] as $p) {
            if (in_array($p['branch_key'], $keys, true)) {
                $uncosted += (int) $p['uncosted_quantity'];
            }
        }

        return [
            'scope' => $all ? 'all' : (string) (int) $scope, 'scopeLabel' => $all ? 'All branches' : (string) $branches->firstWhere('id', (int) $scope)?->name,
            'month' => (string) $data['month'], 'monthEnd' => $first->endOfMonth()->toDateString(), 'zone' => $zone,
            'lines' => $lines, 'creditDifference' => $credit,
            'openingMilliSen' => $openingValue, 'closingMilliSen' => $closingValue, 'debitsMilliSen' => $debits, 'creditsMilliSen' => $credits,
            // Exactly zero by construction; the test proves it.
            'checkMilliSen' => $openingValue + $debits - $credits - $closingValue,
            'uncostedSkus' => $uncosted > 0, 'includesTransit' => $all,
        ];
    }

    /**
     * The CSV of {@see summary()}. One audit row is written first; if it cannot be written, no file is sent.
     *
     * @param  array<string, mixed>  $query
     * @return array{filename: string, csv: string}
     */
    public function csv(User $actor, Branch $active, array $query): array
    {
        $s = $this->summary($actor, $active, $query);
        $sen = fn (int $milli): string => ReportCsv::money(WeightedAverageCostService::sen($milli));
        $rows = [];
        $n = 0;
        foreach (self::LINES as $key => [$description, $account]) {
            $amount = $s['lines'][$key];
            if ($amount === 0) {
                continue;
            }
            $n++;
            $comesIn = in_array($key, ['purchases', 'opening', 'transfers_in', 'patient_returns', 'count_gains', 'other_in'], true);
            $rows[] = [(string) $n, $s['monthEnd'], $description, $comesIn ? self::INVENTORY : $account, $sen($amount), '', ''];
            $rows[] = [(string) $n, $s['monthEnd'], $description, $comesIn ? $account : self::INVENTORY, '', $sen($amount), ''];
        }
        if ($s['creditDifference']['milliSen'] !== 0) {
            $n++;
            $loss = $s['creditDifference']['milliSen'] > 0; // the returned stock cost more than the credit note
            $amount = (int) abs((int) $s['creditDifference']['milliSen']);
            $note = 'Cost of the returned stock minus the supplier credit note amount.';
            $rows[] = [(string) $n, $s['monthEnd'], 'Supplier credit difference', $loss ? self::LOSSES : self::PAYABLE, $sen($amount), '', $note];
            $rows[] = [(string) $n, $s['monthEnd'], 'Supplier credit difference', $loss ? self::PAYABLE : self::LOSSES, '', $sen($amount), $note];
        }
        $rows[] = ['', $s['monthEnd'], 'Memo: opening stock value', self::INVENTORY, '', '', $sen($s['openingMilliSen'])];
        $rows[] = ['', $s['monthEnd'], 'Memo: closing stock value', self::INVENTORY, '', '', $sen($s['closingMilliSen'])];
        $rows[] = ['', $s['monthEnd'], 'Check: opening stock value + debits − credits to Inventory = closing stock value', self::INVENTORY, '', '',
            'Difference '.$sen($s['checkMilliSen'])];
        $rounded = WeightedAverageCostService::sen($s['openingMilliSen']) + array_sum(array_map(fn (string $key): int => WeightedAverageCostService::sen($s['lines'][$key]),
            ['purchases', 'opening', 'transfers_in', 'patient_returns', 'count_gains', 'other_in'])) - array_sum(array_map(fn (string $key): int => WeightedAverageCostService::sen($s['lines'][$key]),
                ['cogs_dispensed', 'cogs_treatment', 'expiry', 'writeoff', 'waste', 'count_losses', 'supplier_returns', 'transfers_out', 'other_out'])) - WeightedAverageCostService::sen($s['closingMilliSen']);
        if ($rounded !== 0) {
            $rows[] = ['', $s['monthEnd'], 'Memo: rounding to sen', self::INVENTORY, '', '', ReportCsv::money($rounded).' (each line is rounded to sen; the check above is exact)'];
        }
        if ($s['uncostedSkus']) {
            $rows[] = ['', $s['monthEnd'], 'Note: some stock has no cost recorded and is left out of every value', '', '', '', ''];
        }
        if ($s['includesTransit']) {
            $rows[] = ['', $s['monthEnd'], 'Note: All branches includes stock in transit; transfers between branches net to zero and are left out', '', '', '', ''];
        }

        $metadata = [
            ["Management report — cost policy to be confirmed by the clinic's accountant. ".WeightedAverageCostService::DISCLAIMER],
            ['No real accounting system receives this file.'],
            ['Scope', $s['scopeLabel']], ['Month', $s['month']], ['Generated', CarbonImmutable::now($s['zone'])->format('j M Y, g:i A').' ('.$s['zone'].')'],
        ];
        $csv = ReportCsv::encode(['Line', 'Date', 'Description', 'Account', 'Debit (RM)', 'Credit (RM)', 'Note'], $rows, $metadata);
        abort_if($this->audit->record('inventory.valuation.journal_exported', null, ['scope' => $s['scope'] === 'all' ? 'all' : 'branch',
            'branch_id' => $s['scope'] === 'all' ? null : (int) $s['scope'], 'month' => $s['month'], 'rows' => count($rows)], $actor, $active) === null, 503, 'The download could not be recorded, so it was not sent.');

        return ['filename' => 'kpone-stock-journal-'.$s['month'].'-'.($s['scope'] === 'all' ? 'all-branches' : 'branch-'.$s['scope']).'.csv', 'csv' => $csv];
    }

    /** Which line a movement in or out of scope belongs to. */
    private function line(string $type, bool $in): string
    {
        return match (true) {
            $in && $type === StockMovement::TYPE_PURCHASE_RECEIPT => 'purchases',
            $in && $type === StockMovement::TYPE_OPENING => 'opening',
            $in && in_array($type, [StockMovement::TYPE_TRANSFER, StockMovement::TYPE_TRANSFER_RECEIPT, StockMovement::TYPE_TRANSFER_DISPATCH], true) => 'transfers_in',
            $in && $type === StockMovement::TYPE_DISPENSARY_RETURN => 'patient_returns',
            $in && in_array($type, [StockMovement::TYPE_STOCKTAKE_GAIN, StockMovement::TYPE_ADJUSTMENT_IN], true) => 'count_gains',
            $in => 'other_in',
            $type === StockMovement::TYPE_DISPENSE => 'cogs_dispensed',
            $type === StockMovement::TYPE_TREATMENT_USAGE => 'cogs_treatment',
            $type === StockMovement::TYPE_EXPIRY_WRITEOFF => 'expiry',
            $type === StockMovement::TYPE_STOCK_WRITEOFF => 'writeoff',
            $type === StockMovement::TYPE_TREATMENT_WASTE => 'waste',
            in_array($type, [StockMovement::TYPE_STOCKTAKE_LOSS, StockMovement::TYPE_ADJUSTMENT_OUT], true) => 'count_losses',
            $type === StockMovement::TYPE_SUPPLIER_RETURN => 'supplier_returns',
            in_array($type, [StockMovement::TYPE_TRANSFER, StockMovement::TYPE_TRANSFER_DISPATCH, StockMovement::TYPE_TRANSFER_RECEIPT], true) => 'transfers_out',
            default => 'other_out',
        };
    }

    /**
     * @param  list<int>  $keys
     * @param  array<int, array<string, mixed>>  $movements
     * @return array{milliSen: int, count: int}
     */
    private function creditDifference(int $organisationId, array $keys, CarbonImmutable $start, CarbonImmutable $end, array $movements): array
    {
        $branchKeys = array_values(array_filter($keys, fn (int $k): bool => $k !== 0));
        $credits = DB::table('inventory_supplier_return_actions as c')->join('inventory_supplier_returns as r', 'r.id', '=', 'c.return_id')
            ->where('c.organisation_id', $organisationId)->where('c.action', 'credit_recorded')->whereNotNull('c.credit_amount_sen')
            ->whereIn('r.branch_id', $branchKeys === [] ? [-1] : $branchKeys)
            ->where('c.created_at', '>=', $start->toDateTimeString())->where('c.created_at', '<=', $end->toDateTimeString())
            ->get(['c.return_id', 'c.credit_amount_sen']);
        $total = 0;
        foreach ($credits as $c) {
            $cost = 0;
            foreach (DB::table('inventory_supplier_return_actions')->where('return_id', $c->return_id)->where('action', 'dispatched')->whereNotNull('movement_id')->pluck('movement_id') as $id) {
                $cost += (int) ($movements[(int) $id]['value_milli_sen'] ?? 0);
            }
            $total += $cost - (int) $c->credit_amount_sen * 1000;
        }

        return ['milliSen' => $total, 'count' => $credits->count()];
    }
}

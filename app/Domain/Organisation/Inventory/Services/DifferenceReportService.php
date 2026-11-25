<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * STK-45 (owner, P10, 25 Nov 2026: "Lulus nota + kebenaran"; decision 18 of 20-21 Nov 2026): staff report a difference on
 * one batch at one location. The report joins the newest live STK-5 count case on that balance, or opens one through
 * GovernedStockService::openCountForReport(). It never moves stock. Whoever reported on a case never counts, recounts or
 * approves it; whoever counted or recounted it can't report on it (service and PostgreSQL). The rough count and note are
 * for the approver only; the note is encrypted and never audited.
 */
final class DifferenceReportService
{
    public const PERMISSION = 'inventory.differences.report.branch';

    public const OBSERVED = ['fewer', 'more', 'damaged_or_expired', 'unsure'];

    public const MY_REPORTS = 25;

    private const ATTEMPTS = 3;

    public const COUNTED_REFUSAL = 'You counted this batch, so another person has to report it.';

    public function __construct(private GovernedStockService $governed) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array{reportId:int,caseId:int,openedCase:bool}
     */
    public function report(User $actor, array $input): array
    {
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer'], 'retry_key' => ['required', 'uuid'],
            'balance_id' => ['required', 'integer', 'min:1'], 'balance_version' => ['required', 'integer', 'min:1'],
            'observed' => ['required', Rule::in(self::OBSERVED)],
            'rough_quantity' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:200', 'not_regex:/[\p{Cc}\p{Cf}<>]/u'],
        ], [
            'observed.required' => 'Pick what you saw.',
            'note.not_regex' => 'Use plain text only in the note.',
        ])->validate();
        $note = trim((string) ($data['note'] ?? ''));
        $rough = trim((string) ($data['rough_quantity'] ?? ''));
        if ($rough !== '') {
            try {
                $rough = StockUnitQuantity::format(StockUnitQuantity::scaled($rough, true));
            } catch (ValidationException) {
                $this->refuse('rough_quantity', 'Enter your rough count as a number of zero or more, with up to 3 decimals.');
            }
        }
        $authority = app(InventoryAuthorityService::class);
        $branch = $authority->activeBranch($actor, $data);
        $hash = hash_hmac('sha256', json_encode(['difference_report', $branch->id, (int) $data['balance_id'], (int) $data['balance_version'], $data['observed'], $rough, $note], JSON_THROW_ON_ERROR), (string) config('app.key'));

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(fn (): array => $this->write($actor, $authority, $branch, $data, $rough, $note, $hash), 3);
            } catch (StaleCountCandidate) {
                // The newest live count changed between the unlocked read and the balance lock: start again.
            }
        }

        $this->refuse('balance_id', 'The stock count for this batch changed while sending. Try again.');
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array{reportId:int,caseId:int,openedCase:bool}
     */
    private function write(User $actor, InventoryAuthorityService $authority, Branch $branch, array $data, string $rough, string $note, string $hash): array
    {
        $actor = $authority->lockForBranch($actor, $branch, self::PERMISSION);
        $existing = DB::table('inventory_difference_reports')->where('organisation_id', $actor->organisation_id)->where('reporter_user_id', $actor->id)->where('retry_key', $data['retry_key'])->first();
        if ($existing !== null) {
            if (! hash_equals($existing->payload_hash, $hash)) {
                $this->refuse('retry_key', 'This retry key was used for a different report.');
            }

            return ['reportId' => (int) $existing->id, 'caseId' => (int) $existing->case_id, 'openedCase' => (bool) $existing->opened_case];
        }
        $balanceId = (int) $data['balance_id'];
        $this->refuseUnreportable($actor, $branch, $balanceId);

        // Lock order (STK-5): case, then SKU, batch, balance. Read the candidate unlocked, lock it, lock the source, and
        // re-check under the balance lock; a changed candidate restarts the transaction.
        $candidate = $this->governed->newestLiveCountId($actor->organisation_id, $branch->id, $balanceId);
        $case = $candidate === null ? null : DB::table('inventory_governed_cases')->where('id', $candidate)->lockForUpdate()->first();
        [$sku, $batch, $location, $balance] = $this->governed->lockReportSource($actor, $branch, $balanceId);
        if ((int) $balance->lock_version !== (int) $data['balance_version']) {
            $this->refuse('balance_version', 'Stock changed. Reload before reporting.');
        }
        if ($this->governed->newestLiveCountId($actor->organisation_id, $branch->id, $balanceId) !== $candidate) {
            throw new StaleCountCandidate;
        }
        if ($case !== null) {
            if (DB::table('inventory_governed_actions')->where('case_id', $case->id)->whereIn('action', ['counted', 'recounted'])->where('actor_user_id', $actor->id)->exists()) {
                $this->refuse('balance_id', self::COUNTED_REFUSAL);
            }
            $caseId = (int) $case->id;
            $opened = false;
        } else {
            $caseId = $this->governed->openCountForReport($actor, $branch, $sku, $batch, $location, $balance, (string) $data['retry_key'], $hash);
            $opened = true;
        }
        $reportId = DB::table('inventory_difference_reports')->insertGetId([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id,
            'balance_id' => $balanceId, 'case_id' => $caseId, 'reporter_user_id' => $actor->id, 'observed' => $data['observed'],
            'rough_quantity' => $rough === '' ? null : $rough, 'base_unit' => $sku->stock_unit,
            'note_encrypted' => $note === '' ? null : Crypt::encryptString($note), 'opened_case' => $opened,
            'retry_key' => $data['retry_key'], 'payload_hash' => $hash, 'created_at' => now()->utc(),
        ]);
        abort_if(app(AuditRecorder::class)->record('inventory.difference_reported', null, ['report_id' => $reportId, 'case_id' => $caseId, 'balance_id' => $balanceId, 'observed' => $data['observed'], 'opened_case' => $opened], $actor, $branch) === null, 503, 'The report could not be audited. Nothing was saved.');

        return ['reportId' => $reportId, 'caseId' => $caseId, 'openedCase' => $opened];
    }

    private function refuseUnreportable(User $actor, Branch $branch, int $balanceId): void
    {
        $row = DB::table('inventory_stock_balances as b')->join('inventory_locations as l', 'l.id', '=', 'b.inventory_location_id')->join('inventory_skus as s', 's.id', '=', 'b.inventory_sku_id')
            ->where('b.id', $balanceId)->where('b.organisation_id', $actor->organisation_id)->where('l.branch_id', $branch->id)->first(['s.is_active as sku_active', 'l.type', 'l.is_active as location_active']);
        abort_unless($row !== null, 404);
        if (! $row->sku_active) {
            $this->refuse('balance_id', 'This medicine is deactivated, so its stock can\'t be counted.');
        }
        if (! $row->location_active) {
            $this->refuse('balance_id', 'This location is not in use, so its stock can\'t be counted.');
        }
        if ($row->type === InventoryLocation::TYPE_DISPENSARY_RETURNS) {
            $this->refuse('balance_id', 'Patient returns are counted with Stock count directly.');
        }
    }

    /**
     * Per-row state for the Report a difference menu item: whether a live count is open on the balance, and whether the
     * viewer counted or recounted it (then the sheet opens to the refusal).
     *
     * @param  list<int>  $balanceIds
     * @return array<int,array{openCount:bool,blocked:bool}>
     */
    public function rowStates(User $actor, Branch $branch, array $balanceIds): array
    {
        if ($balanceIds === []) {
            return [];
        }
        $live = $this->governed->newestLiveCountIds($actor->organisation_id, $branch->id, $balanceIds);
        $blocked = $live === [] ? [] : DB::table('inventory_governed_actions')->whereIn('case_id', array_values($live))->whereIn('action', ['counted', 'recounted'])->where('actor_user_id', $actor->id)->pluck('case_id')->map(fn ($id): int => (int) $id)->all();
        $states = [];
        foreach ($balanceIds as $id) {
            $caseId = $live[$id] ?? null;
            $states[$id] = ['openCount' => $caseId !== null, 'blocked' => $caseId !== null && in_array($caseId, $blocked, true)];
        }

        return $states;
    }

    /**
     * The Report a difference state for rows that are one balance each, for P10 holders only: a balance that can be
     * reported (active SKU and location, not patient returns) gets its id, version and row state; others are left out.
     *
     * @param  list<int>  $balanceIds
     * @return array<int,array{balanceId:int,balanceVersion:int,openCount:bool,blocked:bool}>
     */
    public function forRows(User $actor, Branch $branch, array $balanceIds): array
    {
        if ($balanceIds === [] || ! $actor->can(self::PERMISSION)) {
            return [];
        }
        $reportable = DB::table('inventory_stock_balances as b')->join('inventory_locations as l', 'l.id', '=', 'b.inventory_location_id')->join('inventory_skus as s', 's.id', '=', 'b.inventory_sku_id')
            ->where('b.organisation_id', $actor->organisation_id)->where('l.branch_id', $branch->id)->whereIn('b.id', $balanceIds)
            ->where('l.is_active', true)->where('s.is_active', true)->where('l.type', '<>', InventoryLocation::TYPE_DISPENSARY_RETURNS)
            ->pluck('b.lock_version', 'b.id')->mapWithKeys(fn ($version, $id): array => [(int) $id => (int) $version])->all();
        $states = $this->rowStates($actor, $branch, array_keys($reportable));
        $out = [];
        foreach ($reportable as $id => $version) {
            $out[$id] = ['balanceId' => $id, 'balanceVersion' => $version, ...$states[$id]];
        }

        return $out;
    }

    /**
     * The viewer's last 25 reports at the active branch, with the case state in words.
     *
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function myReports(User $actor): array
    {
        $branch = app(BranchAccessService::class)->activeBranch($actor);
        if ($branch === null || ! $actor->can(self::PERMISSION)) {
            return ['rows' => [], 'total' => 0];
        }
        $base = DB::table('inventory_difference_reports as r')->where('r.organisation_id', $actor->organisation_id)->where('r.branch_id', $branch->id)->where('r.reporter_user_id', $actor->id);
        $total = (clone $base)->count();
        $latest = DB::table('inventory_governed_actions')->select('case_id')->selectRaw('MAX(lock_version) as version')->groupBy('case_id');
        $rows = (clone $base)->join('inventory_governed_cases as c', 'c.id', '=', 'r.case_id')
            ->joinSub($latest, 'latest', 'latest.case_id', '=', 'c.id')
            ->join('inventory_governed_actions as a', fn ($j) => $j->on('a.case_id', '=', 'c.id')->on('a.lock_version', '=', 'latest.version'))
            ->join('inventory_stock_balances as bal', 'bal.id', '=', 'c.balance_id')->join('inventory_skus as s', 's.id', '=', 'c.inventory_sku_id')
            ->join('inventory_items as i', 'i.id', '=', 's.inventory_item_id')->join('inventory_batches as b', 'b.id', '=', 'c.inventory_batch_id')
            ->join('inventory_locations as l', 'l.id', '=', 'c.inventory_location_id')
            ->orderByDesc('r.id')->limit(self::MY_REPORTS)
            ->get(['r.id', 'r.observed', 'r.created_at', 'a.state', 'c.expected_balance_version', 'c.expected_quantity', 'c.base_unit', 'bal.lock_version as balance_version', 'bal.quantity as balance_quantity', 's.stock_unit', 's.sku_code', 'i.generic_name as item', 'b.batch_number', 'l.name as location']);
        $out = [];
        foreach ($rows as $row) {
            $open = in_array($row->state, GovernedStockService::OPEN_STATES, true);
            $stale = $open && ((int) $row->balance_version !== (int) $row->expected_balance_version || (string) $row->balance_quantity !== (string) $row->expected_quantity || $row->stock_unit !== $row->base_unit);
            $out[] = [
                'id' => (int) $row->id, 'item' => (string) $row->item, 'skuCode' => (string) $row->sku_code, 'batchNumber' => (string) $row->batch_number,
                'location' => (string) $row->location, 'observed' => (string) $row->observed,
                'reportedAt' => Carbon::parse($row->created_at)->setTimezone($branch->timezone)->format('j M Y, g:i A'),
                'state' => $stale ? 'stale' : (string) $row->state,
            ];
        }

        return ['rows' => $out, 'total' => $total];
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

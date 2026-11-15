<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\StaffAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * STK-24 (owner programme 2026-11-11, plan approved by B.E.R.N.I.S.S): the controlled-drug register, read from the
 * stock ledger only, for every medicine marked controlled. A running balance per SKU covers every movement in or out
 * of the branch's locations, in ledger order. A periodic check, signed by a second person, covers the movements since
 * the previous check: the checker types what is physically on hand; it never moves stock (a difference is corrected
 * through the governed stock count). The checker never recorded a movement in the range and never did the previous
 * check (service and PostgreSQL). DRAFT pending pharmacist review: no patient or prescriber is shown.
 */
final class ControlledRegisterService
{
    public const VIEW = 'inventory.controlled_register.view.branch';

    public const CHECK = 'inventory.controlled_register.check.branch';

    public const REASONS = ['count_to_follow', 'stock_elsewhere', 'investigate', 'other'];

    private const PAGE_SIZE = 25;

    private const MAX_DAYS = 365;

    private const LABELS = [
        'opening_balance' => 'Opening balance', 'purchase_receipt' => 'Received', 'dispense' => 'Dispensed', 'transfer' => 'Moved',
        'transfer_dispatch' => 'Transfer out', 'transfer_receipt' => 'Transfer in', 'stocktake_gain' => 'Count variance (gain)',
        'stocktake_loss' => 'Count variance (loss)', 'adjustment_in' => 'Adjustment in', 'adjustment_out' => 'Adjustment out',
        'expiry_writeoff' => 'Expiry write-off', 'stock_writeoff' => 'Write-off', 'supplier_return' => 'Supplier return',
        'treatment_usage' => 'Treatment room use', 'treatment_waste' => 'Treatment room waste', 'dispensary_return' => 'Patient return',
    ];

    public function __construct(private InventoryAuthorityService $authority, private AuditRecorder $audit, private StaffAccessService $staff) {}

    /**
     * One row per controlled SKU: its balance at the branch and where its checks stand.
     *
     * @return array<string, mixed>
     */
    public function index(User $actor, Branch $branch): array
    {
        $skus = $this->registerSkus($actor, $branch)->get(['s.id', 's.public_id', 's.sku_code', 's.stock_unit', 'i.generic_name', 'i.strength']);
        $ids = $skus->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $controlled = $this->controlledIds($actor);
        $ledger = $ids === [] ? collect() : $this->movements($actor, $branch)->whereIn('movement.inventory_sku_id', $ids)->groupBy('movement.inventory_sku_id')
            ->selectRaw('movement.inventory_sku_id as sku, '.$this->delta().' as balance, max(movement.id) as newest', $this->bind($branch))->get()->keyBy('sku');
        $checks = $ids === [] ? collect() : DB::table('inventory_controlled_register_checks as c')->where('c.branch_id', $branch->id)->whereIn('c.inventory_sku_id', $ids)
            ->whereRaw('c.sequence = (select max(x.sequence) from inventory_controlled_register_checks x where x.branch_id = c.branch_id and x.inventory_sku_id = c.inventory_sku_id)')
            ->get(['c.inventory_sku_id', 'c.through_movement_id', 'c.checked_at', 'c.outcome'])->keyBy('inventory_sku_id');

        return [
            'expectedBranchId' => $branch->id, 'branchName' => $branch->name,
            'rows' => array_values($skus->map(function (stdClass $sku) use ($ledger, $checks, $branch, $controlled): array {
                $row = $ledger->get($sku->id);
                $check = $checks->get($sku->id);
                $newest = $row === null ? 0 : (int) $row->newest;
                $status = match (true) {
                    ! in_array((int) $sku->id, $controlled, true) => 'no_longer_controlled',
                    $check === null => 'never',
                    (int) $check->through_movement_id === $newest => 'checked',
                    default => 'moved_since',
                };

                return ['skuPublicId' => $sku->public_id, 'medicine' => trim($sku->generic_name.' '.($sku->strength ?? '')), 'skuCode' => $sku->sku_code, 'stockUnit' => $sku->stock_unit,
                    'balance' => self::quantity($row === null ? 0 : (int) $row->balance), 'status' => $status,
                    'lastCheckedOn' => $check === null ? null : $this->local($branch, (string) $check->checked_at)->format('j M Y'), 'lastOutcome' => $check?->outcome];
            })->sortBy(fn (array $row): string => strtolower($row['medicine'].' '.$row['skuCode']))->all()),
        ];
    }

    /**
     * The register for one SKU over a branch-local date range, 25 lines a page, with opening, running and closing
     * balances, its checks and whether this viewer may check it now.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function register(User $actor, Branch $branch, string $skuPublicId, array $query, bool $all = false): array
    {
        $sku = $this->registerSkus($actor, $branch)->where('s.public_id', strtolower($skuPublicId))->first(['s.id', 's.public_id', 's.sku_code', 's.stock_unit', 'i.generic_name', 'i.strength']);
        abort_unless($sku !== null, 404);
        $controlled = in_array((int) $sku->id, $this->controlledIds($actor), true);
        [$from, $to] = $this->range($branch, $query);
        $start = CarbonImmutable::parse($from, $branch->timezone)->startOfDay()->utc();
        $end = CarbonImmutable::parse($to, $branch->timezone)->addDay()->startOfDay()->utc();
        $scope = fn (): Builder => $this->movements($actor, $branch)->where('movement.inventory_sku_id', $sku->id);
        $opening = (int) ($scope()->where('movement.occurred_at', '<', $start)->selectRaw($this->delta().' as total', $this->bind($branch))->value('total') ?? 0);
        // Light rows for the running balance across the whole range, in ledger (id) order as the checks use;
        // details only for the lines shown.
        $deltas = $scope()->where('movement.occurred_at', '>=', $start)->where('movement.occurred_at', '<', $end)->orderBy('movement.id')
            ->selectRaw('movement.id, '.$this->rowDelta().' as delta', $this->bind($branch))->get();
        $total = $deltas->count();
        $lastPage = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = $all ? 1 : min($lastPage, max(1, (int) ($query['page'] ?? 1)));
        $slice = $all ? $deltas : $deltas->slice(($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);
        $running = $opening + (int) $deltas->take(($page - 1) * self::PAGE_SIZE)->sum(fn (stdClass $row): int => (int) $row->delta);
        $closing = $opening + (int) $deltas->sum(fn (stdClass $row): int => (int) $row->delta);
        $ids = $slice->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $details = $ids === [] ? collect() : $scope()->whereIn('movement.id', $ids)->get(['movement.id', 'movement.occurred_at', 'movement.movement_type', 'movement.quantity', 'movement.reference_type',
            'movement.reference_public_id', 'movement.actor_user_id', 'batch.batch_number', 'batch.expiry_date', 'source.name as source', 'source.branch_id as source_branch',
            'destination.name as destination', 'destination.branch_id as destination_branch'])->keyBy('id');
        $checks = DB::table('inventory_controlled_register_checks')->where('branch_id', $branch->id)->where('inventory_sku_id', $sku->id)->orderByDesc('sequence')->limit(20)
            ->get(['public_id', 'sequence', 'through_movement_id', 'ledger_quantity', 'counted_quantity', 'outcome', 'reason', 'note', 'checked_by_user_id', 'checked_at']);
        $people = array_values(array_unique(array_merge($details->pluck('actor_user_id')->map(fn (mixed $id): int => (int) $id)->all(),
            $checks->pluck('checked_by_user_id')->map(fn (mixed $id): int => (int) $id)->all())));
        $names = $people === [] ? collect() : $this->staff->visibleUsers($actor)->whereIn('id', $people)->pluck('name', 'id');
        $lines = [];
        foreach ($slice as $row) {
            $movement = $details->get($row->id);
            if ($movement === null) {
                continue;
            }
            $running += (int) $row->delta;
            $quantity = StockUnitQuantity::scaled((string) $movement->quantity);
            $in = (int) $movement->destination_branch === $branch->id;
            $out = (int) $movement->source_branch === $branch->id;
            $lines[] = [
                'id' => (int) $movement->id, 'when' => $this->local($branch, (string) $movement->occurred_at)->format('j M Y, g:i A'),
                'what' => self::LABELS[$movement->movement_type] ?? Str::headline((string) $movement->movement_type),
                'in' => $in ? self::quantity($quantity) : null, 'out' => $out ? self::quantity($quantity) : null, 'balance' => self::quantity($running),
                'batch' => $movement->batch_number, 'location' => match (true) {
                    $in && $out => $movement->source.' → '.$movement->destination,
                    $in => (string) $movement->destination,
                    default => (string) $movement->source,
                },
                'reference' => Str::headline(Str::after((string) $movement->reference_type, 'inventory_')).' · '.Str::substr((string) $movement->reference_public_id, 0, 8),
                'recordedBy' => $movement->actor_user_id === null ? 'Staff member' : $names->get((int) $movement->actor_user_id, 'Staff member'),
            ];
        }

        $newest = (int) ($scope()->max('movement.id') ?? 0);
        $last = $checks->first();
        $after = $last === null ? 0 : (int) $last->through_movement_id;
        $recorders = $scope()->where('movement.id', '>', $after)->whereNotNull('movement.actor_user_id')->distinct()->pluck('movement.actor_user_id')->map(fn (mixed $id): int => (int) $id)->all();
        $blocked = match (true) {
            ! $actor->can(self::CHECK) => null,
            ! $controlled => 'This medicine is no longer marked controlled, so its register is read only.',
            $last !== null && (int) $last->checked_by_user_id === $actor->id => 'You did the previous check, so another person must check this register.',
            in_array($actor->id, $recorders, true) => 'You recorded a movement since the previous check, so another person must check this register.',
            default => null,
        };

        return [
            'expectedBranchId' => $branch->id, 'branchName' => $branch->name,
            'sku' => ['publicId' => $sku->public_id, 'medicine' => trim($sku->generic_name.' '.($sku->strength ?? '')), 'skuCode' => $sku->sku_code, 'stockUnit' => $sku->stock_unit],
            'filters' => ['from' => $from, 'to' => $to, 'label' => CarbonImmutable::parse($from)->format('j M Y').' to '.CarbonImmutable::parse($to)->format('j M Y')],
            'opening' => self::quantity($opening), 'closing' => self::quantity($closing),
            'lines' => ['page' => $page, 'lastPage' => $all ? 1 : $lastPage, 'total' => $total, 'rows' => $lines],
            'balanceNow' => self::quantity((int) ($scope()->selectRaw($this->delta().' as total', $this->bind($branch))->value('total') ?? 0)),
            'controlled' => $controlled, 'newestMovementId' => $newest, 'newestCheckSequence' => $last === null ? 0 : (int) $last->sequence, 'movementsSinceCheck' => $scope()->where('movement.id', '>', $after)->count(),
            'checks' => array_values($checks->map(fn (stdClass $check): array => [
                'publicId' => $check->public_id, 'sequence' => (int) $check->sequence, 'outcome' => $check->outcome, 'reason' => $check->reason, 'note' => $check->note,
                'ledger' => self::quantity(StockUnitQuantity::scaled((string) $check->ledger_quantity, true)), 'counted' => self::quantity(StockUnitQuantity::scaled((string) $check->counted_quantity, true)),
                'checkedBy' => $names->get((int) $check->checked_by_user_id, 'Staff member'), 'checkedOn' => $this->local($branch, (string) $check->checked_at)->format('j M Y, g:i A'),
            ])->all()),
            'can' => ['check' => $actor->can(self::CHECK) && $blocked === null],
            'checkBlockedReason' => $blocked,
            // The print lists every check signed in its dates.
            ...($all ? ['checksInRange' => array_values(DB::table('inventory_controlled_register_checks')->where('branch_id', $branch->id)->where('inventory_sku_id', $sku->id)
                ->where('checked_at', '>=', $start)->where('checked_at', '<', $end)->orderBy('sequence')
                ->get(['public_id', 'outcome', 'reason', 'ledger_quantity', 'counted_quantity', 'checked_by_user_id', 'checked_at'])
                ->map(fn (stdClass $check): array => ['publicId' => $check->public_id, 'outcome' => $check->outcome, 'reason' => $check->reason,
                    'ledger' => self::quantity(StockUnitQuantity::scaled((string) $check->ledger_quantity, true)), 'counted' => self::quantity(StockUnitQuantity::scaled((string) $check->counted_quantity, true)),
                    'checkedBy' => $this->staff->visibleUsers($actor)->where('id', (int) $check->checked_by_user_id)->value('name') ?? 'Staff member',
                    'checkedOn' => $this->local($branch, (string) $check->checked_at)->format('j M Y, g:i A')])->all())] : []),
        ];
    }

    /**
     * The printable register: the whole range on one page set. Opening it writes nothing; pressing Print records it
     * through {@see recordPrint()}.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function printable(User $actor, Branch $branch, string $skuPublicId, array $query): array
    {
        return $this->register($actor, $branch, $skuPublicId, $query, true) + ['printedOn' => CarbonImmutable::now($branch->timezone)->format('j M Y, g:i A')];
    }

    /**
     * Records one press of Print for a register and date range, with ids and dates only (a POST, as DSP-6b).
     *
     * @param  array<string, mixed>  $input
     */
    public function recordPrint(User $actor, string $skuPublicId, array $input): void
    {
        $data = Validator::make($input, ['expected_branch_id' => ['required', 'integer'], 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d']])->validate();
        $branch = $this->authority->activeBranch($actor, $data);
        abort_unless($actor->can(self::VIEW), 403);
        $register = $this->register($actor, $branch, $skuPublicId, ['from' => $data['from'], 'to' => $data['to']], true);
        $sku = InventorySku::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $register['sku']['publicId'])->firstOrFail();
        abort_if($this->audit->record('inventory.controlled_register.printed', $sku, ['inventory_sku_id' => $sku->id, 'from' => $register['filters']['from'], 'to' => $register['filters']['to'],
            'lines' => $register['lines']['total']], $actor, $branch) === null, 503, 'The print could not be recorded, so it was not printed.');
    }

    /** @param array<string, mixed> $input */
    public function check(User $actor, string $skuPublicId, array $input): string
    {
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer'], 'idempotency_key' => ['required', 'uuid'], 'seen_movement_id' => ['required', 'integer', 'min:0'],
            'seen_check_sequence' => ['required', 'integer', 'min:0'], 'counted' => ['required', 'string', 'max:20'], 'reason' => ['nullable', Rule::in(self::REASONS)], 'note' => ['nullable', 'string', 'max:200', 'not_regex:/[\p{Cc}\p{Cf}<>]/u'],
        ], ['counted.required' => 'Enter the quantity you counted.', 'reason.in' => 'Choose a reason.'], ['counted' => 'counted quantity'])->validate();
        try {
            $counted = StockUnitQuantity::scaled(trim((string) $data['counted']), true);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['counted' => collect($e->errors())->flatten()->first() ?? 'Enter an exact quantity.']);
        }
        $note = trim((string) ($data['note'] ?? ''));
        $branch = $this->authority->activeBranch($actor, $data);
        $key = strtolower((string) $data['idempotency_key']);
        $hash = hash('sha256', json_encode([strtolower($skuPublicId), (int) $data['seen_movement_id'], (int) $data['seen_check_sequence'], $counted, $data['reason'] ?? null, $note], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(fn (): string => $this->checkLocked($actor, $branch, strtolower($skuPublicId), $data, $counted, $note, $key, $hash), 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['seen_check_sequence' => 'Someone else just checked this register. Reload and check again.']);
        } catch (QueryException $e) {
            // The database's own second-person and ledger rules (23514) only fire if a movement slipped past the locks.
            if ($e->getCode() !== '23514') {
                throw $e;
            }
            throw ValidationException::withMessages(['seen_movement_id' => 'The register changed. Reload and check again.']);
        }
    }

    /** @param array<string, mixed> $data */
    private function checkLocked(User $actor, Branch $branch, string $skuPublicId, array $data, int $counted, string $note, string $key, string $hash): string
    {
        $actor = $this->authority->lockForBranch($actor, $branch, self::CHECK);
        abort_unless($actor->can(self::VIEW), 403);
        $sku = InventorySku::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $skuPublicId)->lockForUpdate()->first();
        abort_unless($sku !== null && $this->registerSkus($actor, $branch)->where('s.id', $sku->id)->exists(), 404);
        if (! in_array((int) $sku->id, $this->controlledIds($actor), true)) {
            throw ValidationException::withMessages(['counted' => 'This medicine is no longer marked controlled, so its register is read only.']);
        }
        // Every movement writer locks the stock balance it changes, so waiting on this SKU's balances at the branch
        // lets a movement already being written finish before the newest movement is read.
        DB::table('inventory_stock_balances as b')->join('inventory_locations as l', 'l.id', '=', 'b.inventory_location_id')->where('b.inventory_sku_id', $sku->id)
            ->where('l.branch_id', $branch->id)->orderBy('b.id')->lockForUpdate()->pluck('b.id');
        $existing = DB::table('inventory_controlled_register_checks')->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $key)->first(['public_id', 'request_hash', 'checked_by_user_id']);
        if ($existing !== null) {
            if ($existing->request_hash !== $hash || (int) $existing->checked_by_user_id !== $actor->id) {
                throw ValidationException::withMessages(['idempotency_key' => 'This page was already used for a different check. Reload and try again.']);
            }

            return (string) $existing->public_id;
        }
        $last = DB::table('inventory_controlled_register_checks')->where('branch_id', $branch->id)->where('inventory_sku_id', $sku->id)->orderByDesc('sequence')->lockForUpdate()->first(['sequence', 'through_movement_id', 'checked_by_user_id']);
        $scope = fn (): Builder => $this->movements($actor, $branch)->where('movement.inventory_sku_id', $sku->id);
        $newest = (int) ($scope()->max('movement.id') ?? 0);
        if (($last === null ? 0 : (int) $last->sequence) !== (int) $data['seen_check_sequence']) {
            throw ValidationException::withMessages(['seen_check_sequence' => 'Someone else just checked this register. Reload and check again.']);
        }
        if ($newest !== (int) $data['seen_movement_id']) {
            throw ValidationException::withMessages(['seen_movement_id' => 'The register changed. Reload and check again.']);
        }
        $after = $last === null ? 0 : (int) $last->through_movement_id;
        if ($last !== null && (int) $last->checked_by_user_id === $actor->id) {
            throw new AuthorizationException('You did the previous check, so another person must check this register.');
        }
        if ($scope()->where('movement.id', '>', $after)->where('movement.actor_user_id', $actor->id)->exists()) {
            throw new AuthorizationException('You recorded a movement since the previous check, so another person must check this register.');
        }
        $ledger = (int) ($scope()->selectRaw($this->delta().' as total', $this->bind($branch))->value('total') ?? 0);
        if ($ledger < 0) {
            throw ValidationException::withMessages(['counted' => 'The register shows a balance below zero. Correct it with a governed stock count first.']);
        }
        $matches = $counted === $ledger;
        if (! $matches && ($data['reason'] ?? null) === null) {
            throw ValidationException::withMessages(['reason' => 'The count differs from the register. Choose a reason.']);
        }
        if (! $matches && $data['reason'] === 'other' && $note === '') {
            throw ValidationException::withMessages(['note' => 'Add a note to explain the difference.']);
        }
        $publicId = (string) Str::uuid();
        $id = DB::table('inventory_controlled_register_checks')->insertGetId([
            'public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'inventory_sku_id' => $sku->id,
            'sequence' => $last === null ? 1 : (int) $last->sequence + 1, 'after_movement_id' => $after, 'through_movement_id' => $newest,
            'ledger_quantity' => StockUnitQuantity::format($ledger), 'counted_quantity' => StockUnitQuantity::format($counted),
            'outcome' => $matches ? 'matches' : 'differs', 'reason' => $matches ? null : $data['reason'], 'note' => $matches || $note === '' ? null : $note,
            'checked_by_user_id' => $actor->id, 'checked_at' => now()->utc(), 'idempotency_key' => $key, 'request_hash' => $hash,
        ]);
        // Ids and codes only: no quantities, names or note text in the audit.
        abort_if($this->audit->record('inventory.controlled_register.checked', $sku, ['controlled_register_check_id' => $id, 'inventory_sku_id' => $sku->id,
            'outcome' => $matches ? 'matches' : 'differs', 'reason' => $matches ? null : $data['reason']], $actor, $branch) === null, 503, 'The check could not be recorded, so nothing was saved.');

        return $publicId;
    }

    /**
     * Registers shown at the branch: SKUs marked controlled now, and any SKU already checked here, so a medicine
     * unmarked later keeps its register and signed checks (read only).
     */
    private function registerSkus(User $actor, Branch $branch): Builder
    {
        $ids = $this->controlledIds($actor);
        $checked = DB::table('inventory_controlled_register_checks')->where('branch_id', $branch->id)->distinct()->pluck('inventory_sku_id')->map(fn (mixed $id): int => (int) $id)->all();

        return DB::table('inventory_skus as s')->join('inventory_items as i', 'i.id', '=', 's.inventory_item_id')->where('s.organisation_id', $actor->organisation_id)
            ->whereIn('s.id', array_values(array_unique([...$ids, ...$checked])) ?: [0]);
    }

    /** @return list<int> */
    private function controlledIds(User $actor): array
    {
        return array_values($this->controlledSkus($actor)->pluck('s.id')->map(fn (mixed $id): int => (int) $id)->all());
    }

    /** SKUs linked to an active medicine marked controlled, in the actor's organisation. */
    private function controlledSkus(User $actor): Builder
    {
        return DB::table('inventory_skus as s')->join('inventory_items as i', 'i.id', '=', 's.inventory_item_id')->where('s.organisation_id', $actor->organisation_id)
            ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('medicine_catalogue_inventory_skus as map')->join('medicine_catalogue_items as m', 'm.id', '=', 'map.medicine_catalogue_item_id')
                ->whereColumn('map.inventory_sku_id', 's.id')->where('map.is_active', true)->where('m.is_controlled', true));
    }

    private function movements(User $actor, Branch $branch): Builder
    {
        return InventoryMovementProjection::query((int) $actor->organisation_id, $branch->id);
    }

    /**
     * One movement's change to the branch, in thousandths: in minus out (a move between two branch locations is 0).
     * Bound with the branch id twice ({@see bind()}).
     *
     * @return literal-string
     */
    private function rowDelta(): string
    {
        return '(case when destination.branch_id = ? then round(movement.quantity * 1000)::bigint else 0 end)'
            .' - (case when source.branch_id = ? then round(movement.quantity * 1000)::bigint else 0 end)';
    }

    /** @return literal-string */
    private function delta(): string
    {
        return 'coalesce(sum('.$this->rowDelta().'), 0)';
    }

    /** @return list<int> */
    private function bind(Branch $branch): array
    {
        return [(int) $branch->id, (int) $branch->id];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{0: string, 1: string}
     */
    private function range(Branch $branch, array $query): array
    {
        $today = CarbonImmutable::now($branch->timezone)->startOfDay();
        $parse = fn (mixed $value): ?CarbonImmutable => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            && ($date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $branch->timezone)) !== null && $date->format('Y-m-d') === $value ? $date : null;
        $to = $parse($query['to'] ?? null) ?? $today;
        $from = $parse($query['from'] ?? null) ?? $to->startOfMonth();
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > self::MAX_DAYS) {
            $from = $to->subDays(self::MAX_DAYS);
        }

        return [$from->format('Y-m-d'), $to->format('Y-m-d')];
    }

    private function local(Branch $branch, string $utc): CarbonImmutable
    {
        return CarbonImmutable::parse($utc, 'UTC')->setTimezone($branch->timezone);
    }

    private static function quantity(int $milli): string
    {
        $text = StockUnitQuantity::format(abs($milli));
        $text = str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;

        return ($milli < 0 ? '-' : '').$text;
    }
}

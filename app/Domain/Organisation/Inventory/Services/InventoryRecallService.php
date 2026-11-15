<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\StaffAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * STK-25 (owner programme 2026-11-11, plan approved by B.E.R.N.I.S.S): a recall blocks named batch numbers of one
 * item at every branch at once, today and for stock that arrives later (another expiry or pack size included),
 * through the shared batch eligibility rule
 * ({@see InventoryBatchEligibility}). No stock moves; recalled stock is removed through the governed write-off or a
 * supplier return. Independent of STK-2 branch holds. A Director starts a recall; a Director or a Finance Officer
 * lifts it once, never the person who started it (owner decision 2026-11-12, "recall A"; service and PostgreSQL). Notes are encrypted and never audited.
 */
final class InventoryRecallService
{
    public const MANAGE = 'inventory.recalls.manage.organisation';

    public const LIFT = 'inventory.recalls.lift.organisation';

    public const REASONS = ['supplier_recall', 'regulator_notice', 'quality_issue', 'other'];

    public const LIFT_REASONS = ['supplier_cleared', 'recall_withdrawn', 'recalled_in_error', 'other'];

    private const PAGE_SIZE = 25;

    public function __construct(private InventoryAuthorityService $authority, private AuditRecorder $audit, private StaffAccessService $staff) {}

    /** @param array<string, mixed> $input */
    public function start(User $actor, array $input): string
    {
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer'], 'idempotency_key' => ['required', 'uuid'], 'sku_public_id' => ['required', 'uuid'],
            'batch_public_ids' => ['required', 'array', 'min:1', 'max:50'], 'batch_public_ids.*' => ['required', 'uuid', 'distinct'],
            'reason' => ['required', Rule::in(self::REASONS)], 'reference' => ['nullable', 'string', 'max:60', 'not_regex:/[\p{Cc}\p{Cf}<>]/u'],
            'note' => ['nullable', 'string', 'max:300', 'not_regex:/[\p{Cc}\p{Cf}<>]/u'],
        ], ['batch_public_ids.required' => 'Tick at least one batch.', 'batch_public_ids.min' => 'Tick at least one batch.', 'reason.required' => 'Choose a reason.', 'reason.in' => 'Choose a reason.'])->validate();
        $note = trim((string) ($data['note'] ?? ''));
        if ($data['reason'] === 'other' && $note === '') {
            throw ValidationException::withMessages(['note' => 'Add a note to explain the recall.']);
        }
        $reference = trim((string) ($data['reference'] ?? ''));
        $this->authority->activeBranch($actor, $data);
        $batches = array_values(array_map('strtolower', $data['batch_public_ids']));
        sort($batches);
        $key = strtolower((string) $data['idempotency_key']);
        $hash = hash('sha256', json_encode([strtolower((string) $data['sku_public_id']), $batches, $data['reason'], $reference, $note], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($actor, $data, $batches, $note, $reference, $key, $hash): string {
                $actor = $this->authority->lockForOrganisation($actor, self::MANAGE);
                $existing = DB::table('inventory_recalls')->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $key)->first(['public_id', 'request_hash', 'started_by_user_id']);
                if ($existing !== null) {
                    if ($existing->request_hash !== $hash || (int) $existing->started_by_user_id !== $actor->id) {
                        throw ValidationException::withMessages(['idempotency_key' => 'This page was already used for a different recall. Reload and try again.']);
                    }

                    return (string) $existing->public_id;
                }
                $sku = InventorySku::query()->where('organisation_id', $actor->organisation_id)->where('public_id', strtolower((string) $data['sku_public_id']))->lockForUpdate()->first();
                abort_unless($sku !== null, 404);
                $rows = DB::table('inventory_batches')->where('organisation_id', $actor->organisation_id)->where('inventory_sku_id', $sku->id)->whereIn('public_id', $batches)
                    ->orderBy('id')->lockForUpdate()->get(['id', 'public_id']);
                if ($rows->count() !== count($batches)) {
                    throw ValidationException::withMessages(['batch_public_ids' => 'A ticked batch is not one of this SKU\'s batches. Reload and try again.']);
                }
                // One recall line per batch number (two ticked batches can share a number with different expiries).
                $keys = [];
                foreach (DB::table('inventory_batches')->whereIn('id', $rows->pluck('id'))->orderBy('id')->get(['id', 'batch_number']) as $batch) {
                    $keys[Str::upper(trim((string) $batch->batch_number))] ??= (int) $batch->id;
                }
                if (DB::table('inventory_recall_batches')->where('inventory_item_id', $sku->inventory_item_id)->whereIn('batch_key', array_keys($keys))->where('active', true)->exists()) {
                    throw ValidationException::withMessages(['batch_public_ids' => 'A ticked batch is already under an active recall. Reload to see it.']);
                }
                $publicId = (string) Str::uuid();
                $id = DB::table('inventory_recalls')->insertGetId([
                    'public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'inventory_sku_id' => $sku->id, 'reason' => $data['reason'],
                    'reference' => $reference === '' ? null : $reference, 'note_encrypted' => $note === '' ? null : Crypt::encryptString($note), 'status' => 'active',
                    'started_by_user_id' => $actor->id, 'started_at' => now()->utc(), 'lock_version' => 1, 'idempotency_key' => $key, 'request_hash' => $hash,
                ]);
                $lines = [];
                foreach ($keys as $batchKey => $batch) {
                    $lines[] = ['organisation_id' => $actor->organisation_id, 'recall_id' => $id, 'inventory_item_id' => $sku->inventory_item_id, 'batch_key' => (string) $batchKey,
                        'inventory_batch_id' => $batch, 'active' => true];
                }
                DB::table('inventory_recall_batches')->insert($lines);
                // Ids and codes only: no reference or note text.
                abort_if($this->audit->record('inventory.recall.started', $sku, ['recall_id' => $id, 'inventory_sku_id' => $sku->id, 'batches' => count($keys), 'reason' => $data['reason']], $actor) === null,
                    503, 'The recall could not be recorded, so nothing was saved.');

                return $publicId;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            // Two people recalling the same batch at the same moment: the database keeps one.
            throw ValidationException::withMessages(['batch_public_ids' => 'A ticked batch was just recalled by someone else. Reload to see it.']);
        }
    }

    /** @param array<string, mixed> $input */
    public function lift(User $actor, string $publicId, array $input): void
    {
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer'], 'lock_version' => ['required', 'integer', 'min:1'], 'retry_key' => ['required', 'uuid'],
            'reason' => ['required', Rule::in(self::LIFT_REASONS)], 'note' => ['nullable', 'string', 'max:300', 'not_regex:/[\p{Cc}\p{Cf}<>]/u'],
        ], ['reason.required' => 'Choose a reason.', 'reason.in' => 'Choose a reason.'])->validate();
        $note = trim((string) ($data['note'] ?? ''));
        if ($data['reason'] === 'other' && $note === '') {
            throw ValidationException::withMessages(['note' => 'Add a note to explain why the recall is lifted.']);
        }
        $this->authority->activeBranch($actor, $data);
        $key = strtolower((string) $data['retry_key']);
        $hash = hash('sha256', json_encode([strtolower($publicId), (int) $data['lock_version'], $data['reason'], $note], JSON_THROW_ON_ERROR));

        DB::transaction(function () use ($actor, $publicId, $data, $note, $key, $hash): void {
            $actor = $this->authority->lockForOrganisation($actor, self::LIFT);
            $recall = DB::table('inventory_recalls')->where('organisation_id', $actor->organisation_id)->where('public_id', strtolower($publicId))->first(['id', 'inventory_sku_id']);
            abort_unless($recall !== null, 404);
            // The SKU first, then the recall, as when a recall starts.
            $sku = InventorySku::query()->whereKey((int) $recall->inventory_sku_id)->lockForUpdate()->firstOrFail();
            $recall = DB::table('inventory_recalls')->where('id', $recall->id)->lockForUpdate()->sole();
            // A lift sent twice (a double press or a lost reply) is the same lift.
            if ($recall->lift_idempotency_key !== null && strtolower((string) $recall->lift_idempotency_key) === $key) {
                if ($recall->lift_request_hash !== $hash || (int) $recall->lifted_by_user_id !== $actor->id) {
                    throw ValidationException::withMessages(['retry_key' => 'This page was already used for a different lift. Reload and try again.']);
                }

                return;
            }
            if ((int) $recall->lock_version !== (int) $data['lock_version']) {
                throw ValidationException::withMessages(['lock_version' => 'This recall changed. Reload and review it again.']);
            }
            if ($recall->status !== 'active') {
                throw ValidationException::withMessages(['status' => 'This recall is already lifted.']);
            }
            if ((int) $recall->started_by_user_id === $actor->id) {
                throw new AuthorizationException('You started this recall, so another person must lift it.');
            }
            DB::table('inventory_recalls')->where('id', $recall->id)->update(['status' => 'lifted', 'lifted_by_user_id' => $actor->id, 'lifted_at' => now()->utc(),
                'lift_reason' => $data['reason'], 'lift_note_encrypted' => $note === '' ? null : Crypt::encryptString($note), 'lift_idempotency_key' => $key, 'lift_request_hash' => $hash,
                'lock_version' => (int) $recall->lock_version + 1]);
            DB::table('inventory_recall_batches')->where('recall_id', $recall->id)->where('active', true)->update(['active' => false]);
            abort_if($this->audit->record('inventory.recall.lifted', $sku, ['recall_id' => (int) $recall->id, 'inventory_sku_id' => $sku->id, 'reason' => $data['reason']], $actor) === null,
                503, 'The lift could not be recorded, so nothing was changed.');
        }, 3);
    }

    /**
     * The Recalls page. Anyone who views Inventory at the branch sees the recalls and where the stock is at this
     * branch; those who start or lift recalls (organisation-wide) see every branch and the notes.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function page(User $actor, Branch $branch, array $query): array
    {
        $tab = ($query['tab'] ?? null) === 'lifted' ? 'lifted' : 'active';
        // The removal buttons follow the permissions of the pages they open (Daniel: none for view-only staff).
        $can = ['start' => $actor->can(self::MANAGE), 'lift' => $actor->can(self::LIFT),
            'writeOff' => $actor->can(InventoryControlService::ADJUST_PERMISSION), 'returnToSupplier' => $actor->can(SupplierReturnService::REQUEST)];
        $wide = $can['start'] || $can['lift'];
        $base = DB::table('inventory_recalls as r')->join('inventory_skus as s', 's.id', '=', 'r.inventory_sku_id')->join('inventory_items as i', 'i.id', '=', 's.inventory_item_id')
            ->where('r.organisation_id', $actor->organisation_id);
        $counts = (clone $base)->groupBy('r.status')->selectRaw('r.status, count(*) as total')->pluck('total', 'status');
        $page = (clone $base)->where('r.status', $tab)->orderByDesc($tab === 'active' ? 'r.started_at' : 'r.lifted_at')->orderByDesc('r.id')
            ->paginate(self::PAGE_SIZE, ['r.id', 'r.public_id', 'r.reason', 'r.reference', 'r.note_encrypted', 'r.status', 'r.started_by_user_id', 'r.started_at', 'r.lifted_by_user_id',
                'r.lifted_at', 'r.lift_reason', 'r.lift_note_encrypted', 'r.lock_version', 's.sku_code', 's.stock_unit', 'i.generic_name', 'i.strength'], 'page', max(1, (int) ($query['page'] ?? 1)));
        /** @var list<stdClass> $recalls */
        $recalls = $page->items();
        $ids = array_map(fn (stdClass $row): int => (int) $row->id, $recalls);
        $batches = DB::table('inventory_recall_batches as rb')->join('inventory_batches as b', 'b.id', '=', 'rb.inventory_batch_id')->whereIn('rb.recall_id', $ids === [] ? [0] : $ids)
            ->orderBy('rb.batch_key')->get(['rb.recall_id', 'rb.id', 'rb.batch_key', 'b.batch_number', 'b.expiry_date'])->groupBy('recall_id');
        // Every batch of the item with a recalled number, in any pack size; shared stores (no branch) included.
        $stock = DB::table('inventory_recall_batches as rb')->join('inventory_skus as s', 's.inventory_item_id', '=', 'rb.inventory_item_id')
            ->join('inventory_batches as b', fn ($q) => $q->on('b.inventory_sku_id', '=', 's.id')->on(DB::raw('upper(btrim(b.batch_number))'), '=', 'rb.batch_key'))
            ->join('inventory_stock_balances as bal', 'bal.inventory_batch_id', '=', 'b.id')->join('inventory_locations as l', 'l.id', '=', 'bal.inventory_location_id')
            ->leftJoin('branches as br', 'br.id', '=', 'l.branch_id')
            ->whereIn('rb.recall_id', $ids === [] ? [0] : $ids)->where('bal.quantity', '>', 0)
            ->when(! $wide, fn (Builder $q) => $q->where('l.branch_id', $branch->id))
            ->orderBy('br.name')->orderBy('l.name')->orderBy('b.batch_number')
            ->get(['rb.recall_id', 'br.name as branch', 'l.name as location', 'l.type as location_type', 's.sku_code', 'b.batch_number', 'bal.quantity', 's.stock_unit']);
        $people = array_values(array_unique(array_filter(array_merge(array_map(fn (stdClass $r): int => (int) $r->started_by_user_id, $recalls), array_map(fn (stdClass $r): int => (int) $r->lifted_by_user_id, $recalls)))));
        $names = $people === [] ? collect() : $this->staff->visibleUsers($actor)->whereIn('id', $people)->pluck('name', 'id');
        $local = fn (?string $value): ?string => $value === null ? null : CarbonImmutable::parse($value, 'UTC')->setTimezone($branch->timezone)->format('j M Y, g:i A');

        return [
            'expectedBranchId' => $branch->id, 'branchName' => $branch->name, 'tab' => $tab, 'can' => $can, 'allBranches' => $wide,
            'counts' => ['active' => (int) ($counts['active'] ?? 0), 'lifted' => (int) ($counts['lifted'] ?? 0)],
            'recalls' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total(), 'rows' => array_map(function (stdClass $row) use ($batches, $stock, $names, $local, $actor, $wide): array {
                $own = collect($batches->get($row->id, []));

                return [
                    'publicId' => $row->public_id, 'medicine' => trim($row->generic_name.' '.($row->strength ?? '')), 'skuCode' => $row->sku_code, 'stockUnit' => $row->stock_unit,
                    'reason' => $row->reason, 'reference' => $wide ? $row->reference : null, 'status' => $row->status, 'lockVersion' => (int) $row->lock_version,
                    'note' => $wide ? self::decrypt($row->note_encrypted) : null,
                    'startedBy' => $names->get((int) $row->started_by_user_id, 'Staff member'), 'startedOn' => $local((string) $row->started_at),
                    'startedByYou' => (int) $row->started_by_user_id === $actor->id,
                    'liftedBy' => $row->lifted_by_user_id === null ? null : $names->get((int) $row->lifted_by_user_id, 'Staff member'), 'liftedOn' => $local($row->lifted_at),
                    'liftReason' => $row->lift_reason, 'liftNote' => $wide ? self::decrypt($row->lift_note_encrypted) : null,
                    'batches' => $own->map(fn (stdClass $b): array => ['key' => (string) $b->id, 'batchNumber' => $b->batch_number,
                        'expiry' => $b->expiry_date === null ? null : CarbonImmutable::parse((string) $b->expiry_date)->format('j M Y')])->values()->all(),
                    'whereIs' => $stock->filter(fn (stdClass $s): bool => (int) $s->recall_id === (int) $row->id)->map(fn (stdClass $s): array => [
                        'branch' => $s->branch ?? 'Shared store', 'location' => $s->location, 'returns' => $s->location_type === 'dispensary_returns', 'batchNumber' => $s->batch_number,
                        'skuCode' => $s->sku_code, 'stockUnit' => $s->stock_unit, 'quantity' => self::quantity(StockUnitQuantity::scaled((string) $s->quantity, true)),
                    ])->values()->all(),
                ];
            }, $recalls)],
        ];
    }

    /**
     * The start form's batch list for one SKU, at most 100: batches with stock first, then the latest expiry, with on
     * hand per branch (or shared store) and whether the number is already recalled. For starters only.
     *
     * @return list<array<string, mixed>>
     */
    public function batchOptions(User $actor, string $skuPublicId): array
    {
        abort_unless($actor->can(self::MANAGE), 403);
        $sku = InventorySku::query()->where('organisation_id', $actor->organisation_id)->where('public_id', strtolower($skuPublicId))->first();
        abort_unless($sku !== null, 404);
        $batches = DB::table('inventory_batches as b')->where('b.organisation_id', $actor->organisation_id)->where('b.inventory_sku_id', $sku->id)
            ->orderByRaw('CASE WHEN EXISTS (SELECT 1 FROM inventory_stock_balances x WHERE x.inventory_batch_id = b.id AND x.quantity > 0) THEN 0 ELSE 1 END')
            ->orderByDesc('b.expiry_date')->orderBy('b.batch_number')->limit(100)->get(['b.id', 'b.public_id', 'b.batch_number', 'b.expiry_date']);
        $ids = $batches->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $stock = DB::table('inventory_stock_balances as bal')->join('inventory_locations as l', 'l.id', '=', 'bal.inventory_location_id')->leftJoin('branches as br', 'br.id', '=', 'l.branch_id')
            ->whereIn('bal.inventory_batch_id', $ids === [] ? [0] : $ids)->where('bal.quantity', '>', 0)->groupBy('bal.inventory_batch_id', 'br.name')
            ->selectRaw("bal.inventory_batch_id as batch, coalesce(br.name, 'Shared store') as branch, sum(round(bal.quantity * 1000))::bigint as milli")->orderBy('branch')->get()->groupBy('batch');
        $recalled = DB::table('inventory_recall_batches')->where('inventory_item_id', $sku->inventory_item_id)->where('active', true)->pluck('batch_key')->all();

        return array_values($batches->map(fn (stdClass $b): array => [
            'publicId' => $b->public_id, 'batchNumber' => $b->batch_number, 'expiry' => $b->expiry_date === null ? null : CarbonImmutable::parse((string) $b->expiry_date)->format('j M Y'),
            'recalled' => in_array(Str::upper(trim((string) $b->batch_number)), $recalled, true),
            'onHand' => collect($stock->get($b->id, []))->map(fn (stdClass $s): array => ['branch' => $s->branch, 'quantity' => self::quantity((int) $s->milli)])->values()->all(),
        ])->all());
    }

    /** A note that can no longer be read (for example after a key change) does not take the page down. */
    private static function decrypt(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            return Crypt::decryptString((string) $value);
        } catch (DecryptException) {
            return 'This note can no longer be read.';
        }
    }

    private static function quantity(int $milli): string
    {
        $text = StockUnitQuantity::format(abs($milli));

        return ($milli < 0 ? '-' : '').rtrim(rtrim($text, '0'), '.');
    }
}

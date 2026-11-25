<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\StaffAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\BusinessDetails\BranchLetterheadAssembler;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\PurchaseOrder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Shared\PrintedPhone;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * STK-48 (owner decision 21, 20-21 Nov 2026): requests for quotation before a purchase order.
 *
 * Someone who may raise purchase orders picks the items once and one or more suppliers; KPOne makes one RFQ per
 * supplier with the same lines, grouped as a quote round. KPOne sends nothing: staff print the RFQ and mark it sent.
 * The supplier's answer is typed as an append-only quote version. One RFQ is chosen (a reason when it is not the
 * cheapest; an expired quote needs "Still valid, confirmed with the supplier"), and the chosen RFQ becomes one draft
 * purchase order through ProcurementService::create, the quoted price per stock unit as each line's estimate.
 *
 * Every action uses the purchase order create permission (no new permission). Quoted prices are cost: recording a
 * quote, choosing and converting also need cost view, and prices leave the server only for its holders.
 */
final class RfqService
{
    public const CREATE = ProcurementService::CREATE_PERMISSION;

    public const COST = SupplierTermsService::COST_PERMISSION;

    public const CHOOSE_REASONS = ['faster_delivery', 'preferred_supplier', 'stock_availability', 'other'];

    public const CANCEL_REASONS = ['not_needed', 'supplier_no_reply', 'entered_wrongly', 'other'];

    private const MAX_LINES = 30;

    private const MAX_SUPPLIERS = 6;

    private const PAGE_SIZE = 25;

    public function __construct(
        private InventoryAuthorityService $authority,
        private AuditRecorder $audit,
        private StaffAccessService $staff,
        private ProcurementService $procurement,
        private BranchAccessService $branches,
        private BranchLetterheadAssembler $letterheads,
    ) {}

    /**
     * Starts a quote round: the lines once, one draft RFQ per supplier.
     *
     * @param  array<string, mixed>  $input
     */
    public function start(User $actor, array $input): string
    {
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer'], 'idempotency_key' => ['required', 'uuid'],
            'destination_location_public_id' => ['required', 'uuid'], 'quote_by_date' => ['required', 'date_format:Y-m-d'],
            'supplier_public_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_SUPPLIERS], 'supplier_public_ids.*' => ['required', 'uuid', 'distinct'],
            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES], 'lines.*.sku_public_id' => ['required', 'uuid', 'distinct'], 'lines.*.quantity' => ['required'],
        ], [
            'lines.required' => 'Add at least one item.', 'lines.min' => 'Add at least one item.', 'lines.max' => 'A quote round holds at most 30 items.',
            'lines.*.sku_public_id.distinct' => 'This item is already in the round.', 'supplier_public_ids.required' => 'Choose at least one supplier.',
            'supplier_public_ids.min' => 'Choose at least one supplier.', 'supplier_public_ids.max' => 'Choose at most 6 suppliers.',
            'supplier_public_ids.*.distinct' => 'This supplier is already chosen.', 'destination_location_public_id.required' => 'Choose where the stock should go.',
            'quote_by_date.required' => 'Choose the date to quote by.', 'quote_by_date.date_format' => 'Choose the date to quote by.',
        ])->validate();
        $lines = [];
        foreach ($data['lines'] as $index => $line) {
            try {
                $quantity = StockUnitQuantity::scaled((string) $line['quantity']);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(["lines.{$index}.quantity" => collect($e->errors())->flatten()->first() ?? 'Enter a quantity above zero.']);
            }
            if ($quantity > 1_000_000_000) {
                throw ValidationException::withMessages(["lines.{$index}.quantity" => 'Enter at most 1,000,000 stock units.']);
            }
            $lines[] = ['sku' => strtolower((string) $line['sku_public_id']), 'quantity' => $quantity];
        }
        $suppliers = array_map(fn (mixed $id): string => strtolower((string) $id), $data['supplier_public_ids']);
        $branch = $this->authority->activeBranch($actor, $data);
        $today = now()->setTimezone($branch->timezone)->toDateString();
        if ($data['quote_by_date'] < $today || $data['quote_by_date'] > CarbonImmutable::parse($today)->addDays(90)->toDateString()) {
            throw ValidationException::withMessages(['quote_by_date' => 'Choose a date from today to 90 days ahead.']);
        }
        $key = strtolower((string) $data['idempotency_key']);
        $hash = hash('sha256', json_encode([strtolower((string) $data['destination_location_public_id']), $data['quote_by_date'], $suppliers, $lines], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $branch, $data, $lines, $suppliers, $key, $hash): string {
            $actor = $this->authority->lockForBranch($actor, $branch, self::CREATE);
            $existing = DB::table('inventory_rfq_rounds')->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $key)->first(['public_id', 'request_hash', 'created_by_user_id']);
            if ($existing !== null) {
                if ($existing->request_hash !== $hash || (int) $existing->created_by_user_id !== $actor->id) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This page was already used for a different quote round. Reload and try again.']);
                }

                return (string) $existing->public_id;
            }
            $location = $this->deliveryLocation($actor, $branch, (string) $data['destination_location_public_id']);
            $supplierRows = DB::table('inventory_suppliers')->where('organisation_id', $actor->organisation_id)->whereIn('public_id', $suppliers)->where('is_active', true)
                ->orderBy('id')->lockForUpdate()->get(['id', 'public_id'])->keyBy('public_id');
            foreach ($suppliers as $index => $supplier) {
                if (! $supplierRows->has($supplier)) {
                    throw ValidationException::withMessages(["supplier_public_ids.{$index}" => 'Choose an active supplier.']);
                }
            }
            $skus = DB::table('inventory_skus')->where('organisation_id', $actor->organisation_id)->where('is_active', true)->whereIn('public_id', array_column($lines, 'sku'))->pluck('id', 'public_id');
            foreach ($lines as $index => $line) {
                if (! $skus->has($line['sku'])) {
                    throw ValidationException::withMessages(["lines.{$index}.sku_public_id" => 'Choose an active item.']);
                }
            }
            $publicId = (string) Str::uuid();
            $roundId = DB::table('inventory_rfq_rounds')->insertGetId([
                'public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'destination_location_id' => $location->id,
                'quote_by_date' => $data['quote_by_date'], 'status' => 'open', 'created_by_user_id' => $actor->id, 'created_at' => now()->utc(),
                'lock_version' => 1, 'idempotency_key' => $key, 'request_hash' => $hash,
            ]);
            DB::table('inventory_rfq_round_lines')->insert(array_map(fn (array $line): array => ['organisation_id' => $actor->organisation_id, 'rfq_round_id' => $roundId,
                'inventory_sku_id' => (int) $skus[$line['sku']], 'quantity' => StockUnitQuantity::format($line['quantity'])], $lines));
            $rfqIds = [];
            foreach ($suppliers as $supplier) {
                $rfqIds[] = DB::table('inventory_rfqs')->insertGetId([
                    'public_id' => (string) Str::uuid(), 'rfq_number' => 'RFQ-'.now()->utc()->format('Ym').'-'.Str::upper(Str::random(8)), 'organisation_id' => $actor->organisation_id,
                    'rfq_round_id' => $roundId, 'supplier_id' => (int) $supplierRows[$supplier]->id, 'status' => 'draft', 'lock_version' => 1,
                ]);
            }
            $this->record('inventory.rfq_round.started', ['rfq_round_id' => $roundId, 'rfq_ids' => $rfqIds, 'lines' => count($lines), 'suppliers' => count($suppliers)], $actor, $branch);

            return $publicId;
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function markSent(User $actor, string $rfqPublicId, array $input): void
    {
        $data = Validator::make($input, ['expected_branch_id' => ['required', 'integer'], 'lock_version' => ['required', 'integer', 'min:1']])->validate();
        $branch = $this->authority->activeBranch($actor, $data);

        DB::transaction(function () use ($actor, $branch, $rfqPublicId, $data): void {
            $actor = $this->authority->lockForBranch($actor, $branch, self::CREATE);
            [$round, $rfq] = $this->lockRfq($actor, $branch, $rfqPublicId);
            $this->assertVersion($rfq, (int) $data['lock_version']);
            $this->assertRoundOpen($round);
            if ($rfq->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Only an RFQ that has not been sent or answered can be marked as sent.']);
            }
            DB::table('inventory_rfqs')->where('id', $rfq->id)->update(['status' => 'sent', 'sent_by_user_id' => $actor->id, 'sent_at' => now()->utc(), 'lock_version' => (int) $rfq->lock_version + 1]);
            $this->record('inventory.rfq.sent', ['rfq_round_id' => (int) $round->id, 'rfq_id' => (int) $rfq->id], $actor, $branch);
        }, 3);
    }

    /**
     * Records the supplier's answer as a new, append-only quote version. A quote can be corrected until it is chosen.
     *
     * @param  array<string, mixed>  $input
     */
    public function recordQuote(User $actor, string $rfqPublicId, array $input): int
    {
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer'], 'lock_version' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['required', 'uuid'],
            'lead_time_days' => ['required', 'integer', 'min:0', 'max:365'], 'valid_until' => ['required', 'date_format:Y-m-d'],
            'quote_reference' => ['nullable', 'string', 'max:60', 'not_regex:/[\p{Cc}\p{Cf}<>]/u'],
            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES], 'lines.*.line_id' => ['required', 'integer', 'distinct'],
            'lines.*.cannot_supply' => ['required', 'boolean'], 'lines.*.pack_price' => ['nullable', 'string'], 'lines.*.pack_size' => ['nullable', 'string'],
        ], [
            'lead_time_days.required' => 'Enter the lead time in days.', 'lead_time_days.integer' => 'Enter the lead time in whole days.',
            'lead_time_days.min' => 'Enter the lead time in whole days.', 'lead_time_days.max' => 'Enter at most 365 days.',
            'valid_until.required' => 'Choose the date the quote is valid until.', 'valid_until.date_format' => 'Choose the date the quote is valid until.',
            'quote_reference.max' => 'The reference may be at most 60 characters.',
        ])->validate();
        $answers = [];
        foreach ($data['lines'] as $index => $line) {
            $cannot = filter_var($line['cannot_supply'], FILTER_VALIDATE_BOOLEAN);
            if ($cannot) {
                $answers[(int) $line['line_id']] = ['cannot' => true, 'price' => null, 'pack' => null];

                continue;
            }
            $price = trim((string) ($line['pack_price'] ?? ''));
            if (preg_match('/^(\d{1,6})(?:\.(\d{1,2}))?$/D', $price, $m) !== 1) {
                throw ValidationException::withMessages(["lines.{$index}.pack_price" => 'Enter the pack price in RM, for example 12.00.']);
            }
            try {
                $pack = StockUnitQuantity::scaled(trim((string) ($line['pack_size'] ?? '')));
            } catch (ValidationException) {
                throw ValidationException::withMessages(["lines.{$index}.pack_size" => 'Enter how many stock units are in one pack.']);
            }
            if ($pack > 100_000_000) {
                throw ValidationException::withMessages(["lines.{$index}.pack_size" => 'Enter at most 100,000 stock units in one pack.']);
            }
            $answers[(int) $line['line_id']] = ['cannot' => false, 'price' => (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0'), 'pack' => $pack];
        }
        $reference = trim((string) ($data['quote_reference'] ?? ''));
        $branch = $this->authority->activeBranch($actor, $data);
        $today = now()->setTimezone($branch->timezone)->toDateString();
        if ($data['valid_until'] < $today || $data['valid_until'] > CarbonImmutable::parse($today)->addDays(365)->toDateString()) {
            throw ValidationException::withMessages(['valid_until' => 'Choose a date from today to one year ahead.']);
        }
        ksort($answers);
        $key = strtolower((string) $data['idempotency_key']);
        $hash = hash('sha256', json_encode([strtolower($rfqPublicId), (int) $data['lead_time_days'], $data['valid_until'], $reference, $answers], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $branch, $rfqPublicId, $data, $answers, $reference, $key, $hash): int {
            $actor = $this->lockWithCost($actor, $branch);
            [$round, $rfq] = $this->lockRfq($actor, $branch, $rfqPublicId);
            $existing = DB::table('inventory_rfq_quotes')->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $key)->first(['rfq_id', 'version', 'request_hash', 'recorded_by_user_id']);
            if ($existing !== null) {
                if ($existing->request_hash !== $hash || (int) $existing->recorded_by_user_id !== $actor->id || (int) $existing->rfq_id !== (int) $rfq->id) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This form was already used for a different quote. Reload and try again.']);
                }

                return (int) $existing->version;
            }
            $this->assertVersion($rfq, (int) $data['lock_version']);
            $this->assertRoundOpen($round);
            if (! in_array($rfq->status, ['draft', 'sent', 'quoted'], true)) {
                throw ValidationException::withMessages(['status' => 'This quote can no longer be changed.']);
            }
            $lineIds = DB::table('inventory_rfq_round_lines')->where('rfq_round_id', $round->id)->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
            if (array_keys($answers) !== $lineIds) {
                throw ValidationException::withMessages(['lines' => 'Answer every item: a price, or "can\'t supply".']);
            }
            // A supplier may say "can't supply" to every item; such an RFQ is then never chosen.
            $version = 1 + (int) DB::table('inventory_rfq_quotes')->where('rfq_id', $rfq->id)->max('version');
            $quoteId = DB::table('inventory_rfq_quotes')->insertGetId([
                'organisation_id' => $actor->organisation_id, 'rfq_id' => $rfq->id, 'version' => $version, 'lead_time_days' => (int) $data['lead_time_days'],
                'valid_until' => $data['valid_until'], 'quote_reference' => $reference === '' ? null : $reference, 'recorded_by_user_id' => $actor->id,
                'recorded_at' => now()->utc(), 'idempotency_key' => $key, 'request_hash' => $hash,
            ]);
            DB::table('inventory_rfq_quote_lines')->insert(array_map(fn (int $lineId): array => ['organisation_id' => $actor->organisation_id, 'rfq_quote_id' => $quoteId,
                'rfq_round_line_id' => $lineId, 'cannot_supply' => $answers[$lineId]['cannot'], 'pack_price_sen' => $answers[$lineId]['price'],
                'pack_size' => $answers[$lineId]['pack'] === null ? null : StockUnitQuantity::format($answers[$lineId]['pack'])], $lineIds));
            DB::table('inventory_rfqs')->where('id', $rfq->id)->update(['status' => 'quoted', 'current_quote_id' => $quoteId, 'lock_version' => (int) $rfq->lock_version + 1]);
            // Ids and counts only: never a price, the reference or a note.
            $this->record('inventory.rfq.quote_recorded', ['rfq_round_id' => (int) $round->id, 'rfq_id' => (int) $rfq->id, 'version' => $version,
                'cannot_supply' => count(array_filter($answers, fn (array $a): bool => $a['cannot']))], $actor, $branch);

            return $version;
        }, 3);
    }

    /**
     * Chooses one RFQ of the round. Not an approval: approval stays on the purchase order.
     *
     * @param  array<string, mixed>  $input
     */
    public function choose(User $actor, string $rfqPublicId, array $input): void
    {
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer'], 'lock_version' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', Rule::in(self::CHOOSE_REASONS)], 'note' => ['nullable', 'string', 'max:200', 'not_regex:/[\p{Cc}\p{Cf}<>]/u'],
            'still_valid_confirmed' => ['nullable', 'boolean'],
        ], ['reason.in' => 'Choose a reason.', 'note.max' => 'The note may be at most 200 characters.'])->validate();
        $note = trim((string) ($data['note'] ?? ''));
        $branch = $this->authority->activeBranch($actor, $data);

        DB::transaction(function () use ($actor, $branch, $rfqPublicId, $data, $note): void {
            $actor = $this->lockWithCost($actor, $branch);
            $round = $this->lockRoundOf($actor, $branch, $rfqPublicId);
            $rfqs = $this->lockRfqsOfRound((int) $round->id);
            $rfq = $rfqs->firstWhere('public_id', strtolower($rfqPublicId));
            abort_unless($rfq !== null, 404);
            if ($rfq->status === 'chosen' && (int) $rfq->chosen_by_user_id === $actor->id && (int) $rfq->lock_version === (int) $data['lock_version'] + 1) {
                // The same person pressed Choose again after a lost response.
                return;
            }
            $this->assertVersion($rfq, (int) $data['lock_version']);
            $this->assertRoundOpen($round);
            if ($rfqs->contains(fn (stdClass $r): bool => $r->status === 'chosen')) {
                throw ValidationException::withMessages(['status' => 'Another quote in this round is already chosen. Cancel it first to choose a different one.']);
            }
            if ($rfq->status !== 'quoted') {
                throw ValidationException::withMessages(['status' => 'Only a recorded quote can be chosen.']);
            }
            $comparison = $this->comparison($round, $rfqs);
            $quote = DB::table('inventory_rfq_quotes')->where('id', $rfq->current_quote_id)->first(['valid_until']);
            if ($comparison['totals'][(int) $rfq->id]['supplied'] === 0) {
                throw ValidationException::withMessages(['status' => 'This supplier can\'t supply any item, so the quote cannot be chosen.']);
            }
            $expired = (string) $quote->valid_until < now()->setTimezone($branch->timezone)->toDateString();
            $confirmed = filter_var($data['still_valid_confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($expired && ! $confirmed) {
                throw ValidationException::withMessages(['still_valid_confirmed' => 'This quote has expired. Tick "Still valid, confirmed with the supplier" to continue.']);
            }
            $cheapest = $comparison['cheapestTotal'] === (int) $rfq->id;
            $reason = $data['reason'] ?? null;
            if (! $cheapest && $reason === null) {
                throw ValidationException::withMessages(['reason' => 'This is not the cheapest quote. Choose a reason.']);
            }
            if ($reason === 'other' && $note === '') {
                throw ValidationException::withMessages(['note' => 'Say why in a short note when the reason is Other.']);
            }
            DB::table('inventory_rfqs')->where('id', $rfq->id)->update(['status' => 'chosen', 'chosen_by_user_id' => $actor->id, 'chosen_at' => now()->utc(),
                'chosen_reason' => $cheapest ? null : $reason, 'chosen_note' => ! $cheapest && $reason === 'other' ? $note : null,
                'expired_confirmed' => $expired, 'lock_version' => (int) $rfq->lock_version + 1]);
            $this->record('inventory.rfq.chosen', ['rfq_round_id' => (int) $round->id, 'rfq_id' => (int) $rfq->id, 'cheapest' => $cheapest,
                'reason' => $cheapest ? null : $reason, 'expired_confirmed' => $expired], $actor, $branch);
        }, 3);
    }

    /**
     * Makes the chosen RFQ a draft purchase order. A retry with the same key returns the order already made.
     *
     * @param  array<string, mixed>  $input
     * @return array{order: string, leftOff: array<int, string>}
     */
    public function convert(User $actor, string $roundPublicId, array $input): array
    {
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer'], 'lock_version' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['required', 'uuid'],
        ])->validate();
        $branch = $this->authority->activeBranch($actor, $data);
        $key = strtolower((string) $data['idempotency_key']);

        return DB::transaction(function () use ($actor, $branch, $roundPublicId, $data, $key): array {
            $actor = $this->lockWithCost($actor, $branch);
            $round = $this->lockRound($actor, $branch, $roundPublicId);
            $rfqs = $this->lockRfqsOfRound((int) $round->id);
            $chosen = $rfqs->firstWhere('status', 'chosen');
            if ($round->status === 'converted' && $round->convert_idempotency_key === $key && $chosen !== null && $chosen->purchase_order_id !== null) {
                // The same press again after a lost response: the order already made.
                return ['order' => (string) DB::table('inventory_purchase_orders')->where('id', $chosen->purchase_order_id)->value('public_id'), 'leftOff' => $this->leftOff((int) $chosen->current_quote_id)];
            }
            if ((int) $round->lock_version !== (int) $data['lock_version']) {
                throw ValidationException::withMessages(['lock_version' => 'This quote round changed. Reload and review it again.']);
            }
            $this->assertRoundOpen($round);
            if ($chosen === null) {
                throw ValidationException::withMessages(['status' => 'Choose a quote first.']);
            }
            if ($chosen->purchase_order_id !== null) {
                throw ValidationException::withMessages(['status' => 'This quote round already has a purchase order.']);
            }
            $supplier = DB::table('inventory_suppliers')->where('id', $chosen->supplier_id)->lockForUpdate()->first(['public_id', 'is_active']);
            if ($supplier === null || ! $supplier->is_active) {
                throw ValidationException::withMessages(['status' => 'This supplier has been archived since, so no purchase order can be made. Cancel the round, or choose another quote.']);
            }
            $location = DB::table('inventory_locations')->where('id', $round->destination_location_id)->first(['public_id', 'is_active']);
            if ($location === null || ! $location->is_active) {
                throw ValidationException::withMessages(['status' => 'The delivery location is no longer active. Cancel the round and start a new one.']);
            }
            $quoteLines = DB::table('inventory_rfq_round_lines as l')->join('inventory_skus as s', 's.id', '=', 'l.inventory_sku_id')
                ->join('inventory_rfq_quote_lines as q', fn ($join) => $join->on('q.rfq_round_line_id', '=', 'l.id')->where('q.rfq_quote_id', '=', $chosen->current_quote_id))
                ->where('l.rfq_round_id', $round->id)->orderBy('l.id')->get(['l.id', 'l.quantity', 's.public_id as sku_public_id', 'q.cannot_supply', 'q.pack_price_sen', 'q.pack_size']);
            $orderLines = [];
            foreach ($quoteLines as $line) {
                if (! $line->cannot_supply) {
                    $orderLines[] = ['sku_public_id' => (string) $line->sku_public_id, 'quantity' => StockUnitQuantity::format(StockUnitQuantity::scaled((string) $line->quantity)),
                        'unit_cost_sen' => RfqComparison::unitSen((int) $line->pack_price_sen, StockUnitQuantity::scaled((string) $line->pack_size))];
                }
            }
            if ($orderLines === []) {
                throw ValidationException::withMessages(['status' => 'The chosen supplier can\'t supply any item.']);
            }
            try {
                $order = $this->procurement->create($actor, ['expected_branch_id' => $branch->id, 'supplier_public_id' => (string) $supplier->public_id,
                    'destination_location_public_id' => (string) $location->public_id, 'lines' => $orderLines]);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(['status' => collect($e->errors())->flatten()->first() ?? 'The draft order could not be made.']);
            }
            DB::table('inventory_rfqs')->where('id', $chosen->id)->update(['purchase_order_id' => $order->id, 'lock_version' => (int) $chosen->lock_version + 1]);
            $closed = [];
            foreach ($rfqs as $other) {
                if (in_array($other->status, ['draft', 'sent', 'quoted'], true)) {
                    DB::table('inventory_rfqs')->where('id', $other->id)->update(['status' => 'not_chosen', 'closed_by_user_id' => $actor->id, 'closed_at' => now()->utc(), 'lock_version' => (int) $other->lock_version + 1]);
                    $closed[] = (int) $other->id;
                }
            }
            DB::table('inventory_rfq_rounds')->where('id', $round->id)->update(['status' => 'converted', 'closed_by_user_id' => $actor->id, 'closed_at' => now()->utc(),
                'convert_idempotency_key' => $key, 'lock_version' => (int) $round->lock_version + 1]);
            $leftOff = $this->leftOff((int) $chosen->current_quote_id);
            $this->record('inventory.rfq_round.converted', ['rfq_round_id' => (int) $round->id, 'rfq_id' => (int) $chosen->id, 'purchase_order_id' => $order->id,
                'quote_id' => (int) $chosen->current_quote_id, 'lines' => count($orderLines), 'lines_left_off' => count($leftOff), 'not_chosen_rfq_ids' => $closed], $actor, $branch, $order);

            return ['order' => (string) $order->public_id, 'leftOff' => $leftOff];
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function cancelRfq(User $actor, string $rfqPublicId, array $input): void
    {
        [$reason, $note, $data] = $this->cancelInput($input, true);
        $branch = $this->authority->activeBranch($actor, $data);

        DB::transaction(function () use ($actor, $branch, $rfqPublicId, $data, $reason, $note): void {
            $actor = $this->authority->lockForBranch($actor, $branch, self::CREATE);
            $round = $this->lockRoundOf($actor, $branch, $rfqPublicId);
            $rfqs = $this->lockRfqsOfRound((int) $round->id);
            $rfq = $rfqs->firstWhere('public_id', strtolower($rfqPublicId));
            abort_unless($rfq !== null, 404);
            $this->assertVersion($rfq, (int) $data['lock_version']);
            $this->assertRoundOpen($round);
            if (! in_array($rfq->status, ['draft', 'sent', 'quoted', 'chosen'], true) || $rfq->purchase_order_id !== null) {
                throw ValidationException::withMessages(['status' => 'This RFQ can no longer be cancelled.']);
            }
            DB::table('inventory_rfqs')->where('id', $rfq->id)->update(['status' => 'cancelled', 'cancel_reason' => $reason, 'cancel_note' => $note,
                'closed_by_user_id' => $actor->id, 'closed_at' => now()->utc(), 'lock_version' => (int) $rfq->lock_version + 1]);
            $this->record('inventory.rfq.cancelled', ['rfq_round_id' => (int) $round->id, 'rfq_id' => (int) $rfq->id, 'reason' => $reason, 'was_chosen' => $rfq->status === 'chosen'], $actor, $branch);
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function cancelRound(User $actor, string $roundPublicId, array $input): void
    {
        [$reason, $note, $data] = $this->cancelInput($input, false);
        $branch = $this->authority->activeBranch($actor, $data);

        DB::transaction(function () use ($actor, $branch, $roundPublicId, $data, $reason, $note): void {
            $actor = $this->authority->lockForBranch($actor, $branch, self::CREATE);
            $round = $this->lockRound($actor, $branch, $roundPublicId);
            $rfqs = $this->lockRfqsOfRound((int) $round->id);
            if ((int) $round->lock_version !== (int) $data['lock_version']) {
                throw ValidationException::withMessages(['lock_version' => 'This quote round changed. Reload and review it again.']);
            }
            $this->assertRoundOpen($round);
            $cancelled = [];
            foreach ($rfqs as $rfq) {
                if (in_array($rfq->status, ['draft', 'sent', 'quoted', 'chosen'], true)) {
                    DB::table('inventory_rfqs')->where('id', $rfq->id)->update(['status' => 'cancelled', 'cancel_reason' => $reason, 'cancel_note' => $note,
                        'closed_by_user_id' => $actor->id, 'closed_at' => now()->utc(), 'lock_version' => (int) $rfq->lock_version + 1]);
                    $cancelled[] = (int) $rfq->id;
                }
            }
            DB::table('inventory_rfq_rounds')->where('id', $round->id)->update(['status' => 'cancelled', 'cancel_reason' => $reason, 'cancel_note' => $note,
                'closed_by_user_id' => $actor->id, 'closed_at' => now()->utc(), 'lock_version' => (int) $round->lock_version + 1]);
            $this->record('inventory.rfq_round.cancelled', ['rfq_round_id' => (int) $round->id, 'rfq_ids' => $cancelled, 'reason' => $reason], $actor, $branch);
        }, 3);
    }

    /**
     * The list of quote rounds at the active branch.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function page(User $actor, Branch $branch, array $query): array
    {
        $tab = in_array($query['tab'] ?? null, ['open', 'converted', 'cancelled'], true) ? (string) $query['tab'] : 'open';
        $base = DB::table('inventory_rfq_rounds as r')->where('r.organisation_id', $actor->organisation_id)->where('r.branch_id', $branch->id);
        $counts = (clone $base)->groupBy('r.status')->selectRaw('r.status, count(*) as total')->pluck('total', 'status');
        $page = (clone $base)->join('inventory_locations as l', 'l.id', '=', 'r.destination_location_id')->where('r.status', $tab)
            ->orderByDesc('r.created_at')->orderByDesc('r.id')
            ->paginate(self::PAGE_SIZE, ['r.id', 'r.public_id', 'r.status', 'r.created_at', 'r.created_by_user_id', 'r.quote_by_date', 'l.name as location'], 'page', max(1, (int) ($query['page'] ?? 1)));
        /** @var list<stdClass> $rounds */
        $rounds = $page->items();
        $ids = array_map(fn (stdClass $r): int => (int) $r->id, $rounds) ?: [0];
        $lines = DB::table('inventory_rfq_round_lines as l')->join('inventory_skus as s', 's.id', '=', 'l.inventory_sku_id')->join('inventory_items as i', 'i.id', '=', 's.inventory_item_id')
            ->whereIn('l.rfq_round_id', $ids)->orderBy('l.id')->get(['l.rfq_round_id', 'i.generic_name'])->groupBy('rfq_round_id');
        $rfqs = DB::table('inventory_rfqs')->whereIn('rfq_round_id', $ids)->orderBy('id')->get(['rfq_round_id', 'status', 'current_quote_id'])->groupBy('rfq_round_id');
        $names = $this->names($actor, array_map(fn (stdClass $r): int => (int) $r->created_by_user_id, $rounds));
        $local = fn (string $value): string => CarbonImmutable::parse($value, 'UTC')->setTimezone($branch->timezone)->format('j M Y');

        return [
            'expectedBranchId' => $branch->id, 'branchName' => $branch->name, 'tab' => $tab,
            'can' => ['start' => $actor->can(self::CREATE), 'seePrices' => $actor->can(self::COST)],
            'counts' => ['open' => (int) ($counts['open'] ?? 0), 'converted' => (int) ($counts['converted'] ?? 0), 'cancelled' => (int) ($counts['cancelled'] ?? 0)],
            'rounds' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total(), 'rows' => array_map(function (stdClass $r) use ($lines, $rfqs, $names, $local): array {
                $items = collect($lines->get($r->id, []))->pluck('generic_name')->values()->all();
                $suppliers = collect($rfqs->get($r->id, []));
                $live = $suppliers->where('status', '!=', 'cancelled');

                return ['publicId' => $r->public_id, 'status' => $r->status, 'state' => $this->roundState((string) $r->status, $suppliers->all()),
                    'items' => $items, 'location' => $r->location, 'quotesIn' => $live->whereNotNull('current_quote_id')->count(), 'suppliers' => $live->count(),
                    'quoteBy' => CarbonImmutable::parse((string) $r->quote_by_date)->format('j M Y'), 'startedOn' => $local((string) $r->created_at),
                    'startedBy' => $names->get((int) $r->created_by_user_id, 'Staff member')];
            }, $rounds)],
            'locations' => $this->locationChoices($actor, $branch),
        ];
    }

    /**
     * One quote round with its comparison. Prices are only in the props of a cost-view holder.
     *
     * @return array<string, mixed>
     */
    public function round(User $actor, Branch $branch, string $roundPublicId): array
    {
        $round = DB::table('inventory_rfq_rounds as r')->join('inventory_locations as l', 'l.id', '=', 'r.destination_location_id')
            ->where('r.organisation_id', $actor->organisation_id)->where('r.branch_id', $branch->id)->where('r.public_id', strtolower($roundPublicId))
            ->first(['r.*', 'l.name as location']);
        abort_unless($round !== null, 404);
        $prices = $actor->can(self::COST);
        $today = now()->setTimezone($branch->timezone)->toDateString();
        $lines = DB::table('inventory_rfq_round_lines as l')->join('inventory_skus as s', 's.id', '=', 'l.inventory_sku_id')->join('inventory_items as i', 'i.id', '=', 's.inventory_item_id')
            ->where('l.rfq_round_id', $round->id)->orderBy('l.id')->get(['l.id', 'l.quantity', 's.sku_code', 's.stock_unit', 'i.generic_name']);
        $rfqs = DB::table('inventory_rfqs as q')->join('inventory_suppliers as s', 's.id', '=', 'q.supplier_id')->leftJoin('inventory_rfq_quotes as v', 'v.id', '=', 'q.current_quote_id')
            ->leftJoin('inventory_purchase_orders as po', 'po.id', '=', 'q.purchase_order_id')
            ->where('q.rfq_round_id', $round->id)->orderBy('q.id')
            ->get(['q.*', 's.name as supplier', 's.is_active as supplier_active', 'v.version', 'v.lead_time_days', 'v.valid_until', 'v.quote_reference', 'v.recorded_by_user_id', 'v.recorded_at',
                'po.order_number', 'po.public_id as order_public_id', 'po.status as order_status']);
        $comparison = $this->comparison($round, $rfqs);
        $quoteLines = DB::table('inventory_rfq_quote_lines')->whereIn('rfq_quote_id', $rfqs->pluck('current_quote_id')->filter()->all() ?: [0])
            ->get(['rfq_quote_id', 'rfq_round_line_id', 'cannot_supply', 'pack_price_sen', 'pack_size'])->groupBy('rfq_quote_id');
        $people = [(int) $round->created_by_user_id];
        foreach ($rfqs as $rfq) {
            array_push($people, (int) $rfq->sent_by_user_id, (int) $rfq->recorded_by_user_id, (int) $rfq->chosen_by_user_id, (int) $rfq->closed_by_user_id);
        }
        $names = $this->names($actor, $people);
        $local = fn (?string $value): ?string => $value === null ? null : CarbonImmutable::parse($value, 'UTC')->setTimezone($branch->timezone)->format('j M Y, g:i A');
        $date = fn (?string $value): ?string => $value === null ? null : CarbonImmutable::parse($value)->format('j M Y');
        $cheapestTotal = $comparison['cheapestTotal'];
        $cheapestSen = $cheapestTotal === null ? null : $comparison['totals'][$cheapestTotal]['sen'];
        $open = $round->status === 'open';
        $hasChosen = $rfqs->contains(fn (stdClass $r): bool => $r->status === 'chosen');
        $canAct = $actor->can(self::CREATE);

        return [
            'expectedBranchId' => $branch->id, 'branchName' => $branch->name,
            'can' => ['act' => $canAct, 'seePrices' => $prices, 'recordQuote' => $canAct && $prices && $open, 'choose' => $canAct && $prices && $open && ! $hasChosen,
                'convert' => $canAct && $prices && $open && $hasChosen, 'cancel' => $canAct && $open],
            'today' => $today, 'validUntilMax' => CarbonImmutable::parse($today)->addDays(365)->toDateString(),
            'round' => ['publicId' => $round->public_id, 'status' => $round->status, 'state' => $this->roundState((string) $round->status, $rfqs->all()), 'lockVersion' => (int) $round->lock_version,
                'location' => $round->location, 'quoteBy' => $date((string) $round->quote_by_date), 'startedOn' => $local((string) $round->created_at),
                'startedBy' => $names->get((int) $round->created_by_user_id, 'Staff member'), 'cancelReason' => $round->cancel_reason],
            'lines' => $lines->map(fn (stdClass $l): array => ['id' => (int) $l->id, 'sku' => $l->sku_code, 'item' => $l->generic_name, 'stockUnit' => $l->stock_unit,
                'quantity' => self::quantity((string) $l->quantity),
                ...($prices ? ['cheapest' => $comparison['lines'][(int) $l->id]['cheapest'] === null ? null : $this->publicIdOf($rfqs, $comparison['lines'][(int) $l->id]['cheapest']),
                    'tieBreak' => $comparison['lines'][(int) $l->id]['tieBreak']] : [])])->values()->all(),
            'rfqs' => $rfqs->map(function (stdClass $r) use ($prices, $comparison, $quoteLines, $names, $local, $date, $today, $cheapestTotal, $cheapestSen, $lines): array {
                $cells = collect($quoteLines->get($r->current_quote_id, []))->keyBy('rfq_round_line_id');
                $quoted = $r->current_quote_id !== null;
                $total = $comparison['totals'][(int) $r->id] ?? ['sen' => 0, 'supplied' => 0];

                return [
                    'publicId' => $r->public_id, 'number' => $r->rfq_number, 'supplier' => $r->supplier, 'supplierActive' => (bool) $r->supplier_active,
                    'status' => $r->status, 'lockVersion' => (int) $r->lock_version,
                    'sent' => $r->sent_at === null ? null : ['by' => $names->get((int) $r->sent_by_user_id, 'Staff member'), 'on' => $local($r->sent_at)],
                    'chosen' => $r->chosen_at === null ? null : ['by' => $names->get((int) $r->chosen_by_user_id, 'Staff member'), 'on' => $local($r->chosen_at),
                        'reason' => $r->chosen_reason, 'expiredConfirmed' => (bool) $r->expired_confirmed],
                    'closed' => $r->closed_at === null ? null : ['by' => $names->get((int) $r->closed_by_user_id, 'Staff member'), 'on' => $local($r->closed_at), 'reason' => $r->cancel_reason],
                    'order' => $r->order_number === null ? null : ['number' => $r->order_number, 'status' => $r->order_status],
                    'quote' => ! $quoted ? null : [
                        'version' => (int) $r->version, 'leadTimeDays' => (int) $r->lead_time_days, 'validUntil' => $date($r->valid_until), 'validUntilIso' => (string) $r->valid_until,
                        'expired' => (string) $r->valid_until < $today, 'reference' => $r->quote_reference,
                        'recordedBy' => $names->get((int) $r->recorded_by_user_id, 'Staff member'), 'recordedOn' => $local($r->recorded_at),
                        'suppliedCount' => $total['supplied'],
                        ...($prices ? ['totalSen' => $total['sen'], 'cheapestTotal' => $cheapestTotal === (int) $r->id, 'totalTieBreak' => $cheapestTotal === (int) $r->id ? $comparison['totalTieBreak'] : null,
                            'gapSen' => $cheapestSen === null ? null : $total['sen'] - $cheapestSen] : []),
                    ],
                    // Per line: "can't supply" is not a price, so everyone sees it; prices only with cost view.
                    'cells' => $lines->mapWithKeys(function (stdClass $l) use ($cells, $prices, $comparison, $r): array {
                        $cell = $cells->get($l->id);
                        if ($cell === null) {
                            return [(int) $l->id => null];
                        }
                        $computed = $comparison['cells'][(int) $r->id][(int) $l->id] ?? ['unitHundredthSen' => null, 'lineSen' => null];

                        return [(int) $l->id => ['cannotSupply' => (bool) $cell->cannot_supply, ...($prices && ! $cell->cannot_supply ? [
                            'packPriceSen' => (int) $cell->pack_price_sen, 'packSize' => self::quantity((string) $cell->pack_size),
                            'unitHundredthSen' => $computed['unitHundredthSen'], 'lineSen' => $computed['lineSen'],
                        ] : [])]];
                    })->all(),
                ];
            })->values()->all(),
        ];
    }

    /**
     * The A4 RFQ for the supplier: items and quantities, never a price, note or cost. Each print is audited.
     *
     * @return array<string, mixed>
     */
    public function print(User $actor, string $rfqPublicId): array
    {
        $branch = $this->branches->activeBranch($actor);
        abort_unless($branch !== null && $branch->organisation_id === $actor->organisation_id, 404);

        return DB::transaction(function () use ($actor, $branch, $rfqPublicId): array {
            try {
                $actor = $this->authority->lockForBranch($actor, $branch, self::CREATE);
            } catch (AuthorizationException) {
                abort(403);
            }
            $rfq = DB::table('inventory_rfqs as q')->join('inventory_rfq_rounds as r', 'r.id', '=', 'q.rfq_round_id')->join('inventory_suppliers as s', 's.id', '=', 'q.supplier_id')
                ->join('inventory_locations as l', 'l.id', '=', 'r.destination_location_id')
                ->where('q.organisation_id', $actor->organisation_id)->where('r.branch_id', $branch->id)->where('q.public_id', strtolower($rfqPublicId))
                ->first(['q.id', 'q.rfq_number', 'q.status', 'q.rfq_round_id', 'r.public_id as round_public_id', 'r.quote_by_date', 'r.created_at', 'l.name as location', 'l.branch_id as location_branch_id',
                    's.name as supplier', 's.contact_name', 's.business_phone', 's.business_email']);
            abort_unless($rfq !== null, 404);
            $lines = DB::table('inventory_rfq_round_lines as l')->join('inventory_skus as s', 's.id', '=', 'l.inventory_sku_id')->join('inventory_items as i', 'i.id', '=', 's.inventory_item_id')
                ->where('l.rfq_round_id', $rfq->rfq_round_id)->orderBy('l.id')->get(['l.quantity', 's.sku_code', 's.stock_unit', 'i.generic_name']);
            $letterhead = $this->letterheads->assemble($branch, static fn (string $asset): string => route('inventory.purchase-orders.letterhead-logo', ['asset' => $asset], absolute: false))->toArray();
            foreach (['phone', 'phone_secondary'] as $field) {
                $letterhead['details'][$field] = PrintedPhone::format($letterhead['details'][$field] ?? null);
            }
            $this->record('inventory.rfq.printed', ['rfq_round_id' => (int) $rfq->rfq_round_id, 'rfq_id' => (int) $rfq->id], $actor, $branch);

            return [
                'letterhead' => $letterhead,
                'rfq' => [
                    'number' => $rfq->rfq_number, 'status' => $rfq->status, 'roundPublicId' => $rfq->round_public_id,
                    'date' => CarbonImmutable::parse((string) $rfq->created_at, 'UTC')->setTimezone($branch->timezone)->format('d/m/Y'),
                    'quoteBy' => CarbonImmutable::parse((string) $rfq->quote_by_date)->format('d/m/Y'),
                    'supplier' => ['name' => $rfq->supplier, 'contact' => $rfq->contact_name, 'phone' => PrintedPhone::format($rfq->business_phone), 'email' => $rfq->business_email],
                    'deliverTo' => ['branch' => $rfq->location_branch_id === null ? 'Organisation warehouse' : $branch->name, 'location' => $rfq->location],
                    'lines' => $lines->values()->map(fn (stdClass $l, int $i): array => ['number' => $i + 1, 'skuCode' => $l->sku_code, 'item' => $l->generic_name,
                        'stockUnit' => $l->stock_unit, 'quantity' => self::quantity((string) $l->quantity)])->all(),
                    'printedAt' => CarbonImmutable::now($branch->timezone)->format('d/m/Y, g:i A'),
                ],
            ];
        }, 3);
    }

    /**
     * @param  iterable<stdClass>  $rfqs  rows of inventory_rfqs
     * @return array{lines: array<int, array{cheapest:int|null, tieBreak:string|null}>, cells: array<int, array<int, array{unitHundredthSen:int|null, lineSen:int|null}>>, totals: array<int, array{sen:int, supplied:int}>, cheapestTotal: int|null, totalTieBreak: string|null}
     */
    private function comparison(stdClass $round, iterable $rfqs): array
    {
        $lines = DB::table('inventory_rfq_round_lines')->where('rfq_round_id', $round->id)->orderBy('id')->get(['id', 'quantity'])
            ->map(fn (stdClass $l): array => ['id' => (int) $l->id, 'quantity' => StockUnitQuantity::scaled((string) $l->quantity)])->values()->all();
        $compared = [];
        foreach ($rfqs as $rfq) {
            // Compared: every RFQ with a quote that is not cancelled.
            if ($rfq->current_quote_id === null || $rfq->status === 'cancelled') {
                continue;
            }
            $quote = DB::table('inventory_rfq_quotes')->where('id', $rfq->current_quote_id)->first(['lead_time_days']);
            $cells = [];
            foreach (DB::table('inventory_rfq_quote_lines')->where('rfq_quote_id', $rfq->current_quote_id)->get(['rfq_round_line_id', 'cannot_supply', 'pack_price_sen', 'pack_size']) as $cell) {
                $cells[(int) $cell->rfq_round_line_id] = ['cannot' => (bool) $cell->cannot_supply, 'price' => $cell->pack_price_sen === null ? null : (int) $cell->pack_price_sen,
                    'pack' => $cell->pack_size === null ? null : StockUnitQuantity::scaled((string) $cell->pack_size)];
            }
            $compared[] = ['id' => (int) $rfq->id, 'number' => (string) $rfq->rfq_number, 'lead' => (int) $quote?->lead_time_days, 'cells' => $cells];
        }

        return RfqComparison::compare($lines, $compared);
    }

    /** @return array<int, string> */
    private function leftOff(int $quoteId): array
    {
        return DB::table('inventory_rfq_quote_lines as q')->join('inventory_rfq_round_lines as l', 'l.id', '=', 'q.rfq_round_line_id')->join('inventory_skus as s', 's.id', '=', 'l.inventory_sku_id')
            ->join('inventory_items as i', 'i.id', '=', 's.inventory_item_id')->where('q.rfq_quote_id', $quoteId)->where('q.cannot_supply', true)->orderBy('l.id')
            ->get(['s.sku_code', 'i.generic_name'])->map(fn (stdClass $l): string => $l->sku_code.' · '.$l->generic_name)->values()->all();
    }

    /**
     * @param  array<int, stdClass>  $rfqs
     */
    private function roundState(string $status, array $rfqs): string
    {
        if ($status !== 'open') {
            return $status;
        }
        $live = array_filter($rfqs, fn (stdClass $r): bool => $r->status !== 'cancelled');
        if (array_filter($live, fn (stdClass $r): bool => $r->status === 'chosen') !== []) {
            return 'chosen';
        }

        return array_filter($live, fn (stdClass $r): bool => $r->current_quote_id !== null) !== [] ? 'quotes_in' : 'waiting';
    }

    /** @param iterable<stdClass> $rfqs */
    private function publicIdOf(iterable $rfqs, int $id): ?string
    {
        foreach ($rfqs as $rfq) {
            if ((int) $rfq->id === $id) {
                return (string) $rfq->public_id;
            }
        }

        return null;
    }

    /** Create permission, branch assignment and cost view, all checked under the actor's lock. */
    private function lockWithCost(User $actor, Branch $branch): User
    {
        $locked = $this->authority->lockForBranch($actor, $branch, self::CREATE);
        if (! $locked->can(self::COST)) {
            throw new AuthorizationException('Quoted prices are cost, so only someone who may see inventory cost can record, choose or convert a quote.');
        }

        return $locked;
    }

    private function deliveryLocation(User $actor, Branch $branch, string $publicId): InventoryLocation
    {
        $location = InventoryLocation::query()->where('organisation_id', $actor->organisation_id)->where('public_id', strtolower($publicId))->where('is_active', true)->first();
        if ($location === null || $location->type === InventoryLocation::TYPE_DISPENSARY_RETURNS
            || ($location->branch_id !== null && $location->branch_id !== $branch->id)
            || ($location->branch_id === null && ! $actor->can(ProcurementService::WAREHOUSE_PERMISSION))) {
            throw ValidationException::withMessages(['destination_location_public_id' => 'Choose an active stock location at this branch.']);
        }

        return $location;
    }

    /** @return array<int, array{publicId:string, name:string}> */
    private function locationChoices(User $actor, Branch $branch): array
    {
        return DB::table('inventory_locations')->where('organisation_id', $actor->organisation_id)->where('is_active', true)
            ->where(fn ($q) => $q->where('branch_id', $branch->id)->when($actor->can(ProcurementService::WAREHOUSE_PERMISSION), fn ($w) => $w->orWhereNull('branch_id')))
            ->where('type', '!=', InventoryLocation::TYPE_DISPENSARY_RETURNS)->orderBy('name')->limit(100)->get(['public_id', 'name'])
            ->map(fn (stdClass $l): array => ['publicId' => (string) $l->public_id, 'name' => (string) $l->name])->values()->all();
    }

    private function lockRound(User $actor, Branch $branch, string $publicId): stdClass
    {
        $round = DB::table('inventory_rfq_rounds')->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->where('public_id', strtolower($publicId))->lockForUpdate()->first();
        abort_unless($round !== null, 404);

        return $round;
    }

    /** The round an RFQ belongs to, locked first (round, then RFQs in id order). */
    private function lockRoundOf(User $actor, Branch $branch, string $rfqPublicId): stdClass
    {
        $roundId = DB::table('inventory_rfqs')->where('organisation_id', $actor->organisation_id)->where('public_id', strtolower($rfqPublicId))->value('rfq_round_id');
        abort_unless($roundId !== null, 404);
        $round = DB::table('inventory_rfq_rounds')->where('id', $roundId)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->lockForUpdate()->first();
        abort_unless($round !== null, 404);

        return $round;
    }

    /** @return array{stdClass, stdClass} */
    private function lockRfq(User $actor, Branch $branch, string $rfqPublicId): array
    {
        $round = $this->lockRoundOf($actor, $branch, $rfqPublicId);
        $rfq = DB::table('inventory_rfqs')->where('rfq_round_id', $round->id)->where('public_id', strtolower($rfqPublicId))->lockForUpdate()->first();
        abort_unless($rfq !== null, 404);

        return [$round, $rfq];
    }

    /** @return Collection<int, stdClass> */
    private function lockRfqsOfRound(int $roundId): Collection
    {
        return DB::table('inventory_rfqs')->where('rfq_round_id', $roundId)->orderBy('id')->lockForUpdate()->get();
    }

    private function assertVersion(stdClass $row, int $version): void
    {
        if ((int) $row->lock_version !== $version) {
            throw ValidationException::withMessages(['lock_version' => 'This RFQ changed. Reload and review it again.']);
        }
    }

    private function assertRoundOpen(stdClass $round): void
    {
        if ($round->status !== 'open') {
            throw ValidationException::withMessages(['status' => 'This quote round is already closed.']);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{string, string|null, array<string, mixed>}
     */
    private function cancelInput(array $input, bool $rfq): array
    {
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer'], 'lock_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', Rule::in(self::CANCEL_REASONS)], 'note' => ['nullable', 'string', 'max:200', 'not_regex:/[\p{Cc}\p{Cf}<>]/u'],
        ], ['reason.required' => 'Choose a reason.', 'reason.in' => 'Choose a reason.', 'note.max' => 'The note may be at most 200 characters.'])->validate();
        $note = trim((string) ($data['note'] ?? ''));
        if ($data['reason'] === 'other' && $note === '') {
            throw ValidationException::withMessages(['note' => 'Say why in a short note when the reason is Other.']);
        }

        return [(string) $data['reason'], $note === '' ? null : $note, $data];
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, string>
     */
    private function names(User $actor, array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids === [] ? collect() : $this->staff->visibleUsers($actor)->whereIn('id', $ids)->pluck('name', 'id');
    }

    /**
     * Ids and codes only: never a price, a quote reference or a note.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function record(string $event, array $metadata, User $actor, Branch $branch, ?PurchaseOrder $subject = null): void
    {
        abort_if($this->audit->record($event, $subject, $metadata, $actor, $branch) === null, 503, 'The change could not be recorded, so nothing was saved.');
    }

    private static function quantity(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}

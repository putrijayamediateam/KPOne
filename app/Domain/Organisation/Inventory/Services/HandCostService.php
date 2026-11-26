<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\GoodsReceipt;
use App\Domain\Organisation\Inventory\Models\HandCost;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** STK-46 first slice: checked hand-cost evidence only; no ledger or valuation mutation. */
final class HandCostService
{
    public const ENTER = 'inventory.cost.enter.organisation';

    public const CHECK = 'inventory.cost.check.organisation';

    public const VIEW = 'inventory.cost.view.organisation';

    public const REASONS = ['supplier_invoice_found', 'opening_price_list', 'estimate_was_wrong', 'other'];

    public function __construct(private InventoryAuthorityService $authority, private AuditRecorder $audit) {}

    /** @param array<string, mixed> $input */
    public function enter(User $actor, array $input): HandCost
    {
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer'], 'movement_public_id' => ['required', 'uuid'],
            'lock_version' => ['required', 'integer', 'in:0'], 'idempotency_key' => ['required', 'uuid'],
            'cost_total_sen' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'reason_code' => ['required', Rule::in(self::REASONS)], 'note' => ['nullable', 'string', 'max:200'],
            'reference' => ['nullable', 'string', 'max:200', 'not_regex:/[\p{Cc}\p{Cf}<>]/u'],
        ])->validate();
        $note = $this->optional($data['note'] ?? null);
        $reference = $this->optional($data['reference'] ?? null);
        if ($data['reason_code'] === 'other' && $note === null) {
            $this->refuse('note', 'Explain the reason without patient details.');
        }
        $branch = $this->authority->activeBranch($actor, $data);
        $key = strtolower((string) $data['idempotency_key']);
        $movementKey = strtolower((string) $data['movement_public_id']);
        $hash = hash('sha256', json_encode([$movementKey, (int) $data['cost_total_sen'], $data['reason_code'], $note, $reference], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($actor, $branch, $data, $note, $reference, $key, $movementKey, $hash): HandCost {
                $actor = $this->authorize($actor, $branch, self::ENTER);
                [$movement, $receipt] = $this->lockMovement($actor, $movementKey);
                $existing = HandCost::query()->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $key)->first();
                if ($existing) {
                    if ((int) $existing->entered_by_user_id !== $actor->id || ! hash_equals($existing->payload_hash, $hash)) {
                        $this->refuse('idempotency_key', 'This retry key was already used for a different hand cost.');
                    }

                    return $existing;
                }
                $this->assertEligible($movement, $receipt);
                if (HandCost::query()->where('stock_movement_id', $movement->id)->where('status', 'waiting')->exists()) {
                    $this->refuse('movement_public_id', 'This entry already has a hand cost waiting for a check.');
                }
                $cost = new HandCost;
                $cost->forceFill([
                    'public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id,
                    'branch_id' => DB::table('inventory_locations')->where('id', $movement->destination_location_id)->value('branch_id'),
                    'stock_movement_id' => $movement->id, 'cost_total_sen' => (int) $data['cost_total_sen'],
                    'reason_code' => $data['reason_code'], 'note' => $note, 'reference' => $reference,
                    'status' => 'waiting', 'entered_by_user_id' => $actor->id, 'entered_at' => now()->utc(),
                    'lock_version' => 1, 'idempotency_key' => $key, 'payload_hash' => $hash,
                ])->save();
                $this->record('inventory.hand_cost.entered', $cost, $actor);

                return $cost;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            $this->refuse('idempotency_key', 'This entry or retry key was just used. Reload and review the recorded cost.');
        }
    }

    /** @param array<string, mixed> $input */
    public function decide(User $actor, string $publicId, array $input): HandCost
    {
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer'], 'lock_version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'uuid'], 'status' => ['required', Rule::in(['accepted', 'rejected'])],
            'reason' => ['nullable', 'string', 'max:200', 'not_regex:/[\p{Cc}\p{Cf}<>]/u'],
        ])->validate();
        $reason = $this->optional($data['reason'] ?? null);
        if ($data['status'] === 'rejected' && $reason === null) {
            $this->refuse('reason', 'Give a reason for rejecting this cost.');
        }
        $branch = $this->authority->activeBranch($actor, $data);
        $key = strtolower((string) $data['idempotency_key']);
        $hash = hash('sha256', json_encode([strtolower($publicId), $data['status'], $reason, (int) $data['lock_version']], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($actor, $branch, $publicId, $data, $reason, $key, $hash): HandCost {
                $actor = $this->authorize($actor, $branch, self::CHECK);
                $candidate = HandCost::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $publicId)->firstOrFail();
                $movementKey = StockMovement::query()->whereKey($candidate->stock_movement_id)->value('public_id');
                // Receipt first, before the hand-cost row: same direction as receiving correction.
                [$movement, $receipt] = $this->lockMovement($actor, (string) $movementKey);
                $cost = HandCost::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                if ((int) $cost->entered_by_user_id === $actor->id) {
                    throw new AuthorizationException('A different person must check a hand cost.');
                }
                if ($cost->decision_idempotency_key === $key) {
                    if ((int) $cost->decided_by_user_id !== $actor->id || ! hash_equals((string) $cost->decision_payload_hash, $hash)) {
                        $this->refuse('idempotency_key', 'This retry key was used for a different decision.');
                    }

                    return $cost;
                }
                if ($cost->status !== 'waiting' || $cost->lock_version !== (int) $data['lock_version']) {
                    $this->refuse('lock_version', 'This cost changed. Reload before checking it.');
                }
                if ($data['status'] === 'accepted') {
                    $this->assertEligible($movement, $receipt);
                }
                $cost->forceFill([
                    'status' => $data['status'], 'decided_by_user_id' => $actor->id, 'decided_at' => now()->utc(),
                    'decision_reason' => $reason, 'decision_idempotency_key' => $key, 'decision_payload_hash' => $hash,
                    'lock_version' => $cost->lock_version + 1,
                ])->save();
                $this->record('inventory.hand_cost.'.$data['status'], $cost, $actor);

                return $cost;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            $this->refuse('idempotency_key', 'This decision retry key was already used. Reload and review the decision.');
        }
    }

    private function authorize(User $actor, Branch $branch, string $permission): User
    {
        $actor = $this->authority->lockForBranch($actor, $branch, $permission);
        if (! $actor->can(self::VIEW)) {
            throw new AuthorizationException('Cost visibility is required to enter or check a hand cost.');
        }

        return $actor;
    }

    /** @return array{StockMovement, ?GoodsReceipt} */
    private function lockMovement(User $actor, string $publicId): array
    {
        $candidate = StockMovement::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $publicId)->firstOrFail();
        $receipt = null;
        if ($candidate->movement_type === StockMovement::TYPE_PURCHASE_RECEIPT) {
            abort_unless($candidate->reference_type === 'inventory_goods_receipt', 404);
            $receipt = GoodsReceipt::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $candidate->reference_public_id)->lockForUpdate()->firstOrFail();
        }
        $movement = StockMovement::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
        abort_unless(DB::table('inventory_locations')->where('id', $movement->destination_location_id)->where('organisation_id', $actor->organisation_id)->exists(), 404);

        return [$movement, $receipt];
    }

    private function assertEligible(StockMovement $movement, ?GoodsReceipt $receipt): void
    {
        $eligible = match ($movement->movement_type) {
            StockMovement::TYPE_OPENING => $movement->unit_cost_sen === null && $movement->cost_total_sen === null,
            StockMovement::TYPE_PURCHASE_RECEIPT => $movement->cost_total_sen === null && in_array($movement->cost_source, [null, StockMovement::COST_ESTIMATE], true),
            default => false,
        };
        if (! $eligible) {
            $this->refuse('movement_public_id', 'Only a missing, estimated or pre-STK-10B receipt cost may be entered by hand. Actual costs use receiving correction.');
        }
        if ($receipt && DB::table('inventory_receipt_corrections')->where('goods_receipt_id', $receipt->id)->exists()) {
            $this->refuse('movement_public_id', 'This receipt has been reversed. Its hand cost cannot be entered or accepted.');
        }
    }

    private function record(string $event, HandCost $cost, User $actor): void
    {
        $branch = $cost->branch_id === null ? null : Branch::query()->find($cost->branch_id);
        abort_if($this->audit->record($event, $cost, ['hand_cost_id' => $cost->id, 'stock_movement_id' => $cost->stock_movement_id, 'reason_code' => $cost->reason_code, 'status' => $cost->status], $actor, $branch) === null, 503, 'The hand cost could not be audited. Nothing was changed.');
    }

    private function optional(?string $value): ?string
    {
        $value = trim($value ?? '');

        return $value === '' ? null : $value;
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

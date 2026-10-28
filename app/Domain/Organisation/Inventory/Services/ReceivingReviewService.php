<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\PurchaseOrder;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ReceivingReviewService
{
    public const APPROVE = 'inventory.receiving.exceptions.approve.branch';

    /** @param array<string,mixed> $input */
    public function request(User $actor, PurchaseOrder $order, array $input): int
    {
        $command = Validator::make($input, ['expected_branch_id' => ['required', 'integer'], 'lock_version' => ['required', 'integer', 'min:1'], 'retry_key' => ['required', 'uuid'], 'kind' => ['sometimes', Rule::in(['receipt', 'correction'])]])->validate();
        $kind = $command['kind'] ?? 'receipt';
        $payload = $kind === 'receipt' ? app(ReceivingPayload::class)->normalise($input) : Validator::make($input, ['receipt_public_id' => ['required', 'uuid'], 'reason' => ['required', Rule::in(['duplicate_delivery', 'incorrect_quantity', 'incorrect_batch', 'other_approved'])]])->validate();
        $hash = hash('sha256', json_encode([$order->id, $kind, $payload, (int) $command['lock_version']], JSON_THROW_ON_ERROR));
        $authority = app(InventoryAuthorityService::class);
        $branch = $authority->activeBranch($actor, $command);

        return DB::transaction(function () use ($actor, $order, $command, $kind, $payload, $hash, $authority, $branch): int {
            $actor = $authority->lockForBranch($actor, $branch, ProcurementService::RECEIVE_PERMISSION);
            $order = PurchaseOrder::query()->whereKey($order->id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
            $destination = DB::table('inventory_locations')->where('id', $order->destination_location_id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->where('is_active', true)->first();
            abort_unless($destination !== null, 404);
            $retry = DB::table('inventory_receiving_reviews')->where('organisation_id', $actor->organisation_id)->where('receiver_user_id', $actor->id)->where('retry_key', $command['retry_key'])->first();
            if ($retry) {
                $this->sameHash($retry->payload_hash, $hash);

                return $retry->id;
            }
            if ($order->lock_version !== (int) $command['lock_version']) {
                $this->refuse('lock_version', 'The Purchase Order changed. Reload and review.');
            }
            if ($kind === 'receipt') {
                if (! in_array($order->status, [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PARTIALLY_RECEIVED], true)) {
                    $this->refuse('purchase_order', 'The Purchase Order is not ready for receiving.');
                }
                $plans = app(ProcurementService::class)->receiptPlan($actor, $order, $branch, $payload);
                $reasons = app(ProcurementService::class)->exceptionReasons($plans);
                if ($reasons === []) {
                    $this->refuse('lines', 'This delivery has no exception. Post the receipt without exception approval.');
                }
            } else {
                $receipt = DB::table('inventory_goods_receipts')->where('organisation_id', $actor->organisation_id)->where('purchase_order_id', $order->id)->where('public_id', $payload['receipt_public_id'])->firstOrFail();
                abort_unless($receipt->received_by_user_id === $actor->id, 403, 'Only the original receiver may request a receipt correction.');
                abort_unless(DB::table('inventory_receipt_delivery_evidence')->where('goods_receipt_id', $receipt->id)->where('branch_id', $branch->id)->exists(), 404);
                if ($order->status === PurchaseOrder::STATUS_CLOSED) {
                    $this->refuse('purchase_order', 'A closed Purchase Order requires owner review; it cannot be corrected here.');
                }
                if (DB::table('inventory_receipt_corrections')->where('goods_receipt_id', $receipt->id)->exists()) {
                    $this->refuse('receipt', 'This receipt already has a reversing correction.');
                }
                $reasons = ['correction'];
            }
            $id = DB::table('inventory_receiving_reviews')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'purchase_order_id' => $order->id, 'receiver_user_id' => $actor->id, 'kind' => $kind, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'reason_codes' => json_encode($reasons, JSON_THROW_ON_ERROR), 'payload_hash' => $hash, 'order_lock_version' => $order->lock_version, 'retry_key' => $command['retry_key'], 'lock_version' => 1, 'created_at' => now()->utc()]);
            $this->audit('inventory.receiving.review_requested', $order, ['review_id' => $id], $actor, $branch);

            return $id;
        }, 3);
    }

    /** @param array<string,mixed> $input */
    public function approve(User $actor, string $key, array $input): int
    {
        $data = Validator::make($input, ['expected_branch_id' => ['required', 'integer'], 'lock_version' => ['required', 'integer', 'min:1'], 'retry_key' => ['required', 'uuid']])->validate();
        $authority = app(InventoryAuthorityService::class);
        $branch = $authority->activeBranch($actor, $data);
        $hash = hash('sha256', json_encode([$key, $data], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $key, $data, $authority, $branch, $hash): int {
            $actor = $authority->lockForBranch($actor, $branch, self::APPROVE);
            $candidate = DB::table('inventory_receiving_reviews')->where('public_id', $key)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->firstOrFail();
            $order = PurchaseOrder::query()->whereKey($candidate->purchase_order_id)->lockForUpdate()->firstOrFail();
            $review = DB::table('inventory_receiving_reviews')->where('id', $candidate->id)->lockForUpdate()->sole();
            abort_if($review->receiver_user_id === $actor->id, 403, 'A different authorised person must approve this receiving exception.');
            $retry = DB::table('inventory_receiving_approvals')->where('organisation_id', $actor->organisation_id)->where('actor_user_id', $actor->id)->where('retry_key', $data['retry_key'])->first();
            if ($retry) {
                $this->sameHash($retry->payload_hash, $hash);

                return $retry->id;
            }
            if ((int) $data['lock_version'] !== 1 || $order->lock_version !== $review->order_lock_version || DB::table('inventory_receiving_approvals')->where('review_id', $review->id)->exists()) {
                $this->refuse('lock_version', 'The receiving review changed or is stale. Reload and review the delivery.');
            }
            $id = DB::table('inventory_receiving_approvals')->insertGetId(['review_id' => $review->id, 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'actor_user_id' => $actor->id, 'retry_key' => $data['retry_key'], 'payload_hash' => $hash, 'lock_version' => 2, 'created_at' => now()->utc()]);
            $this->audit('inventory.receiving.exception_approved', $order, ['review_id' => $review->id, 'approval_id' => $id], $actor, $branch);

            return $id;
        }, 3);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $input
     * @param  list<string>  $reasons
     */
    public function approvedForPosting(User $actor, PurchaseOrder $order, Branch $branch, array $payload, array $input, array $reasons): ?int
    {
        if ($reasons === []) {
            return null;
        }
        $key = $input['review_public_id'] ?? null;
        $review = DB::table('inventory_receiving_reviews')->where('public_id', $key)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->where('purchase_order_id', $order->id)->where('receiver_user_id', $actor->id)->where('kind', 'receipt')->lockForUpdate()->first();
        if (! $review || (int) ($input['review_lock_version'] ?? 0) !== 2 || $review->order_lock_version !== $order->lock_version || ! DB::table('inventory_receiving_approvals')->where('review_id', $review->id)->exists() || DB::table('inventory_receiving_consumptions')->where('review_id', $review->id)->exists()) {
            $message = in_array('overage', $reasons, true) ? 'A received quantity exceeds the remaining Purchase Order quantity. An independently approved exception is required.' : 'An independently approved delivery exception is required before this receipt can post.';
            $this->refuse('lines', $message);
        }
        $expected = hash('sha256', json_encode([$order->id, 'receipt', $payload, $order->lock_version], JSON_THROW_ON_ERROR));
        $this->sameHash($review->payload_hash, $expected);

        return $review->id;
    }

    /**
     * @param  array<string,mixed>  $query
     * @return array<string,mixed>
     */
    public function page(User $actor, array $query): array
    {
        $actor = $actor->fresh()?->load(['roles.permissions', 'permissions']);
        abort_unless($actor && $actor->is_active && $actor->can('inventory.view.branch'), 403);
        $access = app(BranchAccessService::class);
        $branch = $access->activeBranch($actor);
        abort_unless($branch && $access->hasEffectiveAssignment($actor, $branch), 403);
        $data = Validator::make($query, ['receiving_page' => ['sometimes', 'integer', 'min:1', 'max:10000'], 'receiving_receipts_page' => ['sometimes', 'integer', 'min:1', 'max:10000']])->validate();
        $rows = DB::table('inventory_receiving_reviews as r')->join('inventory_purchase_orders as p', 'p.id', '=', 'r.purchase_order_id')->leftJoin('inventory_receiving_approvals as a', 'a.review_id', '=', 'r.id')->leftJoin('inventory_receiving_consumptions as c', 'c.review_id', '=', 'r.id')->where('r.organisation_id', $actor->organisation_id)->where('r.branch_id', $branch->id)->orderByDesc('r.id')->paginate(25, ['r.*', 'p.public_id as order_public_id', 'p.order_number', 'a.id as approval_id', 'c.id as consumption_id'], 'receiving_page', (int) ($data['receiving_page'] ?? 1));

        $receipts = DB::table('inventory_receipt_delivery_evidence as e')->join('inventory_goods_receipts as r', 'r.id', '=', 'e.goods_receipt_id')->join('inventory_purchase_orders as p', 'p.id', '=', 'r.purchase_order_id')->leftJoin('inventory_receipt_corrections as c', 'c.goods_receipt_id', '=', 'r.id')->where('e.organisation_id', $actor->organisation_id)->where('e.branch_id', $branch->id)->orderByDesc('r.id')->paginate(25, ['r.public_id', 'e.delivery_reference', 'e.received_at', 'e.received_by_user_id', 'p.public_id as order_public_id', 'p.order_number', 'p.lock_version as order_version', 'p.status as order_status', 'c.id as correction_id'], 'receiving_receipts_page', (int) ($data['receiving_receipts_page'] ?? 1));

        return ['branchId' => $branch->id, 'timezone' => $branch->timezone, 'receipts' => ['page' => $receipts->currentPage(), 'lastPage' => $receipts->lastPage(), 'total' => $receipts->total(), 'rows' => collect($receipts->items())->map(fn ($r) => [...(array) $r, 'received_at_local' => CarbonImmutable::parse($r->received_at)->setTimezone($branch->timezone)->format('d M Y H:i'), 'canCorrect' => $r->received_by_user_id === $actor->id && $actor->can(ProcurementService::RECEIVE_PERMISSION) && ! $r->correction_id && $r->order_status !== PurchaseOrder::STATUS_CLOSED])->all()], 'page' => $rows->currentPage(), 'lastPage' => $rows->lastPage(), 'total' => $rows->total(), 'rows' => collect($rows->items())->map(fn ($r) => [...(array) $r, 'payload' => json_decode($r->payload, true, 512, JSON_THROW_ON_ERROR), 'reason_codes' => json_decode($r->reason_codes, true, 512, JSON_THROW_ON_ERROR), 'canApprove' => ! $r->approval_id && $r->receiver_user_id !== $actor->id && $actor->can(self::APPROVE), 'canPost' => $r->approval_id && ! $r->consumption_id && $r->receiver_user_id === $actor->id && $actor->can(ProcurementService::RECEIVE_PERMISSION), 'version' => $r->approval_id ? 2 : 1])->all()];
    }

    private function sameHash(string $old, string $new): void
    {
        if (! hash_equals($old, $new)) {
            $this->refuse('retry_key', 'This retry key or approval belongs to different receiving data.');
        }
    }

    /** @param array<string,mixed> $metadata */
    private function audit(string $event, PurchaseOrder $order, array $metadata, User $actor, Branch $branch): void
    {
        abort_if(app(AuditRecorder::class)->record($event, $order, $metadata, $actor, $branch) === null, 503, 'The receiving action could not be audited. Nothing was saved.');
    }

    private function refuse(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}

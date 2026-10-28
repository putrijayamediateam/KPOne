<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class InventoryStockReviewService
{
    public const MANAGE = 'inventory.stock_reviews.manage.branch';

    public const EXPIRY_WINDOW_DAYS = 90;

    public const PER_PAGE = 25;

    public const LISTS = ['low_stock', 'expiring', 'expired'];

    public const OUTCOMES = ['ordered_externally', 'no_action_needed', 'monitoring', 'disposal_requested_external'];

    /** Live quantities only. Existing per-location thresholds are never merged. */
    private function items(User $actor, Branch $branch, string $kind): Builder
    {
        $eligibility = app(InventoryBatchEligibility::class);
        if ($kind === 'low_stock') {
            $totals = DB::table('inventory_stock_balances as balance')
                ->joinSub($eligibility->query($actor->organisation_id, $branch), 'e', fn ($j) => $j->on('e.id', '=', 'balance.inventory_batch_id')->on('e.organisation_id', '=', 'balance.organisation_id'))
                ->where('balance.organisation_id', $actor->organisation_id)
                ->groupBy('balance.inventory_location_id', 'balance.inventory_sku_id')
                ->selectRaw('balance.inventory_location_id, balance.inventory_sku_id, SUM(CASE WHEN e.eligibility_reason IS NULL THEN balance.quantity ELSE 0 END) as eligible, SUM(CASE WHEN e.hold_reason IS NOT NULL THEN balance.quantity ELSE 0 END) as held');

            return DB::table('inventory_reorder_levels as r')
                ->join('inventory_locations as l', 'l.id', '=', 'r.inventory_location_id')
                ->join('inventory_skus as s', 's.id', '=', 'r.inventory_sku_id')
                ->join('inventory_items as i', 'i.id', '=', 's.inventory_item_id')
                ->leftJoinSub($totals, 't', fn ($j) => $j->on('t.inventory_location_id', '=', 'l.id')->on('t.inventory_sku_id', '=', 's.id'))
                ->where('r.organisation_id', $actor->organisation_id)->where('l.organisation_id', $actor->organisation_id)->where('s.organisation_id', $actor->organisation_id)->where('i.organisation_id', $actor->organisation_id)
                ->where('l.branch_id', $branch->id)->where('l.is_active', true)->where('s.is_active', true)->where('i.is_active', true)
                ->where('r.reorder_level', '>', 0)->whereRaw('COALESCE(t.eligible, 0) <= r.reorder_level')
                ->selectRaw('r.id as item_id, s.id as sku_id, l.id as location_id, NULL::bigint as batch_id, s.sku_code, i.generic_name as item, l.name as location, s.stock_unit, NULL::text as batch, NULL::date as expiry, r.reorder_level, COALESCE(t.eligible, 0)::text as quantity, COALESCE(t.held, 0)::text as held');
        }
        $query = DB::table('inventory_stock_balances as r')
            ->join('inventory_locations as l', 'l.id', '=', 'r.inventory_location_id')
            ->join('inventory_skus as s', 's.id', '=', 'r.inventory_sku_id')
            ->join('inventory_items as i', 'i.id', '=', 's.inventory_item_id')
            ->joinSub($eligibility->query($actor->organisation_id, $branch), 'e', fn ($j) => $j->on('e.id', '=', 'r.inventory_batch_id')->on('e.organisation_id', '=', 'r.organisation_id'))
            ->where('r.organisation_id', $actor->organisation_id)->where('l.organisation_id', $actor->organisation_id)->where('s.organisation_id', $actor->organisation_id)->where('i.organisation_id', $actor->organisation_id)
            ->where('l.branch_id', $branch->id)->where('r.quantity', '>', 0);
        if ($kind === 'expiring') {
            $query->where('l.is_active', true)->whereNull('e.eligibility_reason')->whereDate('e.expiry_date', '<=', CarbonImmutable::parse($eligibility->localDate($branch))->addDays(self::EXPIRY_WINDOW_DAYS)->toDateString());
        } else {
            // Never hide expired stock because its references are inactive, unavailable or held.
            $query->whereDate('e.expiry_date', '<=', $eligibility->localDate($branch));
        }

        return $query->selectRaw('r.id as item_id, s.id as sku_id, l.id as location_id, e.id as batch_id, s.sku_code, i.generic_name as item, l.name as location, s.stock_unit, e.batch_number as batch, e.expiry_date as expiry, NULL::numeric as reorder_level, r.quantity::text as quantity, CASE WHEN e.hold_reason IS NOT NULL THEN r.quantity ELSE 0 END::text as held');
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function page(User $actor, array $input): array
    {
        $actor = $actor->fresh()?->load(['roles.permissions', 'permissions']);
        abort_unless($actor && $actor->is_active && $actor->can('inventory.view.branch'), 403);
        $access = app(BranchAccessService::class);
        $branch = $access->activeBranch($actor);
        abort_unless($branch && $branch->organisation_id === $actor->organisation_id && $access->hasEffectiveAssignment($actor, $branch), 403);
        $rules = ['q' => ['nullable', 'string', 'max:100']];
        foreach (self::LISTS as $kind) {
            $rules[$kind.'_page'] = ['sometimes', 'integer', 'min:1', 'max:1000000'];
        }
        $data = Validator::make($input, $rules)->validate();
        $lists = [];
        $ids = [];
        foreach (self::LISTS as $kind) {
            $query = DB::query()->fromSub($this->items($actor, $branch, $kind), 'work');
            if (filled($data['q'] ?? null)) {
                $needle = '%'.addcslashes($data['q'], '%_\\').'%';
                $query->where(fn ($q) => $q->where('sku_code', 'ilike', $needle)->orWhere('item', 'ilike', $needle)->orWhere('batch', 'ilike', $needle)->orWhere('location', 'ilike', $needle));
            }
            $p = $query->orderBy('sku_code')->orderBy('expiry')->orderBy('item_id')->paginate(self::PER_PAGE, ['*'], $kind.'_page', (int) ($data[$kind.'_page'] ?? 1));
            $rows = array_map(fn ($row): array => (array) $row, $p->items());
            $ids[$kind] = array_column($rows, 'item_id');
            $lists[$kind] = ['rows' => $rows, 'total' => $p->total(), 'page' => $p->currentPage(), 'lastPage' => $p->lastPage()];
        }
        $ranked = DB::table('inventory_stock_reviews')->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
            ->where(function ($q) use ($ids): void {
                foreach ($ids as $kind => $keys) {
                    $q->orWhere(fn ($q) => $q->where('worklist', $kind)->whereIn('item_id', $keys));
                }
            })->select('*')->selectRaw('ROW_NUMBER() OVER (PARTITION BY worklist, item_id ORDER BY lock_version DESC) as position');
        $history = DB::query()->fromSub($ranked, 'recent')->where('position', '<=', 10)->orderByDesc('id')->get();
        $names = DB::table('users')->where('organisation_id', $actor->organisation_id)->whereIn('id', $history->pluck('actor_user_id')->unique())->pluck('name', 'id');
        $grouped = [];
        $today = app(InventoryBatchEligibility::class)->localDate($branch);
        foreach ($history as $review) {
            $grouped[$review->worklist][$review->item_id][] = ['id' => $review->id, 'version' => $review->lock_version, 'reviewer' => $names[$review->actor_user_id] ?? 'Former reviewer', 'when' => CarbonImmutable::parse($review->created_at)->setTimezone($branch->timezone)->format('d M Y H:i'), 'outcome' => $review->outcome, 'reviewBy' => $review->review_by, 'overdue' => $review->review_by !== null && $review->review_by < $today];
        }
        foreach ($lists as $kind => &$list) {
            foreach ($list['rows'] as &$row) {
                $row['history'] = $grouped[$kind][$row['item_id']] ?? [];
                $row['latest'] = $row['history'][0] ?? null;
                $row['lock_version'] = $row['latest']['version'] ?? 0;
            }
            unset($row);
        }
        unset($list);

        return ['branchId' => $branch->id, 'branchName' => $branch->name, 'today' => $today, 'windowDays' => self::EXPIRY_WINDOW_DAYS, 'canManage' => $actor->can(self::MANAGE), 'q' => $data['q'] ?? '', 'lists' => $lists];
    }

    /** @param array<string,mixed> $input */
    public function acknowledge(User $actor, string $kind, int $key, array $input): int
    {
        abort_unless(in_array($kind, self::LISTS, true), 404);
        $data = Validator::make($input, ['expected_branch_id' => ['required', 'integer'], 'lock_version' => ['required', 'integer', 'min:0'], 'retry_key' => ['required', 'uuid'], 'outcome' => ['required', Rule::in(self::OUTCOMES)], 'review_by' => ['nullable', 'required_if:outcome,monitoring,ordered_externally', 'date_format:Y-m-d']])->validate();
        $authority = app(InventoryAuthorityService::class);
        $branch = $authority->activeBranch($actor, $data);
        $data['review_by'] ??= null;
        ksort($data);
        $hash = hash_hmac('sha256', json_encode([$kind, $key, $data], JSON_THROW_ON_ERROR), (string) config('app.key'));

        return DB::transaction(function () use ($actor, $authority, $branch, $kind, $key, $data, $hash): int {
            $actor = $authority->lockForBranch($actor, $branch, self::MANAGE);
            $retry = DB::table('inventory_stock_reviews')->where('organisation_id', $actor->organisation_id)->where('actor_user_id', $actor->id)->where('retry_key', $data['retry_key'])->first();
            if ($retry) {
                if (! hash_equals($retry->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['retry_key' => 'This retry key was used for a different review.']);
                }

                return $retry->id;
            }
            $today = app(InventoryBatchEligibility::class)->localDate($branch);
            if ($data['review_by'] !== null && ($data['review_by'] < $today || $data['review_by'] > CarbonImmutable::parse($today)->addDays(14)->toDateString())) {
                throw ValidationException::withMessages(['review_by' => 'Choose a review date from today to 14 days ahead.']);
            }
            $candidate = DB::query()->fromSub($this->items($actor, $branch, $kind), 'work')->where('item_id', $key)->first();
            abort_unless($candidate !== null, 404, 'This stock condition has cleared or is unavailable.');
            $sku = InventorySku::query()->whereKey($candidate->sku_id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
            // Serialize all reviewers of this SKU without changing its state or any stock row.
            $current = DB::query()->fromSub($this->items($actor, $branch, $kind), 'work')->where('item_id', $key)->first();
            abort_unless($current !== null, 404, 'This stock condition has cleared. Reload the review page.');
            $version = (int) DB::table('inventory_stock_reviews')->where('branch_id', $branch->id)->where('worklist', $kind)->where('item_id', $key)->max('lock_version');
            if ($version !== (int) $data['lock_version']) {
                throw ValidationException::withMessages(['lock_version' => 'This item was reviewed by someone else. Reload before acknowledging.']);
            }
            $id = DB::table('inventory_stock_reviews')->insertGetId(['organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'worklist' => $kind, 'item_id' => $key, 'inventory_sku_id' => $sku->id, 'inventory_location_id' => $current->location_id, 'inventory_batch_id' => $current->batch_id, 'actor_user_id' => $actor->id, 'outcome' => $data['outcome'], 'review_by' => $data['review_by'], 'lock_version' => $version + 1, 'retry_key' => $data['retry_key'], 'payload_hash' => $hash, 'created_at' => now()->utc()]);
            abort_if(app(AuditRecorder::class)->record('inventory.stock_review.acknowledged', $sku, ['review_id' => $id, 'worklist' => $kind, 'outcome' => $data['outcome']], $actor, $branch) === null, 503, 'The review could not be audited. Nothing was saved.');

            return $id;
        }, 3);
    }
}

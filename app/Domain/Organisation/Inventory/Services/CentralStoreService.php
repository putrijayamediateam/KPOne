<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventoryStoreSetting;
use App\Domain\Organisation\Inventory\Models\StockRequest;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * STK-47 and STK-60 (owner decision 19; TEAM.md decision 47; design note STK-47-60-DESIGN.md revision 2 with Daniel's
 * conditions): the central store HQ view and the weekly store request day.
 *
 * "The store" is every active location with no branch (the organisation store). A stock request "to the store" is one
 * whose source location has no branch, as StockRequestService already treats it. The page is read only. The one write is
 * the request day, which only reminds and warns: nothing here refuses or holds back a stock request.
 */
final class CentralStoreService
{
    public const PAGE_PERMISSION = StockRequestService::WAREHOUSE_PERMISSION;

    public const SETTING_PERMISSION = InventoryReferenceAdministrationService::PERMISSION;

    /** Thursday, ISO weekday. */
    public const DEFAULT_WEEKDAY = 4;

    public const PER_PAGE = 25;

    /** Requests to the store list at most the 100 oldest open ones; the page says how many more there are. */
    public const REQUEST_CAP = 100;

    public const DAY_NAMES = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    /** A store request the store still has to act on. "Received with differences" is added for open differences. */
    private const OPEN_STATUSES = [StockRequest::STATUS_REQUESTED, StockRequest::STATUS_APPROVED, StockRequest::STATUS_DISPATCHED];

    public function __construct(
        private BranchAccessService $branches,
        private InventoryAuthorityService $authority,
        private InventoryBatchEligibility $eligibility,
        private AuditRecorder $audit,
    ) {}

    /**
     * The Central store page. Read only.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function page(User $actor, array $input): array
    {
        $actor = $actor->fresh()?->load(['roles.permissions', 'permissions']);
        abort_unless($actor && $actor->is_active && $actor->can(self::PAGE_PERMISSION), 403);
        $data = Validator::make($input, [
            'tab' => ['nullable', 'in:store,branches,requests'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
        ])->validate();
        $organisationId = (int) $actor->organisation_id;
        $tab = $data['tab'] ?? 'store';
        $needle = filled($data['q'] ?? null) ? mb_strtolower(trim((string) $data['q'])) : null;
        $page = (int) ($data['page'] ?? 1);
        $branches = Branch::query()->where('organisation_id', $organisationId)->where('is_active', true)->orderBy('name')->orderBy('id')->get();
        $branchList = array_values($branches->all());
        // The date that decides expiry in the store: the viewer's active branch, otherwise the first branch.
        $dateBranch = $this->branches->activeBranch($actor) ?? $branches->sortBy('id')->first();
        abort_unless($dateBranch instanceof Branch, 404);
        $setting = $this->setting($organisationId);
        $weekday = $setting->request_weekday ?? self::DEFAULT_WEEKDAY;
        $stores = $this->storeLocations($organisationId);

        $storeRows = $this->storeStock($organisationId, $dateBranch, $stores);
        $branchRows = $this->branchStock($organisationId, $branchList, array_keys($storeRows));
        $storeRows = $this->search(array_values($storeRows), $needle);
        $open = $this->openRequests($organisationId, $branchList);
        $branchRows = $this->search($branchRows, $needle);

        return [
            'stores' => array_map(fn (\stdClass $store): array => ['name' => (string) $store->name], $stores),
            'requestDay' => ['weekday' => $weekday, 'name' => self::DAY_NAMES[$weekday]],
            'canChangeDay' => $actor->can(self::SETTING_PERMISSION),
            'settingLockVersion' => $setting->lock_version ?? 0,
            'lookBackDays' => ReorderSuggestionService::LOOK_BACK_DAYS,
            'filters' => ['tab' => $tab, 'q' => $data['q'] ?? ''],
            'thisWeek' => $this->thisWeek($actor, $organisationId, $branchList, $weekday),
            'storeStock' => $this->paginate($storeRows, $tab === 'store' ? $page : 1),
            'branchStock' => [...$this->paginate($branchRows, $tab === 'branches' ? $page : 1), 'branches' => $branches->map(fn (Branch $branch): array => ['id' => $branch->id, 'name' => $branch->name])->values()->all()],
            'requests' => $open['groups'],
            // "and N more" when more than the 100 oldest open requests exist.
            'requestsMore' => $open['more'],
        ];
    }

    /** The saved request day, or null when the organisation has none (Thursday then applies). */
    public function setting(int $organisationId): ?InventoryStoreSetting
    {
        return InventoryStoreSetting::query()->where('organisation_id', $organisationId)->first();
    }

    public function requestWeekday(int $organisationId): int
    {
        return $this->setting($organisationId)->request_weekday ?? self::DEFAULT_WEEKDAY;
    }

    /**
     * What the Inventory page needs for the off-day warning and the reminder: the store's locations (by public id), the
     * request day, and whether today is the request day in the active branch's own time zone. Null for someone who cannot
     * start a stock request, or when the organisation has no store.
     *
     * @return array{dayName: string, isRequestDay: bool, storeLocationPublicIds: list<string>, reminder: ?array{message: string, href: string}}|null
     */
    public function requestFormContext(User $actor, Branch $branch): ?array
    {
        if (! $actor->can(StockRequestService::CREATE_PERMISSION)) {
            return null;
        }
        $stores = $this->storeLocations((int) $actor->organisation_id);
        if ($stores === []) {
            return null;
        }
        $weekday = $this->requestWeekday((int) $actor->organisation_id);

        return [
            'dayName' => self::DAY_NAMES[$weekday],
            'isRequestDay' => $this->today($branch)->isoWeekday() === $weekday,
            'storeLocationPublicIds' => array_map(fn (\stdClass $store): string => (string) $store->public_id, $stores),
            'reminder' => $this->reminderFor($actor),
        ];
    }

    /**
     * The in-KPOne reminder: on the request day and the day before, for someone who may start a stock request at their
     * active branch, while that branch has not sent this week's store request. Nothing is sent outside KPOne.
     *
     * @return array{message: string, href: string}|null
     */
    public function reminderFor(User $actor): ?array
    {
        if (! $actor->is_active || ! $actor->can(StockRequestService::CREATE_PERMISSION)) {
            return null;
        }
        $branch = $this->branches->activeBranch($actor);
        if (! $branch instanceof Branch || ! $this->branches->hasEffectiveAssignment($actor, $branch)) {
            return null;
        }
        $organisationId = (int) $actor->organisation_id;
        if ($this->storeLocations($organisationId) === []) {
            return null;
        }
        $weekday = $this->requestWeekday($organisationId);
        $today = $this->today($branch)->isoWeekday();
        $dayBefore = $weekday === 1 ? 7 : $weekday - 1;
        if ($today !== $weekday && $today !== $dayBefore) {
            return null;
        }
        if ($this->sentThisWeek($organisationId, [$branch])[$branch->id] !== null) {
            return null;
        }

        return [
            'message' => 'Store request day is '.self::DAY_NAMES[$weekday].'. Your branch hasn\'t sent one this week.',
            'href' => '/inventory#store-request',
        ];
    }

    /**
     * Change the request day. Fresh permission check under lock, one transaction, the setting row locked, the expected
     * version checked, a retry key (the same key and the same day return the saved change; a different day is refused),
     * one appended version and an audit row with the changed field name only.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateRequestDay(User $actor, array $attributes): InventoryStoreSetting
    {
        $data = Validator::make($attributes, [
            'request_weekday' => ['required', 'integer', 'between:1,7'],
            'lock_version' => ['required', 'integer', 'min:0'],
            'idempotency_key' => ['required', 'uuid'],
        ], [
            'request_weekday.*' => 'Choose a day from Monday to Sunday.',
            'idempotency_key.*' => 'The request key is missing. Reload the page and try again.',
        ])->validate();
        $weekday = (int) $data['request_weekday'];
        $key = Str::lower((string) $data['idempotency_key']);
        $hash = hash_hmac('sha256', json_encode(['request_day', $weekday], JSON_THROW_ON_ERROR), (string) config('app.key'));

        try {
            return $this->saveRequestDay($actor, $data, $weekday, $key, $hash);
        } catch (UniqueConstraintViolationException) {
            // Two first saves at once: the unique organisation index lets one through; the other is refused.
            throw ValidationException::withMessages(['lock_version' => 'Someone else changed the request day. Reload and try again.']);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function saveRequestDay(User $actor, array $data, int $weekday, string $key, string $hash): InventoryStoreSetting
    {
        return DB::transaction(function () use ($actor, $data, $weekday, $key, $hash): InventoryStoreSetting {
            $locked = $this->authority->lockForOrganisation($actor, self::SETTING_PERMISSION);
            $organisationId = (int) $locked->organisation_id;
            $earlier = DB::table('inventory_store_setting_versions')->where('organisation_id', $organisationId)->where('idempotency_key', $key)->first();
            if ($earlier !== null) {
                if ((int) $earlier->changed_by_user_id !== $locked->id || ! hash_equals((string) $earlier->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This request key was already used for a different change. Reload the page and try again.']);
                }

                return InventoryStoreSetting::query()->findOrFail((int) $earlier->setting_id);
            }
            // A first save creates the row; two at once meet on the unique organisation index (see updateRequestDay).
            $setting = InventoryStoreSetting::query()->where('organisation_id', $organisationId)->lockForUpdate()->first();
            $current = $setting->lock_version ?? 0;
            if ((int) $data['lock_version'] !== $current) {
                throw ValidationException::withMessages(['lock_version' => 'Someone else changed the request day. Reload the page and try again.']);
            }
            if ($setting !== null && $setting->request_weekday === $weekday) {
                throw ValidationException::withMessages(['request_weekday' => 'That is already the request day.']);
            }
            if ($setting === null) {
                $setting = new InventoryStoreSetting;
                $setting->forceFill(['organisation_id' => $organisationId, 'request_weekday' => $weekday, 'lock_version' => 1, 'updated_by_user_id' => $locked->id])->save();
            } else {
                $setting->forceFill(['request_weekday' => $weekday, 'lock_version' => $setting->lock_version + 1, 'updated_by_user_id' => $locked->id])->save();
            }
            DB::table('inventory_store_setting_versions')->insert([
                'organisation_id' => $organisationId, 'setting_id' => $setting->id, 'version' => $setting->lock_version, 'request_weekday' => $weekday,
                'changed_by_user_id' => $locked->id, 'idempotency_key' => $key, 'payload_hash' => $hash, 'created_at' => now()->utc(),
            ]);
            abort_if($this->audit->record('inventory.store_settings.updated', $setting, ['changed_fields' => ['request_weekday'], 'version' => $setting->lock_version], $locked) === null,
                503, 'The change could not be audited. Nothing was saved.');

            return $setting;
        }, 3);
    }

    /** @return list<\stdClass> active store locations (no branch), by name */
    private function storeLocations(int $organisationId): array
    {
        return array_values(DB::table('inventory_locations')->where('organisation_id', $organisationId)->whereNull('branch_id')->where('is_active', true)
            ->where('type', '<>', InventoryLocation::TYPE_DISPENSARY_RETURNS)
            ->orderBy('name')->orderBy('id')->get(['id', 'public_id', 'name'])->all());
    }

    private function today(Branch $branch): CarbonImmutable
    {
        return CarbonImmutable::now($branch->timezone);
    }

    /**
     * Store stock per SKU: usable (the STK-2 rule; no branch hold applies to the organisation store, as STK-37 decided),
     * held or expired apart, nearest usable expiry, and weeks of cover at the branches' combined STK-16 30-day rate.
     *
     * @param  list<\stdClass>  $stores
     * @return array<int, array<string, mixed>> keyed by SKU id
     */
    private function storeStock(int $organisationId, Branch $dateBranch, array $stores): array
    {
        if ($stores === []) {
            return [];
        }
        $usable = "(e.eligibility_reason IS NULL OR e.eligibility_reason = 'held')";
        $rows = DB::table('inventory_stock_balances as balance')
            ->joinSub($this->eligibility->query($organisationId, $dateBranch), 'e', fn ($j) => $j->on('e.id', '=', 'balance.inventory_batch_id')->on('e.organisation_id', '=', 'balance.organisation_id'))
            ->join('inventory_skus as s', fn ($j) => $j->on('s.id', '=', 'balance.inventory_sku_id')->on('s.organisation_id', '=', 'balance.organisation_id'))
            ->join('inventory_items as i', fn ($j) => $j->on('i.id', '=', 's.inventory_item_id')->on('i.organisation_id', '=', 's.organisation_id'))
            ->join('inventory_locations as l', fn ($j) => $j->on('l.id', '=', 'balance.inventory_location_id')->on('l.organisation_id', '=', 'balance.organisation_id'))
            ->where('balance.organisation_id', $organisationId)->where('balance.quantity', '>', 0)
            ->whereIn('balance.inventory_location_id', array_map(fn (\stdClass $store): int => (int) $store->id, $stores))
            ->groupBy('l.id', 'l.name', 's.id', 's.sku_code', 'i.generic_name', 's.stock_unit')
            ->orderBy('s.sku_code')->orderBy('l.name')
            ->selectRaw("l.name as location, s.id as sku_id, s.sku_code, i.generic_name as item, s.stock_unit,
                COALESCE(SUM(balance.quantity) FILTER (WHERE {$usable}), 0)::text as usable,
                COALESCE(SUM(balance.quantity) FILTER (WHERE NOT {$usable}), 0)::text as not_usable,
                MIN(e.expiry_date) FILTER (WHERE {$usable}) as nearest_expiry")
            ->get();
        $skus = [];
        foreach ($rows as $row) {
            $id = (int) $row->sku_id;
            $skus[$id] ??= ['skuId' => $id, 'skuCode' => (string) $row->sku_code, 'itemName' => (string) $row->item, 'stockUnit' => (string) $row->stock_unit,
                'usable' => 0, 'notUsable' => 0, 'nearestExpiry' => null, 'locations' => []];
            $usableMilli = StockUnitQuantity::scaled((string) $row->usable, true);
            $skus[$id]['usable'] += $usableMilli;
            $skus[$id]['notUsable'] += StockUnitQuantity::scaled((string) $row->not_usable, true);
            if ($row->nearest_expiry !== null && ($skus[$id]['nearestExpiry'] === null || (string) $row->nearest_expiry < $skus[$id]['nearestExpiry'])) {
                $skus[$id]['nearestExpiry'] = substr((string) $row->nearest_expiry, 0, 10);
            }
            $skus[$id]['locations'][] = ['name' => (string) $row->location, 'usable' => StockUnitQuantity::format($usableMilli)];
        }
        $rate = $this->branchUse($organisationId, $dateBranch->timezone, array_keys($skus));
        foreach ($skus as $id => $sku) {
            $used = $rate[$id] ?? 0;
            // Weeks = usable ÷ (30-day use ÷ 30 × 7). Null when nothing was used: "No dispensing data".
            $weeks = $used > 0 ? $sku['usable'] * ReorderSuggestionService::LOOK_BACK_DAYS / ($used * 7) : null;
            $skus[$id] = [
                ...$sku,
                'usable' => StockUnitQuantity::format($sku['usable']),
                'notUsable' => StockUnitQuantity::format($sku['notUsable']),
                'used30' => StockUnitQuantity::format($used),
                'coverWeeks' => $weeks === null ? null : number_format(floor($weeks * 10) / 10, 1, '.', ''),
                'coverTone' => $weeks === null ? 'neutral' : ($weeks < 1 ? 'danger' : ($weeks < 2 ? 'warning' : 'neutral')),
                'sortWeeks' => $weeks,
            ];
        }
        // Shortest cover first, then items with no recent use, each by SKU code.
        uasort($skus, fn (array $a, array $b): int => [$a['sortWeeks'] === null ? 1 : 0, $a['sortWeeks'] ?? 0, $a['skuCode']] <=> [$b['sortWeeks'] === null ? 1 : 0, $b['sortWeeks'] ?? 0, $b['skuCode']]);

        return array_map(function (array $sku): array {
            unset($sku['sortWeeks'], $sku['skuId']);

            return $sku;
        }, $skus);
    }

    /**
     * The branches' combined 30-day use per SKU: dispensing plus unreversed treatment use out of every branch location,
     * through the same STK-16 query reorder suggestions use.
     *
     * @param  list<int>  $skuIds
     * @return array<int, int> thousandths, keyed by SKU id
     */
    private function branchUse(int $organisationId, string $timezone, array $skuIds): array
    {
        if ($skuIds === []) {
            return [];
        }
        $locationIds = array_values(DB::table('inventory_locations')->where('organisation_id', $organisationId)->whereNotNull('branch_id')
            ->where('type', '<>', InventoryLocation::TYPE_DISPENSARY_RETURNS)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all());
        [$used] = app(ReorderSuggestionService::class)->used($organisationId, $timezone, $locationIds, $skuIds);
        $bySku = [];
        foreach ($used as $key => $quantity) {
            $skuId = (int) explode(':', (string) $key)[1];
            $bySku[$skuId] = ($bySku[$skuId] ?? 0) + $quantity;
        }

        return $bySku;
    }

    /**
     * Each branch's usable quantity (its own STK-2 eligibility, so its own holds count) against its reorder levels, for
     * every SKU in the store or with a level at a branch. "Below level" is at or below a positive level, as Stock review.
     *
     * @param  list<Branch>  $branches
     * @param  list<int>  $storeSkuIds
     * @return list<array<string, mixed>>
     */
    private function branchStock(int $organisationId, array $branches, array $storeSkuIds): array
    {
        $levels = DB::table('inventory_reorder_levels as r')
            ->join('inventory_locations as l', fn ($j) => $j->on('l.id', '=', 'r.inventory_location_id')->on('l.organisation_id', '=', 'r.organisation_id'))
            ->where('r.organisation_id', $organisationId)->whereNotNull('l.branch_id')->where('l.is_active', true)
            ->where('l.type', '<>', InventoryLocation::TYPE_DISPENSARY_RETURNS)->where('r.reorder_level', '>', 0)
            ->groupBy('l.branch_id', 'r.inventory_sku_id')
            ->selectRaw('l.branch_id, r.inventory_sku_id as sku_id, SUM(r.reorder_level)::text as level')->get();
        $levelBy = [];
        foreach ($levels as $level) {
            $levelBy[(int) $level->sku_id][(int) $level->branch_id] = StockUnitQuantity::scaled((string) $level->level, true);
        }
        $skuIds = array_values(array_unique([...$storeSkuIds, ...array_keys($levelBy)]));
        if ($skuIds === []) {
            return [];
        }
        $usableBy = [];
        foreach ($branches as $branch) {
            foreach (DB::table('inventory_stock_balances as balance')
                ->joinSub($this->eligibility->query($organisationId, $branch), 'e', fn ($j) => $j->on('e.id', '=', 'balance.inventory_batch_id')->on('e.organisation_id', '=', 'balance.organisation_id'))
                ->join('inventory_locations as l', fn ($j) => $j->on('l.id', '=', 'balance.inventory_location_id')->on('l.organisation_id', '=', 'balance.organisation_id'))
                ->where('balance.organisation_id', $organisationId)->whereIn('balance.inventory_sku_id', $skuIds)
                ->where('l.branch_id', $branch->id)->where('l.is_active', true)->where('l.type', '<>', InventoryLocation::TYPE_DISPENSARY_RETURNS)
                ->whereNull('e.eligibility_reason')
                ->groupBy('balance.inventory_sku_id')
                ->selectRaw('balance.inventory_sku_id as sku_id, SUM(balance.quantity)::text as quantity')->get() as $row) {
                $usableBy[(int) $row->sku_id][$branch->id] = StockUnitQuantity::scaled((string) $row->quantity, true);
            }
        }
        $rows = [];
        foreach (DB::table('inventory_skus as s')->join('inventory_items as i', fn ($j) => $j->on('i.id', '=', 's.inventory_item_id')->on('i.organisation_id', '=', 's.organisation_id'))
            ->where('s.organisation_id', $organisationId)->whereIn('s.id', $skuIds)->orderBy('s.sku_code')
            ->get(['s.id', 's.sku_code', 'i.generic_name', 's.stock_unit']) as $sku) {
            $cells = [];
            $short = false;
            foreach ($branches as $branch) {
                $usable = $usableBy[(int) $sku->id][$branch->id] ?? 0;
                $level = $levelBy[(int) $sku->id][$branch->id] ?? null;
                $below = $level !== null && $usable <= $level;
                $short = $short || $below;
                $cells[] = ['branchId' => $branch->id, 'branch' => $branch->name, 'usable' => StockUnitQuantity::format($usable),
                    'level' => $level === null ? null : StockUnitQuantity::format($level), 'below' => $below];
            }
            $rows[] = ['skuCode' => (string) $sku->sku_code, 'itemName' => (string) $sku->generic_name, 'stockUnit' => (string) $sku->stock_unit, 'shortAnywhere' => $short, 'branches' => $cells];
        }
        // Short anywhere first, then by SKU code (already in SKU order; usort keeps it with the code tie-break).
        usort($rows, fn (array $a, array $b): int => [$a['shortAnywhere'] ? 0 : 1, $a['skuCode']] <=> [$b['shortAnywhere'] ? 0 : 1, $b['skuCode']]);

        return $rows;
    }

    /**
     * Open requests to the store, oldest first, capped at the 100 oldest: waiting for approval, approved, dispatched, and
     * received with a difference nobody has resolved yet. Grouped by requesting branch (active branches by name, then any
     * inactive branch under "Other branches", so nothing is hidden). Links only.
     *
     * @param  list<Branch>  $branches  the active branches, by name
     * @return array{groups: list<array{branch: string, rows: list<array<string, mixed>>}>, more: int}
     */
    private function openRequests(int $organisationId, array $branches): array
    {
        $unresolved = fn ($query) => $query->selectRaw('1')->from('inventory_transfer_differences as d')
            ->leftJoin('inventory_transfer_difference_resolutions as res', 'res.difference_id', '=', 'd.id')
            ->whereColumn('d.stock_request_id', 'request.id')->whereNull('res.id');
        $open = DB::table('inventory_stock_requests as request')
            ->join('inventory_locations as source', fn ($j) => $j->on('source.id', '=', 'request.source_location_id')->on('source.organisation_id', '=', 'request.organisation_id'))
            ->where('request.organisation_id', $organisationId)->whereNull('source.branch_id')
            ->where(fn ($query) => $query->whereIn('request.status', self::OPEN_STATUSES)
                ->orWhere(fn ($received) => $received->where('request.status', StockRequest::STATUS_RECEIVED)->whereExists($unresolved)));
        $total = (clone $open)->count();
        $rows = $open
            ->join('inventory_locations as destination', fn ($j) => $j->on('destination.id', '=', 'request.destination_location_id')->on('destination.organisation_id', '=', 'request.organisation_id'))
            ->orderBy('request.requested_at')->orderBy('request.id')->limit(self::REQUEST_CAP)
            ->selectRaw('request.public_id, request.request_number, request.status, request.requested_at, request.requesting_branch_id, source.name as source_name, destination.name as destination_name,
                (SELECT COUNT(*) FROM inventory_stock_request_lines line WHERE line.stock_request_id = request.id) as line_count')
            ->get();
        $zones = Branch::query()->where('organisation_id', $organisationId)->get(['id', 'timezone'])->keyBy('id');
        $active = [];
        foreach ($branches as $branch) {
            $active[$branch->id] = true;
        }
        $byBranch = [];
        $other = [];
        foreach ($rows as $row) {
            $branchId = (int) $row->requesting_branch_id;
            $zone = (string) ($zones->get($branchId)->timezone ?? 'Asia/Kuala_Lumpur');
            $status = (string) $row->status === StockRequest::STATUS_RECEIVED ? 'received_with_differences' : (string) $row->status;
            $item = [
                'publicId' => (string) $row->public_id, 'number' => (string) $row->request_number, 'status' => $status,
                'requestedOn' => CarbonImmutable::parse((string) $row->requested_at)->setTimezone($zone)->format('D j M Y'),
                'from' => (string) $row->source_name, 'to' => (string) $row->destination_name, 'lineCount' => (int) $row->line_count,
                'href' => '/inventory#stock-request-'.$row->public_id,
            ];
            if (isset($active[$branchId])) {
                $byBranch[$branchId][] = $item;
            } else {
                $other[] = $item;
            }
        }
        $groups = [];
        foreach ($branches as $branch) {
            if (isset($byBranch[$branch->id])) {
                $groups[] = ['branch' => $branch->name, 'rows' => $byBranch[$branch->id]];
            }
        }
        if ($other !== []) {
            $groups[] = ['branch' => 'Other branches', 'rows' => $other];
        }

        return ['groups' => $groups, 'more' => max(0, $total - self::REQUEST_CAP)];
    }

    /**
     * Per branch, whether it has sent this week's store request. "This week" is Monday to Sunday in the branch's own time
     * zone; any store request created in it counts, a rejected one included (stock requests have no draft or cancelled
     * state, so there is nothing else to leave out).
     *
     * @param  list<Branch>  $branches
     * @return list<array<string, mixed>>
     */
    private function thisWeek(User $viewer, int $organisationId, array $branches, int $weekday): array
    {
        $sent = $this->sentThisWeek($organisationId, $branches);
        $names = app(InventoryStaffNames::class)->resolver($viewer, array_map(fn (?array $row): ?int => $row['by'] ?? null, $sent));
        $cards = [];
        foreach ($branches as $branch) {
            $today = $this->today($branch);
            $row = $sent[$branch->id];
            $cards[] = [
                'branchId' => $branch->id, 'branch' => $branch->name,
                'weekOf' => 'Week of '.$today->startOfWeek(CarbonImmutable::MONDAY)->format('j M'),
                'sent' => $row !== null,
                'sentOn' => $row === null ? null : $row['at']->setTimezone($branch->timezone)->format('D j M'),
                'sentBy' => $row === null ? null : $names($row['by']),
                'rejected' => $row !== null && $row['status'] === StockRequest::STATUS_REJECTED,
                // Not sent: neutral before the request day, warning on and after it; never red.
                'tone' => $row !== null ? 'success' : ($today->isoWeekday() < $weekday ? 'neutral' : 'warning'),
            ];
        }

        return $cards;
    }

    /**
     * The latest store request each branch created this week (its own Monday to Sunday), or null.
     *
     * @param  list<Branch>  $branches
     * @return array<int, array{at: CarbonImmutable, by: int, status: string}|null>
     */
    private function sentThisWeek(int $organisationId, array $branches): array
    {
        $sent = [];
        foreach ($branches as $branch) {
            $monday = $this->today($branch)->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
            $row = DB::table('inventory_stock_requests as request')
                ->join('inventory_locations as source', fn ($j) => $j->on('source.id', '=', 'request.source_location_id')->on('source.organisation_id', '=', 'request.organisation_id'))
                ->where('request.organisation_id', $organisationId)->where('request.requesting_branch_id', $branch->id)->whereNull('source.branch_id')
                ->where('request.requested_at', '>=', $monday->utc()->format('Y-m-d H:i:sP'))
                ->where('request.requested_at', '<', $monday->addWeek()->utc()->format('Y-m-d H:i:sP'))
                ->orderByDesc('request.requested_at')->orderByDesc('request.id')
                ->first(['request.requested_at', 'request.requested_by_user_id', 'request.status']);
            $sent[$branch->id] = $row === null ? null : ['at' => CarbonImmutable::parse((string) $row->requested_at), 'by' => (int) $row->requested_by_user_id, 'status' => (string) $row->status];
        }

        return $sent;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function search(array $rows, ?string $needle): array
    {
        if ($needle === null) {
            return $rows;
        }

        return array_values(array_filter($rows, fn (array $row): bool => str_contains(mb_strtolower($row['skuCode'].' '.$row['itemName']), $needle)));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{rows: list<array<string, mixed>>, page: int, lastPage: int, total: int}
     */
    private function paginate(array $rows, int $page): array
    {
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $lastPage);

        return ['rows' => array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE), 'page' => $page, 'lastPage' => $lastPage, 'total' => $total];
    }
}

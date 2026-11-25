<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\StockRequest;
use App\Domain\Organisation\Inventory\Services\CentralStoreService;
use App\Domain\Organisation\Inventory\Services\InventoryBatchHoldService;
use App\Domain\Organisation\Inventory\Services\InventoryRecallService;
use App\Domain\Organisation\Inventory\Services\StockRequestService;
use App\Domain\Organisation\Inventory\Services\TreatmentUsageService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\GovernedStockFixture;

/**
 * STK-47 and STK-60 (owner decision 19; TEAM.md decision 47; design note STK-47-60-DESIGN.md revision 2 with Daniel's
 * conditions): the central store HQ view, read only, and the weekly store request day, which reminds and warns only.
 */
final class CentralStoreTest extends VisitTestCase
{
    private Branch $other;

    /** @var array<string, mixed> */
    private array $f;

    private InventoryLocation $store;

    protected function setUp(): void
    {
        parent::setUp();
        // Wednesday 25 Nov 2026, 10:00 in Kuala Lumpur: the day before the default Thursday request day.
        $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00:00', $this->branch->timezone));
        $this->other = Branch::query()->where('organisation_id', $this->branch->organisation_id)->whereKeyNot($this->branch->id)->where('is_active', true)->orderBy('id')->firstOrFail();
        // Our branch dispensary holds 20 of a SKU; the organisation store (no branch) holds 70 of it.
        $this->f = (new GovernedStockFixture($this->branch))->build('20.000');
        $this->store = $this->location(null, 'Synthetic Cheras central store');
        $this->balance($this->store, $this->f['batch'], '70.000');
    }

    private function location(?Branch $branch, string $name, string $type = InventoryLocation::TYPE_BRANCH_STORE): InventoryLocation
    {
        $location = new InventoryLocation;
        $location->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->branch->organisation_id, 'branch_id' => $branch?->id,
            'code' => 'CS-'.Str::upper(Str::random(8)), 'name' => $name, 'type' => $type, 'is_active' => true])->save();

        return $location;
    }

    private function batch(string $expiry, string $status = 'available'): InventoryBatch
    {
        $batch = new InventoryBatch;
        $batch->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->branch->organisation_id, 'inventory_sku_id' => $this->f['sku']->id,
            'batch_number' => 'CS-'.Str::upper(Str::random(8)), 'expiry_date' => $expiry, 'received_at' => now()->subDays(3), 'status' => $status])->save();

        return $batch;
    }

    private function balance(InventoryLocation $location, InventoryBatch $batch, string $quantity): void
    {
        DB::table('inventory_stock_balances')->insert(['organisation_id' => $this->branch->organisation_id, 'inventory_location_id' => $location->id, 'inventory_sku_id' => $this->f['sku']->id,
            'inventory_batch_id' => $batch->id, 'quantity' => $quantity, 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function level(InventoryLocation $location, string $level): void
    {
        DB::table('inventory_reorder_levels')->insert(['organisation_id' => $location->organisation_id, 'inventory_location_id' => $location->id, 'inventory_sku_id' => $this->f['sku']->id,
            'reorder_level' => $level, 'max_level' => null, 'updated_by_user_id' => $this->f['proposer']->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Unreversed treatment-room use out of a branch location, recorded through the service that long ago (the STK-16 rate reads it). */
    private function used(Branch $branch, InventoryLocation $location, string $quantity, int $daysAgo): void
    {
        $now = now();
        $this->travelTo($now->copy()->subDays($daysAgo));
        app(TreatmentUsageService::class)->record($this->staff('ca', $branch), ['expected_branch_id' => $branch->id, 'retry_key' => (string) Str::uuid(),
            'location_id' => $location->id, 'batch_id' => $this->f['batch']->id, 'unit' => $this->f['sku']->stock_unit, 'quantity' => $quantity, 'kind' => 'usage', 'reason' => 'wound_care']);
        $this->travelTo($now);
    }

    private function staff(string $role, ?Branch $branch = null): User
    {
        $branch ??= $this->branch;
        $actor = (new GovernedStockFixture($branch))->actor($role);
        app(BranchAccessService::class)->select($actor, $branch);

        return $actor;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function page(User $actor, array $query = []): array
    {
        return $this->actingAs($actor)->get(route('inventory.central-store', $query))->assertOk()->viewData('page')['props']['store'];
    }

    private function storeRequest(User $actor, Branch $branch, InventoryLocation $destination, ?InventoryLocation $source = null): StockRequest
    {
        return app(StockRequestService::class)->create($actor, ['expected_branch_id' => $branch->id, 'source_location_public_id' => ($source ?? $this->store)->public_id,
            'destination_location_public_id' => $destination->public_id, 'lines' => [['sku_public_id' => $this->f['sku']->public_id, 'quantity' => '5']]]);
    }

    public function test_store_stock_counts_only_usable_stock_with_cover_weeks_and_no_cost(): void
    {
        // A second store location, an expired batch and a quarantined batch in the store, and a returns location at a branch.
        $second = $this->location(null, 'Synthetic second store');
        $this->balance($second, $this->batch(now($this->branch->timezone)->addDays(40)->toDateString()), '10.000');
        $this->balance($this->store, $this->batch(now($this->branch->timezone)->toDateString()), '4.000');
        $this->balance($this->store, $this->batch(now($this->branch->timezone)->addYear()->toDateString(), 'quarantined'), '3.000');
        $returns = $this->location($this->branch, 'Synthetic returns', InventoryLocation::TYPE_DISPENSARY_RETURNS);
        $this->balance($returns, $this->f['batch'], '9.000');
        // 120 used at the branches in the last 30 days = 28 a week; 500 more 40 days ago does not count.
        $theirs = $this->location($this->other, 'Synthetic other treatment store');
        $this->balance($theirs, $this->f['batch'], '2000.000');
        $this->used($this->other, $theirs, '80.000', 3);
        $this->used($this->other, $theirs, '40.000', 10);
        $this->used($this->other, $theirs, '500.000', 40);

        $store = $this->page($this->staff('ca_supervisor'));
        $this->assertSame(['Synthetic Cheras central store', 'Synthetic second store'], array_column($store['stores'], 'name'));
        $row = $store['storeStock']['rows'][0];
        // Usable 70 + 10 = 80 (expired and quarantined apart: 7); returns stock never counts. 80 ÷ 28 = 2.857 → 2.8 weeks.
        $this->assertSame(['80.000', '7.000', '120.000', '2.8', 'neutral'], [$row['usable'], $row['notUsable'], $row['used30'], $row['coverWeeks'], $row['coverTone']]);
        $this->assertSame(now($this->branch->timezone)->addDays(40)->toDateString(), $row['nearestExpiry']);
        $this->assertSame([['name' => 'Synthetic Cheras central store', 'usable' => '70.000'], ['name' => 'Synthetic second store', 'usable' => '10.000']], $row['locations']);
        // Branch stock leaves the returns location out.
        $ours = collect($store['branchStock']['rows'][0]['branches'])->firstWhere('branchId', $this->branch->id);
        $this->assertSame('20.000', $ours['usable']);

        // No cost or value anywhere on the page.
        $json = json_encode($store, JSON_THROW_ON_ERROR);
        $this->assertDoesNotMatchRegularExpression('/"[a-zA-Z]*(cost|Cost|value|Value|price|Price|Sen|Rm)[a-zA-Z]*"\s*:/', $json);

        // Faster use (520 in 30 days = 121 a week): less than 1 week is destructive, 1 to 2 weeks warning.
        $this->used($this->other, $theirs, '400.000', 1);
        $this->assertSame(['0.6', 'danger'], [$this->page($this->staff('ca_supervisor'))['storeStock']['rows'][0]['coverWeeks'], $this->page($this->staff('ca_supervisor'))['storeStock']['rows'][0]['coverTone']]);
        // The page writes nothing.
        $this->assertSame(0, DB::table('inventory_store_settings')->count());
    }

    public function test_zero_use_shows_no_cover_and_a_recalled_batch_is_not_usable(): void
    {
        $row = $this->page($this->staff('ca_supervisor'))['storeStock']['rows'][0];
        // No dispensing or treatment use: the page says "No dispensing data" (null cover, neutral tone).
        $this->assertNull($row['coverWeeks']);
        $this->assertSame(['0.000', 'neutral', '70.000'], [$row['used30'], $row['coverTone'], $row['usable']]);

        // An all-branch recall makes the store's batch not usable.
        app(InventoryRecallService::class)->start($this->staff('director'), ['expected_branch_id' => $this->branch->id,
            'idempotency_key' => (string) Str::uuid(), 'sku_public_id' => $this->f['sku']->public_id, 'batch_public_ids' => [$this->f['batch']->public_id],
            'reason' => 'supplier_recall', 'reference' => 'SYN-RECALL-47']);
        $row = $this->page($this->staff('ca_supervisor'))['storeStock']['rows'][0];
        $this->assertSame(['0.000', '70.000'], [$row['usable'], $row['notUsable']]);
        $this->assertNull($row['nearestExpiry']);
    }

    public function test_branch_stock_shows_levels_and_short_anywhere_first(): void
    {
        // Our dispensary: 20 against level 25 → below. The other branch: nothing, no level.
        $this->level($this->f['location'], '25.000');
        $rows = $this->page($this->staff('ca_supervisor'))['branchStock']['rows'];
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['shortAnywhere']);
        $cells = collect($rows[0]['branches'])->keyBy('branchId');
        $this->assertSame(['20.000', '25.000', true], [$cells[$this->branch->id]['usable'], $cells[$this->branch->id]['level'], $cells[$this->branch->id]['below']]);
        $this->assertSame(['0.000', null, false], [$cells[$this->other->id]['usable'], $cells[$this->other->id]['level'], $cells[$this->other->id]['below']]);

        // A held batch at our branch is not usable there.
        DB::table('inventory_reorder_levels')->where('inventory_location_id', $this->f['location']->id)->update(['reorder_level' => '5.000']);
        $this->assertFalse($this->page($this->staff('ca_supervisor'))['branchStock']['rows'][0]['shortAnywhere']);
        app(InventoryBatchHoldService::class)->act($this->staff('ca_supervisor'), $this->f['batch']->public_id, 'place',
            ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'retry_key' => (string) Str::uuid(), 'reason' => 'damaged']);
        $store = $this->page($this->staff('ca_supervisor'));
        $cell = collect($store['branchStock']['rows'][0]['branches'])->firstWhere('branchId', $this->branch->id);
        $this->assertSame(['0.000', true], [$cell['usable'], $cell['below']]);
        // A branch hold never touches the organisation store.
        $this->assertSame('70.000', $store['storeStock']['rows'][0]['usable']);

        // The search narrows both stock lists.
        $this->assertSame(0, $this->page($this->staff('ca_supervisor'), ['q' => 'NO-SUCH'])['branchStock']['total']);
        $this->assertSame(1, $this->page($this->staff('ca_supervisor'), ['q' => $this->f['sku']->sku_code])['storeStock']['total']);
    }

    public function test_requests_to_the_store_are_grouped_by_branch_oldest_first_and_links_only(): void
    {
        $service = app(StockRequestService::class);
        $ours = $this->staff('ca_supervisor');
        $theirs = $this->staff('ca_supervisor', $this->other);
        $theirDispensary = $this->location($this->other, 'Synthetic other dispensary', InventoryLocation::TYPE_DISPENSARY);
        $first = $this->storeRequest($ours, $this->branch, $this->f['location']);
        $this->travel(5)->minutes();
        $second = $this->storeRequest($theirs, $this->other, $theirDispensary);
        $this->travel(5)->minutes();
        $third = $this->storeRequest($ours, $this->branch, $this->f['location']);
        // A same-branch request (not to the store) and a rejected store request are not open store requests.
        $this->storeRequest($ours, $this->branch, $this->location($this->branch, 'Synthetic branch store'), $this->f['location']);
        $rejected = $this->storeRequest($ours, $this->branch, $this->f['location']);
        $service->reject($this->staff('ca_supervisor'), $rejected, ['expected_branch_id' => $this->branch->id, 'lock_version' => $rejected->lock_version, 'reason' => 'Synthetic duplicate']);
        $service->approve($this->staff('ca_supervisor'), $third, ['expected_branch_id' => $this->branch->id, 'lock_version' => $third->lock_version]);

        $groups = collect($this->page($ours, ['tab' => 'requests'])['requests'])->keyBy('branch');
        $this->assertSame([$first->public_id, $third->public_id], array_column($groups[$this->branch->name]['rows'], 'publicId'));
        $this->assertSame(['requested', 'approved'], array_column($groups[$this->branch->name]['rows'], 'status'));
        $this->assertSame([$second->public_id], array_column($groups[$this->other->name]['rows'], 'publicId'));
        $this->assertSame('/inventory#stock-request-'.$first->public_id, $groups[$this->branch->name]['rows'][0]['href']);
    }

    public function test_sent_this_week_follows_the_branch_local_week_and_counts_rejected_requests(): void
    {
        $service = app(StockRequestService::class);
        // Sunday 22 Nov, 23:30 in Kuala Lumpur (15:30 UTC): last week for the branch, though the same UTC week as Monday morning.
        $this->travelTo(CarbonImmutable::parse('2026-11-22 23:30:00', $this->branch->timezone));
        $ours = $this->staff('ca_supervisor');
        $viewer = $this->staff('ca_supervisor');
        $this->storeRequest($ours, $this->branch, $this->f['location']);
        $this->travelTo(CarbonImmutable::parse('2026-11-23 00:30:00', $this->branch->timezone));
        $card = collect($this->page($viewer)['thisWeek'])->firstWhere('branchId', $this->branch->id);
        $this->assertSame([false, 'Week of 23 Nov', 'neutral'], [$card['sent'], $card['weekOf'], $card['tone']]);

        // A store request rejected this week still counts as sent; the rejection shows on its own.
        $request = $this->storeRequest($ours, $this->branch, $this->f['location']);
        $service->reject($this->staff('ca_supervisor'), $request, ['expected_branch_id' => $this->branch->id, 'lock_version' => $request->lock_version, 'reason' => 'Synthetic duplicate']);
        $card = collect($this->page($viewer)['thisWeek'])->firstWhere('branchId', $this->branch->id);
        $this->assertSame([true, true, 'Mon 23 Nov', 'success', 'Synthetic stock ca_supervisor'], [$card['sent'], $card['rejected'], $card['sentOn'], $card['tone'], $card['sentBy']]);

        // The other branch has not sent: neutral before Thursday, warning on and after it; never red.
        $this->assertSame('neutral', collect($this->page($viewer)['thisWeek'])->firstWhere('branchId', $this->other->id)['tone']);
        $this->travelTo(CarbonImmutable::parse('2026-11-26 09:00:00', $this->branch->timezone));
        $this->assertSame('warning', collect($this->page($viewer)['thisWeek'])->firstWhere('branchId', $this->other->id)['tone']);
        $this->travelTo(CarbonImmutable::parse('2026-11-29 09:00:00', $this->branch->timezone));
        $this->assertSame('warning', collect($this->page($viewer)['thisWeek'])->firstWhere('branchId', $this->other->id)['tone']);
        // A request that is not to the store does not count.
        $this->storeRequest($this->staff('ca_supervisor', $this->other), $this->other, $this->location($this->other, 'Synthetic branch store'), $this->location($this->other, 'Synthetic other dispensary', InventoryLocation::TYPE_DISPENSARY));
        $this->assertFalse(collect($this->page($viewer)['thisWeek'])->firstWhere('branchId', $this->other->id)['sent']);
    }

    public function test_the_reminder_shows_on_the_day_and_the_day_before_only_to_requesters_at_branches_that_have_not_sent(): void
    {
        $centralStore = app(CentralStoreService::class);
        $ca = $this->staff('ca');
        $theirCa = $this->staff('ca', $this->other);
        $finance = $this->staff('finance_officer');
        $message = "Store request day is Thursday. Your branch hasn't sent one this week.";

        // Wednesday (the day before): the requesters see it, on Inventory and on My day; someone who cannot request does not.
        $this->assertSame(['message' => $message, 'href' => '/inventory#store-request'], $centralStore->reminderFor($ca));
        $this->actingAs($ca)->get(route('my-day'))->assertOk()->assertInertia(fn ($page) => $page->where('storeReminder.message', $message));
        $this->actingAs($ca)->get(route('inventory.index'))->assertOk()->assertInertia(fn ($page) => $page->where('storeDay.reminder.message', $message)
            ->where('storeDay.isRequestDay', false)->where('storeDay.dayName', 'Thursday')->where('storeDay.storeLocationPublicIds', [$this->store->public_id]));
        $this->assertNull($centralStore->reminderFor($finance));
        $this->actingAs($finance)->get(route('my-day'))->assertOk()->assertInertia(fn ($page) => $page->where('storeReminder', null));

        // Once our branch has sent, it goes away for our branch only.
        $this->storeRequest($this->staff('ca_supervisor'), $this->branch, $this->f['location']);
        $this->assertNull($centralStore->reminderFor($ca));
        $this->assertSame($message, $centralStore->reminderFor($theirCa)['message'] ?? null);

        // Thursday: still shown to the branch that has not sent. Friday and Tuesday: never.
        $this->travelTo(CarbonImmutable::parse('2026-11-26 09:00:00', $this->branch->timezone));
        $this->assertNotNull($centralStore->reminderFor($theirCa));
        $this->travelTo(CarbonImmutable::parse('2026-11-27 09:00:00', $this->branch->timezone));
        $this->assertNull($centralStore->reminderFor($theirCa));
        $this->travelTo(CarbonImmutable::parse('2026-12-01 09:00:00', $this->branch->timezone));
        $this->assertNull($centralStore->reminderFor($theirCa));

        // Nothing leaves KPOne: no notification, mail or outbound message rows.
        foreach (['notifications', 'jobs'] as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                $this->assertSame(0, DB::table($table)->count(), $table);
            }
        }
    }

    public function test_the_off_day_warning_uses_the_branch_local_day_and_never_blocks_the_request(): void
    {
        $supervisor = $this->staff('ca_supervisor');
        // Wednesday 16:30 UTC is already Thursday 00:30 in Kuala Lumpur: the request day for this branch.
        $this->travelTo(CarbonImmutable::parse('2026-11-25 16:30:00', 'UTC'));
        $this->actingAs($supervisor)->get(route('inventory.index'))->assertOk()->assertInertia(fn ($page) => $page->where('storeDay.isRequestDay', true));
        // Friday: an off day; the request to the store still saves as usual.
        $this->travelTo(CarbonImmutable::parse('2026-11-27 10:00:00', $this->branch->timezone));
        $this->actingAs($supervisor)->get(route('inventory.index'))->assertOk()->assertInertia(fn ($page) => $page->where('storeDay.isRequestDay', false));
        $this->actingAs($supervisor)->post(route('inventory.stock-requests.store'), ['expected_branch_id' => $this->branch->id, 'source_location_public_id' => $this->store->public_id,
            'destination_location_public_id' => $this->f['location']->public_id, 'lines' => [['sku_public_id' => $this->f['sku']->public_id, 'quantity' => '5']], 'retry_key' => (string) Str::uuid()])
            ->assertRedirect();
        $this->assertSame(1, DB::table('inventory_stock_requests')->where('status', 'requested')->count());

        // No store at all: no warning context.
        DB::table('inventory_locations')->where('id', $this->store->id)->update(['is_active' => false]);
        $this->actingAs($supervisor)->get(route('inventory.index'))->assertOk()->assertInertia(fn ($page) => $page->where('storeDay', null));
    }

    public function test_the_page_is_refused_without_the_warehouse_permission(): void
    {
        foreach (['ca', 'director', 'finance_officer', 'resident_doctor', 'marketing'] as $role) {
            $this->actingAs($this->staff($role))->get(route('inventory.central-store'))->assertForbidden();
        }
        $this->actingAs($this->staff('store_admin'))->get(route('inventory.central-store'))->assertOk();
        $this->assertFalse($this->page($this->staff('store_admin'))['canChangeDay']);
        $this->assertTrue($this->page($this->staff('ca_supervisor'))['canChangeDay']);
    }

    public function test_the_request_day_is_versioned_audited_and_refused_without_permission(): void
    {
        $supervisor = $this->staff('ca_supervisor');
        $this->assertSame(['weekday' => 4, 'name' => 'Thursday'], $this->page($supervisor)['requestDay']);
        $key = (string) Str::uuid();
        $this->actingAs($supervisor)->put(route('inventory.central-store.request-day'), ['request_weekday' => 2, 'lock_version' => 0, 'idempotency_key' => $key])
            ->assertRedirect(route('inventory.central-store'));
        $store = $this->page($supervisor);
        $this->assertSame([['weekday' => 2, 'name' => 'Tuesday'], 1], [$store['requestDay'], $store['settingLockVersion']]);
        // The same key again returns the saved change and writes nothing more.
        $this->actingAs($supervisor)->put(route('inventory.central-store.request-day'), ['request_weekday' => 2, 'lock_version' => 0, 'idempotency_key' => $key])->assertRedirect();
        $this->assertSame(1, DB::table('inventory_store_setting_versions')->count());
        // The same key with a different day is refused.
        $this->actingAs($supervisor)->put(route('inventory.central-store.request-day'), ['request_weekday' => 5, 'lock_version' => 1, 'idempotency_key' => $key])->assertSessionHasErrors('idempotency_key');
        // A stale version is refused.
        $this->actingAs($supervisor)->put(route('inventory.central-store.request-day'), ['request_weekday' => 5, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('lock_version');
        $this->actingAs($supervisor)->put(route('inventory.central-store.request-day'), ['request_weekday' => 8, 'lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('request_weekday');
        $this->actingAs($supervisor)->put(route('inventory.central-store.request-day'), ['request_weekday' => 5, 'lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $this->assertSame([[1, 2], [2, 5]], DB::table('inventory_store_setting_versions')->orderBy('version')->get(['version', 'request_weekday'])->map(fn ($row) => [(int) $row->version, (int) $row->request_weekday])->all());
        $audit = DB::table('audit_logs')->where('event', 'inventory.store_settings.updated')->orderByDesc('id')->first();
        $this->assertSame(['changed_fields' => ['request_weekday'], 'version' => 2], array_intersect_key(json_decode((string) $audit->metadata, true), ['changed_fields' => 1, 'version' => 1]));
        // The new day drives the reminder: Thursday 3 Dec is the day before Friday.
        $this->travelTo(CarbonImmutable::parse('2026-12-03 10:00:00', $this->branch->timezone));
        $this->assertSame("Store request day is Friday. Your branch hasn't sent one this week.", app(CentralStoreService::class)->reminderFor($this->staff('ca'))['message'] ?? null);

        // Without the reference-data permission: refused at the route and in the service, nothing written.
        $this->actingAs($this->staff('store_admin'))->put(route('inventory.central-store.request-day'), ['request_weekday' => 1, 'lock_version' => 2, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        try {
            app(CentralStoreService::class)->updateRequestDay($this->staff('ca'), ['request_weekday' => 1, 'lock_version' => 2, 'idempotency_key' => (string) Str::uuid()]);
            $this->fail('A CA changed the store request day.');
        } catch (AuthorizationException) {
            $this->assertSame(5, (int) DB::table('inventory_store_settings')->value('request_weekday'));
        }
        $this->assertSame(2, DB::table('inventory_store_setting_versions')->count());

        // Versions are append-only in PostgreSQL.
        if (DB::getDriverName() === 'pgsql') {
            foreach (['update inventory_store_setting_versions set request_weekday = 1', 'delete from inventory_store_setting_versions'] as $sql) {
                try {
                    DB::transaction(fn () => DB::statement($sql));
                    $this->fail('A store setting version changed.');
                } catch (QueryException) {
                    $this->assertSame(2, DB::table('inventory_store_setting_versions')->count());
                }
            }
        }
    }

    public function test_a_validation_refusal_keeps_the_setting_unchanged(): void
    {
        try {
            app(CentralStoreService::class)->updateRequestDay($this->staff('ca_supervisor'), ['request_weekday' => 4, 'lock_version' => 0]);
            $this->fail('A change without a key was saved.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('idempotency_key', $exception->errors());
        }
        $this->assertSame(0, DB::table('inventory_store_settings')->count());
    }

    public function test_sent_this_week_turns_over_at_kuala_lumpur_midnight_between_sunday_and_monday(): void
    {
        // Sunday 22 Nov, 23:59 in Kuala Lumpur: a store request sent now belongs to the week of 16 Nov.
        $this->travelTo(CarbonImmutable::parse('2026-11-22 23:59:00', $this->branch->timezone));
        $ours = $this->staff('ca_supervisor');
        $viewer = $this->staff('ca_supervisor');
        $this->storeRequest($ours, $this->branch, $this->f['location']);
        $card = collect($this->page($viewer)['thisWeek'])->firstWhere('branchId', $this->branch->id);
        $this->assertSame([true, 'Week of 16 Nov', 'Sun 22 Nov'], [$card['sent'], $card['weekOf'], $card['sentOn']]);
        $this->assertNull(app(CentralStoreService::class)->reminderFor($this->staff('ca')));

        // Monday 23 Nov, 00:00 exactly: a new week, nothing sent yet.
        $this->travelTo(CarbonImmutable::parse('2026-11-23 00:00:00', $this->branch->timezone));
        $card = collect($this->page($viewer)['thisWeek'])->firstWhere('branchId', $this->branch->id);
        $this->assertSame([false, 'Week of 23 Nov'], [$card['sent'], $card['weekOf']]);
        // A request at Monday 00:00 counts for the new week.
        $this->storeRequest($ours, $this->branch, $this->f['location']);
        $card = collect($this->page($viewer)['thisWeek'])->firstWhere('branchId', $this->branch->id);
        $this->assertSame([true, 'Mon 23 Nov'], [$card['sent'], $card['sentOn']]);
    }

    public function test_a_store_batch_held_at_the_viewers_branch_still_counts_as_usable_in_the_store(): void
    {
        $viewer = $this->staff('ca_supervisor');
        app(InventoryBatchHoldService::class)->act($this->staff('ca_supervisor'), $this->f['batch']->public_id, 'place',
            ['expected_branch_id' => $this->branch->id, 'lock_version' => 0, 'retry_key' => (string) Str::uuid(), 'reason' => 'damaged']);
        // The viewer's active branch is the branch that holds the batch; branch holds don't apply at the store (STK-37).
        $this->assertSame($this->branch->id, app(BranchAccessService::class)->activeBranch($viewer)?->id);
        $row = $this->page($viewer)['storeStock']['rows'][0];
        $this->assertSame(['70.000', '0.000'], [$row['usable'], $row['notUsable']]);
        $this->assertNotNull($row['nearestExpiry']);
        // The same batch at the branch itself is not usable there.
        $cell = collect($this->page($viewer)['branchStock']['rows'][0]['branches'])->firstWhere('branchId', $this->branch->id);
        $this->assertSame('0.000', $cell['usable']);
    }

    public function test_a_failed_audit_saves_nothing(): void
    {
        $supervisor = $this->staff('ca_supervisor');
        $this->mock(AuditRecorder::class, fn ($mock) => $mock->shouldReceive('record')->andReturn(null));
        try {
            app(CentralStoreService::class)->updateRequestDay($supervisor, ['request_weekday' => 2, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid()]);
            $this->fail('The request day was saved without an audit row.');
        } catch (HttpException $exception) {
            $this->assertSame([503, 'The change could not be audited. Nothing was saved.'], [$exception->getStatusCode(), $exception->getMessage()]);
        }
        $this->assertSame(0, DB::table('inventory_store_settings')->count());
        $this->assertSame(0, DB::table('inventory_store_setting_versions')->count());
    }

    public function test_requests_to_the_store_list_the_hundred_oldest_and_keep_inactive_branches_under_other_branches(): void
    {
        $requester = $this->staff('ca_supervisor');
        $row = fn (int $i) => ['public_id' => (string) Str::uuid(), 'organisation_id' => $this->branch->organisation_id, 'requesting_branch_id' => $this->branch->id,
            'source_location_id' => $this->store->id, 'destination_location_id' => $this->f['location']->id, 'request_number' => sprintf('SR-SYN-%04d', $i),
            'status' => 'requested', 'lock_version' => 1, 'requested_by_user_id' => $requester->id, 'requested_at' => now()->subMinutes(300 - $i), 'created_at' => now(), 'updated_at' => now()];
        DB::table('inventory_stock_requests')->insert(array_map($row, range(1, 102)));

        $store = $this->page($requester, ['tab' => 'requests']);
        $rows = collect($store['requests'])->flatMap(fn (array $group) => $group['rows']);
        $this->assertSame(100, $rows->count());
        $this->assertSame(['SR-SYN-0001', 'SR-SYN-0100'], [$rows->first()['number'], $rows->last()['number']]);
        $this->assertSame(2, $store['requestsMore']);

        // A request from a branch that is no longer active stays visible under "Other branches".
        $theirs = $this->location($this->other, 'Synthetic other dispensary', InventoryLocation::TYPE_DISPENSARY);
        $old = $this->storeRequest($this->staff('ca_supervisor', $this->other), $this->other, $theirs);
        DB::table('inventory_stock_requests')->where('request_number', 'like', 'SR-SYN-%')->update(['status' => 'rejected', 'decided_by_user_id' => $requester->id, 'decided_at' => now(), 'rejection_reason' => 'Synthetic']);
        DB::table('branches')->where('id', $this->other->id)->update(['is_active' => false]);
        $store = $this->page($requester, ['tab' => 'requests']);
        $this->assertSame(0, $store['requestsMore']);
        $this->assertSame([['branch' => 'Other branches', 'ids' => [$old->public_id]]], array_map(fn (array $group) => ['branch' => $group['branch'], 'ids' => array_column($group['rows'], 'publicId')], $store['requests']));
    }
}

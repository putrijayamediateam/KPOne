<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Models\InventoryItem;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\PurchaseOrder;
use App\Domain\Organisation\Inventory\Services\ProcurementService;
use App\Domain\Organisation\Inventory\Services\RfqComparison;
use App\Domain\Organisation\Inventory\Services\RfqService;
use App\Domain\Organisation\Inventory\Services\SupplierAdministrationService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\VerifiedReceivingFixture;

/** STK-48 (owner decision 21; "build before Monday", 25 Nov 2026): requests for quotation before a purchase order. */
final class RfqTest extends VisitTestCase
{
    private function as(User $actor): User
    {
        app(BranchAccessService::class)->select($actor, $this->branch);

        return $actor;
    }

    private function staff(string $role): User
    {
        return $this->as((new VerifiedReceivingFixture($this->branch))->actor($role));
    }

    private function service(): RfqService
    {
        return app(RfqService::class);
    }

    /** The fixture, a second item and three suppliers. */
    private function setUpRound(): array
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build();
        $item = InventoryItem::query()->findOrFail($f['sku']->inventory_item_id)->replicate();
        $item->forceFill(['public_id' => (string) Str::uuid(), 'code' => 'STK48-'.Str::upper(Str::random(6)), 'generic_name' => 'Synthetic RFQ syrup'])->save();
        $second = InventorySku::query()->findOrFail($f['sku']->id)->replicate();
        $second->forceFill(['public_id' => (string) Str::uuid(), 'inventory_item_id' => $item->id, 'sku_code' => 'STK48-'.Str::upper(Str::random(6)), 'barcode' => null])->save();
        $suppliers = [$f['supplier']];
        foreach (['B', 'C'] as $letter) {
            $suppliers[] = app(SupplierAdministrationService::class)->create($f['finance'], ['code' => 'SYN-RFQ-'.$letter.Str::upper(Str::random(5)), 'name' => 'Synthetic RFQ supplier '.$letter,
                'business_email' => 'rfq-'.strtolower($letter).'@supplier.test', 'contact_name' => null, 'business_phone' => null]);
        }

        return [...$f, 'second' => $second, 'suppliers' => $suppliers, 'finance' => $this->as($f['finance'])];
    }

    /** @return array<string, mixed> */
    private function startInput(array $f, array $extra = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), 'destination_location_public_id' => $f['location']->public_id,
            'quote_by_date' => now()->setTimezone($this->branch->timezone)->addDays(5)->toDateString(),
            'supplier_public_ids' => array_map(fn ($s) => $s->public_id, $f['suppliers']),
            'lines' => [['sku_public_id' => $f['sku']->public_id, 'quantity' => '300'], ['sku_public_id' => $f['second']->public_id, 'quantity' => '20']], ...$extra];
    }

    private function rfq(string $roundPublicId, int $index): object
    {
        $round = DB::table('inventory_rfq_rounds')->where('public_id', $roundPublicId)->sole();

        return DB::table('inventory_rfqs')->where('rfq_round_id', $round->id)->orderBy('id')->get()[$index];
    }

    /**
     * @param  list<array{0:bool, 1?:string, 2?:string}>  $answers  [cannot supply, pack price RM, pack size]
     */
    private function quote(User $actor, string $round, int $index, array $answers, int $lead = 7, ?string $validUntil = null, ?string $key = null): int
    {
        $rfq = $this->rfq($round, $index);
        $lineIds = DB::table('inventory_rfq_round_lines')->where('rfq_round_id', $rfq->rfq_round_id)->orderBy('id')->pluck('id')->all();
        $lines = [];
        foreach ($answers as $i => $answer) {
            $lines[] = ['line_id' => $lineIds[$i], 'cannot_supply' => $answer[0], 'pack_price' => $answer[1] ?? null, 'pack_size' => $answer[2] ?? null];
        }

        return $this->service()->recordQuote($actor, $rfq->public_id, ['expected_branch_id' => $this->branch->id, 'lock_version' => (int) $rfq->lock_version,
            'idempotency_key' => $key ?? (string) Str::uuid(), 'lead_time_days' => $lead, 'valid_until' => $validUntil ?? now()->setTimezone($this->branch->timezone)->addDays(30)->toDateString(),
            'quote_reference' => 'Q-REF-'.$index, 'lines' => $lines]);
    }

    private function choose(User $actor, string $round, int $index, array $extra = []): void
    {
        $rfq = $this->rfq($round, $index);
        $this->service()->choose($actor, $rfq->public_id, ['expected_branch_id' => $this->branch->id, 'lock_version' => (int) $rfq->lock_version, ...$extra]);
    }

    private function roundProps(User $actor, string $round): array
    {
        return $this->actingAs($actor)->get(route('inventory.rfqs.round', ['round' => $round]))->assertOk()->viewData('page')['props']['rfq'];
    }

    public function test_starting_a_round_makes_one_rfq_per_supplier_with_the_same_lines_and_is_idempotent(): void
    {
        $f = $this->setUpRound();
        $supervisor = $this->staff('ca_supervisor');
        $input = $this->startInput($f);
        $this->actingAs($supervisor)->post(route('inventory.rfqs.store'), $input)->assertSessionHasNoErrors();
        $round = DB::table('inventory_rfq_rounds')->sole();
        $this->assertSame(['open', $supervisor->id], [$round->status, (int) $round->created_by_user_id]);
        $rfqs = DB::table('inventory_rfqs')->where('rfq_round_id', $round->id)->orderBy('id')->get();
        $this->assertCount(3, $rfqs);
        $this->assertSame(array_map(fn ($s) => $s->id, $f['suppliers']), $rfqs->pluck('supplier_id')->map(fn ($id) => (int) $id)->all());
        foreach ($rfqs as $rfq) {
            $this->assertMatchesRegularExpression('/^RFQ-\d{6}-[A-Z0-9]{8}$/', $rfq->rfq_number);
            $this->assertSame('draft', $rfq->status);
        }
        $this->assertSame(2, DB::table('inventory_rfq_round_lines')->where('rfq_round_id', $round->id)->count());
        // A retry with the same key returns the same round; a different payload with it is refused.
        $this->assertSame($round->public_id, $this->service()->start($supervisor, $input));
        $this->assertThrows(fn () => $this->service()->start($supervisor, [...$input, 'quote_by_date' => now()->setTimezone($this->branch->timezone)->addDays(6)->toDateString()]), ValidationException::class);
        $this->assertSame(1, DB::table('inventory_rfq_rounds')->count());
        $audit = DB::table('audit_logs')->where('event', 'inventory.rfq_round.started')->sole();
        $this->assertSame(['lines', 'rfq_ids', 'rfq_round_id', 'suppliers'], collect(array_keys(json_decode((string) $audit->metadata, true)))->sort()->values()->all());
        // Someone without the purchase order permission gets nothing.
        $this->actingAs($this->staff('ca'))->get(route('inventory.rfqs'))->assertForbidden();
        $this->actingAs($this->staff('ca'))->post(route('inventory.rfqs.store'), $this->startInput($f))->assertForbidden();
    }

    public function test_the_print_has_items_and_quantities_but_never_a_price(): void
    {
        $f = $this->setUpRound();
        $round = $this->service()->start($f['finance'], $this->startInput($f));
        $this->quote($f['finance'], $round, 0, [[false, '12.00', '30'], [false, '5.00', '1']]);
        $rfq = $this->rfq($round, 0);
        $page = $this->actingAs($f['finance'])->get(route('inventory.rfqs.print', ['rfq' => $rfq->public_id]))->assertOk()->viewData('page');
        $this->assertSame('Inventory/RfqPrint', $page['component']);
        $json = json_encode($page['props']);
        $this->assertStringContainsString('"quoteBy"', $json);
        $this->assertSame(['300', '20'], array_column($page['props']['rfq']['lines'], 'quantity'));
        foreach (['packPrice', 'Sen', 'price', 'Q-REF', 'cost', 'note'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, (string) json_encode($page['props']['rfq']), $forbidden);
        }
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'inventory.rfq.printed')->count());
    }

    public function test_quotes_are_versioned_corrected_until_chosen_and_refused_without_cost_view(): void
    {
        $f = $this->setUpRound();
        $supervisor = $this->staff('ca_supervisor');
        $round = $this->service()->start($supervisor, $this->startInput($f));
        $rfq = $this->rfq($round, 0);
        $this->service()->markSent($supervisor, $rfq->public_id, ['expected_branch_id' => $this->branch->id, 'lock_version' => 1]);
        $this->assertSame(['sent', $supervisor->id], [$this->rfq($round, 0)->status, (int) $this->rfq($round, 0)->sent_by_user_id]);

        // A CA Supervisor has no cost view: recording a quote is refused.
        $this->assertThrows(fn () => $this->quote($supervisor, $round, 0, [[false, '12.00', '30'], [true]]), AuthorizationException::class);

        $key = (string) Str::uuid();
        $this->assertSame(1, $this->quote($f['finance'], $round, 0, [[false, '12.00', '30'], [true]], 7, null, $key));
        // A lost response retried with the same key returns the same version.
        $rfq = $this->rfq($round, 0);
        $this->assertSame(1, $this->service()->recordQuote($f['finance'], $rfq->public_id, ['expected_branch_id' => $this->branch->id, 'lock_version' => 2, 'idempotency_key' => $key,
            'lead_time_days' => 7, 'valid_until' => now()->setTimezone($this->branch->timezone)->addDays(30)->toDateString(), 'quote_reference' => 'Q-REF-0',
            'lines' => DB::table('inventory_rfq_round_lines')->where('rfq_round_id', $rfq->rfq_round_id)->orderBy('id')->pluck('id')->values()
                ->map(fn ($id, $i) => ['line_id' => $id, 'cannot_supply' => $i === 1, 'pack_price' => $i === 0 ? '12.00' : null, 'pack_size' => $i === 0 ? '30' : null])->all()]));
        $this->assertSame(2, $this->quote($f['finance'], $round, 0, [[false, '11.50', '30'], [false, '4.00', '1']]));
        $this->assertSame([1, 2], DB::table('inventory_rfq_quotes')->orderBy('version')->pluck('version')->map(fn ($v) => (int) $v)->all());
        $this->assertSame('quoted', $this->rfq($round, 0)->status);

        // An incomplete answer or a bad price is refused.
        $this->assertThrows(fn () => $this->quote($f['finance'], $round, 1, [[false, '12.00', '30']]), ValidationException::class);
        $this->assertThrows(fn () => $this->quote($f['finance'], $round, 1, [[false, '12.005', '30'], [true]]), ValidationException::class);

        // Once chosen, the quote is frozen.
        $this->choose($f['finance'], $round, 0);
        $this->assertThrows(fn () => $this->quote($f['finance'], $round, 0, [[false, '10.00', '30'], [true]]), ValidationException::class);
        $this->assertSame(DB::table('inventory_rfq_quotes')->where('version', 2)->value('id'), $this->rfq($round, 0)->current_quote_id);
        // The audit never holds a price or the reference.
        foreach (DB::table('audit_logs')->where('event', 'like', 'inventory.rfq%')->pluck('metadata') as $metadata) {
            $this->assertStringNotContainsString('Q-REF', (string) $metadata);
            $this->assertStringNotContainsString('1150', (string) $metadata);
        }
    }

    public function test_the_comparison_picks_the_cheapest_per_line_and_in_total_and_hides_prices_without_cost_view(): void
    {
        $f = $this->setUpRound();
        $round = $this->service()->start($f['finance'], $this->startInput($f));
        // Line 1 (300 units): A 12.00/30 = 0.40, B 6.00/15 = 0.40 (tie, B is faster), C 0.45. Line 2 (20 units): A 5.00, B 6.00, C can't supply.
        $this->quote($f['finance'], $round, 0, [[false, '12.00', '30'], [false, '5.00', '1']], 10);
        $this->quote($f['finance'], $round, 1, [[false, '6.00', '15'], [false, '6.00', '1']], 3);
        $props = $this->roundProps($f['finance'], $round);
        $a = $props['rfqs'][0]['publicId'];
        $b = $props['rfqs'][1]['publicId'];
        $this->assertSame([$b, 'faster_delivery'], [$props['lines'][0]['cheapest'], $props['lines'][0]['tieBreak']]);
        $this->assertSame([$a, null], [$props['lines'][1]['cheapest'], $props['lines'][1]['tieBreak']]);
        // Totals: A 120.00 + 100.00 = 220.00; B 120.00 + 120.00 = 240.00.
        $this->assertSame([22000, true, 0], [$props['rfqs'][0]['quote']['totalSen'], $props['rfqs'][0]['quote']['cheapestTotal'], $props['rfqs'][0]['quote']['gapSen']]);
        $this->assertSame([24000, false, 2000], [$props['rfqs'][1]['quote']['totalSen'], $props['rfqs'][1]['quote']['cheapestTotal'], $props['rfqs'][1]['quote']['gapSen']]);
        $this->assertSame(4000, $props['rfqs'][0]['cells'][$props['lines'][0]['id']]['unitHundredthSen']);
        $this->assertNull($props['rfqs'][2]['quote']);

        // Unequal sets: C can't supply line 2, so no cheapest total is given across the round.
        $this->quote($f['finance'], $round, 2, [[false, '13.50', '30'], [true]], 1);
        $props = $this->roundProps($f['finance'], $round);
        $this->assertSame([false, false, false], array_map(fn ($r) => $r['quote']['cheapestTotal'], $props['rfqs']));
        $this->assertSame(1, $props['rfqs'][2]['quote']['suppliedCount']);

        // A CA Supervisor sees lines, lead times and "can't supply", never a price or a cheapest marker.
        $hidden = $this->roundProps($this->staff('ca_supervisor'), $round);
        $json = (string) json_encode($hidden);
        foreach (['packPriceSen', 'unitHundredthSen', 'lineSen', 'totalSen', 'gapSen', 'cheapest', 'packSize'] as $key) {
            $this->assertStringNotContainsString('"'.$key, $json, $key);
        }
        $this->assertTrue($hidden['rfqs'][2]['cells'][$hidden['lines'][1]['id']]['cannotSupply']);
        $this->assertSame(1, $hidden['rfqs'][2]['quote']['leadTimeDays']);
        $this->assertFalse($hidden['can']['recordQuote']);
        $this->assertFalse($hidden['can']['choose']);
    }

    public function test_choosing_a_quote_that_is_not_the_cheapest_needs_a_reason_and_an_expired_quote_needs_confirmation(): void
    {
        $f = $this->setUpRound();
        $round = $this->service()->start($f['finance'], $this->startInput($f));
        $today = now()->setTimezone($this->branch->timezone)->toDateString();
        $this->quote($f['finance'], $round, 0, [[false, '12.00', '30'], [false, '5.00', '1']], 10, $today);
        $this->quote($f['finance'], $round, 1, [[false, '15.00', '30'], [false, '6.00', '1']], 2);

        try {
            $this->choose($f['finance'], $round, 1);
            $this->fail('A more expensive quote was chosen without a reason.');
        } catch (ValidationException $e) {
            $this->assertSame('This is not the cheapest quote. Choose a reason.', $e->errors()['reason'][0]);
        }
        $this->assertThrows(fn () => $this->choose($f['finance'], $round, 1, ['reason' => 'other']), ValidationException::class);
        // A CA Supervisor may not choose at all.
        $this->assertThrows(fn () => $this->choose($this->staff('ca_supervisor'), $round, 1, ['reason' => 'faster_delivery']), AuthorizationException::class);

        // The cheapest quote expires tomorrow: choosing it needs "Still valid, confirmed with the supplier".
        $this->travel(2)->days();
        try {
            $this->choose($f['finance'], $round, 0);
            $this->fail('An expired quote was chosen without confirmation.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('still_valid_confirmed', $e->errors());
        }
        $this->choose($f['finance'], $round, 0, ['still_valid_confirmed' => true]);
        $chosen = $this->rfq($round, 0);
        $this->assertSame(['chosen', true, null], [$chosen->status, (bool) $chosen->expired_confirmed, $chosen->chosen_reason]);
        // One chosen RFQ per round.
        $this->assertThrows(fn () => $this->choose($f['finance'], $round, 1, ['reason' => 'faster_delivery']), ValidationException::class);
    }

    public function test_conversion_makes_one_draft_order_with_the_quoted_estimates_and_closes_the_others(): void
    {
        $f = $this->setUpRound();
        $round = $this->service()->start($f['finance'], $this->startInput($f));
        // Line 1: RM 1.00 for 7 units = 14.2857 sen a unit, rounded half up to 14. Line 2 can't be supplied.
        $this->quote($f['finance'], $round, 0, [[false, '1.00', '7'], [true]], 4);
        $this->quote($f['finance'], $round, 1, [[false, '2.00', '7'], [false, '5.00', '1']], 4);
        $this->choose($f['finance'], $round, 0, ['reason' => 'stock_availability']);
        $roundRow = DB::table('inventory_rfq_rounds')->where('public_id', $round)->sole();
        $key = (string) Str::uuid();
        $input = ['expected_branch_id' => $this->branch->id, 'lock_version' => (int) $roundRow->lock_version, 'idempotency_key' => $key];

        // A CA Supervisor cannot convert (it would carry quoted prices).
        $this->assertThrows(fn () => $this->service()->convert($this->staff('ca_supervisor'), $round, $input), AuthorizationException::class);

        $result = $this->service()->convert($f['finance'], $round, $input);
        $order = PurchaseOrder::query()->where('public_id', $result['order'])->sole();
        $this->assertSame([PurchaseOrder::STATUS_DRAFT, $f['suppliers'][0]->id, $f['finance']->id], [$order->status, (int) $order->supplier_id, (int) $order->created_by_user_id]);
        $line = $order->lines()->sole();
        $this->assertSame(['300.000', 14, $f['sku']->id], [(string) $line->ordered_quantity, (int) $line->estimated_unit_cost_sen, (int) $line->inventory_sku_id]);
        $this->assertCount(1, $result['leftOff']);
        $this->assertSame([$order->id, 'chosen'], [(int) $this->rfq($round, 0)->purchase_order_id, $this->rfq($round, 0)->status]);
        $this->assertSame(['not_chosen', 'not_chosen'], [$this->rfq($round, 1)->status, $this->rfq($round, 2)->status]);
        $this->assertSame('converted', DB::table('inventory_rfq_rounds')->where('public_id', $round)->value('status'));

        // A retry with the same key returns the same order; another key is refused; only one order exists.
        $this->assertSame($result['order'], $this->service()->convert($f['finance'], $round, $input)['order']);
        $this->assertThrows(fn () => $this->service()->convert($f['finance'], $round, [...$input, 'idempotency_key' => (string) Str::uuid()]), ValidationException::class);
        $this->assertSame(1, DB::table('inventory_rfqs')->whereNotNull('purchase_order_id')->count());

        // The converted order says where it came from, and a converted round cannot be cancelled.
        $this->assertThrows(fn () => $this->service()->cancelRound($f['finance'], $round, ['expected_branch_id' => $this->branch->id, 'lock_version' => 2, 'reason' => 'not_needed']), ValidationException::class);
        $audit = DB::table('audit_logs')->where('event', 'inventory.rfq_round.converted')->sole();
        $this->assertStringNotContainsString('"14"', (string) $audit->metadata);
    }

    public function test_the_estimate_is_the_exact_pack_price_over_the_pack_size_rounded_half_up(): void
    {
        $this->assertSame(40, RfqComparison::unitSen(1200, 30_000));
        $this->assertSame(14, RfqComparison::unitSen(100, 7_000));
        $this->assertSame(2, RfqComparison::unitSen(5, 3_000));
        $this->assertSame(1, RfqComparison::unitSen(1, 2_000));
        $this->assertSame(0, RfqComparison::unitSen(1, 3_000));
        $this->assertSame(40, RfqComparison::unitSen(1, 25)); // 0.025 units in a pack
    }

    public function test_a_ca_supervisor_editing_the_converted_draft_keeps_the_estimates_and_never_receives_them(): void
    {
        $f = $this->setUpRound();
        $round = $this->service()->start($f['finance'], $this->startInput($f));
        $this->quote($f['finance'], $round, 0, [[false, '12.00', '30'], [false, '5.00', '1']]);
        $this->choose($f['finance'], $round, 0);
        $order = PurchaseOrder::query()->where('public_id', $this->service()->convert($f['finance'], $round, ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])['order'])->sole();
        $supervisor = $this->staff('ca_supervisor');

        // The supervisor's Inventory page and print carry no estimate.
        $operations = $this->actingAs($supervisor)->get(route('inventory.index'))->assertOk()->viewData('page')['props'];
        $this->assertStringNotContainsString('estimated', strtolower((string) json_encode($operations['operations']['purchaseOrders'] ?? [])));
        $this->assertFalse($operations['operations']['permissions']['viewCost']);
        $print = $this->actingAs($supervisor)->get(route('inventory.purchase-orders.print', ['purchaseOrder' => $order->public_id]))->assertOk()->viewData('page')['props']['order'];
        $this->assertFalse($print['showPrices']);
        $this->assertArrayNotHasKey('unitCostSen', $print['lines'][0]);

        // Their edit form has no cost fields: each line keeps its estimate; a line they add has none.
        $third = InventorySku::query()->findOrFail($f['second']->id)->replicate();
        $third->forceFill(['public_id' => (string) Str::uuid(), 'sku_code' => 'STK48-'.Str::upper(Str::random(6)), 'barcode' => null])->save();
        $order = app(ProcurementService::class)->updateDraft($supervisor, $order, ['expected_branch_id' => $this->branch->id, 'lock_version' => $order->lock_version,
            'supplier_public_id' => $f['suppliers'][0]->public_id, 'destination_location_public_id' => $f['location']->public_id, 'lines' => [
                ['sku_public_id' => $f['sku']->public_id, 'quantity' => '250'], ['sku_public_id' => $f['second']->public_id, 'quantity' => '20'], ['sku_public_id' => $third->public_id, 'quantity' => '5']]]);
        $estimates = $order->lines()->get()->mapWithKeys(fn ($l) => [(int) $l->inventory_sku_id => $l->estimated_unit_cost_sen === null ? null : (int) $l->estimated_unit_cost_sen])->all();
        $this->assertSame([$f['sku']->id => 40, $f['second']->id => 500, $third->id => null], $estimates);

        // A cost-view holder's request stays final, so they can clear an estimate.
        $order = app(ProcurementService::class)->updateDraft($f['finance'], $order, ['expected_branch_id' => $this->branch->id, 'lock_version' => $order->lock_version,
            'supplier_public_id' => $f['suppliers'][0]->public_id, 'destination_location_public_id' => $f['location']->public_id, 'lines' => [['sku_public_id' => $f['sku']->public_id, 'quantity' => '250']]]);
        $this->assertNull($order->lines()->sole()->estimated_unit_cost_sen);
    }

    public function test_conversion_is_refused_when_the_supplier_has_been_archived(): void
    {
        $f = $this->setUpRound();
        $round = $this->service()->start($f['finance'], $this->startInput($f));
        $this->quote($f['finance'], $round, 1, [[false, '12.00', '30'], [false, '5.00', '1']]);
        $this->choose($f['finance'], $round, 1);
        DB::table('inventory_suppliers')->where('id', $f['suppliers'][1]->id)->update(['is_active' => false]);
        try {
            $this->service()->convert($f['finance'], $round, ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'idempotency_key' => (string) Str::uuid()]);
            $this->fail('An order was made for an archived supplier.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('archived', $e->errors()['status'][0]);
        }
        $this->assertSame(0, DB::table('inventory_rfqs')->whereNotNull('purchase_order_id')->count());
    }

    public function test_cancel_rules(): void
    {
        $f = $this->setUpRound();
        $round = $this->service()->start($f['finance'], $this->startInput($f));
        $rfq = $this->rfq($round, 2);
        $cancel = fn (array $extra) => $this->service()->cancelRfq($f['finance'], $rfq->public_id, ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, ...$extra]);
        $this->assertThrows(fn () => $cancel([]), ValidationException::class);
        $this->assertThrows(fn () => $cancel(['reason' => 'other']), ValidationException::class);
        $cancel(['reason' => 'supplier_no_reply']);
        $this->assertSame(['cancelled', 'supplier_no_reply'], [$this->rfq($round, 2)->status, $this->rfq($round, 2)->cancel_reason]);
        // A cancelled RFQ takes no quote.
        $this->assertThrows(fn () => $this->quote($f['finance'], $round, 2, [[true], [true]]), ValidationException::class);

        // A chosen RFQ can be cancelled before conversion, which frees the round to choose another.
        $this->quote($f['finance'], $round, 0, [[false, '12.00', '30'], [false, '5.00', '1']]);
        $this->quote($f['finance'], $round, 1, [[false, '13.00', '30'], [false, '5.00', '1']]);
        $this->choose($f['finance'], $round, 0);
        $this->service()->cancelRfq($f['finance'], $this->rfq($round, 0)->public_id, ['expected_branch_id' => $this->branch->id, 'lock_version' => (int) $this->rfq($round, 0)->lock_version, 'reason' => 'entered_wrongly']);
        $this->choose($f['finance'], $round, 1);
        $this->assertSame('chosen', $this->rfq($round, 1)->status);

        // Cancelling the round closes every open RFQ in it.
        $this->service()->cancelRound($this->staff('ca_supervisor'), $round, ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'reason' => 'other', 'note' => 'Ordered elsewhere']);
        $this->assertSame(['cancelled', 'cancelled', 'cancelled'], DB::table('inventory_rfqs')->orderBy('id')->pluck('status')->all());
        $this->assertSame('cancelled', DB::table('inventory_rfq_rounds')->value('status'));
        $this->assertStringNotContainsString('Ordered elsewhere', (string) DB::table('audit_logs')->where('event', 'inventory.rfq_round.cancelled')->value('metadata'));
    }

    public function test_postgresql_keeps_rfqs_moving_forward_and_never_deletes_them(): void
    {
        $f = $this->setUpRound();
        $round = $this->service()->start($f['finance'], $this->startInput($f));
        $this->quote($f['finance'], $round, 0, [[false, '12.00', '30'], [false, '5.00', '1']]);
        $this->quote($f['finance'], $round, 1, [[false, '13.00', '30'], [false, '5.00', '1']]);
        $first = $this->rfq($round, 0);
        $second = $this->rfq($round, 1);
        $refused = function (callable $sql, string $why): void {
            try {
                DB::transaction(fn () => $sql());
                $this->fail($why);
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        };
        $refused(fn () => DB::table('inventory_rfqs')->where('id', $first->id)->delete(), 'An RFQ was deleted.');
        $refused(fn () => DB::statement('TRUNCATE inventory_rfqs CASCADE'), 'RFQs were truncated.');
        $refused(fn () => DB::table('inventory_rfq_quotes')->where('rfq_id', $first->id)->update(['lead_time_days' => 1]), 'A quote version was changed.');
        $refused(fn () => DB::table('inventory_rfq_quote_lines')->delete(), 'Quote lines were deleted.');
        $refused(fn () => DB::table('inventory_rfq_round_lines')->update(['quantity' => '1']), 'A round line was changed.');
        $refused(fn () => DB::table('inventory_rfq_rounds')->delete(), 'A round was deleted.');
        $refused(fn () => DB::table('inventory_rfqs')->where('id', $first->id)->update(['status' => 'sent', 'lock_version' => (int) $first->lock_version + 1]), 'An RFQ status moved back.');
        // One chosen per round.
        $choose = fn (object $rfq) => DB::table('inventory_rfqs')->where('id', $rfq->id)->update(['status' => 'chosen', 'chosen_by_user_id' => $f['finance']->id, 'chosen_at' => now(), 'lock_version' => (int) $rfq->lock_version + 1]);
        $choose($first);
        $refused(fn () => $choose($second), 'Two RFQs were chosen in one round.');
        // A converted RFQ links only to a draft order of its own supplier.
        $other = app(ProcurementService::class)->create($f['finance'], ['expected_branch_id' => $this->branch->id, 'supplier_public_id' => $f['suppliers'][2]->public_id,
            'destination_location_public_id' => $f['location']->public_id, 'lines' => [['sku_public_id' => $f['sku']->public_id, 'quantity' => '1']]]);
        $refused(fn () => DB::table('inventory_rfqs')->where('id', $first->id)->update(['purchase_order_id' => $other->id, 'lock_version' => (int) $first->lock_version + 2]), 'An RFQ was linked to another supplier\'s order.');
    }

    public function test_the_pages_render_for_their_holders(): void
    {
        $f = $this->setUpRound();
        $round = $this->service()->start($f['finance'], $this->startInput($f));
        $list = $this->actingAs($f['finance'])->get(route('inventory.rfqs'))->assertOk()->viewData('page');
        $this->assertSame('Inventory/Rfqs', $list['component']);
        $row = $list['props']['rfqs']['rounds']['rows'][0];
        $this->assertSame([0, 3, 'waiting'], [$row['quotesIn'], $row['suppliers'], $row['state']]);
        $this->quote($f['finance'], $round, 0, [[false, '12.00', '30'], [false, '5.00', '1']]);
        $row = $this->actingAs($f['finance'])->get(route('inventory.rfqs'))->viewData('page')['props']['rfqs']['rounds']['rows'][0];
        $this->assertSame([1, 'quotes_in'], [$row['quotesIn'], $row['state']]);
        $this->assertSame('Inventory/RfqRound', $this->actingAs($f['finance'])->get(route('inventory.rfqs.round', ['round' => $round]))->assertOk()->viewData('page')['component']);
        // HTTP actions return to the round with the reason shown.
        $rfq = $this->rfq($round, 1);
        $this->actingAs($f['finance'])->post(route('inventory.rfqs.act', ['rfq' => $rfq->public_id, 'action' => 'choose']), ['expected_branch_id' => $this->branch->id, 'lock_version' => (int) $rfq->lock_version, 'round_public_id' => $round])
            ->assertRedirect(route('inventory.rfqs.round', ['round' => $round]))->assertSessionHasErrors('status');
        $this->actingAs($f['finance'])->post(route('inventory.rfqs.act', ['rfq' => $rfq->public_id, 'action' => 'sent']), ['expected_branch_id' => $this->branch->id, 'lock_version' => (int) $rfq->lock_version, 'round_public_id' => $round])
            ->assertRedirect(route('inventory.rfqs.round', ['round' => $round]))->assertSessionHasNoErrors();
    }
}

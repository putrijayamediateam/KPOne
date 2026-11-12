<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Organisation\Inventory\Models\PurchaseOrder;
use App\Domain\Organisation\Inventory\Services\PurchaseRequestService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\VerifiedReceivingFixture;

/** STK-18 (plan approved by B.E.R.N.I.S.S for the owner, 2026-11-12): purchase requests, never reviewed by the requester. */
final class PurchaseRequestTest extends VisitTestCase
{
    private function staff(string $role): User
    {
        $actor = (new VerifiedReceivingFixture($this->branch))->actor($role);
        app(BranchAccessService::class)->select($actor, $this->branch);

        return $actor;
    }

    /** @return array<string, mixed> */
    private function sendInput(array $f, array $extra = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), 'destination_location_public_id' => $f['location']->public_id,
            'preferred_supplier_public_id' => $f['supplier']->public_id, 'lines' => [['sku_public_id' => $f['sku']->public_id, 'quantity' => '10', 'note' => 'Running low before the weekend']], ...$extra];
    }

    private function request(string $publicId): object
    {
        return DB::table('inventory_purchase_requests')->where('public_id', $publicId)->sole();
    }

    public function test_only_the_ca_and_ca_supervisor_may_send_requests(): void
    {
        $holders = collect(PermissionCatalogue::roles())->filter(fn (array $p) => in_array(PurchaseRequestService::CREATE, $p, true))->keys()->sort()->values()->all();
        $this->assertSame(['ca', 'ca_supervisor'], $holders);
        $this->assertTrue(Role::findByName('ca')->hasPermissionTo(PurchaseRequestService::CREATE));
        $this->assertFalse(Role::findByName('finance_officer')->hasPermissionTo(PurchaseRequestService::CREATE));
    }

    public function test_a_ca_sends_a_request_and_a_supervisor_converts_it_into_a_draft_order_keeping_the_asked_quantity(): void
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build();
        $ca = $this->staff('ca');
        $this->actingAs($ca)->post(route('inventory.purchase-requests.store'), $this->sendInput($f))->assertRedirect(route('inventory.purchase-requests'))->assertSessionHasNoErrors();
        $request = DB::table('inventory_purchase_requests')->sole();
        $this->assertSame(['waiting', $ca->id], [$request->status, (int) $request->requested_by_user_id]);
        $this->assertStringStartsWith('PR-', $request->request_number);

        $supervisor = $this->staff('ca_supervisor');
        $line = DB::table('inventory_purchase_request_lines')->sole();
        $this->actingAs($supervisor)->post(route('inventory.purchase-requests.act', ['purchaseRequest' => $request->public_id, 'action' => 'convert']), [
            'expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'supplier_public_id' => $f['supplier']->public_id, 'lines' => [['line_id' => $line->id, 'quantity' => '6']],
        ])->assertRedirect(route('inventory.purchase-requests', ['tab' => 'converted']))->assertSessionHasNoErrors();

        $request = $this->request($request->public_id);
        $order = PurchaseOrder::query()->findOrFail($request->purchase_order_id);
        $this->assertSame(['converted', $supervisor->id, PurchaseOrder::STATUS_DRAFT, $supervisor->id], [$request->status, (int) $request->decided_by_user_id, $order->status, (int) $order->created_by_user_id]);
        $this->assertSame('6.000', (string) $order->lines()->sole()->ordered_quantity);
        $line = DB::table('inventory_purchase_request_lines')->sole();
        $this->assertSame(['10.000', '6.000'], [(string) $line->requested_quantity, (string) $line->converted_quantity]);
        $this->assertSame(['sent', 'converted'], DB::table('inventory_purchase_request_actions')->orderBy('id')->pluck('action')->all());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'inventory.purchase_request.converted')->count());

        // The converted tab shows the asked and ordered quantities and the order number.
        $page = $this->actingAs($ca)->get(route('inventory.purchase-requests', ['tab' => 'converted']))->assertOk()->viewData('page')['props']['requests'];
        $row = $page['requests']['rows'][0];
        $this->assertSame(['10', '6', $order->order_number], [$row['lines'][0]['asked'], $row['lines'][0]['converted'], $row['order']['number']]);
    }

    public function test_the_requester_can_never_convert_or_reject_their_own_request(): void
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build();
        $supervisor = $this->staff('ca_supervisor');
        $service = app(PurchaseRequestService::class);
        $id = $service->send($supervisor, $this->sendInput($f));
        $line = DB::table('inventory_purchase_request_lines')->sole();

        foreach ([fn () => $service->convert($supervisor, $id, ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'supplier_public_id' => $f['supplier']->public_id,
            'lines' => [['line_id' => $line->id, 'quantity' => '10']]]), fn () => $service->reject($supervisor, $id, ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'reason' => 'budget'])] as $attempt) {
            try {
                $attempt();
                $this->fail('The requester reviewed their own request.');
            } catch (AuthorizationException $e) {
                $this->assertSame('You sent this request, so another person must review it.', $e->getMessage());
            }
        }
        $this->assertSame([0, 'waiting'], [PurchaseOrder::query()->count() - 1, $this->request($id)->status]);

        // Over HTTP the reason stays on the page.
        $this->actingAs($supervisor)->post(route('inventory.purchase-requests.act', ['purchaseRequest' => $id, 'action' => 'reject']), ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'reason' => 'budget'])
            ->assertRedirect(route('inventory.purchase-requests', ['tab' => 'waiting']))->assertSessionHasErrors(['request' => 'You sent this request, so another person must review it.']);

        // PostgreSQL repeats the rule even if the service is bypassed.
        $this->expectException(QueryException::class);
        DB::table('inventory_purchase_requests')->where('public_id', $id)->update(['status' => 'rejected', 'reject_reason' => 'budget', 'decided_by_user_id' => $supervisor->id, 'decided_at' => now(), 'lock_version' => 2]);
    }

    public function test_a_finance_officer_rejects_with_a_reason_and_only_the_requester_withdraws(): void
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build();
        $ca = $this->staff('ca');
        $finance = $this->staff('finance_officer');
        $service = app(PurchaseRequestService::class);

        $first = $service->send($ca, $this->sendInput($f));
        try {
            $service->reject($finance, $first, ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'reason' => 'shrug']);
            $this->fail('An unknown reason was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }
        $service->reject($finance, $first, ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'reason' => 'stock_elsewhere']);
        $this->assertSame(['rejected', 'stock_elsewhere'], [$this->request($first)->status, $this->request($first)->reject_reason]);
        // Decided once: a second decision is refused.
        $this->assertThrows(fn () => $service->withdraw($ca, $first, ['expected_branch_id' => $this->branch->id, 'lock_version' => 2]), ValidationException::class);

        $second = $service->send($ca, $this->sendInput($f));
        $otherCa = $this->staff('ca');
        $this->assertThrows(fn () => $service->withdraw($otherCa, $second, ['expected_branch_id' => $this->branch->id, 'lock_version' => 1]), AuthorizationException::class);
        $service->withdraw($ca, $second, ['expected_branch_id' => $this->branch->id, 'lock_version' => 1]);
        $this->assertSame('withdrawn', $this->request($second)->status);
    }

    public function test_sending_is_idempotent_and_checks_lines_location_and_permission(): void
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build();
        $ca = $this->staff('ca');
        $service = app(PurchaseRequestService::class);
        $input = $this->sendInput($f);
        $this->assertSame($service->send($ca, $input), $service->send($ca, $input));
        $this->assertSame(1, DB::table('inventory_purchase_requests')->count());
        $this->assertThrows(fn () => $service->send($ca, [...$input, 'lines' => [['sku_public_id' => $f['sku']->public_id, 'quantity' => '11']]]), ValidationException::class);

        foreach ([['lines' => []], ['lines' => [['sku_public_id' => $f['sku']->public_id, 'quantity' => '0']]], ['destination_location_public_id' => (string) Str::uuid()],
            ['lines' => [['sku_public_id' => $f['sku']->public_id, 'quantity' => '1'], ['sku_public_id' => $f['sku']->public_id, 'quantity' => '2']]]] as $bad) {
            $this->assertThrows(fn () => $service->send($ca, $this->sendInput($f, $bad)), ValidationException::class);
        }
        $this->assertSame(1, DB::table('inventory_purchase_requests')->count());

        $this->actingAs($this->staff('finance_officer'))->post(route('inventory.purchase-requests.store'), $this->sendInput($f))->assertForbidden();
    }

    public function test_conversion_needs_one_positive_line_drops_zero_lines_and_refuses_a_stale_page(): void
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build();
        $ca = $this->staff('ca');
        $supervisor = $this->staff('ca_supervisor');
        $service = app(PurchaseRequestService::class);
        $id = $service->send($ca, $this->sendInput($f));
        $line = DB::table('inventory_purchase_request_lines')->sole();
        $convert = fn (array $extra) => $service->convert($supervisor, $id, ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'supplier_public_id' => $f['supplier']->public_id,
            'lines' => [['line_id' => $line->id, 'quantity' => '4']], ...$extra]);

        $this->assertThrows(fn () => $convert(['lines' => [['line_id' => $line->id, 'quantity' => '0']]]), ValidationException::class);
        $this->assertThrows(fn () => $convert(['lock_version' => 2]), ValidationException::class);
        $this->assertThrows(fn () => $convert(['lines' => [['line_id' => $line->id + 999, 'quantity' => '4']]]), ValidationException::class);
        $this->assertSame('waiting', $this->request($id)->status);
        // A CA cannot convert at all.
        $this->assertThrows(fn () => $service->convert($this->staff('ca'), $id, ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'supplier_public_id' => $f['supplier']->public_id,
            'lines' => [['line_id' => $line->id, 'quantity' => '4']]]), AuthorizationException::class);

        $convert([]);
        $this->assertSame('converted', $this->request($id)->status);
        // Lines are frozen once converted.
        $this->expectException(QueryException::class);
        DB::table('inventory_purchase_request_lines')->where('id', $line->id)->update(['converted_quantity' => '9.000']);
    }

    public function test_reorder_suggestions_prefill_the_new_request_form(): void
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build();
        $ca = $this->staff('ca');
        $page = $this->actingAs($ca)->get(route('inventory.purchase-requests', ['location' => $f['location']->public_id, 'lines' => [['sku' => $f['sku']->public_id, 'quantity' => '12'], ['sku' => (string) Str::uuid(), 'quantity' => '3']]]))
            ->assertOk()->viewData('page')['props']['requests'];
        $this->assertSame($f['location']->public_id, $page['prefill']['location']['publicId']);
        $this->assertSame([[$f['sku']->public_id, '12']], collect($page['prefill']['lines'])->map(fn (array $l) => [$l['skuPublicId'], $l['quantity']])->all());
        $this->assertSame(['request' => true, 'review' => false], $page['can']);

        $finance = $this->staff('finance_officer');
        $page = $this->actingAs($finance)->get(route('inventory.purchase-requests'))->assertOk()->viewData('page')['props']['requests'];
        $this->assertSame([null, [], ['request' => false, 'review' => true]], [$page['prefill'], $page['locations'], $page['can']]);
    }

    public function test_requests_are_append_only(): void
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build();
        app(PurchaseRequestService::class)->send($this->staff('ca'), $this->sendInput($f));
        $this->expectException(QueryException::class);
        DB::table('inventory_purchase_requests')->delete();
    }
}

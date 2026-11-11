<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Organisation\Inventory\Services\ProcurementService;
use App\Domain\Organisation\Inventory\Services\SupplierInvoiceService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\VerifiedReceivingFixture;

/** STK-22 (approved by B.E.R.N.I.S.S for the owner, 2026-11-11): three-way match of a supplier invoice. */
final class SupplierInvoiceTest extends VisitTestCase
{
    private function as(User $actor): User
    {
        app(BranchAccessService::class)->select($actor, $this->branch);

        return $actor;
    }

    private function staff(string $role): User
    {
        return (new VerifiedReceivingFixture($this->branch))->actor($role);
    }

    /** The fixture's order (10 ordered at RM 1.25 estimate) with 2 received, optionally with an actual lot cost. */
    private function received(?int $costTotalSen = null): array
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build('10.000');
        $payload = $f['payload'];
        if ($costTotalSen !== null) {
            $payload['lines'][0]['cost_total_sen'] = $costTotalSen;
        }
        app(ProcurementService::class)->receive($this->as($f['receiver']), $f['order'], $payload);

        return $f;
    }

    private function invoice(array $f, array $extra = [], string $quantity = '2', string $total = '2.50'): array
    {
        $line = $f['order']->lines()->sole();

        return ['expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), 'purchase_order_public_id' => $f['order']->public_id,
            'invoice_number' => 'INV-'.Str::upper(Str::random(6)), 'invoice_date' => now()->setTimezone($this->branch->timezone)->toDateString(),
            'lines' => [['line_public_id' => $line->public_id, 'quantity' => $quantity, 'total' => $total]], ...$extra];
    }

    private function row(string $publicId): object
    {
        return DB::table('inventory_supplier_invoices')->where('public_id', $publicId)->sole();
    }

    public function test_the_two_permissions_go_to_the_approved_roles(): void
    {
        $holders = fn (string $p) => collect(PermissionCatalogue::roles())->filter(fn (array $perms) => in_array($p, $perms, true))->keys()->sort()->values()->all();
        $this->assertSame(['ca_supervisor', 'finance_officer'], $holders(SupplierInvoiceService::RECORD));
        $this->assertSame(['director', 'finance_officer'], $holders(SupplierInvoiceService::APPROVE));
    }

    public function test_an_invoice_that_matches_the_order_estimate_is_matched_with_nothing_more_to_do(): void
    {
        $f = $this->received();
        $supervisor = $this->staff('ca_supervisor');
        $id = app(SupplierInvoiceService::class)->record($this->as($supervisor), $this->invoice($f, ['stated_total' => '2.50']));
        $invoice = $this->row($id);
        $this->assertSame(['matched', 250, 250], [$invoice->status, (int) $invoice->lines_total_sen, (int) $invoice->stated_total_sen]);
        $line = DB::table('inventory_supplier_invoice_lines')->where('supplier_invoice_id', $invoice->id)->sole();
        $this->assertSame(['matches', 'estimate', 250, '2.000'], [$line->result, $line->price_basis, (int) $line->expected_total_sen, $line->invoiced_quantity]);
        $audit = DB::table('audit_logs')->where('event', 'inventory.supplier_invoice.recorded')->sole();
        $this->assertSame(['lines_differ', 'purchase_order_id', 'status', 'supplier_invoice_id'], collect(json_decode($audit->metadata, true))->keys()->sort()->values()->all());
        $this->assertStringNotContainsString($invoice->invoice_number, (string) $audit->metadata);
        // A matched invoice cannot be decided.
        $this->assertThrows(fn () => app(SupplierInvoiceService::class)->decide($this->as($f['finance']), $id, 'accepted', ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'reason' => 'rounding']), ValidationException::class);
    }

    public function test_the_actual_cost_recorded_at_receipt_wins_over_the_estimate(): void
    {
        $f = $this->received(costTotalSen: 300);
        $service = app(SupplierInvoiceService::class);
        $supervisor = $this->staff('ca_supervisor');
        $line = fn (string $id) => DB::table('inventory_supplier_invoice_lines')->where('supplier_invoice_id', $this->row($id)->id)->sole();
        $first = $service->record($this->as($supervisor), $this->invoice($f));
        $this->assertSame(['waiting', 'price_differs', 'receipt', 300], [$this->row($first)->status, $line($first)->result, $line($first)->price_basis, (int) $line($first)->expected_total_sen]);
        $service->decide($this->as($f['director']), $first, 'rejected', ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'reason' => 'wrong_price']);
        // The same number may be recorded again, corrected, once the first copy is rejected.
        $again = $service->record($this->as($supervisor), $this->invoice($f, ['invoice_number' => $this->row($first)->invoice_number], '2', '3.00'));
        $this->assertSame('matched', $this->row($again)->status);
    }

    public function test_a_mismatch_is_decided_by_a_finance_officer_or_director_who_did_not_record_it(): void
    {
        $f = $this->received();
        $service = app(SupplierInvoiceService::class);
        $finance = $this->staff('finance_officer');
        // Three units invoiced for the price of the two received: the quantity differs, the line total matches.
        $id = $service->record($this->as($finance), $this->invoice($f, [], '3', '2.50'));
        $this->assertSame('waiting', $this->row($id)->status);
        $this->assertSame('quantity_differs', DB::table('inventory_supplier_invoice_lines')->where('supplier_invoice_id', $this->row($id)->id)->value('result'));
        $cmd = ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'reason' => 'credit_note_expected'];
        $this->assertThrows(fn () => $service->decide($this->as($finance), $id, 'accepted', $cmd), AuthorizationException::class);
        $this->assertThrows(fn () => $service->decide($this->as($this->staff('ca_supervisor')), $id, 'accepted', $cmd), AuthorizationException::class);
        $this->assertThrows(fn () => $service->decide($this->as($f['director']), $id, 'accepted', [...$cmd, 'reason' => 'other']), ValidationException::class);
        $this->assertThrows(fn () => $service->decide($this->as($f['director']), $id, 'accepted', [...$cmd, 'reason' => 'wrong_price']), ValidationException::class);
        $this->assertThrows(fn () => $service->decide($this->as($f['director']), $id, 'accepted', [...$cmd, 'lock_version' => 2]), ValidationException::class);
        $service->decide($this->as($f['director']), $id, 'accepted', [...$cmd, 'note' => '  Supplier will credit one box  ']);
        $row = $this->row($id);
        $this->assertSame(['accepted', 'credit_note_expected', 'Supplier will credit one box', $f['director']->id, 2], [$row->status, $row->decision_reason, $row->decision_note, (int) $row->decided_by_user_id, (int) $row->lock_version]);
        $this->assertSame(['reason', 'supplier_invoice_id'], collect(json_decode(DB::table('audit_logs')->where('event', 'inventory.supplier_invoice.accepted')->sole()->metadata, true))->keys()->sort()->values()->all());
        // Decided once only.
        $this->assertThrows(fn () => $service->decide($this->as($f['director']), $id, 'rejected', [...$cmd, 'lock_version' => 2, 'reason' => 'wrong_price']), ValidationException::class);
    }

    public function test_recording_rules(): void
    {
        $f = $this->received();
        $service = app(SupplierInvoiceService::class);
        $supervisor = $this->as($this->staff('ca_supervisor'));
        $record = fn (array $input) => $service->record($this->as($supervisor), $input);
        $tomorrow = now()->setTimezone($this->branch->timezone)->addDay()->toDateString();
        foreach ([
            $this->invoice($f, ['stated_total' => '9.99']),
            $this->invoice($f, ['invoice_date' => $tomorrow]),
            $this->invoice($f, ['invoice_number' => '   ']),
            $this->invoice($f, ['lines' => []]),
            $this->invoice($f, [], '2', '2.505'),
            $this->invoice($f, [], '2', '-1'),
        ] as $bad) {
            $this->assertThrows(fn () => $record($bad), ValidationException::class);
        }
        // Retrying the same page records once; reusing it for a different invoice is refused.
        $input = $this->invoice($f);
        $id = $record($input);
        $this->assertSame($id, $record($input));
        $this->assertThrows(fn () => $record([...$input, 'invoice_number' => 'OTHER-1']), ValidationException::class);
        // One live invoice per order and per supplier number.
        $this->assertThrows(fn () => $record($this->invoice($f)), ValidationException::class);
        $this->assertSame(1, DB::table('inventory_supplier_invoices')->count());
        // An order with nothing received yet cannot be invoiced; a CA cannot record.
        $fresh = (new VerifiedReceivingFixture($this->branch))->build('5.000');
        $this->assertThrows(fn () => $record($this->invoice($fresh)), ValidationException::class);
        $this->assertThrows(fn () => $service->record($this->as($this->staff('ca')), $this->invoice($f)), AuthorizationException::class);
    }

    public function test_postgresql_keeps_invoices_and_their_decisions_honest(): void
    {
        $f = $this->received();
        $finance = $this->staff('finance_officer');
        $id = app(SupplierInvoiceService::class)->record($this->as($finance), $this->invoice($f, [], '2', '9.00'));
        $invoice = $this->row($id);
        $attempt = function (callable $write, string $message): void {
            DB::beginTransaction();
            try {
                $write();
                $this->fail('PostgreSQL accepted the write.');
            } catch (QueryException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            } finally {
                DB::rollBack();
            }
        };
        $decide = ['status' => 'accepted', 'decided_at' => now()->utc(), 'decision_reason' => 'rounding', 'lock_version' => 2];
        $attempt(fn () => DB::table('inventory_supplier_invoices')->where('id', $invoice->id)->update([...$decide, 'decided_by_user_id' => $finance->id]), 'supplier_invoices_shape');
        $attempt(fn () => DB::table('inventory_supplier_invoices')->where('id', $invoice->id)->update(['lines_total_sen' => 1, 'lock_version' => 2]), 'Only a waiting supplier invoice is decided');
        $attempt(fn () => DB::table('inventory_supplier_invoices')->where('id', $invoice->id)->update([...$decide, 'decided_by_user_id' => $f['director']->id, 'decision_reason' => 'wrong_price']), 'supplier_invoices_shape');
        $attempt(fn () => DB::table('inventory_supplier_invoice_lines')->where('supplier_invoice_id', $invoice->id)->update(['invoiced_total_sen' => 250]), 'frozen when recorded');
        $attempt(fn () => DB::table('inventory_supplier_invoices')->where('id', $invoice->id)->delete(), 'Supplier invoices are kept');
        $copy = (array) $invoice;
        unset($copy['id']);
        $attempt(fn () => DB::table('inventory_supplier_invoices')->insert([...$copy, 'public_id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(), 'invoice_number' => 'X', 'invoice_key' => 'X']), 'supplier_invoices_order_live');
    }

    public function test_the_page_shows_money_only_to_cost_holders(): void
    {
        $f = $this->received();
        $supervisor = $this->staff('ca_supervisor');
        $this->actingAs($this->as($supervisor))->post(route('inventory.supplier-invoices.store'), $this->invoice($f, [], '2', '1,000.00'))->assertRedirect(route('inventory.supplier-invoices'));
        $props = fn (User $u) => $this->actingAs($this->as($u))->get(route('inventory.supplier-invoices', ['tab' => 'waiting']))->assertOk()->viewData('page')['props']['invoices'];
        $mine = $props($supervisor);
        $this->assertFalse($mine['showAmounts']);
        $this->assertArrayNotHasKey('totalSen', $mine['invoices']['rows'][0]);
        $this->assertArrayNotHasKey('invoicedSen', $mine['invoices']['rows'][0]['lines'][0]);
        $this->assertTrue($mine['invoices']['rows'][0]['recordedByYou']);
        $finance = $props($f['finance']);
        $this->assertSame([true, 100000, 100000, 250, 'estimate'], [$finance['showAmounts'], $finance['invoices']['rows'][0]['totalSen'], $finance['invoices']['rows'][0]['lines'][0]['invoicedSen'],
            $finance['invoices']['rows'][0]['lines'][0]['expectedSen'], $finance['invoices']['rows'][0]['lines'][0]['priceBasis']]);
        $this->assertSame($supervisor->name, $finance['invoices']['rows'][0]['recordedBy']);
        // A CA sees the page from the Inventory task row but no invoices, and cannot post.
        $ca = $this->staff('ca');
        $this->assertSame([[], []], [$props($ca)['invoices']['rows'], $props($ca)['orders']]);
        $this->actingAs($this->as($ca))->post(route('inventory.supplier-invoices.store'), $this->invoice($f))->assertForbidden();
        // The recorder is refused on the page with the reason.
        $finance2 = $this->staff('finance_officer');
        $id = app(SupplierInvoiceService::class)->record($this->as($finance2), $this->invoice($this->received(), [], '1', '1.25'));
        $this->actingAs($this->as($finance2))->post(route('inventory.supplier-invoices.decide', [$id, 'accepted']), ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'reason' => 'rounding'])
            ->assertRedirect(route('inventory.supplier-invoices'))->assertSessionHasErrors('status');
    }
}

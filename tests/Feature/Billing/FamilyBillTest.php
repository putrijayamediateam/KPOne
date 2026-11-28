<?php

namespace Tests\Feature\Billing;

use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Family\PatientFamilyService;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\FamilyBillExclusion;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentAllocation;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Billing\Services\FinancialLedger;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/** BIL-39: the read-only family bill and the "Family bill" link on a member's Billing page. */
class FamilyBillTest extends BillingTestCase
{
    private ?ChargeDefinition $charge = null;

    private ?PriceBook $book = null;

    private int $version = 0;

    public function test_family_members_open_invoices_are_shown_oldest_first_with_one_total(): void
    {
        $doctor = $this->doctor();
        $ca = $this->actor('ca');
        [$first, $firstInvoice] = $this->invoiceFor($doctor, $ca, 4000);
        [$second, $secondInvoice] = $this->invoiceFor($doctor, $ca, 2500);
        [$alone] = $this->invoiceFor($doctor, $ca, 1000);
        $this->link($ca, $first, $second);
        $this->assertNotNull($first->refresh()->family_group_id);
        $this->assertSame($first->family_group_id, $second->refresh()->family_group_id);

        $this->selectBranch($ca);
        $this->get(route('billing.show', $second))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('billing.familyBillUrl', route('billing.family', $second, absolute: false)));
        $this->get(route('billing.show', $alone))->assertInertia(fn (Assert $page) => $page->where('billing.familyBillUrl', null));

        $this->get(route('billing.family', $second))->assertOk()->assertHeader('Cache-Control')->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Family')
            ->where('family.anchorVisitNumber', $second->visit_number)
            ->where('family.totalDueNowSen', 6500)
            ->where('family.members.0.visitNumber', $first->visit_number)
            ->where('family.members.0.invoice.publicId', $firstInvoice->public_id)
            ->where('family.members.0.invoice.state.due_now', 4000)
            ->where('family.members.1.visitNumber', $second->visit_number)
            ->where('family.members.1.invoice.number', $secondInvoice->invoice_number)
            ->where('family.members.1.invoice.state.due_now', 2500)
            ->has('family.members', 2)
            ->has('family.members.0.invoice.lines', 1)
            ->where('family.linesShown', true)
            ->where('family.can.pay', true));
        $this->get(route('billing.family', $alone))->assertNotFound();
    }

    public function test_a_completed_or_voided_member_drops_out_and_the_link_needs_another_open_member(): void
    {
        $doctor = $this->doctor();
        $ca = $this->actor('ca');
        [$first] = $this->invoiceFor($doctor, $ca, 4000);
        [$second, $secondInvoice] = $this->invoiceFor($doctor, $ca, 2500);
        $this->link($ca, $first, $second);
        // A voided invoice is no longer the visit's current invoice.
        $secondInvoice->forceFill(['status' => 'voided', 'current_visit_guard' => null, 'voided_at' => now()->utc(), 'voided_by_user_id' => $ca->id, 'void_reason' => 'Synthetic void', 'lock_version' => $secondInvoice->lock_version + 1])->save();

        $this->selectBranch($ca);
        $this->get(route('billing.family', $first))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('family.members', 1)->where('family.totalDueNowSen', 4000));
        $this->get(route('billing.show', $first))->assertInertia(fn (Assert $page) => $page->where('billing.familyBillUrl', null));
    }

    public function test_access_follows_the_billing_page_and_another_branch_is_not_found(): void
    {
        $doctor = $this->doctor();
        $ca = $this->actor('ca');
        [$first] = $this->invoiceFor($doctor, $ca, 4000);
        [$second] = $this->invoiceFor($doctor, $ca, 2500);
        $this->link($ca, $first, $second);

        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->get(route('billing.family', $first))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('family.members', 2)->where('family.linesShown', true));

        $this->selectBranch($doctor);
        $this->get(route('billing.family', $first))->assertForbidden();

        $elsewhere = Branch::query()->where('organisation_id', $this->organisation->id)->whereKeyNot($this->branch->id)->firstOrFail();
        $other = $this->actor('ca', $elsewhere);
        $this->selectBranch($other, $elsewhere);
        $this->get(route('billing.family', $first))->assertNotFound();
    }

    public function test_a_family_payment_pays_oldest_first_one_receipt_each_and_a_retry_returns_the_same_receipts(): void
    {
        $doctor = $this->doctor();
        $ca = $this->actor('ca');
        [$first, $firstInvoice] = $this->invoiceFor($doctor, $ca, 4000);
        [$second, $secondInvoice] = $this->invoiceFor($doctor, $ca, 2500);
        $this->link($ca, $first, $second);
        $this->cash($first);
        $input = $this->payInput([$firstInvoice, $secondInvoice], 6500, '5000');

        $this->selectBranch($ca);
        $this->post(route('billing.family.payment', $second), $input)->assertRedirect(route('billing.family', $second))->assertSessionHasNoErrors();
        $receipts = Payment::query()->orderBy('id')->get();
        $this->assertCount(2, $receipts);
        $this->assertSame([4000, 1000], $receipts->pluck('amount_sen')->map(fn ($v): int => (int) $v)->all());
        $this->assertSame(1, $receipts->pluck('family_payment_id')->unique()->count());
        $this->assertNotNull($receipts[0]->family_payment_id);
        $this->assertSame(1, $receipts->pluck('family_payload_hash')->unique()->count());
        $this->assertSame([$firstInvoice->id, $secondInvoice->id], PaymentAllocation::query()->orderBy('id')->pluck('invoice_id')->map(fn ($v): int => (int) $v)->all());
        $this->assertSame(0, app(FinancialLedger::class)->state($firstInvoice->refresh())['due_now']);
        $this->assertSame(1500, app(FinancialLedger::class)->state($secondInvoice->refresh())['due_now']);
        $audit = DB::table('audit_logs')->where('event', 'billing.family_payment_recorded')->sole();
        $this->assertStringNotContainsString('Synthetic Visit Patient', (string) $audit->metadata);

        // The same press again: the same two receipts, although the family now owes less.
        $this->post(route('billing.family.payment', $second), $input)->assertSessionHasNoErrors();
        $this->assertSame(2, Payment::query()->count());
        // The same key with a changed amount is refused and records nothing.
        $this->post(route('billing.family.payment', $second), [...$input, 'amount_sen' => '6000'])->assertSessionHasErrors('payment');
        $this->assertSame(2, Payment::query()->count());

        // The rest of the family total, as a new payment: only the second invoice is still owed.
        $this->post(route('billing.family.payment', $second), $this->payInput([$firstInvoice->refresh(), $secondInvoice->refresh()], 1500, '1500'))->assertSessionHasNoErrors();
        $this->assertSame(3, Payment::query()->count());
        $this->assertSame(0, app(FinancialLedger::class)->state($secondInvoice->refresh())['due_now']);
    }

    public function test_a_stale_version_total_or_amount_refuses_the_whole_family_payment(): void
    {
        $doctor = $this->doctor();
        $ca = $this->actor('ca');
        [$first, $firstInvoice] = $this->invoiceFor($doctor, $ca, 4000);
        [$second, $secondInvoice] = $this->invoiceFor($doctor, $ca, 2500);
        $this->link($ca, $first, $second);
        $this->cash($first);
        $this->selectBranch($ca);

        $stale = $this->payInput([$firstInvoice, $secondInvoice], 6500, '6500');
        $stale['members'][1]['lock_version'] = $secondInvoice->lock_version + 5;
        $this->post(route('billing.family.payment', $first), $stale)->assertRedirect(route('billing.family', $first))->assertSessionHasErrors('family');
        $this->post(route('billing.family.payment', $first), $this->payInput([$firstInvoice, $secondInvoice], 6000, '1000'))->assertSessionHasErrors('family');
        $this->post(route('billing.family.payment', $first), $this->payInput([$firstInvoice], 4000, '1000'))->assertSessionHasErrors('family');
        $this->post(route('billing.family.payment', $first), $this->payInput([$firstInvoice, $secondInvoice], 6500, '6600'))->assertSessionHasErrors('amount_sen');
        $this->assertSame(0, Payment::query()->count());

        // A payment needs payments.add.branch; a Panel Officer may view but not pay.
        $panel = $this->actor('panel_officer');
        $this->selectBranch($panel);
        $this->post(route('billing.family.payment', $first), $this->payInput([$firstInvoice, $secondInvoice], 6500, '6500'))->assertForbidden();
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_remove_and_put_back_change_only_the_family_bill(): void
    {
        $doctor = $this->doctor();
        $ca = $this->actor('ca');
        [$first, $firstInvoice] = $this->invoiceFor($doctor, $ca, 4000);
        [$second, $secondInvoice] = $this->invoiceFor($doctor, $ca, 2500);
        $this->link($ca, $first, $second);
        $before = $secondInvoice->refresh()->lock_version;

        $this->selectBranch($ca);
        $input = ['expected_branch_id' => $this->branch->id, 'invoice_public_id' => $secondInvoice->public_id];
        $this->post(route('billing.family.remove', $first), $input)->assertRedirect(route('billing.family', $first))->assertSessionHasNoErrors();
        $this->post(route('billing.family.remove', $first), $input)->assertSessionHasNoErrors(); // a repeat records nothing
        $this->assertSame(1, FamilyBillExclusion::query()->count());
        $this->assertSame($before, $secondInvoice->refresh()->lock_version);
        $this->get(route('billing.family', $first))->assertInertia(fn (Assert $page) => $page
            ->has('family.members', 1)->where('family.totalDueNowSen', 4000)
            ->has('family.removed', 1)->where('family.removed.0.invoicePublicId', $secondInvoice->public_id)->where('family.can.remove', true));
        $this->get(route('billing.show', $first))->assertInertia(fn (Assert $page) => $page->where('billing.familyBillUrl', null));

        $this->post(route('billing.family.restore', $first), $input)->assertSessionHasNoErrors();
        $this->post(route('billing.family.restore', $first), $input)->assertSessionHasNoErrors();
        $this->get(route('billing.family', $first))->assertInertia(fn (Assert $page) => $page->has('family.members', 2)->has('family.removed', 0));
        $row = FamilyBillExclusion::query()->sole();
        $this->assertNotNull($row->restored_at);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'billing.family_bill_member_removed')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'billing.family_bill_member_restored')->count());

        // Remove needs billing.build.branch: a Finance Officer may view the family bill but not change it.
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->post(route('billing.family.remove', $first), $input)->assertForbidden();

        if (DB::getDriverName() === 'pgsql') {
            $this->assertRefusedByDatabase(fn () => DB::table('family_bill_exclusions')->where('id', $row->id)->delete());
            $this->assertRefusedByDatabase(fn () => DB::table('family_bill_exclusions')->where('id', $row->id)->update(['restored_at' => now()->utc()]));
        }
    }

    private function assertRefusedByDatabase(\Closure $write): void
    {
        DB::beginTransaction();
        try {
            $write();
            $this->fail('The database accepted a write it must refuse.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        } finally {
            DB::rollBack();
        }
    }

    private function cash(Visit $visit): void
    {
        $method = new PaymentMethod;
        $method->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash'])->save();
    }

    /**
     * @param  list<Invoice>  $invoices
     * @return array<string, mixed>
     */
    private function payInput(array $invoices, int $totalSen, string $amountSen): array
    {
        return ['expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), 'method' => 'cash', 'reference' => null,
            'amount_sen' => $amountSen, 'expected_total_sen' => $totalSen,
            'members' => array_map(fn (Invoice $invoice): array => ['invoice_public_id' => $invoice->public_id, 'lock_version' => $invoice->refresh()->lock_version], $invoices)];
    }

    /** @return array{Visit, Invoice} */
    private function invoiceFor(User $doctor, User $ca, int $price): array
    {
        [, , $visit, $queue] = $this->servingFixture($doctor, $ca);
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        app(CompleteConsultationService::class)->complete($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version, 'encounter_lock_version' => $encounter->lock_version, 'lock_version' => null, 'service_deliveries' => []]);
        if (! $this->charge) {
            $this->charge = new ChargeDefinition;
            $this->charge->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $visit->organisation_id, 'code' => 'BIL39-CONSULT', 'type' => 'consultation', 'display_name' => 'Synthetic consultation', 'unit' => 'consultation', 'source_key' => 'consultation'])->save();
            $this->book = new PriceBook;
            $this->book->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $visit->organisation_id, 'scope_key' => 'organisation', 'name' => 'Synthetic prices', 'currency' => 'MYR'])->save();
        }
        $this->version++;
        $entry = new PriceEntry;
        $entry->forceFill(['organisation_id' => $visit->organisation_id, 'price_book_id' => $this->book->id, 'charge_definition_id' => $this->charge->id, 'unit_price_sen' => $price, 'version' => $this->version, 'effective_at' => now()->subMinutes(60 - $this->version), 'published_by_user_id' => $ca->id])->save();
        $this->selectBranch($ca, $visit->branch);
        $builder = app(BillingBuilderService::class);
        $invoice = $builder->build($ca, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => null]);
        $invoice = $builder->finalize($ca, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version]);

        return [$visit->refresh(), $invoice];
    }

    /** FAM-1's own service links the two patients and stamps their open visits of today; Billing never writes the key. */
    private function link(User $ca, Visit $one, Visit $two): void
    {
        $this->selectBranch($ca);
        app(PatientFamilyService::class)->execute($ca, $one->patient()->firstOrFail(), [
            'operation' => 'add', 'relative_patient_number' => $two->patient()->firstOrFail()->patient_number,
            'relative_role' => 'child', 'patient_role' => 'parent', 'expected_family_version' => 0,
            'idempotency_key' => (string) Str::uuid(), 'expected_branch_id' => $this->branch->id,
        ]);
    }
}

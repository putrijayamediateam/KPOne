<?php

namespace Tests\Feature\Billing;

use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Family\PatientFamilyService;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
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

<?php

namespace Tests\Feature\Billing;

use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Models\PatientIdentifier;
use App\Domain\Visit\Billing\Models\IssuedBillingDocument;
use App\Domain\Visit\Billing\Models\IssuedBillingDocumentPrint;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\IssuedBillingDocumentService;
use App\Domain\Visit\Models\Visit;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * BIL-9 (owner decisions 2026-11-06): issued invoices and receipts follow the clinic's paper. The patient is named by
 * IC/passport and phone, the invoice carries seven category totals, and a version issued in the earlier layout keeps
 * that layout: the layout change alone never makes a REVISED version.
 */
class InvoiceReceiptLayoutTest extends BillingTestCase
{
    public function test_a_new_invoice_is_issued_in_the_clinic_layout_with_identity_categories_and_totals(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture(4000);
        $this->identity($visit, 'nric', '900101145678', '+60123456789');
        $this->selectBranch($ca);
        $this->post(route('billing.issue', [$visit, $invoice]), $this->input($visit))->assertRedirect();
        $print = IssuedBillingDocumentPrint::query()->sole();

        $this->get(route('billing.issued.show', [$visit, $print->public_id]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Print')->where('layout', 'bil9')->where('issued.label', 'original')
            ->where('billing.patient.identifier', '900101-14-5678')->where('billing.patient.phone', '012-345 6789')
            ->where('billing.invoice.lines.0.category', 'Consultation')
            ->where('billing.invoice.categories', [['name' => 'Service', 'totalSen' => 0], ['name' => 'Procedure', 'totalSen' => 0],
                ['name' => 'Investigation', 'totalSen' => 0], ['name' => 'Consultation', 'totalSen' => 4000], ['name' => 'Medicine', 'totalSen' => 0],
                ['name' => 'Document', 'totalSen' => 0], ['name' => 'Package', 'totalSen' => 0]])
            ->where('billing.invoice.adjustment', ['subtotalSen' => 4000, 'adjustmentSen' => 0])
            ->where('billing.invoice.state.due_now', 4000)->where('letterhead.details.phone_secondary', null)
            ->missing('billing.can')->missing('billing.payments'));

        // The seven rows always add up to the item lines.
        $content = json_decode(Crypt::decryptString(IssuedBillingDocument::query()->sole()->encrypted_snapshot), true);
        $this->assertSame(array_sum(array_column($content['billing']['invoice']['lines'], 'totalSen')), array_sum(array_column($content['billing']['invoice']['categories'], 'totalSen')));
        // Identity never reaches audit metadata.
        $this->assertStringNotContainsString('900101', (string) DB::table('audit_logs')->pluck('metadata')->implode(' '));
        $this->assertStringNotContainsString('123456789', (string) DB::table('audit_logs')->pluck('metadata')->implode(' '));
    }

    public function test_missing_ic_and_phone_print_nothing_and_a_receipt_carries_its_invoice_lines_and_number(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture(4000);
        Patient::query()->whereKey($visit->patient_id)->update(['mobile_phone' => null]);
        PatientIdentifier::query()->where('patient_id', $visit->patient_id)->update(['retired_at' => now()]);
        $this->selectBranch($ca);
        (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash', 'classification' => 'cash'])->save();
        $this->post(route('billing.payment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => '1500', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $receipt = Payment::query()->sole();
        $this->post(route('billing.receipt.issue', [$visit, $invoice, $receipt]), $this->input($visit))->assertRedirect();
        $print = IssuedBillingDocumentPrint::query()->sole();

        $this->get(route('billing.issued.show', [$visit, $print->public_id]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('layout', 'bil9')->where('billing.patient.identifier', null)->where('billing.patient.phone', null)
            ->where('billing.invoice.number', $invoice->invoice_number)->has('billing.invoice.lines', 1)
            ->where('receipt.amountSen', 1500)->where('receipt.number', $receipt->receipt_number)
            // A receipt prints no categories and carries none, so a category rename never revises it.
            ->missing('billing.invoice.categories')->missing('billing.invoice.lines.0.category'));
    }

    public function test_a_version_issued_in_the_earlier_layout_reprints_as_copy_in_that_layout_until_its_content_changes(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture(4000);
        $this->selectBranch($ca);
        // A version 1 issued before BIL-9, with exactly the earlier (D03) content.
        $method = new \ReflectionMethod(IssuedBillingDocumentService::class, 'legacyContent');
        [$legacy] = $method->invoke(app(IssuedBillingDocumentService::class), $ca, $visit, $invoice->refresh(), null, $visit->branch);
        $encrypted = Crypt::encryptString(json_encode($legacy, JSON_THROW_ON_ERROR));
        $old = new IssuedBillingDocument;
        $old->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'invoice_id' => $invoice->id,
            'payment_id' => null, 'document_type' => 'invoice', 'version' => 1, 'content_sha256' => hash('sha256', json_encode($legacy, JSON_THROW_ON_ERROR)),
            'encrypted_snapshot' => $encrypted, 'ciphertext_sha256' => hash('sha256', $encrypted), 'issued_by_user_id' => $ca->id, 'issued_at' => now()->subDay()->utc()])->save();
        (new IssuedBillingDocumentPrint)->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $visit->organisation_id, 'issued_billing_document_id' => $old->id,
            'label' => 'original', 'printed_by_user_id' => $ca->id, 'printed_at' => now()->subDay()->utc(), 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('0', 64)])->save();

        // Nothing it printed has changed: COPY of version 1, in the layout it was issued in.
        $this->post(route('billing.issue', [$visit, $invoice]), $this->input($visit))->assertRedirect();
        $copy = IssuedBillingDocumentPrint::query()->latest('id')->first();
        $this->assertSame(['copy', $old->id], [$copy->label, $copy->issued_billing_document_id]);
        $this->assertSame(1, IssuedBillingDocument::query()->count());
        $this->get(route('billing.issued.show', [$visit, $copy->public_id]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->missing('layout')->missing('billing.patient.identifier')->where('issued.label', 'copy')->where('issued.version', 1));

        // The old paper never printed IC or phone: changing them alone still reprints version 1 as COPY.
        $this->identity($visit, 'passport', 'SYNP1234567', '+60198765432');
        $this->post(route('billing.issue', [$visit, $invoice]), $this->input($visit))->assertRedirect();
        $this->assertSame([1, 'copy'], [IssuedBillingDocument::query()->count(), IssuedBillingDocumentPrint::query()->latest('id')->first()->label]);

        // A payment changes what it printed: a REVISED version 2, now in the clinic layout.
        (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash', 'classification' => 'cash'])->save();
        $this->post(route('billing.payment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => '4000', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post(route('billing.issue', [$visit, $invoice]), $this->input($visit))->assertRedirect();
        $revised = IssuedBillingDocumentPrint::query()->latest('id')->first();
        $this->assertSame('revised', $revised->label);
        $this->get(route('billing.issued.show', [$visit, $revised->public_id]))->assertInertia(fn (Assert $page) => $page
            ->where('layout', 'bil9')->where('issued.version', 2)->where('billing.invoice.state.due_now', 0));
        // And the next print of the same content is a COPY of version 2.
        $this->post(route('billing.issue', [$visit, $invoice]), $this->input($visit))->assertRedirect();
        $this->assertSame(['copy', 2], [IssuedBillingDocumentPrint::query()->latest('id')->first()->label, IssuedBillingDocument::query()->max('version')]);
    }

    public function test_a_printer_who_cannot_see_patient_identity_cannot_issue(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture(4000);
        Role::findByName('ca', 'web')->revokePermissionTo('patients.view.organisation');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->selectBranch($ca->fresh());
        $this->post(route('billing.issue', [$visit, $invoice]), $this->input($visit))->assertSessionHasErrors('document');
        $this->assertSame(0, IssuedBillingDocument::query()->count());
    }

    public function test_printed_phones_read_in_the_clinic_format_while_the_earlier_layout_keeps_the_stored_value(): void
    {
        // Owner decision 2026-11-06 ("go with Daniel's phone format"): display only, the stored value never changes.
        [, $ca, $visit, $invoice] = $this->finalizedFixture(4000);
        DB::table('branch_business_profiles')->insert(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'clinic_name' => 'Synthetic Clinic', 'address_line_1' => '1 Jalan Sintetik', 'postcode' => '43000', 'city' => 'Kajang', 'state' => 'Selangor', 'country' => 'Malaysia',
            'phone' => '+60321234567', 'email' => 'clinic@bil9.test', 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->identity($visit, 'nric', '900101145678', '60123456789');
        $this->selectBranch($ca);
        $this->post(route('billing.issue', [$visit, $invoice]), $this->input($visit))->assertRedirect();
        $this->get(route('billing.issued.show', [$visit, IssuedBillingDocumentPrint::query()->sole()->public_id]))->assertInertia(fn (Assert $page) => $page
            ->where('letterhead.details.phone', '03-2123 4567')->where('billing.patient.phone', '012-345 6789'));
        [$legacy] = (new \ReflectionMethod(IssuedBillingDocumentService::class, 'legacyContent'))->invoke(app(IssuedBillingDocumentService::class), $ca, $visit, $invoice->refresh(), null, $visit->branch);
        $this->assertSame('+60321234567', $legacy['letterhead']['details']['phone']);
        $this->assertSame('+60321234567', DB::table('branch_business_profiles')->where('branch_id', $visit->branch_id)->value('phone'));
    }

    public function test_the_earlier_layout_content_keeps_exactly_the_shape_issued_before_bil9(): void
    {
        // Hand-written from BIL-5 (D03), so the legacy match cannot drift with the code it checks.
        [, $ca, $visit, $invoice] = $this->finalizedFixture(4000);
        $this->selectBranch($ca);
        [$legacy] = (new \ReflectionMethod(IssuedBillingDocumentService::class, 'legacyContent'))->invoke(app(IssuedBillingDocumentService::class), $ca, $visit, $invoice->refresh(), null, $visit->branch);
        $this->assertSame(['billing', 'receipt', 'billTo', 'letterhead'], array_keys($legacy));
        $this->assertSame(['patient', 'visit', 'invoice'], array_keys($legacy['billing']));
        $this->assertSame(['name', 'patientNumber'], array_keys($legacy['billing']['patient']));
        $this->assertSame(['visitNumber'], array_keys($legacy['billing']['visit']));
        $this->assertSame(['number', 'finalizedAt', 'status', 'lines', 'adjustment', 'state'], array_keys($legacy['billing']['invoice']));
        $this->assertSame(['name', 'quantity', 'unit', 'unitPriceSen', 'totalSen'], array_keys($legacy['billing']['invoice']['lines'][0]));
        $this->assertSame(['subtotalSen', 'adjustmentSen'], array_keys($legacy['billing']['invoice']['adjustment']));
        $this->assertSame(['total', 'self_pay', 'panel', 'deferred', 'due_now'], array_keys($legacy['billing']['invoice']['state']));
        $this->assertSame(['details', 'logo', 'footerText'], array_keys($legacy['letterhead']));
        $this->assertSame([], array_diff(array_keys($legacy['letterhead']['details']), ['clinic_name', 'address_line_1', 'address_line_2', 'postcode', 'city', 'state', 'country', 'email', 'phone']));
    }

    public function test_a_new_layout_copy_is_opened_or_reprinted_only_by_someone_who_may_see_patient_identity(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture(4000);
        $this->selectBranch($ca);
        $this->post(route('billing.issue', [$visit, $invoice]), $this->input($visit))->assertRedirect();
        $print = IssuedBillingDocumentPrint::query()->sole();
        Role::findByName('ca', 'web')->revokePermissionTo('patients.view.organisation');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->selectBranch($ca->fresh());
        $this->get(route('billing.issued.show', [$visit, $print->public_id]))->assertRedirect();
        $this->post(route('billing.issued.reprint', [$visit, IssuedBillingDocument::query()->sole()->public_id]), $this->input($visit))->assertSessionHasErrors('document');
        $this->assertSame(1, IssuedBillingDocumentPrint::query()->count());
    }

    public function test_service_lines_are_grouped_by_their_clinical_service_category(): void
    {
        $this->assertSame('Consultation', IssuedBillingDocumentService::category('consultation', 'Procedure'));
        $this->assertSame('Medicine', IssuedBillingDocumentService::category('medicine', null));
        $this->assertSame('Procedure', IssuedBillingDocumentService::category('service', ' Procedures '));
        $this->assertSame('Investigation', IssuedBillingDocumentService::category('service', 'investigation'));
        $this->assertSame('Service', IssuedBillingDocumentService::category('service', 'Wound care'));
        $this->assertSame('Service', IssuedBillingDocumentService::category('service', null));
        $this->assertSame(['Service', 'Procedure', 'Investigation', 'Consultation', 'Medicine', 'Document', 'Package'], IssuedBillingDocumentService::CATEGORIES);
    }

    private function identity(Visit $visit, string $type, string $value, string $phone): void
    {
        Patient::query()->whereKey($visit->patient_id)->update(['mobile_phone' => $phone]);
        PatientIdentifier::query()->where('patient_id', $visit->patient_id)->update(['retired_at' => now()->subMinute()]);
        $identifier = new PatientIdentifier;
        $identifier->forceFill(['organisation_id' => $visit->organisation_id, 'patient_id' => $visit->patient_id, 'identifier_type' => $type, 'normalized_value' => $value,
            'issuing_country_code' => 'MY'])->save();
    }

    /** @return array<string, mixed> */
    private function input(Visit $visit): array
    {
        return ['expected_branch_id' => $visit->branch_id, 'idempotency_key' => (string) Str::uuid(), 'return_to' => 'billing'];
    }
}

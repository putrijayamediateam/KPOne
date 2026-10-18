<?php

namespace Tests\Feature\Billing;

use App\Domain\Organisation\BusinessDetails\BranchBusinessProfile;
use App\Domain\Organisation\BusinessDetails\BranchDocumentFooter;
use App\Domain\Organisation\BusinessDetails\BusinessDetailAsset;
use App\Domain\Organisation\BusinessDetails\BusinessDetailsService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\PaymentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

class DocumentLetterheadTest extends BillingTestCase
{
    public function test_print_uses_branch_letterhead_and_document_logo_without_settings_access_or_mutation(): void
    {
        Storage::fake('local');
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $editor = $this->actor('director');
        $this->selectBranch($editor);
        app(BusinessDetailsService::class)->save($editor, [
            'expected_branch_id' => $this->branch->id, 'organisation_lock_version' => 0, 'branch_lock_version' => 0,
            'idempotency_key' => (string) Str::uuid(), 'legal_name' => 'Synthetic Group', 'clinic_name' => 'Synthetic Document Clinic',
            'address_line_1' => '42 Synthetic Street', 'postcode' => '50000', 'city' => 'Synthetic City', 'state' => 'Synthetic State', 'country' => 'MY', 'email' => 'clinic@documents.test',
        ], UploadedFile::fake()->image('synthetic-logo.png', 160, 90));
        BranchDocumentFooter::query()->create(['organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id, 'text' => "Synthetic footer\nA & B", 'lock_version' => 1]);
        $this->selectBranch($ca);
        $this->assertFalse($ca->can('catalogue.settings.manage.organisation'));
        $before = [$invoice->fresh()->attributesToArray(), $visit->fresh()->attributesToArray(), DB::table('audit_logs')->count()];
        $response = $this->get(route('billing.print', [$visit, $invoice]));
        $response->assertOk()->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private')->assertInertia(fn (Assert $p) => $p
            ->component('Billing/Print')->where('letterhead.details.clinic_name', 'Synthetic Document Clinic')
            ->where('letterhead.details.address_line_1', '42 Synthetic Street')->where('letterhead.footerText', "Synthetic footer\nA & B")
            ->where('billing.invoice.state.total', 4000)->missing('letterhead.path')->missing('billing.clinicalNote'));
        $asset = BusinessDetailAsset::query()->sole();
        $logoUrl = route('billing.letterhead-logo', [$visit, $invoice, $asset->public_id]);
        $this->get($logoUrl)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($before, [$invoice->fresh()->attributesToArray(), $visit->fresh()->attributesToArray(), DB::table('audit_logs')->count()]);
        $this->get(route('business-details.logo', ['branch' => $this->branch->id, 'asset' => $asset->public_id]))->assertForbidden();
        $this->get(route('billing.letterhead-logo', [$visit, $invoice, (string) Str::uuid()]))->assertNotFound();
        foreach (['director', 'administrator', 'finance_officer', 'panel_officer', 'resident_doctor', 'technical_admin'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->get(route('billing.print', [$visit, $invoice]))->assertForbidden();
            $this->get($logoUrl)->assertForbidden();
        }
    }

    public function test_missing_configuration_omits_logo_and_never_borrows_another_branch_details(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $other = Branch::query()->where('id', '!=', $this->branch->id)->firstOrFail();
        BranchBusinessProfile::query()->create(['organisation_id' => $this->organisation->id, 'branch_id' => $other->id, 'clinic_name' => 'OTHER BRANCH PRIVATE', 'address_line_1' => 'OTHER ADDRESS', 'postcode' => '90000', 'city' => 'Other City', 'state' => 'Other State', 'country' => 'MY', 'lock_version' => 1]);
        $this->get(route('billing.print', [$visit, $invoice]))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('letterhead.details.clinic_name', $this->branch->name)->where('letterhead.details.address_line_1', '')
            ->where('letterhead.details.phone', null)->where('letterhead.logo', null)->where('letterhead.footerText', null));
        $this->selectBranch($this->actor('ca', $other), $other);
        $this->get(route('billing.print', [$visit, $invoice]))->assertNotFound();
    }

    public function test_receipt_remains_actual_received_amount_and_current_letterhead_is_live(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash', 'requires_reference' => false, 'is_active' => true])->save();
        $payment = app(PaymentService::class)->add($ca, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 1000, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()]);
        $url = route('billing.receipt', [$visit, $invoice, $payment]);
        $before = [$invoice->fresh()->attributesToArray(), $payment->fresh()->attributesToArray(), DB::table('payment_allocations')->get()->toJson()];
        $this->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p->where('receipt.amountSen', 1000)->where('letterhead.footerText', null));
        BranchDocumentFooter::query()->create(['organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id, 'text' => 'Current synthetic footer', 'lock_version' => 1]);
        $this->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p->where('receipt.amountSen', 1000)->where('letterhead.footerText', 'Current synthetic footer'));
        $this->assertSame($before, [$invoice->fresh()->attributesToArray(), $payment->fresh()->attributesToArray(), DB::table('payment_allocations')->get()->toJson()]);
    }
}

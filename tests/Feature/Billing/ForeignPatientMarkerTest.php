<?php

namespace Tests\Feature\Billing;

use App\Domain\Patient\Services\ForeignPatientRule;
use App\Domain\Visit\Billing\Services\FinancialLedger;
use App\Domain\Visit\Models\Visit;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SyntheticVisitCompletion;

/**
 * BIL-29 (owner decision 12): foreign (passport) patients are marked on the bill with "SST: to be confirmed". The mark
 * is a yes or no only, no identifier value or country reaches billing, and no money changes.
 */
class ForeignPatientMarkerTest extends BillingTestCase
{
    private function identifier(Visit $visit, string $type, string $country, string $value, bool $retired = false): void
    {
        DB::table('patient_identifiers')->insert([
            'organisation_id' => $visit->organisation_id, 'patient_id' => $visit->patient_id,
            'identifier_type' => $type, 'issuing_country_code' => $country, 'normalized_value' => $value,
            'retired_at' => $retired ? now() : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function clearIdentifiers(Visit $visit): void
    {
        DB::table('patient_identifiers')->where('patient_id', $visit->patient_id)->delete();
    }

    private function foreign(Visit $visit): bool
    {
        return app(ForeignPatientRule::class)->isForeign((int) $visit->organisation_id, (int) $visit->patient_id);
    }

    public function test_the_rule_counts_only_a_current_foreign_passport_without_a_current_malaysian_ic(): void
    {
        [, , $visit] = $this->finalizedFixture();

        $this->clearIdentifiers($visit);
        $this->assertFalse($this->foreign($visit), 'no identifiers');

        $this->identifier($visit, 'nric', 'MY', '900101015551');
        $this->assertFalse($this->foreign($visit), 'IC only');

        $this->identifier($visit, 'passport', 'TH', 'AA1234567');
        $this->assertFalse($this->foreign($visit), 'IC and a foreign passport');

        $this->clearIdentifiers($visit);
        $this->identifier($visit, 'passport', 'MY', 'A12345678');
        $this->assertFalse($this->foreign($visit), 'Malaysian passport');

        $this->clearIdentifiers($visit);
        $this->identifier($visit, 'passport', 'TH', 'AA1234568');
        $this->assertTrue($this->foreign($visit), 'foreign passport');

        $this->identifier($visit, 'nric', 'MY', '900101015552', retired: true);
        $this->assertTrue($this->foreign($visit), 'retired IC and a current foreign passport');

        $this->clearIdentifiers($visit);
        $this->identifier($visit, 'passport', 'TH', 'AA1234569', retired: true);
        $this->assertFalse($this->foreign($visit), 'retired foreign passport only');
    }

    public function test_billing_pages_carry_only_the_flag_and_the_money_is_unchanged(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $before = app(FinancialLedger::class)->state($invoice);
        $this->clearIdentifiers($visit);
        $this->identifier($visit, 'passport', 'TH', 'AA7654321');

        foreach ([route('billing.show', $visit), route('billing.payments', $visit)] as $url) {
            $response = $this->actingAs($ca)->get($url)->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('billing.patient.foreign', true)
                    ->where('billing.invoice.state', $before));
            $response->assertDontSee('AA7654321')->assertDontSee('"TH"', false);
        }

        SyntheticVisitCompletion::complete($visit);
        $response = $this->actingAs($ca)->get(route('visits.invoice', $visit))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('invoice.patient.foreign', true)
                ->where('invoice.financial.state', $before));
        $response->assertDontSee('AA7654321');
        $this->assertSame($before, app(FinancialLedger::class)->state($invoice->refresh()));
    }

    public function test_a_malaysian_patient_is_not_marked(): void
    {
        [, $ca, $visit] = $this->finalizedFixture();
        $this->clearIdentifiers($visit);
        $this->identifier($visit, 'nric', 'MY', '900101015553');

        $this->actingAs($ca)->get(route('billing.show', $visit))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('billing.patient.foreign', false));
    }

    public function test_the_issued_invoice_print_does_not_carry_the_flag(): void
    {
        [, , $visit, $invoice] = $this->finalizedFixture();
        $this->clearIdentifiers($visit);
        $this->identifier($visit, 'passport', 'TH', 'AA1112223');

        // Owner choice pending (BIL-29-DESIGN.md section 4); until then the print content is unchanged.
        $this->issuedInvoicePage($visit, $invoice)->assertOk()
            ->assertDontSee('Foreign patient')->assertDontSee('SST');
    }
}

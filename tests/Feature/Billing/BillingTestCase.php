<?php

namespace Tests\Feature\Billing;

use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use Illuminate\Support\Str;
use Tests\Feature\Clinical\ClinicalTestCase;

abstract class BillingTestCase extends ClinicalTestCase
{
    protected function billingFixture(int $price = 4000): array
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        app(CompleteConsultationService::class)->complete($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version, 'encounter_lock_version' => $encounter->lock_version, 'lock_version' => null, 'service_deliveries' => []]);
        $charge = new ChargeDefinition;
        $charge->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $visit->organisation_id, 'code' => 'UAT-CONSULT', 'type' => 'consultation', 'display_name' => 'Synthetic consultation', 'unit' => 'consultation', 'source_key' => 'consultation'])->save();
        $book = new PriceBook;
        $book->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $visit->organisation_id, 'scope_key' => 'organisation', 'name' => 'Synthetic prices', 'currency' => 'MYR'])->save();
        $entry = new PriceEntry;
        $entry->forceFill(['organisation_id' => $visit->organisation_id, 'price_book_id' => $book->id, 'charge_definition_id' => $charge->id, 'unit_price_sen' => $price, 'version' => 1, 'effective_at' => now()->subMinute(), 'published_by_user_id' => $ca->id])->save();
        $this->selectBranch($ca, $visit->branch);

        return [$doctor, $ca, $visit, $queue, $charge, $book];
    }

    protected function finalizedFixture(int $price = 4000): array
    {
        [$doctor, $ca, $visit, $queue] = $this->billingFixture($price);
        $builder = app(BillingBuilderService::class);
        $invoice = $builder->build($ca, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => null]);
        $invoice = $builder->finalize($ca, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version]);

        return [$doctor, $ca, $visit, $invoice];
    }
}

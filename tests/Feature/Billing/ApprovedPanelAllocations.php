<?php

namespace Tests\Feature\Billing;

use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use App\Domain\Visit\Models\Panel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Synthetic approved Panel allocations for the Panel team's tests (PNL-2, PNL-3). Each call completes a
 * consultation, finalises its invoice and approves a RM 30.00 Panel responsibility with one Panel Officer.
 *
 * @mixin BillingTestCase
 */
trait ApprovedPanelAllocations
{
    private ?User $panelOfficer = null;

    private bool $pricesReady = false;

    protected function officer(): User
    {
        return $this->panelOfficer ??= $this->actor('panel_officer');
    }

    /** @return array{0: CoverageAllocation, 1: Invoice} */
    protected function approvedAllocation(Panel $panel): array
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        app(CompleteConsultationService::class)->complete($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version, 'encounter_lock_version' => $encounter->lock_version, 'lock_version' => null, 'service_deliveries' => []]);
        if (! $this->pricesReady) {
            $charge = new ChargeDefinition;
            $charge->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $visit->organisation_id, 'code' => 'UAT-CONSULT', 'type' => 'consultation', 'display_name' => 'Synthetic consultation', 'unit' => 'consultation', 'source_key' => 'consultation'])->save();
            $book = new PriceBook;
            $book->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $visit->organisation_id, 'scope_key' => 'organisation', 'name' => 'Synthetic prices', 'currency' => 'MYR'])->save();
            $entry = new PriceEntry;
            $entry->forceFill(['organisation_id' => $visit->organisation_id, 'price_book_id' => $book->id, 'charge_definition_id' => $charge->id, 'unit_price_sen' => 4000, 'version' => 1, 'effective_at' => now()->subMinute(), 'published_by_user_id' => $ca->id])->save();
            $this->pricesReady = true;
        }
        $this->selectBranch($ca, $visit->branch);
        $builder = app(BillingBuilderService::class);
        $invoice = $builder->build($ca, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => null]);
        $invoice = $builder->finalize($ca, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version]);
        $allocation = app(ResponsibilityService::class)->propose($ca, $visit, $invoice, 'panel', [
            'expected_branch_id' => $visit->branch_id,
            'lock_version' => $invoice->lock_version,
            'amount_sen' => 3000,
            'reason' => 'Synthetic Panel responsibility for tests',
            'panel_id' => $panel->id,
            'member_reference' => 'SYNTHETIC-TEST-REF',
        ]);
        $officer = $this->officer();
        DB::table('billing_approval_limits')->updateOrInsert(
            ['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'user_id' => $officer->id, 'capability' => 'panel'],
            ['limit_sen' => 400000],
        );
        $this->selectBranch($officer, $this->branch);
        app(ResponsibilityService::class)->approve($officer, $visit, $invoice, 'panel', $allocation->public_id, [
            'expected_branch_id' => $visit->branch_id,
            'lock_version' => $invoice->refresh()->lock_version,
            'proposal_lock_version' => $allocation->lock_version,
        ]);

        return [$allocation->refresh(), $invoice->refresh()];
    }
}

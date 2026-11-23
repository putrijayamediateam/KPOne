<?php

namespace Tests\Feature\Agents;

use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Models\Panel;
use Illuminate\Support\Str;
use Tests\Feature\Billing\ApprovedPanelAllocations;
use Tests\Feature\Billing\BillingTestCase;
use Tests\Support\AsksTheConnector;

/** CN-3: panel_claims_made reconciles with the Panel Claims list (claims created, amount, status). */
class ConnectorPanelClaimsTest extends BillingTestCase
{
    use ApprovedPanelAllocations;
    use AsksTheConnector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectorSetUp();
    }

    public function test_panel_claims_made_counts_the_days_claims_per_status_and_panel(): void
    {
        [$allocation, $invoice] = $this->approvedAllocation(Panel::factory()->create(['organisation_id' => $this->organisation->id]));
        $this->selectBranch($this->officer(), $this->branch);
        $this->post(route('panel-claims.store', $allocation), [
            'expected_branch_id' => $allocation->branch_id, 'allocation_lock_version' => $allocation->lock_version, 'invoice_lock_version' => $invoice->lock_version,
            'idempotency_key' => (string) Str::uuid(), 'submitted_date' => now()->setTimezone($this->branch->timezone)->toDateString(), 'remarks' => 'Synthetic',
        ])->assertRedirect();
        $claim = PanelClaim::query()->sole();
        $director = $this->actor('director');

        $entry = $this->connectorAnswer($director, 'panel_claims_made')['branches'][0];

        $this->assertSame(1, $entry['claims']);
        $this->assertSame($claim->claim_amount_sen, $entry['claimedSen']);
        $this->assertSame(['submitted' => 1], $entry['byStatus']);
        $this->assertSame([['panel' => Panel::query()->findOrFail($claim->panel_id)->name, 'claims' => 1, 'claimedSen' => $claim->claim_amount_sen]], $entry['panels']);
        $this->assertSame($entry['from'], $entry['to']);

        $yesterday = now()->setTimezone($this->branch->timezone)->subDays(3)->toDateString();
        $this->assertSame(0, $this->connectorAnswer($director, 'panel_claims_made', ['from' => $yesterday, 'to' => $yesterday])['branches'][0]['claims']);
    }
}

<?php

namespace Tests\Feature\Insights;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\TreatmentUsageService;
use App\Domain\Shared\Services\ClinicInsightsReportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\SupplierReturnFixture;

/** STK-15: Insights shows treatment-room waste on its own line, net of reversed entries. */
final class TreatmentWasteInsightTest extends VisitTestCase
{
    public function test_treatment_room_waste_counts_unreversed_waste_only(): void
    {
        $fixture = new SupplierReturnFixture($this->branch);
        $f = $fixture->received('10.000');
        $ca = $fixture->actor('ca');
        $supervisor = $fixture->actor('ca_supervisor');
        $usage = app(TreatmentUsageService::class);
        $entry = fn (string $quantity, string $kind, string $reason) => ['expected_branch_id' => $this->branch->id, 'retry_key' => (string) Str::uuid(),
            'location_id' => $f['location']->id, 'batch_id' => $f['returnBatchId'], 'unit' => $f['sku']->stock_unit, 'quantity' => $quantity, 'kind' => $kind, 'reason' => $reason];

        app(BranchAccessService::class)->select($ca, $this->branch);
        $usage->record($ca, $entry('1.500', 'waste', 'spilled_or_broken'));
        $reversed = $usage->record($ca, $entry('2.000', 'waste', 'opened_not_used'));
        $usage->record($ca, $entry('3.000', 'usage', 'wound_care'));
        app(BranchAccessService::class)->select($supervisor, $this->branch);
        $usage->reverse($supervisor, $reversed, ['expected_branch_id' => $this->branch->id, 'retry_key' => (string) Str::uuid(), 'reason' => 'recorded_in_error']);

        $director = $fixture->actor('director');
        $today = now($this->branch->timezone)->toDateString();
        $summary = app(ClinicInsightsReportService::class)->report($director, 'inventory', $this->branch->code, $today, $today, null)['report']['summary'];
        $this->assertSame(1.5, $summary['treatmentWasteUnits']);
        // The existing wastage figure is unchanged by treatment-room entries.
        $this->assertSame(0.0, (float) $summary['wastageUnits']);
        $this->assertSame(1, DB::table('inventory_treatment_usage_reversals')->count());
    }
}

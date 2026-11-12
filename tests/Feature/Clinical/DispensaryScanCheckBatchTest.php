<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\OtcDispensaryFixtures;

/**
 * DSP-20 (owner: "go with DSP-20", 2026-11-12): scan to check compares a GS1 box's batch with the batches saved on a
 * medicine line, so each line's allocations carry the batch number and expiry. Read only. Synthetic data only.
 */
class DispensaryScanCheckBatchTest extends VisitTestCase
{
    use OtcDispensaryFixtures;

    public function test_saved_allocations_carry_the_batch_number_and_expiry(): void
    {
        $f = $this->fixture();
        $case = $this->open($f);
        $this->confirmAllergy($f, $case);
        app(DispensaryService::class)->addItem($f['ca'], $case->refresh(), $this->addPayload($f, $case));

        $this->actingAs($f['ca'])->get(route('dispensary.show', $case))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dispensary/Show')
            ->where('dispensary.items.0.allocations.0.batchPublicId', $f['batch']->public_id)
            ->where('dispensary.items.0.allocations.0.batchNumber', $f['batch']->batch_number)
            ->where('dispensary.items.0.allocations.0.expiryDate', $f['batch']->expiry_date->toDateString()));
    }
}

<?php

namespace Tests\Feature\Queue;

use Illuminate\Support\Facades\Date;
use Tests\Feature\Clinical\Triage\TriageTestCase;

/** Combined Q-16 integration evidence; standalone feature assertions remain unchanged. */
class CombinedQueueSummaryTest extends TriageTestCase
{
    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    public function test_zone_order_details_red_alert_and_summaries_preserve_their_different_scopes(): void
    {
        Date::setTestNow('2026-08-25 01:00:00 UTC');
        $this->approvedGuideline();
        $ca = $this->actor('ca');
        $owner = $this->doctor();
        $other = $this->doctor();
        $green = $this->consultationVisit($ca, $owner);
        $this->send($ca, $green);
        $this->triage($ca, $green->refresh(), ['chosen_zone' => 'green']);
        $red = $this->consultationVisit($ca, $owner);
        $this->send($ca, $red);
        $this->triage($ca, $red->refresh(), ['chosen_zone' => 'red']);

        $this->selectBranch($ca);
        $board = $this->postJson(route('queue.search'), [])->assertOk()->json();
        $this->assertSame([$green->visit_number], collect($board['waiting']['data'])->pluck('visitNumber')->all());
        $this->assertSame([$red->visit_number], collect($board['emergency']['data'])->pluck('visitNumber')->all());
        $this->getJson(route('queue.rooms-today', ['expected_branch_id' => $this->branch->id]))->assertOk();
        $this->getJson(route('queue.average-wait', ['expected_branch_id' => $this->branch->id]))->assertOk()->assertJsonPath('averageWaitMinutes', null);
        $details = $this->getJson(route('queue.details', $red->visit_number))->assertOk();
        $details->assertJsonPath('details.triage.zone', 'red');
        $this->assertStringNotContainsString($red->patient->full_name, $details->getContent());

        $this->selectBranch($other);
        $this->postJson(route('queue.search'), [])->assertOk()->assertJsonPath('waiting.data', [])->assertJsonPath('emergency.data', []);
        $this->getJson(route('queue.rooms-today', ['expected_branch_id' => $this->branch->id]))->assertForbidden();
        $this->getJson(route('queue.details', $red->visit_number))->assertForbidden();
        $this->getJson(route('queue.average-wait', ['expected_branch_id' => $this->branch->id]))->assertOk()->assertJsonPath('samples', 0);
        $alerts = $this->getJson(route('queue.red-zone-alerts', ['expected_branch_id' => $this->branch->id]))->assertOk();
        $this->assertStringNotContainsString($red->patient->full_name, $alerts->getContent());
        $this->assertNotEmpty($alerts->json('alerts'));
    }
}

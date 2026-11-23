<?php

namespace Tests\Feature;

use App\Domain\Approvals\ApprovalInbox;
use App\Domain\Patient\Services\PatientMergeService;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/** DAY-1c: "Waiting for me" on My day comes from the Approvals counts: counts only, only for deciders, hidden at zero. */
final class MyDayWaitingTest extends VisitTestCase
{
    private function propose(User $by): void
    {
        $kept = $this->patient();
        $other = $this->patient();
        app(PatientMergeService::class)->propose($by, $kept->refresh(), [
            'kind' => 'merge', 'other_patient_number' => $other->patient_number, 'reason_code' => 'same_person_registered_twice',
            'expected_branch_id' => $this->branch->id, 'kept_lock_version' => $kept->lock_version, 'other_lock_version' => $other->refresh()->lock_version,
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    public function test_a_decider_sees_the_waiting_block_with_counts_only_and_it_matches_the_inbox_number(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->propose($ca);
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        Cache::flush();

        $response = $this->actingAs($supervisor)->get(route('my-day'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('waiting.total', 1)->where('waiting.areas.0.area', 'Patients and clinical')->where('waiting.areas.0.count', 1));
        $this->assertSame(app(ApprovalInbox::class)->summary($supervisor)['total'], 1, 'one count path: the page and the account button agree');
        $json = (string) json_encode($response->viewData('page')['props']['waiting'] ?? []);
        $this->assertStringNotContainsString('Synthetic', $json);
        $this->assertSame(['total', 'areas'], array_keys(json_decode($json, true)));
    }

    public function test_the_block_is_hidden_when_nothing_waits_and_for_people_who_decide_nothing(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        Cache::flush();
        $this->actingAs($supervisor)->get(route('my-day'))->assertInertia(fn (Assert $page) => $page->where('waiting', null));

        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->propose($ca);
        Cache::flush();
        $this->actingAs($ca)->get(route('my-day'))->assertInertia(fn (Assert $page) => $page->where('waiting', null));
        $doctor = $this->actor('resident_doctor');
        $this->selectBranch($doctor);
        $this->actingAs($doctor)->get(route('my-day'))->assertInertia(fn (Assert $page) => $page->where('waiting', null));
    }
}

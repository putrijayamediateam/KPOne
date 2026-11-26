<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\TreatmentRoom\TreatmentRoomPolicy;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Models\Visit;
use App\Models\User;

class TreatmentRoomBatchPolicyTest extends ClinicalTestCase
{
    public function test_current_batch_matches_single_policy_for_permitted_and_denied_actors(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $visit->refresh()->load(['clinicalEncounter', 'queueEntry']);
        $policy = app(TreatmentRoomPolicy::class);
        foreach ([$doctor, $this->actor('ca_supervisor')] as $actor) {
            $this->assertParity($policy, $actor, $visit, true);
        }
        foreach ([$this->doctor(), $this->actor('director')] as $actor) {
            $this->assertParity($policy, $actor, $visit, false);
        }
        $inactive = $this->doctor();
        $inactive->forceFill(['is_active' => false])->save();
        $this->assertParity($policy, $inactive, $visit, false);
        $foreignBranch = Branch::query()->where('organisation_id', $this->organisation->id)->whereKeyNot($this->branch->id)->firstOrFail();
        $foreignReader = $this->actor('ca_supervisor', $foreignBranch);
        $this->selectBranch($foreignReader, $foreignBranch);
        $this->assertFalse($policy->view($foreignReader, $visit));
        $this->assertSame([], $policy->viewableCurrentVisitIds($foreignReader, collect([$visit])));

        // Current-record batching cannot opt into the single-view history path.
        $this->selectBranch($doctor);
        $this->assertTrue($policy->view($doctor, $visit, true));
        $historical = clone $visit;
        $historical->status = Visit::STATUS_COMPLETED;
        $this->assertTrue($policy->view($doctor, $historical, true));
        $this->assertParity($policy, $doctor, $historical, false);
        $foreignOrganisation = clone $visit;
        $foreignOrganisation->organisation_id = $visit->organisation_id + 10000;
        $this->assertParity($policy, $doctor, $foreignOrganisation, false);
        $this->assertSame([$visit->id], $policy->viewableCurrentVisitIds($doctor, collect([$visit, $visit])));
        $doctor->roles()->sole()->revokePermissionTo('encounters.view.own');
        $this->assertParity($policy, $doctor->fresh(), $visit, false);
    }

    public function test_batch_rechecks_revoked_branch_authority_after_a_successful_read(): void
    {
        [$doctor, , $visit] = $this->servingFixture();
        $visit->load(['clinicalEncounter', 'queueEntry']);
        $policy = app(TreatmentRoomPolicy::class);
        $this->assertParity($policy, $doctor, $visit, true);
        $doctor->roles()->sole()->revokePermissionTo('branch_context.switch.branch');
        $this->assertParity($policy, $doctor->fresh(), $visit, false);
    }

    private function assertParity(TreatmentRoomPolicy $policy, User $actor, Visit $visit, bool $expected): void
    {
        $this->selectBranch($actor);
        $this->assertSame($expected, $policy->view($actor, $visit));
        $this->assertSame($expected ? [$visit->id] : [], $policy->viewableCurrentVisitIds($actor, collect([$visit])));
    }
}

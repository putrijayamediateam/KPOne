<?php

namespace Tests\Feature\Queue;

use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class EmergencyDoctorPresenceTest extends QueueTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::purge('array');
    }

    private function poll(User $actor, ?Branch $branch = null): array
    {
        $branch ??= $this->branch;
        $this->selectBranch($actor, $branch);

        return $this->getJson(route('queue.red-zone-alerts', ['expected_branch_id' => $branch->id]))->assertOk()->json();
    }

    public function test_counts_distinct_recent_doctor_polls_without_roster_or_recorder_inference(): void
    {
        $ca = $this->actor();
        $one = $this->doctor();
        $two = $this->doctor();
        $this->assertSame(['available' => true, 'doctorCount' => 0, 'windowSeconds' => 30], $this->poll($ca)['presence']);
        $this->assertSame(1, $this->poll($one)['presence']['doctorCount']);
        $this->assertSame(1, $this->poll($one)['presence']['doctorCount']);
        $body = $this->poll($two);
        $this->assertSame(2, $body['presence']['doctorCount']);
        $this->assertSame([], $body['alerts']);
        $this->assertSame(['alerts', 'presence'], array_keys($body));
        $this->assertStringNotContainsString($one->name, json_encode($body));
        $this->assertSame(2, $this->poll($ca)['presence']['doctorCount']);
        $this->travel(31)->seconds();
        $this->assertSame(0, $this->poll($ca)['presence']['doctorCount']);
        $this->travelBack();
    }

    public function test_presence_is_branch_scoped_and_wrong_active_branch_cannot_poll(): void
    {
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->where('id', '!=', $this->branch->id)->where('is_active', true)->firstOrFail();
        $doctor = $this->doctor($other);
        $this->assertSame(1, $this->poll($doctor, $other)['presence']['doctorCount']);
        $this->assertSame(0, $this->poll($this->actor())['presence']['doctorCount']);
        $this->selectBranch($doctor, $other);
        $this->getJson(route('queue.red-zone-alerts', ['expected_branch_id' => $this->branch->id]))->assertForbidden();
    }

    public function test_revoked_and_inactive_cached_doctors_are_removed_before_expiry(): void
    {
        $one = $this->doctor();
        $two = $this->doctor();
        $this->poll($one);
        $this->poll($two);
        $one->forceFill(['is_active' => false])->save();
        $two->removeRole('resident_doctor');
        $this->assertSame(0, $this->poll($this->actor())['presence']['doctorCount']);
    }

    public function test_cache_failure_preserves_alerts_and_reports_unknown_not_zero(): void
    {
        $ca = $this->actor();
        Cache::partialMock()->shouldReceive('lock')->once()->andThrow(new RuntimeException('Synthetic cache unavailable'));
        $body = $this->poll($ca);
        $this->assertSame(['available' => false, 'doctorCount' => null, 'windowSeconds' => 30], $body['presence']);
        $this->assertSame([], $body['alerts']);
    }

    public function test_busy_cache_lock_is_unknown_and_stale_entries_are_pruned(): void
    {
        $ca = $this->actor();
        $key = "queue:emergency:presence:{$this->organisation->id}:{$this->branch->id}";
        Cache::put($key, [999999 => now()->subSeconds(31)->getTimestamp()], 60);
        $this->assertSame(0, $this->poll($ca)['presence']['doctorCount']);
        $this->assertSame([], Cache::get($key));
        $lock = Cache::lock($key.':lock', 5);
        $this->assertTrue($lock->get());
        try {
            $this->assertSame(['available' => false, 'doctorCount' => null, 'windowSeconds' => 30], $this->poll($ca)['presence']);
        } finally {
            $lock->release();
        }
    }

    public function test_cache_capacity_is_bounded_and_invalid_state_is_unavailable(): void
    {
        $key = "queue:emergency:presence:{$this->organisation->id}:{$this->branch->id}";
        Cache::put($key, array_fill(1, 513, now()->getTimestamp()), 60);
        $this->assertFalse($this->poll($this->actor())['presence']['available']);
        $this->assertCount(513, Cache::get($key));
    }
}

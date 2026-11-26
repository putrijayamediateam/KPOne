<?php

namespace Tests\Feature\Queue;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Queue\Emergency\EmergencyDoctorPresence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\Support\StaffBranchAssignmentBootstrapper;

class EmergencyDoctorPresenceBatchTest extends QueueTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::purge('array');
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    public function test_presence_query_count_is_constant_for_one_and_twenty_six_recent_doctors(): void
    {
        Date::setTestNow(CarbonImmutable::parse('2026-08-25 09:00:00', $this->branch->timezone)->utc());
        $ca = $this->actor();
        $doctors = collect();
        for ($i = 0; $i < 26; $i++) {
            $doctors->push($this->doctor());
        }
        $this->selectBranch($ca);
        $key = "queue:emergency:presence:{$this->organisation->id}:{$this->branch->id}";
        $counts = [];
        foreach ([1, 26] as $size) {
            Cache::put($key, $doctors->take($size)->mapWithKeys(fn (User $doctor): array => [$doctor->id => now()->getTimestamp()])->all(), 60);
            app(EmergencyDoctorPresence::class)->poll($ca, $this->branch->id);
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $result = app(EmergencyDoctorPresence::class)->poll($ca, $this->branch->id);
                $counts[$size] = count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
            $this->assertSame($size, $result['doctorCount']);
            $this->assertSame(['available', 'doctorCount', 'windowSeconds'], array_keys($result));
        }
        $this->assertSame($counts[1], $counts[26], 'Cached doctor cardinality must not introduce per-user assignment queries.');
        $this->assertLessThanOrEqual(20, $counts[26]);
    }

    public function test_batch_selection_matches_single_user_authority_across_branch_local_assignment_boundaries(): void
    {
        $day = CarbonImmutable::parse('2026-08-25 00:00:00', $this->branch->timezone);
        Date::setTestNow($day->utc());
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->where('id', '!=', $this->branch->id)->firstOrFail();
        $today = $this->doctor($other);
        $future = $this->doctor($other);
        $expired = $this->doctor($other);
        foreach ([[$today, $day, $day], [$future, $day->addDay(), null], [$expired, $day->subDays(2), $day->subDay()]] as [$doctor, $from, $until]) {
            StaffBranchAssignmentBootstrapper::create($doctor->staffProfile, $this->branch, ['is_primary' => false, 'valid_from' => $from->toDateString(), 'valid_until' => $until?->toDateString()]);
        }
        $director = $this->actor('director', $other);
        $noProfile = User::factory()->create(['organisation_id' => $this->organisation->id]);
        $noProfile->assignRole('resident_doctor');
        $foreignOrg = Organisation::query()->create(['name' => 'Synthetic Other Presence Organisation', 'code' => 'SYNTH-PRESENCE', 'is_active' => true]);
        $foreignBranch = new Branch;
        $foreignBranch->forceFill(['organisation_id' => $foreignOrg->id, 'name' => 'Synthetic Other Presence Branch', 'code' => 'SYNTH-PRESENCE', 'timezone' => $this->branch->timezone, 'is_active' => true])->save();
        $foreign = $this->doctor($foreignBranch);
        $ids = [$today->id, $future->id, $expired->id, $director->id, $noProfile->id, $foreign->id];
        $access = app(BranchAccessService::class);
        foreach ([$day, $day->endOfDay(), $day->addDay()] as $time) {
            Date::setTestNow($time->utc());
            $users = User::query()->whereIn('id', $ids)->orderBy('id')->get();
            $single = $users->filter(fn (User $user): bool => $access->canSelect($user, $this->branch))->pluck('id')->all();
            $this->assertSame($single, $access->selectableUserIds($users, $this->branch));
            $this->assertSame($time->toDateString() === $day->toDateString(), in_array($today->id, $single, true));
            $this->assertSame($time->toDateString() !== $day->toDateString(), in_array($future->id, $single, true));
            $this->assertNotContains($expired->id, $single);
            $this->assertNotContains($foreign->id, $single);
            $this->assertNotContains($noProfile->id, $single);
            $this->assertContains($director->id, $single);
        }
        $inactive = clone $this->branch;
        $inactive->is_active = false;
        $this->assertSame([], $access->selectableUserIds(new Collection([$today, $director]), $inactive));
    }

    public function test_presence_expires_exactly_at_window_end_without_roster_or_zero_on_failure_inference(): void
    {
        $start = CarbonImmutable::parse('2026-08-25 09:00:00', $this->branch->timezone)->utc();
        Date::setTestNow($start);
        $doctor = $this->doctor();
        $ca = $this->actor();
        $this->selectBranch($doctor);
        $service = app(EmergencyDoctorPresence::class);
        $this->assertSame(1, $service->poll($doctor, $this->branch->id)['doctorCount']);
        $this->selectBranch($ca);
        Date::setTestNow($start->addSeconds(29));
        $this->assertSame(1, $service->poll($ca, $this->branch->id)['doctorCount']);
        Date::setTestNow($start->addSeconds(30));
        $this->assertSame(0, $service->poll($ca, $this->branch->id)['doctorCount']);
    }
}

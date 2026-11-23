<?php

namespace Tests\Feature\Queue;

use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Clinical\Dispensary\Services\DoctorDispensaryAttentionService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Clinical\ClinicalTestCase;
use Tests\Support\DoctorCheckFixtures;

/**
 * Q-15 (owner decision 21 Nov 2026): the "N to check" count next to the doctor's Queue tab. One aggregate count of the
 * dispensary cases waiting for this doctor's check, a top-level payload key (tabCounts keeps its shape). Synthetic data only.
 */
class QueueChecksWaitingTest extends ClinicalTestCase
{
    use DoctorCheckFixtures;

    /** @param array<string, mixed> $f */
    private function sendForCheck(array $f): void
    {
        $this->selectBranch($f['ca'], $f['visit']->branch);
        app(DispensaryService::class)->sendForDoctorCheck($f['ca'], $f['case']->refresh(), [
            'expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $f['case']->refresh()->lock_version, 'retry_key' => (string) Str::uuid(),
        ]);
    }

    private function payloadCount(User $actor): mixed
    {
        $this->selectBranch($actor);

        return $this->actingAs($actor)->postJson(route('queue.search'), ['status' => 'removed'])->assertOk()->json('checksWaiting');
    }

    public function test_the_doctor_sees_the_count_and_it_leaves_tab_counts_as_it_was(): void
    {
        $f = $this->prepared();
        $this->switchOn($f['visit']->branch);
        $this->assertSame(0, $this->payloadCount($f['doctor']), 'Nothing waits yet.');

        $this->sendForCheck($f);
        $this->selectBranch($f['doctor'], $f['visit']->branch);
        $response = $this->actingAs($f['doctor'])->postJson(route('queue.search'), ['status' => 'removed'])->assertOk();

        $this->assertSame(1, $response->json('checksWaiting'));
        $this->assertSame(['all', 'waiting', 'serving', 'removed'], array_keys($response->json('tabCounts')));
    }

    public function test_everyone_else_gets_zero_and_no_query(): void
    {
        $f = $this->prepared();
        $this->switchOn($f['visit']->branch);
        $this->sendForCheck($f);

        foreach ([$f['ca'], $this->actor('ca_supervisor'), $this->actor('director'), $this->doctor()] as $actor) {
            $this->selectBranch($actor);
            $this->assertSame(0, app(DoctorDispensaryAttentionService::class)->checksWaitingCount($actor, $this->branch));
            $this->assertSame(0, $this->payloadCount($actor));
        }
    }

    public function test_the_count_is_one_aggregate_and_agrees_with_the_row_flag(): void
    {
        $f = $this->prepared();
        $this->switchOn($f['visit']->branch);
        $this->sendForCheck($f);
        $this->selectBranch($f['doctor'], $f['visit']->branch);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertSame(1, app(DoctorDispensaryAttentionService::class)->checksWaitingCount($f['doctor'], $f['visit']->branch));
        $counts = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains(strtolower($q['query']), 'count(*)') && str_contains($q['query'], 'dispensary_cases'));
        DB::disableQueryLog();
        $this->assertCount(1, $counts, 'One aggregate count, no per-row query.');
        // It counts every waiting case, not the rows a page lists: no LIMIT, OFFSET or page window in the query (Aisyah's query-log check).
        $sql = strtolower($counts->first()['query']);
        $this->assertStringNotContainsString(' limit ', $sql);
        $this->assertStringNotContainsString(' offset ', $sql);

        $row = collect($this->actingAs($f['doctor'])->postJson(route('queue.search'), ['status' => 'removed'])->json('removed'))->firstWhere('visitNumber', $f['visit']->visit_number);
        $this->assertTrue($row['dispensaryCheckWaiting'], 'The count and the Q-13 row flag agree.');
    }
}

<?php

namespace Tests\Feature\Queue;

use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Clinical\ClinicalTestCase;
use Tests\Support\DoctorCheckFixtures;

/**
 * Q-13 (owner decision on DSP-23, 20-21 Nov 2026): the doctor's own removed Queue row says when the dispensary case waits
 * for the doctor's check. One boolean per row, batched, ids only. Synthetic data only.
 */
class QueueDispensaryCheckRowTest extends ClinicalTestCase
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

    /** @return array<string, mixed>|null */
    private function removedRow(User $actor, string $visitNumber): ?array
    {
        $this->selectBranch($actor);
        $rows = $this->actingAs($actor)->postJson(route('queue.search'), ['status' => 'removed'])->assertOk()->json('removed');

        return collect($rows)->firstWhere('visitNumber', $visitNumber);
    }

    public function test_the_assigned_doctors_row_is_flagged_and_can_open_the_consultation(): void
    {
        $f = $this->prepared();
        $this->switchOn($f['visit']->branch);
        $this->sendForCheck($f);
        $this->assertSame(DispensaryCase::STATUS_AWAITING_DOCTOR_CHECK, $f['case']->refresh()->status);

        $row = $this->removedRow($f['doctor'], $f['visit']->visit_number);
        $this->assertNotNull($row);
        $this->assertTrue($row['dispensaryCheckWaiting']);
        $this->assertTrue($row['canOpenEncounter']);
    }

    public function test_the_flag_is_false_for_the_ca_the_director_and_another_doctor(): void
    {
        $f = $this->prepared();
        $this->switchOn($f['visit']->branch);
        $this->sendForCheck($f);

        foreach ([$f['ca'], $this->actor('ca_supervisor'), $this->actor('director'), $this->doctor()] as $actor) {
            $row = $this->removedRow($actor, $f['visit']->visit_number);
            if ($row !== null) {
                $this->assertFalse($row['dispensaryCheckWaiting'], 'Only the assigned doctor sees the flag.');
            }
        }
    }

    public function test_the_flag_is_false_when_the_case_is_not_waiting_for_the_check(): void
    {
        $f = $this->prepared();
        $this->switchOn($f['visit']->branch, false);
        $row = $this->removedRow($f['doctor'], $f['visit']->visit_number);
        if ($row !== null) {
            $this->assertFalse($row['dispensaryCheckWaiting']);
        }
        $this->assertNotSame(DispensaryCase::STATUS_AWAITING_DOCTOR_CHECK, $f['case']->refresh()->status);
    }

    public function test_the_flag_is_one_batched_read_for_the_page(): void
    {
        $f = $this->prepared();
        $this->switchOn($f['visit']->branch);
        $this->sendForCheck($f);
        $this->selectBranch($f['doctor'], $f['visit']->branch);
        $this->actingAs($f['doctor']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->postJson(route('queue.search'), ['status' => 'removed'])->assertOk();
        // The check helper's one read: a dispensary_cases query bound to the waiting status and not to the declined-medicine reason.
        $checks = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'dispensary_cases')
            && in_array('awaiting_doctor_check', $q['bindings'], true)
            && ! in_array('patient_declined', $q['bindings'], true)
            // Q-15 adds its own aggregate count of the same cases to the payload; this test is about the row flag's read.
            && ! str_contains(strtolower($q['query']), 'count(*)'))->count();
        DB::disableQueryLog();

        $this->assertSame(1, $checks, 'One batched read for the whole page, not one per row.');
    }
}

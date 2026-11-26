<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Dispensary\Services\DispensaryListService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Clinical\Dispensary\Services\OtcDispensaryService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Services\OtcQueueService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DoctorCheckFixtures;

class DispensaryQueueSearchTest extends ClinicalTestCase
{
    use DoctorCheckFixtures;

    /** @return array<string, mixed> */
    private function otc(?User $ca = null): array
    {
        $ca ??= $this->actor('ca');
        $visit = $this->register($ca, $this->patient());
        app(OtcQueueService::class)->enter($ca, $visit, ['expected_branch_id' => $visit->branch_id]);
        $case = app(OtcDispensaryService::class)->open($ca, $visit, ['expected_branch_id' => $visit->branch_id]);

        return compact('ca', 'visit', 'case');
    }

    public function test_queue_search_matches_exact_series_and_keeps_type_counts_and_authority_scope(): void
    {
        $consultation = $this->prepared();
        $otc = $this->otc($consultation['ca']);
        $actor = $consultation['ca'];
        $this->selectBranch($actor, $this->branch);
        $service = app(DispensaryListService::class);
        $all = $service->page($actor, 'prepare', 1, '');
        foreach ($all['rows'] as $row) {
            foreach ([$row['queueNumber'], strtolower($row['queueNumber'])] as $term) {
                $found = $service->page($actor, 'prepare', 1, $term);
                $this->assertSame([$row['publicId']], array_column($found['rows'], 'publicId'));
                $this->assertSame($all['counts'], $found['counts']);
                $otherType = $row['caseType'] === 'otc' ? 'consultation' : 'otc';
                $this->assertSame(0, $service->page($actor, 'prepare', 1, $term, $otherType)['total']);
            }
        }
        foreach (['A-000', 'B-000', 'A-9223372036854775808', 'B-99999999999999999999999', 'A-1', 'A-001x'] as $invalid) {
            $this->assertSame(0, $service->page($actor, 'prepare', 1, $invalid)['total']);
        }
        $other = Branch::where('organisation_id', $this->organisation->id)->whereKeyNot($this->branch->id)->firstOrFail();
        $otherCa = $this->actor('ca', $other);
        $this->selectBranch($otherCa, $other);
        $this->assertSame(0, $service->page($otherCa, 'prepare', 1, $all['rows'][0]['queueNumber'])['total']);
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance, $this->branch);
        $this->actingAs($finance)->get(route('dispensary.index', ['search' => $all['rows'][0]['queueNumber']]))->assertForbidden();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $service->page($actor, 'prepare', 1, '');
        $unfiltered = count(DB::getQueryLog());
        DB::flushQueryLog();
        $service->page($actor, 'prepare', 1, $all['rows'][0]['queueNumber']);
        $filtered = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($unfiltered, $filtered);
    }

    public function test_repeated_day_numbers_and_four_digit_numbers_preserve_existing_tab_boundaries(): void
    {
        $first = $this->prepared();
        $service = app(DispensaryListService::class);
        $number = $service->page($first['ca'], 'prepare', 1, '')['rows'][0]['queueNumber'];
        $this->travel(1)->days();
        try {
            $second = $this->prepared();
            $this->selectBranch($second['ca'], $this->branch);
            $this->assertSame(2, $service->page($second['ca'], 'prepare', 1, $number)['total']);
            DB::table('queue_number_counters')->where('branch_id', $this->branch->id)
                ->where('operational_date', now()->setTimezone($this->branch->timezone)->toDateString())
                ->where('series', 'A')->update(['next_value' => 1000]);
            $large = $this->prepared();
            $this->selectBranch($large['ca'], $this->branch);
            $this->assertSame([$large['case']->public_id], array_column($service->page($large['ca'], 'prepare', 1, 'A-1000')['rows'], 'publicId'));
            app(DispensaryService::class)->complete($large['ca'], $large['case']->refresh(), ['expected_branch_id' => $this->branch->id, 'case_lock_version' => $large['case']->lock_version]);
            $this->assertSame(1, $service->page($large['ca'], 'done', 1, 'A-1000')['total']);
            $this->assertSame(0, $service->page($large['ca'], 'prepare', 1, 'A-1000')['total']);
            $this->switchOn($this->branch);
            app(DispensaryService::class)->sendForDoctorCheck($second['ca'], $second['case']->refresh(), ['expected_branch_id' => $this->branch->id, 'case_lock_version' => $second['case']->lock_version, 'retry_key' => (string) Str::uuid()]);
            $this->assertSame(1, $service->page($second['ca'], 'check', 1, $number)['total']);
            $this->assertSame(1, $service->page($second['ca'], 'prepare', 1, $number)['total']);
            $this->travel(1)->days();
            $this->assertSame(0, $service->page($large['ca'], 'done', 1, 'A-1000')['total']);
        } finally {
            $this->travelBack();
        }
    }
}

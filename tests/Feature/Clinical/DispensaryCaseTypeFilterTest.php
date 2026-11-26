<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Dispensary\Services\DispensaryListService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Clinical\Dispensary\Services\OtcDispensaryService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\DoctorCheckFixtures;

class DispensaryCaseTypeFilterTest extends ClinicalTestCase
{
    use DoctorCheckFixtures;

    /** @return array<string, mixed> */
    private function otc(?User $ca = null): array
    {
        $ca ??= $this->actor('ca');
        $visit = $this->register($ca, $this->patient());
        $case = app(OtcDispensaryService::class)->open($ca, $visit, ['expected_branch_id' => $visit->branch_id]);

        return compact('ca', 'visit', 'case');
    }

    public function test_type_narrows_prepare_rows_and_count_but_not_other_status_counts_or_search_count_semantics(): void
    {
        $consultation = $this->prepared();
        $otc = $this->otc();
        $done = $this->prepared();
        $this->selectBranch($done['ca'], $this->branch);
        app(DispensaryService::class)->complete($done['ca'], $done['case']->refresh(), ['expected_branch_id' => $this->branch->id, 'case_lock_version' => $done['case']->lock_version]);
        $waiting = $this->prepared();
        $this->switchOn($this->branch);
        $this->selectBranch($waiting['ca'], $this->branch);
        app(DispensaryService::class)->sendForDoctorCheck($waiting['ca'], $waiting['case']->refresh(), ['expected_branch_id' => $this->branch->id, 'case_lock_version' => $waiting['case']->lock_version, 'retry_key' => (string) Str::uuid()]);
        $this->actingAs($consultation['ca']);
        foreach (['consultation' => $consultation['case'], 'otc' => $otc['case']] as $type => $case) {
            $this->get(route('dispensary.index', ['case_type' => $type]))->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('list.caseType', $type)->where('list.total', 1)->where('list.rows.0.publicId', $case->public_id)
                ->where('list.counts', ['prepare' => 1, 'check' => 1, 'done' => 1]));
        }
        $this->get(route('dispensary.index', ['case_type' => 'otc', 'search' => 'Synthetic no matching patient xyz']))->assertInertia(fn (Assert $page) => $page
            ->where('list.total', 0)->where('list.counts', ['prepare' => 1, 'check' => 1, 'done' => 1]));
        $number = $otc['visit']->patient->patient_number;
        $this->get(route('dispensary.index', ['case_type' => 'otc', 'search' => $number]))->assertInertia(fn (Assert $page) => $page
            ->where('list.search', $number)->where('list.rows.0.publicId', $otc['case']->public_id));
        foreach (['check', 'done'] as $tab) {
            $this->get(route('dispensary.index', ['tab' => $tab, 'case_type' => 'otc']))->assertInertia(fn (Assert $page) => $page
                ->where('list.caseType', 'all')->where('list.total', 1)->where('list.rows.0.caseType', 'consultation')
                ->where('list.counts', ['prepare' => 2, 'check' => 1, 'done' => 1]));
        }
        $this->get(route('dispensary.index'))->assertInertia(fn (Assert $page) => $page->where('list.caseType', 'all')->where('list.total', 2));
    }

    public function test_invalid_type_values_normalize_and_existing_branch_and_role_boundaries_hold(): void
    {
        $f = $this->otc();
        $this->actingAs($f['ca']);
        foreach (['unknown', ['otc']] as $type) {
            $this->get(route('dispensary.index', ['case_type' => $type]))->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('list.caseType', 'all')->where('list.total', 1));
        }
        $other = Branch::where('organisation_id', $this->organisation->id)->whereKeyNot($this->branch->id)->firstOrFail();
        $otherCa = $this->actor('ca', $other);
        $this->selectBranch($otherCa, $other);
        $this->actingAs($otherCa)->get(route('dispensary.index', ['case_type' => 'otc']))->assertInertia(fn (Assert $page) => $page
            ->where('list.total', 0)->where('list.counts', ['prepare' => 0, 'check' => 0, 'done' => 0]));
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance, $this->branch);
        $this->actingAs($finance)->get(route('dispensary.index', ['case_type' => 'otc']))->assertForbidden();
    }

    public function test_type_filtered_pages_remain_bounded_and_add_no_queries(): void
    {
        $f = $this->otc();
        for ($i = 1; $i < 26; $i++) {
            $this->otc($f['ca']);
        }
        $this->prepared();
        $this->selectBranch($f['ca'], $this->branch);
        $service = app(DispensaryListService::class);
        $first = $service->page($f['ca'], 'prepare', 1, '', 'otc');
        $last = $service->page($f['ca'], 'prepare', 999, '', 'otc');
        $this->assertSame([26, 25, 2], [$first['total'], count($first['rows']), $first['lastPage']]);
        $this->assertSame([2, 1], [$last['page'], count($last['rows'])]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $service->page($f['ca'], 'prepare', 1, '');
        $all = count(DB::getQueryLog());
        DB::flushQueryLog();
        $service->page($f['ca'], 'prepare', 1, '', 'otc');
        $filtered = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($all, $filtered);
    }
}

<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Dispensary\Services\DispensaryDoctorFilter;
use App\Domain\Clinical\Dispensary\Services\DispensaryListService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Clinical\Dispensary\Services\OtcDispensaryService;
use App\Domain\Organisation\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\DoctorCheckFixtures;

class DispensaryDoctorFilterTest extends ClinicalTestCase
{
    use DoctorCheckFixtures;

    public function test_case_derived_doctor_and_otc_filter_only_prepare_and_preserve_other_counts_and_search(): void
    {
        $f = $this->prepared();
        $other = $this->prepared();
        $visit = $this->register($f['ca'], $this->patient());
        app(OtcDispensaryService::class)->open($f['ca'], $visit, ['expected_branch_id' => $this->branch->id]);
        $waiting = $this->prepared();
        $this->switchOn($this->branch);
        $this->selectBranch($waiting['ca'], $this->branch);
        app(DispensaryService::class)->sendForDoctorCheck($waiting['ca'], $waiting['case']->refresh(), ['expected_branch_id' => $this->branch->id, 'case_lock_version' => $waiting['case']->lock_version, 'retry_key' => (string) Str::uuid()]);
        $this->selectBranch($f['ca'], $this->branch);
        $this->actingAs($f['ca']);
        $options = $this->getJson(route('dispensary.doctor-options'))->assertOk()->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private')->json();
        $this->assertSame(2, $options['total']);
        $choice = collect($options['data'])->firstWhere('label', $f['doctor']->name);
        $this->assertNotNull($choice);
        $this->assertSame(['value', 'label'], array_keys($choice));
        $this->get(route('dispensary.index', ['doctor' => $choice['value']]))->assertInertia(fn (Assert $p) => $p
            ->where('list.total', 1)->where('list.rows.0.publicId', $f['case']->public_id)->where('list.doctorLabel', $f['doctor']->name)
            ->where('list.counts', ['prepare' => 1, 'check' => 1, 'done' => 0])->where('list.doctorFilterReset', false));
        $this->get(route('dispensary.index', ['doctor' => $choice['value'], 'case_type' => 'otc', 'search' => 'not matching']))->assertInertia(fn (Assert $p) => $p->where('list.total', 0)->where('list.counts.prepare', 0));
        $this->get(route('dispensary.index', ['doctor' => $choice['value'], 'search' => 'not matching']))->assertInertia(fn (Assert $p) => $p->where('list.total', 0)->where('list.counts.prepare', 1));
        $this->get(route('dispensary.index', ['doctor' => 'otc']))->assertInertia(fn (Assert $p) => $p->where('list.total', 1)->where('list.rows.0.caseType', 'otc'));
        foreach (['check', 'done'] as $tab) {
            $this->get(route('dispensary.index', ['doctor' => $choice['value'], 'tab' => $tab]))->assertInertia(fn (Assert $p) => $p
                ->where('list.doctorFilter', 'all')->where('list.doctorFilterReset', false)->where('list.counts.prepare', 3));
        }
        $this->getJson(route('dispensary.doctor-options', ['selected' => $choice['value'], 'case_type' => 'otc', 'doctor' => 'otc']))->assertJsonPath('total', 2)->assertJsonPath('selected.value', $choice['value']);
        $this->assertNotSame($f['doctor']->id, $other['doctor']->id);
    }

    public function test_stale_foreign_malformed_and_array_tokens_reset_without_label_leakage_and_authority_is_fresh(): void
    {
        $f = $this->prepared();
        $this->selectBranch($f['ca'], $this->branch);
        $reader = app(DispensaryDoctorFilter::class);
        $choice = $reader->options($f['ca'], 1, '', 'all')['data'][0];
        $this->actingAs($f['ca']);
        foreach (['bad-token', ['nested']] as $bad) {
            $this->get(route('dispensary.index', ['doctor' => $bad]))->assertInertia(fn (Assert $p) => $p->where('list.doctorFilter', 'all')->where('list.doctorFilterReset', true));
            $this->getJson(route('dispensary.doctor-options', ['selected' => $bad, 'page' => ['bad'], 'search' => ['bad']]))->assertOk()->assertJsonPath('reset', true);
        }
        $otherBranch = Branch::where('organisation_id', $this->organisation->id)->whereKeyNot($this->branch->id)->firstOrFail();
        $otherCa = $this->actor('ca', $otherBranch);
        $this->selectBranch($otherCa, $otherBranch);
        $this->actingAs($otherCa)->getJson(route('dispensary.doctor-options', ['selected' => $choice['value']]))
            ->assertOk()->assertJsonPath('data', [])->assertJsonPath('selected.label', 'All doctors')->assertJsonPath('reset', true)->assertDontSee($f['doctor']->name);
        $this->selectBranch($f['ca'], $this->branch);
        app(DispensaryService::class)->complete($f['ca'], $f['case']->refresh(), ['expected_branch_id' => $this->branch->id, 'case_lock_version' => $f['case']->lock_version]);
        $stale = app(DispensaryListService::class)->page($f['ca'], 'prepare', 1, '', 'all', $choice['value']);
        $this->assertSame(['all', 'All doctors', true], [$stale['doctorFilter'], $stale['doctorLabel'], $stale['doctorFilterReset']]);
        $finance = $this->actor('finance_officer');
        $this->actingAs($finance)->getJson(route('dispensary.doctor-options'))->assertForbidden();
        $f['ca']->forceFill(['is_active' => false])->save();
        $this->expectException(HttpException::class);
        $reader->options($f['ca'], 1, '', 'all');
    }

    public function test_distinct_doctor_choices_have_stable_bounded_pagination_literal_search_and_fixed_query_count(): void
    {
        $first = $this->prepared();
        for ($i = 1; $i < 26; $i++) {
            $this->prepared();
        }
        $first['doctor']->forceFill(['name' => 'Synthetic 25%_\\ Doctor'])->save();
        $this->selectBranch($first['ca'], $this->branch);
        $reader = app(DispensaryDoctorFilter::class);
        $a = $reader->options($first['ca'], 1, '', 'all');
        $b = $reader->options($first['ca'], 999, '', 'all');
        $this->assertSame([26, 25, 2], [$a['total'], count($a['data']), $a['lastPage']]);
        $this->assertSame([2, 1], [$b['page'], count($b['data'])]);
        $this->assertSame(26, count(array_unique(array_column([...$a['data'], ...$b['data']], 'label'))));
        $literal = $reader->options($first['ca'], 1, '%_\\', 'all');
        $this->assertSame(1, $literal['total']);
        $selected = $literal['data'][0]['value'];
        $this->assertSame($selected, $reader->options($first['ca'], 2, 'no match', $selected)['selected']['value']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $reader->options($first['ca'], 1, '', 'all');
        $queries = count(DB::getQueryLog());
        DB::flushQueryLog();
        $reader->options($first['ca'], 2, '%_\\', 'all');
        $this->assertSame($queries, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }
}

<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Dispensary\Services\DispensaryListService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Clinical\Dispensary\Services\OtcDispensaryService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Models\Panel;
use App\Domain\Visit\Services\VisitReasonService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DoctorCheckFixtures;

class DispensaryRowContextTest extends ClinicalTestCase
{
    use DoctorCheckFixtures;

    public function test_reason_and_coverage_use_existing_snapshots_and_never_expose_panel_notes_or_demographics(): void
    {
        $ca = $this->actor('ca');
        $reasons = collect(range(1, 5))->map(fn ($n) => app(VisitReasonService::class)->create($ca, 'Synthetic saved reason '.$n.' with longer descriptive text'));
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'name' => 'Synthetic saved panel']);
        $visit = $this->register($ca, $this->patient($ca), ['visit_reason_public_ids' => $reasons->pluck('public_id')->all(), 'coverage_type' => 'panel', 'panel_id' => $panel->id, 'coverage_panel_note' => 'Private synthetic note must stay absent', 'panel_note_panel_id' => $panel->id]);
        app(OtcDispensaryService::class)->open($ca, $visit, ['expected_branch_id' => $this->branch->id]);
        $saved = $reasons->pluck('name')->join(' · ');
        foreach ($reasons as $reason) {
            $reason->forceFill(['name' => 'Renamed current catalogue '.$reason->id])->save();
        }
        $panel->forceFill(['name' => 'Renamed live panel'])->save();
        $row = app(DispensaryListService::class)->page($ca, 'prepare', 1, '')['rows'][0];
        $this->assertSame(['visitReasonExcerpt' => Str::limit($saved, 80), 'coverageLabel' => 'Synthetic saved panel'], $row['context']);
        $this->assertStringNotContainsString('Private synthetic note', json_encode($row, JSON_THROW_ON_ERROR));
        foreach (['sex', 'dateOfBirth', 'allergies', 'phone', 'panelNote'] as $key) {
            $this->assertArrayNotHasKey($key, $row);
        }
        $this->actingAs($ca)->get(route('dispensary.index'))->assertOk();
    }

    public function test_legacy_and_absent_reason_remain_truthful_waiting_has_context_done_does_not_and_scope_is_unchanged(): void
    {
        $done = $this->prepared();
        app(DispensaryService::class)->complete($done['ca'], $done['case']->refresh(), ['expected_branch_id' => $this->branch->id, 'case_lock_version' => $done['case']->lock_version]);
        $waiting = $this->prepared();
        $this->switchOn($this->branch);
        app(DispensaryService::class)->sendForDoctorCheck($waiting['ca'], $waiting['case']->refresh(), ['expected_branch_id' => $this->branch->id, 'case_lock_version' => $waiting['case']->lock_version, 'retry_key' => (string) Str::uuid()]);
        $ca = $waiting['ca'];
        $visit = $this->register($ca, $this->patient($ca));
        app(OtcDispensaryService::class)->open($ca, $visit, ['expected_branch_id' => $this->branch->id]);
        // Explicit legacy read fixture: the old string exists without structured assignments.
        DB::table('visit_reason_assignments')->where('visit_id', $visit->id)->delete();
        $visit->forceFill(['visit_reason' => 'Synthetic legacy reason'])->save();
        $list = app(DispensaryListService::class);
        $this->assertSame(['visitReasonExcerpt' => 'Synthetic legacy reason', 'coverageLabel' => 'Self-pay'], $list->page($ca, 'prepare', 1, '')['rows'][0]['context']);
        $visit->forceFill(['visit_reason' => null])->save();
        $this->assertNull($list->page($ca, 'prepare', 1, '')['rows'][0]['context']['visitReasonExcerpt']);
        $this->assertArrayHasKey('context', $list->page($ca, 'check', 1, '')['rows'][0]);
        $this->assertArrayNotHasKey('context', $list->page($ca, 'done', 1, '')['rows'][0]);
        $other = Branch::where('organisation_id', $this->organisation->id)->whereKeyNot($this->branch->id)->firstOrFail();
        $otherCa = $this->actor('ca', $other);
        $this->selectBranch($otherCa, $other);
        $this->assertSame([], $list->page($otherCa, 'prepare', 1, '')['rows']);
        $finance = $this->actor('finance_officer');
        $this->actingAs($finance)->get(route('dispensary.index'))->assertForbidden();
    }

    public function test_twenty_five_cases_with_five_reasons_each_use_constant_queries_not_nested_lazy_loads(): void
    {
        $ca = $this->actor('ca');
        $reasons = collect(range(1, 5))->map(fn ($n) => app(VisitReasonService::class)->create($ca, 'Synthetic bounded reason '.$n));
        $visits = [];
        for ($i = 0; $i < 26; $i++) {
            $visit = $this->register($ca, $this->patient($ca), ['visit_reason_public_ids' => $reasons->pluck('public_id')->all()]);
            app(OtcDispensaryService::class)->open($ca, $visit, ['expected_branch_id' => $this->branch->id]);
            $visits[] = $visit;
        }
        $list = app(DispensaryListService::class);
        $list->page($ca, 'prepare', 1, '');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $single = $list->page($ca, 'prepare', 1, $visits[0]->visit_number);
        $count = count(DB::getQueryLog());
        DB::flushQueryLog();
        $page = $list->page($ca, 'prepare', 1, '');
        $this->assertSame($count, count(DB::getQueryLog()));
        DB::disableQueryLog();
        $this->assertSame(1, $single['total']);
        $this->assertSame([26, 25, 2], [$page['total'], count($page['rows']), $page['lastPage']]);
        foreach ($page['rows'] as $row) {
            $this->assertSame(Str::limit($reasons->pluck('name')->join(' · '), 80), $row['context']['visitReasonExcerpt']);
        }
        $this->assertSame(130, DB::table('visit_reason_assignments')->count());
    }
}

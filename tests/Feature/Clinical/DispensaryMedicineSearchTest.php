<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Dispensary\Services\DispensaryHandoffService;
use App\Domain\Clinical\Dispensary\Services\DispensaryListService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Clinical\Services\TreatmentPlanService;
use App\Domain\Organisation\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DoctorCheckFixtures;
use Tests\Support\OtcDispensaryFixtures;

class DispensaryMedicineSearchTest extends ClinicalTestCase
{
    use DoctorCheckFixtures, OtcDispensaryFixtures;

    public function test_otc_snapshot_names_are_escaped_and_catalogue_renames_and_removed_lines_do_not_match(): void
    {
        $f = $this->fixture();
        $f['medicine']->forceFill(['display_name' => 'Literal 25%_\\ remedy'])->save();
        $case = $this->open($f);
        $item = app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case));
        $service = app(DispensaryListService::class);
        foreach (['25%', '%_', '_\\', 'REMEDY'] as $term) {
            $this->assertSame([$case->public_id], array_column($service->page($f['ca'], 'prepare', 1, $term)['rows'], 'publicId'));
        }
        $f['medicine']->forceFill(['display_name' => 'Renamed catalogue only'])->save();
        $this->assertSame(0, $service->page($f['ca'], 'prepare', 1, 'Renamed catalogue')['total']);
        $this->assertSame(1, $service->page($f['ca'], 'prepare', 1, 'remedy')['total']);
        $this->assertSame(0, $service->page($f['ca'], 'prepare', 1, 'remedy', 'consultation')['total']);
        $this->assertSame(1, $service->page($f['ca'], 'prepare', 1, 'remedy', 'otc')['total']);
        $this->assertSame(0, $service->page($f['ca'], 'prepare', 1, 'One tablet')['total']);
        app(DispensaryService::class)->removeItem($f['ca'], $case->refresh(), $item, ['expected_branch_id' => $this->branch->id, 'case_lock_version' => $case->lock_version, 'item_lock_version' => $item->lock_version]);
        $this->assertSame(0, $service->page($f['ca'], 'prepare', 1, 'remedy')['total']);
        $this->assertSame(1, $service->page($f['ca'], 'prepare', 1, 'remedy')['counts']['prepare']);
    }

    public function test_latest_attempt_is_selected_before_medicine_matching_not_the_latest_matching_attempt(): void
    {
        $f = $this->prepared();
        $service = app(DispensaryService::class);
        $case = $service->returnToDoctor($f['ca'], $f['case']->refresh(), ['expected_branch_id' => $this->branch->id, 'case_lock_version' => $f['case']->lock_version]);
        $medicine = $f['medicine']->replicate();
        $medicine->forceFill(['public_id' => (string) Str::uuid(), 'code' => 'SYN-NEW-'.Str::upper(Str::random(6)), 'display_name' => 'Current handoff remedy'])->save();
        $this->selectBranch($f['doctor'], $this->branch);
        $plan = app(TreatmentPlanService::class)->save($f['doctor'], $f['visit']->refresh(), [
            'expected_branch_id' => $this->branch->id, 'lock_version' => $case->treatmentPlan->lock_version, 'services' => [], 'medicines' => [[
                'public_id' => null, 'catalogue_public_id' => $medicine->public_id, 'quantity_ordered' => 2, 'dosage' => 'Synthetic dosage', 'frequency' => 'Synthetic frequency',
                'duration' => null, 'route' => null, 'administration_instruction' => null, 'indication' => null, 'precaution' => null,
            ]],
        ]);
        app(DispensaryHandoffService::class)->send($f['doctor'], $f['visit']->refresh(), ['expected_branch_id' => $this->branch->id, 'lock_version' => $plan->lock_version]);
        $this->selectBranch($f['ca'], $this->branch);
        $list = app(DispensaryListService::class);
        $this->assertSame(0, $list->page($f['ca'], 'prepare', 1, 'Synthetic medicine')['total']);
        $this->assertSame(1, $list->page($f['ca'], 'prepare', 1, 'Current handoff')['total']);
        $this->assertSame(1, $list->page($f['doctor'], 'prepare', 1, 'Current handoff')['total']);
        $this->assertSame([1, 2], $case->handoffs()->pluck('attempt_number')->all());
        $this->assertSame(2, $case->latestHandoff->attempt_number);
    }

    public function test_patient_name_or_medicine_match_stays_inside_branch_scope_and_adds_no_queries(): void
    {
        $f = $this->prepared();
        $other = $this->fixture();
        $other['medicine']->forceFill(['display_name' => 'Distinct sharedmarker remedy'])->save();
        $case = $this->open($other);
        app(DispensaryService::class)->addItem($other['ca'], $case, $this->addPayload($other, $case));
        $f['visit']->patient->forceFill(['full_name' => 'Sharedmarker Synthetic Patient', 'search_name' => 'sharedmarker synthetic patient'])->save();
        $list = app(DispensaryListService::class);
        $found = $list->page($f['ca'], 'prepare', 1, 'sharedmarker');
        $this->assertEqualsCanonicalizing([$f['case']->public_id, $case->public_id], array_column($found['rows'], 'publicId'));
        $otherBranch = Branch::where('organisation_id', $this->organisation->id)->whereKeyNot($this->branch->id)->firstOrFail();
        $otherCa = $this->actor('ca', $otherBranch);
        $this->selectBranch($otherCa, $otherBranch);
        $this->assertSame(0, $list->page($otherCa, 'prepare', 1, 'sharedmarker')['total']);
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance, $this->branch);
        $this->actingAs($finance)->get(route('dispensary.index', ['search' => 'sharedmarker']))->assertForbidden();
        // Warm the same actor's lazy role/branch relations for equivalent query-count samples.
        $list->page($f['ca'], 'prepare', 1, '');
        $list->page($f['ca'], 'prepare', 1, 'sharedmarker');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $list->page($f['ca'], 'prepare', 1, '');
        $allQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        $list->page($f['ca'], 'prepare', 1, 'sharedmarker');
        $filteredQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($allQueries, $filteredQueries);
    }

    public function test_exact_identifier_search_takes_precedence_over_matching_medicine_snapshot_text(): void
    {
        $f = $this->fixture();
        $f['medicine']->forceFill(['display_name' => 'Identifier KP-99999999 KPV-99999999 A-999 B-999'])->save();
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case));
        $list = app(DispensaryListService::class);
        $this->assertSame(1, $list->page($f['ca'], 'prepare', 1, 'Identifier')['total']);
        foreach (['KP-99999999', 'KPV-99999999', 'A-999', 'B-999'] as $identifier) {
            $this->assertSame(0, $list->page($f['ca'], 'prepare', 1, $identifier)['total']);
        }
    }
}

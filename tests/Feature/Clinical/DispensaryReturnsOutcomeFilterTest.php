<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Dispensary\Models\DispensaryReturn;
use App\Domain\Clinical\Dispensary\Services\DispensaryReturnService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Organisation\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\OtcDispensaryFixtures;
use Tests\Support\ReturnsLocationFixture;

class DispensaryReturnsOutcomeFilterTest extends VisitTestCase
{
    use OtcDispensaryFixtures;

    /** @return array<string, mixed> */
    private function completed(bool $controlled = false): array
    {
        $f = $this->fixture();
        DB::table('medicine_catalogue_items')->where('id', $f['medicine']->id)->update(['is_controlled' => $controlled]);
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case, '3.000'));
        $this->confirmAllergy($f, $case->refresh());
        $case = $this->complete($f, $case)->refresh();
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor, $f['visit']->branch);

        return ['f' => $f, 'case' => $case, 'item' => $case->handoffs()->sole()->items()->sole(), 'supervisor' => $supervisor];
    }

    /** @param array<string, mixed> $w */
    private function record(array $w, ?string $outcome, string $quantity = '1.000'): DispensaryReturn
    {
        $service = app(DispensaryReturnService::class);
        $return = $service->record($w['f']['ca'], $w['case'], $w['item']->public_id, [
            'expected_branch_id' => $w['f']['visit']->branch_id, 'retry_key' => (string) Str::uuid(),
            'expected_return_count' => DispensaryReturn::where('dispensary_item_id', $w['item']->id)->count(),
            'quantity' => $quantity, 'reason' => 'other', 'note' => 'Synthetic private return note', 'condition' => 'sealed_in_date',
        ]);
        if ($outcome !== null) {
            $location = $outcome === 'quarantine' ? ReturnsLocationFixture::create($w['supervisor'], $w['f']['visit']->branch) : null;
            $service->check($w['supervisor'], $w['case'], $return->public_id, [
                'expected_branch_id' => $w['f']['visit']->branch_id, 'retry_key' => (string) Str::uuid(),
                'outcome' => $outcome, 'refuse_reason' => $outcome === 'refuse' ? 'other' : null,
                'refuse_note' => $outcome === 'refuse' ? 'Synthetic private check note' : null,
                'location_public_id' => $location?->public_id,
                'batch_public_id' => $outcome === 'quarantine' ? $w['f']['batch']->public_id : null,
            ]);
        }

        return $return;
    }

    public function test_each_real_outcome_filters_the_checked_rows_and_counts_without_leaking_notes(): void
    {
        $w = $this->completed();
        $ids = [];
        foreach (['refuse', 'dispose', 'quarantine'] as $outcome) {
            $ids[$outcome] = $this->record($w, $outcome)->public_id;
        }
        $waiting = $this->record($w, null);
        $this->actingAs($w['supervisor']);
        foreach ($ids as $outcome => $id) {
            $response = $this->get(route('dispensary.returns-to-check', ['view' => 'checked', 'outcome' => $outcome]))->assertOk();
            $response->assertInertia(fn (Assert $page) => $page
                ->where('returns.outcome', $outcome)->where('returns.total', 1)
                ->where('returns.counts', ['waiting' => 1, 'checked' => 1])
                ->where('returns.rows.0.publicId', $id)->where('returns.rows.0.outcome', $outcome));
            $this->assertStringNotContainsString('Synthetic private', (string) $response->getContent());
        }
        $this->get(route('dispensary.returns-to-check', ['outcome' => 'dispose']))->assertInertia(fn (Assert $page) => $page
            ->where('returns.outcome', 'all')->where('returns.total', 1)->where('returns.rows.0.publicId', $waiting->public_id)
            ->where('returns.counts', ['waiting' => 1, 'checked' => 3]));
        $this->assertSame(1, app(DispensaryReturnService::class)->waitingTotal($w['supervisor']));
    }

    public function test_controlled_filter_combines_with_outcome_and_invalid_queries_normalize_without_authority_changes(): void
    {
        $controlled = $this->completed(true);
        $target = $this->record($controlled, 'dispose');
        $plain = $this->completed();
        $this->record($plain, 'dispose');
        $this->actingAs($plain['supervisor']);
        $this->get(route('dispensary.returns-to-check', ['view' => 'checked', 'outcome' => 'dispose', 'controlled' => 'yes']))->assertInertia(fn (Assert $page) => $page
            ->where('returns.total', 1)->where('returns.rows.0.publicId', $target->public_id));
        foreach (['unknown', ['dispose']] as $outcome) {
            $this->get(route('dispensary.returns-to-check', ['view' => 'checked', 'outcome' => $outcome]))->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('returns.outcome', 'all')->where('returns.total', 2));
        }
        $this->actingAs($plain['f']['ca'])->get(route('dispensary.returns-to-check', ['view' => 'checked', 'outcome' => 'dispose']))->assertForbidden();
    }

    public function test_filtered_pagination_clamps_and_adds_no_queries_for_equivalent_in_range_pages(): void
    {
        $w = $this->completed();
        for ($i = 0; $i < 26; $i++) {
            $this->record($w, 'dispose', '0.100');
        }
        $service = app(DispensaryReturnService::class);
        $first = $service->waitingList($w['supervisor'], 1, 'checked', outcome: 'dispose');
        $last = $service->waitingList($w['supervisor'], 999, 'checked', outcome: 'dispose');
        $this->assertSame([26, 2, 25], [$first['total'], $first['lastPage'], count($first['rows'])]);
        $this->assertSame([2, 1], [$last['page'], count($last['rows'])]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $service->waitingList($w['supervisor'], 1, 'checked');
        $allQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        $service->waitingList($w['supervisor'], 1, 'checked', outcome: 'dispose');
        $filteredQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($allQueries, $filteredQueries);
    }

    public function test_outcome_filter_retains_branch_local_half_open_month_and_active_branch_scope(): void
    {
        $start = CarbonImmutable::now($this->branch->timezone)->startOfMonth();
        $included = null;
        foreach ([$start->subSecond(), $start, $start->addMonth()] as $at) {
            $this->travelTo($at->utc());
            $w = $this->completed();
            $row = $this->record($w, 'refuse');
            if ($at->equalTo($start)) {
                $included = $row->public_id;
            }
        }
        $this->travelBack();
        $original = $this->branch;
        $this->branch = Branch::where('organisation_id', $original->organisation_id)->whereKeyNot($original->id)->firstOrFail();
        $other = $this->completed();
        $this->record($other, 'refuse');
        $this->branch = $original;
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor, $original);
        $rows = app(DispensaryReturnService::class)->waitingList($supervisor, 1, 'checked', outcome: 'refuse');
        $this->assertSame(1, $rows['total']);
        $this->assertNotNull($included);
        $this->assertSame($included, $rows['rows'][0]['publicId']);
    }
}

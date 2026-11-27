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

class DispensaryReturnsAgeFilterTest extends VisitTestCase
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

    public function test_exact_branch_calendar_days_use_half_open_utc_edges_and_all_retains_older_returns(): void
    {
        $clock = CarbonImmutable::parse('2026-12-01 00:05:00', $this->branch->timezone);
        $start = $clock->startOfDay();
        $this->travelTo($start->subDays(4)->utc());
        try {
            $w = $this->completed();
            $expected = ['today' => [], 'yesterday' => [], '2days' => [], '3days' => []];
            foreach ([
                [$start->subDays(4)->addHour(), null],
                [$start->subDays(3), '3days'],
                [$start->subDays(2)->subSecond(), '3days'],
                [$start->subDays(2), '2days'],
                [$start->subDay(), 'yesterday'],
                [$start->subSecond(), 'yesterday'],
                [$start, 'today'],
                [$start->addDay()->subSecond(), 'today'],
                [$start->addDay(), null],
            ] as [$at, $age]) {
                $this->travelTo($at->utc());
                $record = $this->record($w, null, '0.100');
                if ($age !== null) {
                    $expected[$age][] = $record->public_id;
                }
            }
            $this->travelTo($clock->utc());
            $this->record($w, 'refuse', '0.100');
            $service = app(DispensaryReturnService::class);
            foreach ($expected as $age => $ids) {
                $result = $service->waitingList($w['supervisor'], 1, waitingAge: $age);
                $this->assertSame($age, $result['waitingAge']);
                $this->assertSame($ids, array_column($result['rows'], 'publicId'));
                $this->assertSame(['waiting' => count($ids), 'checked' => 1], $result['counts']);
            }
            $this->assertSame(9, $service->waitingTotal($w['supervisor']));
            $this->assertSame(9, $service->waitingList($w['supervisor'], 1)['total']);
            $checked = $service->waitingList($w['supervisor'], 1, 'checked', waitingAge: '3days');
            $this->assertSame('all', $checked['waitingAge']);
            $this->assertSame(['waiting' => 9, 'checked' => 1], $checked['counts']);
            $this->assertSame('2026-11-30 16:00:00', $start->utc()->format('Y-m-d H:i:s'));
        } finally {
            $this->travelBack();
        }
    }

    public function test_age_and_controlled_filters_normalize_queries_and_preserve_branch_and_role_denials(): void
    {
        $controlled = $this->completed(true);
        $target = $this->record($controlled, null);
        $plain = $this->completed();
        $this->record($plain, null);
        $this->actingAs($plain['supervisor']);
        $this->get(route('dispensary.returns-to-check', ['waiting_age' => 'today', 'controlled' => 'yes']))->assertInertia(fn (Assert $page) => $page
            ->where('returns.waitingAge', 'today')->where('returns.total', 1)->where('returns.rows.0.publicId', $target->public_id));
        foreach (['unknown', ['today']] as $age) {
            $this->get(route('dispensary.returns-to-check', ['waiting_age' => $age]))->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('returns.waitingAge', 'all')->where('returns.total', 2));
        }
        $other = Branch::where('organisation_id', $this->organisation->id)->whereKeyNot($this->branch->id)->firstOrFail();
        $otherSupervisor = $this->actor('ca_supervisor', $other);
        $this->selectBranch($otherSupervisor, $other);
        $this->actingAs($otherSupervisor)->get(route('dispensary.returns-to-check', ['waiting_age' => 'today']))->assertInertia(fn (Assert $page) => $page->where('returns.total', 0));
        $this->actingAs($plain['f']['ca'])->get(route('dispensary.returns-to-check', ['waiting_age' => 'today']))->assertForbidden();
    }

    public function test_age_filtered_pagination_clamps_and_adds_no_queries(): void
    {
        $w = $this->completed();
        for ($i = 0; $i < 26; $i++) {
            $this->record($w, null, '0.100');
        }
        $service = app(DispensaryReturnService::class);
        $first = $service->waitingList($w['supervisor'], 1, waitingAge: 'today');
        $last = $service->waitingList($w['supervisor'], 999, waitingAge: 'today');
        $this->assertSame([26, 2, 25], [$first['total'], $first['lastPage'], count($first['rows'])]);
        $this->assertSame([2, 1], [$last['page'], count($last['rows'])]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $service->waitingList($w['supervisor'], 1);
        $all = count(DB::getQueryLog());
        DB::flushQueryLog();
        $service->waitingList($w['supervisor'], 1, waitingAge: 'today');
        $filtered = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($all, $filtered);
    }
}

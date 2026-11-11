<?php

namespace Tests\Feature;

use App\Domain\Clinical\Dispensary\Services\OtcDispensaryService;
use App\Domain\Queue\Services\OtcQueueService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/** DS-3: the Pharmacy space shows only what the person's own Dispensary pages already show. */
class PharmacySpaceTest extends VisitTestCase
{
    public function test_a_ca_sees_the_dispensary_widget_but_not_returns_to_check(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);

        $this->get('/spaces/pharmacy')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Spaces/Show')
            ->where('pharmacy.toDispense.total', 0)
            ->where('pharmacy.toDispense.rows', [])
            ->where('pharmacy.returnsToCheck', null));
    }

    public function test_a_ca_supervisor_also_sees_returns_to_check(): void
    {
        $sup = $this->actor('ca_supervisor');
        $this->selectBranch($sup);

        $this->get('/spaces/pharmacy')->assertInertia(fn (Assert $p) => $p
            ->where('pharmacy.returnsToCheck.total', 0)
            ->has('pharmacy.links', 3));
    }

    public function test_the_director_gets_a_read_only_stock_watch_but_not_the_dispensary_widgets(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);

        $this->get('/spaces/pharmacy')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('pharmacy.toDispense', null)
            ->where('pharmacy.returnsToCheck', null)
            ->has('pharmacy.stockWatch')
            ->where('pharmacy.links', [['title' => 'Stock review', 'href' => '/inventory/stock-review']]));
    }

    public function test_roles_without_dispensary_are_refused_and_other_spaces_carry_no_pharmacy_data(): void
    {
        foreach (['resident_doctor', 'panel_officer', 'finance_officer', 'marketing'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->get('/spaces/pharmacy')->assertForbidden();
        }
        $this->selectBranch($this->actor('ca'));
        $this->get('/spaces/front_desk')->assertInertia(fn (Assert $p) => $p->where('pharmacy', null));
    }

    public function test_the_list_shows_queue_numbers_short_names_a_cap_and_counts_that_match(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $names = ['Ahmad Ismail bin Ali', 'Nurul Huda binti Hassan', 'Tan Mei Ling', 'Siva Kumar a/l Raju', 'Aisyah Rahman', 'Lim Wei Jie'];
        foreach ($names as $i => $name) {
            $visit = $this->register($ca, $this->patient($ca, ['full_name' => $name]));
            app(OtcQueueService::class)->enter($ca, $visit, ['expected_branch_id' => $this->branch->id]);
            $case = app(OtcDispensaryService::class)->open($ca, $visit, ['expected_branch_id' => $this->branch->id]);
            DB::table('dispensary_cases')->where('id', $case->id)->update(['received_at' => now()->utc()->subMinutes(10 * ($i + 1))]);
        }

        $response = $this->get('/spaces/pharmacy')->assertOk();
        $props = $response->viewData('page')['props']['pharmacy']['toDispense'];
        $this->assertSame(6, $props['total']);
        $this->assertCount(5, $props['rows']);
        $this->assertSame('B-006', $props['rows'][0]['queueNumber']);
        $minutes = array_column($props['rows'], 'minutes');
        $sorted = $minutes;
        rsort($sorted);
        $this->assertSame($sorted, $minutes, 'longest waiting first');
        $this->assertNotContains('B-001', array_column($props['rows'], 'queueNumber'));
        $this->assertSame('Lim W.', $props['rows'][0]['patient']);
        foreach ($props['rows'] as $row) {
            $this->assertMatchesRegularExpression('/^B-\d{3}$/', $row['queueNumber']);
            $this->assertStringNotContainsString('Ismail', $row['patient']);
            $this->assertSame(['visitNumber', 'queueNumber', 'patient', 'minutes', 'medicineCount', 'href'], array_keys($row));
        }
    }

    public function test_the_longest_waiting_patients_show_even_when_more_than_one_board_page_waits(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        foreach (range(1, 27) as $i) {
            $visit = $this->register($ca, $this->patient($ca, ['full_name' => "Synthetic Patient $i"]));
            app(OtcQueueService::class)->enter($ca, $visit, ['expected_branch_id' => $this->branch->id]);
            $case = app(OtcDispensaryService::class)->open($ca, $visit, ['expected_branch_id' => $this->branch->id]);
            DB::table('dispensary_cases')->where('id', $case->id)->update(['received_at' => now()->utc()->subMinutes(3 * $i)]);
        }

        $props = $this->get('/spaces/pharmacy')->assertOk()->viewData('page')['props']['pharmacy']['toDispense'];
        $this->assertSame(27, $props['total']);
        $this->assertCount(5, $props['rows']);
        $this->assertSame([81, 78, 75, 72, 69], array_column($props['rows'], 'minutes'));
    }
}

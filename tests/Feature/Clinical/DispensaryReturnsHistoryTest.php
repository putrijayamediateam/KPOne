<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryReturn;
use App\Domain\Clinical\Dispensary\Services\DispensaryReturnService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\OtcDispensaryFixtures;

/**
 * DSP-28 (owner, 25 Nov 2026, Clean Look gap list B3 and B5): Returns to check also lists the returns checked this
 * branch-local month, and both lists filter by controlled medicine. Read only. Synthetic data only.
 */
class DispensaryReturnsHistoryTest extends VisitTestCase
{
    use OtcDispensaryFixtures;

    /** @return array{f: array<string, mixed>, case: DispensaryCase, item: string, return: DispensaryReturn, supervisor: User} */
    private function waiting(bool $controlled): array
    {
        $f = $this->fixture();
        DB::table('medicine_catalogue_items')->where('id', $f['medicine']->id)->update(['is_controlled' => $controlled]);
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case, '3.000'));
        $this->confirmAllergy($f, $case->refresh());
        $case = $this->complete($f, $case)->refresh();
        $item = $case->handoffs()->sole()->items()->sole()->public_id;
        $return = app(DispensaryReturnService::class)->record($f['ca'], $case, $item, ['expected_branch_id' => $f['visit']->branch_id, 'retry_key' => (string) Str::uuid(), 'expected_return_count' => 0, 'quantity' => '2.000', 'reason' => 'other', 'note' => 'Synthetic private note', 'condition' => 'sealed_in_date']);
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor, $f['visit']->branch);

        return ['f' => $f, 'case' => $case, 'item' => $item, 'return' => $return, 'supervisor' => $supervisor];
    }

    /** @param array{f: array<string, mixed>, case: DispensaryCase, return: DispensaryReturn, supervisor: User} $w */
    private function refuse(array $w): void
    {
        app(DispensaryReturnService::class)->check($w['supervisor'], $w['case'], $w['return']->public_id, ['expected_branch_id' => $w['f']['visit']->branch_id, 'retry_key' => (string) Str::uuid(), 'outcome' => 'refuse', 'refuse_reason' => 'other', 'refuse_note' => 'Synthetic refuse note', 'location_public_id' => null, 'batch_public_id' => null]);
    }

    public function test_a_checked_return_moves_to_checked_this_month_with_its_outcome_and_checker(): void
    {
        $w = $this->waiting(controlled: true);
        $this->refuse($w);

        $response = $this->actingAs($w['supervisor'])->get(route('dispensary.returns-to-check', ['view' => 'checked']))->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('returns.view', 'checked')
            ->where('returns.counts', ['waiting' => 0, 'checked' => 1])
            ->where('returns.monthLabel', now($w['f']['visit']->branch->timezone)->format('F Y'))
            ->where('returns.rows.0.publicId', $w['return']->public_id)
            ->where('returns.rows.0.outcome', 'refuse')
            ->where('returns.rows.0.refuseReason', 'other')
            ->where('returns.rows.0.checkedBy', $w['supervisor']->name)
            ->where('returns.rows.0.checkedByMe', true)
            ->where('returns.rows.0.checkedAt', fn ($at) => is_string($at) && $at !== '')
            ->missing('returns.rows.0.note'));
        $this->assertStringNotContainsString('Synthetic refuse note', (string) $response->getContent());
        $this->assertStringNotContainsString('Synthetic private note', (string) $response->getContent());

        $this->get(route('dispensary.returns-to-check'))->assertInertia(fn (Assert $page) => $page->where('returns.view', 'waiting')->where('returns.total', 0));
        $this->assertSame(0, app(DispensaryReturnService::class)->waitingTotal($w['supervisor']));
    }

    public function test_checked_this_month_starts_at_branch_local_month_start(): void
    {
        $w = $this->waiting(controlled: false);
        $this->refuse($w);
        $lastMonth = now($w['f']['visit']->branch->timezone)->startOfMonth()->subMinute()->utc();
        DB::statement('ALTER TABLE dispensary_return_checks DISABLE TRIGGER USER');
        DB::table('dispensary_return_checks')->update(['checked_at' => $lastMonth]);
        DB::statement('ALTER TABLE dispensary_return_checks ENABLE TRIGGER USER');

        $this->actingAs($w['supervisor'])->get(route('dispensary.returns-to-check', ['view' => 'checked']))
            ->assertInertia(fn (Assert $page) => $page->where('returns.counts.checked', 0)->has('returns.rows', 0));
    }

    public function test_both_lists_filter_by_controlled_medicine(): void
    {
        $controlled = $this->waiting(controlled: true);
        $plain = $this->waiting(controlled: false);
        $this->actingAs($plain['supervisor']);

        $this->get(route('dispensary.returns-to-check', ['controlled' => 'yes']))->assertInertia(fn (Assert $page) => $page
            ->where('returns.controlled', 'yes')->where('returns.total', 1)->where('returns.rows.0.publicId', $controlled['return']->public_id));
        $this->get(route('dispensary.returns-to-check', ['controlled' => 'no']))->assertInertia(fn (Assert $page) => $page
            ->where('returns.total', 1)->where('returns.rows.0.publicId', $plain['return']->public_id));
        $this->get(route('dispensary.returns-to-check', ['controlled' => 'nonsense', 'view' => 'nonsense']))->assertInertia(fn (Assert $page) => $page
            ->where('returns.controlled', 'all')->where('returns.view', 'waiting')->where('returns.total', 2));

        $this->refuse($plain);
        $this->get(route('dispensary.returns-to-check', ['view' => 'checked', 'controlled' => 'yes']))->assertInertia(fn (Assert $page) => $page->where('returns.total', 0));
        $this->get(route('dispensary.returns-to-check', ['view' => 'checked', 'controlled' => 'no']))->assertInertia(fn (Assert $page) => $page->where('returns.total', 1));
    }

    public function test_the_checked_list_is_refused_to_those_who_may_not_check(): void
    {
        $w = $this->waiting(controlled: false);
        $ca = $w['f']['ca'];
        $this->selectBranch($ca, $w['f']['visit']->branch);

        $this->actingAs($ca)->get(route('dispensary.returns-to-check', ['view' => 'checked']))->assertForbidden();
    }
}

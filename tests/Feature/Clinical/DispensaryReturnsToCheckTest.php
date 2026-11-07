<?php

namespace Tests\Feature\Clinical;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryReturn;
use App\Domain\Clinical\Dispensary\Models\DispensaryReturnCheck;
use App\Domain\Clinical\Dispensary\Services\DispensaryReturnService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\OtcDispensaryFixtures;
use Tests\Support\ReturnsLocationFixture;

/**
 * DSP-7b (approved by B.E.R.N.I.S.S for the owner, 2026-11-06): a read-only branch list of returns waiting for a
 * check, for the people who may check them (v1: a CA Supervisor). The check itself stays on the case page.
 * Synthetic data only.
 */
class DispensaryReturnsToCheckTest extends VisitTestCase
{
    use OtcDispensaryFixtures;

    /** @return array{f: array<string, mixed>, case: DispensaryCase, item: string, return: DispensaryReturn, supervisor: User} */
    private function waiting(): array
    {
        $f = $this->fixture();
        DB::table('medicine_catalogue_items')->where('id', $f['medicine']->id)->update(['is_controlled' => true]);
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

    public function test_a_ca_supervisor_sees_waiting_returns_with_a_link_to_the_case_and_no_private_note(): void
    {
        $w = $this->waiting();
        $this->actingAs($w['supervisor'])->get(route('dispensary.returns-to-check'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dispensary/ReturnsToCheck')
                ->where('returns.total', 1)
                ->where('returns.rows.0.publicId', $w['return']->public_id)
                ->where('returns.rows.0.patient', $w['f']['visit']->patient->full_name)
                ->where('returns.rows.0.visitNumber', $w['f']['visit']->visit_number)
                ->where('returns.rows.0.quantity', '2')
                ->where('returns.rows.0.reason', 'other')
                ->where('returns.rows.0.controlled', true)
                ->where('returns.rows.0.recordedByMe', false)
                ->where('returns.rows.0.caseUrl', route('dispensary.show', $w['case'], absolute: false))
                ->missing('returns.rows.0.note'));
        $this->assertStringNotContainsString('Synthetic private note', (string) $this->get(route('dispensary.returns-to-check'))->getContent());
    }

    public function test_checked_returns_leave_the_list_and_the_recorder_is_told_another_supervisor_must_check(): void
    {
        $w = $this->waiting();
        $own = app(DispensaryReturnService::class)->record($w['supervisor'], $w['case'], $w['item'], ['expected_branch_id' => $w['f']['visit']->branch_id, 'retry_key' => (string) Str::uuid(), 'expected_return_count' => 1, 'quantity' => '1', 'reason' => 'not_used', 'note' => null, 'condition' => 'cannot_tell']);
        ReturnsLocationFixture::create($w['supervisor'], $w['f']['visit']->branch);
        app(DispensaryReturnService::class)->check($w['supervisor'], $w['case'], $w['return']->public_id, ['expected_branch_id' => $w['f']['visit']->branch_id, 'retry_key' => (string) Str::uuid(), 'outcome' => 'dispose', 'refuse_reason' => null, 'refuse_note' => null, 'location_public_id' => null, 'batch_public_id' => null]);
        $this->assertSame(1, DispensaryReturnCheck::query()->count());

        $this->actingAs($w['supervisor'])->get(route('dispensary.returns-to-check'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('returns.total', 1)
                ->where('returns.rows.0.publicId', $own->public_id)
                ->where('returns.rows.0.recordedByMe', true));
    }

    public function test_only_people_who_may_check_open_the_list_and_see_its_link(): void
    {
        $w = $this->waiting();
        foreach (['ca', 'finance_officer', 'director', 'resident_doctor', 'administrator'] as $role) {
            $actor = $role === 'ca' ? $w['f']['ca'] : $this->actor($role);
            $this->selectBranch($actor, $w['f']['visit']->branch);
            $this->actingAs($actor)->get(route('dispensary.returns-to-check'))->assertForbidden();
            $this->get(route('clinic-management.index'))->assertDontSee('dispensary/returns-to-check');
        }
        $this->actingAs($w['supervisor'])->get(route('clinic-management.index'))->assertSee('dispensary\/returns-to-check', false);
    }

    public function test_the_list_shows_only_the_active_branch_and_reading_it_writes_nothing(): void
    {
        $w = $this->waiting();
        $other = Branch::query()->whereKeyNot($w['f']['visit']->branch_id)->firstOrFail();
        $before = [DB::table('dispensary_returns')->count(), DB::table('dispensary_return_checks')->count(), DB::table('stock_movements')->count(), DB::table('audit_logs')->count()];
        $this->actingAs($w['supervisor'])->get(route('dispensary.returns-to-check'))->assertInertia(fn (Assert $page) => $page->where('returns.total', 1));
        $this->assertSame($before, [DB::table('dispensary_returns')->count(), DB::table('dispensary_return_checks')->count(), DB::table('stock_movements')->count(), DB::table('audit_logs')->count()]);

        // A CA Supervisor assigned to another branch, with that branch active, sees nothing from this one.
        $elsewhere = $this->actor('ca_supervisor', $other);
        $this->selectBranch($elsewhere, $other);
        $this->get(route('dispensary.returns-to-check'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('returns.total', 0)->where('returns.branchName', $other->name));
    }

    public function test_oldest_first_25_a_page_and_a_page_past_the_end_shows_the_last_page(): void
    {
        $w = $this->waiting();
        $service = app(DispensaryReturnService::class);
        for ($n = 1; $n <= 25; $n++) {
            $service->record($w['f']['ca'], $w['case'], $w['item'], ['expected_branch_id' => $w['f']['visit']->branch_id, 'retry_key' => (string) Str::uuid(), 'expected_return_count' => $n, 'quantity' => '0.010', 'reason' => 'not_used', 'note' => null, 'condition' => 'cannot_tell']);
        }
        $this->actingAs($w['supervisor'])->get(route('dispensary.returns-to-check'))->assertInertia(fn (Assert $page) => $page
            ->where('returns.total', 26)->where('returns.lastPage', 2)->has('returns.rows', 25)
            ->where('returns.rows.0.publicId', $w['return']->public_id));
        $this->get(route('dispensary.returns-to-check', ['page' => 2]))->assertInertia(fn (Assert $page) => $page->where('returns.page', 2)->has('returns.rows', 1));
        // After returns are checked, an old link to a page that no longer exists shows the last page, not "No returns".
        $this->get(route('dispensary.returns-to-check', ['page' => 9]))->assertInertia(fn (Assert $page) => $page->where('returns.page', 2)->has('returns.rows', 1));
        $this->get(route('dispensary.returns-to-check', ['page' => 'abc']))->assertInertia(fn (Assert $page) => $page->where('returns.page', 1));
    }

    public function test_a_ca_supervisor_of_another_organisation_never_sees_these_returns(): void
    {
        $w = $this->waiting();
        $foreign = Organisation::query()->create(['code' => 'DSP13_FOREIGN', 'name' => 'Synthetic Foreign Organisation']);
        DB::table('departments')->insert(['organisation_id' => $foreign->id, 'code' => 'DSP13_FOREIGN', 'name' => 'Synthetic Foreign Department', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $foreignBranch = (new Branch)->forceFill(['organisation_id' => $foreign->id, 'code' => 'DSP13_FOREIGN', 'name' => 'Synthetic Foreign Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true]);
        $foreignBranch->save();
        $outsider = $this->actor('ca_supervisor', $foreignBranch);
        $this->selectBranch($outsider, $foreignBranch);

        $this->get(route('dispensary.returns-to-check'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('returns.total', 0)->where('returns.branchName', $foreignBranch->name));

        // Pointing the session at this organisation's branch does not select it: the page stays on the outsider's
        // own branch, and no patient from this organisation reaches the page.
        session([BranchAccessService::SESSION_KEY => $w['f']['visit']->branch_id]);
        $response = $this->get(route('dispensary.returns-to-check'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('returns.total', 0)->where('returns.branchName', $foreignBranch->name));
        $this->assertStringNotContainsString($w['f']['visit']->patient->full_name, (string) $response->getContent());
    }

    public function test_a_recorder_the_viewer_may_not_see_shows_as_staff_member(): void
    {
        $w = $this->waiting();
        $this->actingAs($w['supervisor'])->get(route('dispensary.returns-to-check'))
            ->assertInertia(fn (Assert $page) => $page->where('returns.rows.0.recordedBy', $w['f']['ca']->name));

        // The recorder is no longer assigned to this branch, so a branch-level viewer may not see their name.
        DB::table('staff_branch_assignments')->where('staff_profile_id', $w['f']['ca']->staffProfile->id)
            ->update(['valid_until' => now()->subDays(2)->toDateString()]);
        $response = $this->get(route('dispensary.returns-to-check'))
            ->assertInertia(fn (Assert $page) => $page->where('returns.rows.0.recordedBy', 'Staff member'));
        $content = (string) $response->getContent();
        $this->assertStringNotContainsString(e($w['f']['ca']->name), $content);
        $this->assertStringNotContainsString(trim((string) json_encode($w['f']['ca']->name), '"'), $content);
    }

    public function test_an_inactive_ca_supervisor_is_refused(): void
    {
        $w = $this->waiting();
        $w['supervisor']->forceFill(['is_active' => false])->save();
        $response = $this->actingAs($w['supervisor'])->get(route('dispensary.returns-to-check'));
        // Inactive sessions are refused before the page (sign-out redirect) or by the service (403); never a list.
        $this->assertContains($response->status(), [302, 403]);
    }
}

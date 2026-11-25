<?php

namespace Tests\Feature\Clinical;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\DoctorCheckFixtures;

/**
 * DSP-27 (owner, 25 Nov 2026, Clean Look gap list B1 and B2): the branch's Dispensary list in three status tabs with
 * counts, read only.
 */
class DispensaryListTest extends ClinicalTestCase
{
    use DoctorCheckFixtures;

    /** @param array<string, mixed> $f */
    private function sendCase(array $f): void
    {
        $this->switchOn($f['visit']->branch);
        $this->selectBranch($f['ca'], $f['visit']->branch);
        app(DispensaryService::class)->sendForDoctorCheck($f['ca'], $f['case']->refresh(), [
            'expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $f['case']->refresh()->lock_version, 'retry_key' => (string) Str::uuid(),
        ]);
    }

    /** @param array<string, mixed> $f */
    private function completeCase(array $f): void
    {
        $this->selectBranch($f['ca'], $f['visit']->branch);
        app(DispensaryService::class)->complete($f['ca'], $f['case']->refresh(), ['expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $f['case']->refresh()->lock_version]);
    }

    private function listAs(User $actor, array $query = [], ?Branch $branch = null): TestResponse
    {
        $this->selectBranch($actor, $branch ?? $this->branch);

        return $this->actingAs($actor)->get(route('dispensary.index', $query));
    }

    public function test_each_case_sits_in_one_tab_with_the_counts(): void
    {
        $preparing = $this->prepared();
        $done = $this->prepared();
        $this->completeCase($done);
        $waiting = $this->prepared();
        $this->sendCase($waiting);

        $this->listAs($preparing['ca'])->assertOk()->assertInertia(fn (Assert $page) => $page->component('Dispensary/Index')
            ->where('list.tab', 'prepare')
            ->where('list.counts', ['prepare' => 1, 'check' => 1, 'done' => 1])
            ->has('list.rows', 1)
            ->where('list.rows.0.publicId', $preparing['case']->public_id)
            ->where('list.rows.0.status', DispensaryCase::STATUS_DISPENSING)
            ->where('list.rows.0.queueNumber', fn ($n) => is_string($n) && str_starts_with($n, 'A-'))
            ->where('list.rows.0.medicineCount', 1)
            ->where('list.rows.0.labelUrl', null)
            ->where('list.rows.0.caseUrl', route('dispensary.show', $preparing['case'])));

        $this->listAs($preparing['ca'], ['tab' => 'check'])->assertInertia(fn (Assert $page) => $page->has('list.rows', 1)
            ->where('list.rows.0.publicId', $waiting['case']->public_id));

        $this->listAs($preparing['ca'], ['tab' => 'done'])->assertInertia(fn (Assert $page) => $page->has('list.rows', 1)
            ->where('list.rows.0.publicId', $done['case']->public_id)
            ->where('list.rows.0.waitingMinutes', null)
            ->where('list.rows.0.completedAt', fn ($t) => is_string($t) && $t !== '')
            ->where('list.rows.0.labelUrl', route('dispensary.labels', $done['case']))
            ->where('list.rows.0.labelAction', 'reprint'));
    }

    public function test_done_today_counts_from_branch_local_midnight_only(): void
    {
        $done = $this->prepared();
        $this->completeCase($done);
        $yesterday = now($this->branch->timezone)->startOfDay()->subMinute()->utc();
        DB::table('dispensary_cases')->where('id', $done['case']->id)->update(['completed_at' => $yesterday]);

        $this->listAs($done['ca'], ['tab' => 'done'])->assertInertia(fn (Assert $page) => $page->where('list.counts.done', 0)->has('list.rows', 0));
    }

    public function test_search_finds_by_name_kp_number_and_visit_number(): void
    {
        $f = $this->prepared();
        $patient = $f['visit']->patient;

        foreach ([$patient->patient_number, Str::lower($f['visit']->visit_number), Str::substr($patient->full_name, 0, 6)] as $term) {
            $this->listAs($f['ca'], ['search' => $term])->assertInertia(fn (Assert $page) => $page->has('list.rows', 1)->where('list.search', $term));
        }
        $this->listAs($f['ca'], ['search' => 'zz-no-such-patient'])->assertInertia(fn (Assert $page) => $page->has('list.rows', 0)->where('list.counts.prepare', 1));
    }

    public function test_an_unknown_tab_falls_back_to_to_prepare(): void
    {
        $f = $this->prepared();

        $this->listAs($f['ca'], ['tab' => 'everything'])->assertInertia(fn (Assert $page) => $page->where('list.tab', 'prepare'));
    }

    public function test_the_doctor_sees_the_list_view_only_and_others_are_refused(): void
    {
        $done = $this->prepared();
        $this->completeCase($done);

        $this->listAs($done['doctor'], ['tab' => 'done'])->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('list.canPrintLabels', false)
            ->where('list.rows.0.labelAction', 'view'));

        foreach (['finance_officer', 'panel_officer', 'technical_admin', 'administrator', 'queue_display'] as $role) {
            $this->listAs($this->actor($role))->assertForbidden();
        }
    }

    public function test_only_the_active_branch_is_listed(): void
    {
        $f = $this->prepared();
        $puchong = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        $elsewhere = $this->actor('ca', $puchong);

        $this->listAs($elsewhere, [], $puchong)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('list.counts', ['prepare' => 0, 'check' => 0, 'done' => 0])
            ->has('list.rows', 0));
        $this->assertNotNull($f['case']);
    }

    public function test_opening_the_list_writes_nothing(): void
    {
        $f = $this->prepared();
        $before = [AuditLog::query()->count(), DB::table('dispensary_cases')->get()->toArray()];

        foreach (['prepare', 'check', 'done'] as $tab) {
            $this->listAs($f['ca'], ['tab' => $tab])->assertOk();
        }

        $this->assertEquals($before, [AuditLog::query()->count(), DB::table('dispensary_cases')->get()->toArray()]);
    }
}

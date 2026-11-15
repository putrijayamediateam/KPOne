<?php

namespace Tests\Feature\Agents;

use App\Domain\Agents\Assistant\DirectorAssistant;
use App\Domain\Agents\Models\AiAgentRun;
use App\Domain\Agents\Models\AiAgentTool;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Services\QueueEntryService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\KPOneAgentSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Queue\QueueTestCase;
use Tests\Support\GovernedStockFixture;
use Tests\Support\StaffBranchAssignmentBootstrapper;

/** AG-5: Dato' Arif answers the Director's preset questions, one logged run per answer, nothing stored or changed. */
class DirectorAssistantTest extends QueueTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-05T03:00:00Z'));
        $this->seed(KPOneAgentSeeder::class);
    }

    /** @param array<string, string> $query @return array<string, mixed> */
    private function page(User $director, array $query = []): array
    {
        $this->selectBranch($director);
        $props = [];
        $this->actingAs($director)->get('/agents/assistant'.($query === [] ? '' : '?'.http_build_query($query)))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$props): void {
                $page->component('Agents/Assistant');
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    /** Two consultation visits for one doctor at CHERAS, one of them called in. */
    private function clinicDay(): User
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $called = $this->consultationVisit($ca, $doctor);
        $queue = $this->send($ca, $called);
        $this->send($ca, $this->consultationVisit($ca, $doctor));
        $this->selectBranch($doctor);
        app(QueueEntryService::class)->call($doctor, $called->refresh(), ['expected_branch_id' => $this->branch->id, 'visit_lock_version' => $called->lock_version, 'queue_lock_version' => $queue->refresh()->lock_version]);

        return $doctor;
    }

    public function test_only_the_director_opens_the_page_and_sees_twelve_questions_in_four_groups(): void
    {
        $props = $this->page($this->actor('director'));

        $this->assertSame(['Clinic today', 'Money', 'Panel', 'Stock'], array_column($props['groups'], 'label'));
        $this->assertSame([3, 3, 3, 3], array_map(fn (array $group): int => count($group['questions']), $props['groups']));
        $this->assertNull($props['answer']);
        $this->assertSame(0, AiAgentRun::query()->count(), 'opening the page reads nothing');

        foreach (['ca_supervisor', 'administrator', 'finance_officer', 'technical_admin', 'ca'] as $role) {
            $this->actingAs($this->actor($role))->get('/agents/assistant?question=clinic.patients_seen')->assertForbidden();
        }
        $this->assertSame(0, AiAgentRun::query()->count());
    }

    public function test_patients_seen_is_one_logged_run_with_a_table_and_a_fixed_sentence(): void
    {
        $this->clinicDay();
        $director = $this->actor('director');

        $answer = $this->page($director, ['question' => 'clinic.patients_seen', 'branch' => 'CHERAS', 'date' => '2026-11-05'])['answer'];

        $this->assertSame('1 patient seen on 5 Nov 2026 at Cheras (2 registered).', $answer['sentence']);
        $this->assertSame([[['value' => 'Cheras', 'kind' => 'text'], ['value' => 2, 'kind' => 'count'], ['value' => 1, 'kind' => 'count']]], $answer['rows']);
        $run = AiAgentRun::query()->sole();
        $this->assertSame([$answer['runId'], 'completed', 'quick_answer', $director->id, $this->branch->id], [$run->id, $run->status, $run->task_type, $run->requested_by_user_id, $run->branch_id]);
        $this->assertSame(
            [['data_read', 'clinic.day_summary'], ['decision', null]],
            DB::table('ai_agent_run_steps')->where('ai_agent_run_id', $run->id)->orderBy('sequence')->get()->map(fn ($s): array => [$s->kind, $s->tool_code])->all(),
        );
        $this->assertSame('Answered: Patients seen, per branch.', DB::table('ai_agent_run_steps')->where('ai_agent_run_id', $run->id)->where('kind', 'decision')->value('summary'));
    }

    public function test_all_branches_lists_every_branch_with_a_total_and_doctors_by_name(): void
    {
        $doctor = $this->clinicDay();
        $director = $this->actor('director');

        $answer = $this->page($director, ['question' => 'clinic.patients_seen', 'branch' => 'all'])['answer'];
        $this->assertSame(['Cheras', 'Puchong', 'Sungai Besi'], array_map(fn (array $row): string => $row[0]['value'], $answer['rows']));
        $this->assertSame(['All branches', 2, 1], array_column($answer['totals'], 'value'));
        $this->assertSame('all branches', $answer['scope']['branch']);

        $doctors = $this->page($director, ['question' => 'clinic.seen_per_doctor', 'branch' => 'all'])['answer'];
        $this->assertSame([['Cheras', $doctor->name, 1]], array_map(fn (array $row): array => [$row[0]['value'], $row[1]['value'], $row[2]['value']], $doctors['rows']));
    }

    public function test_stock_reads_each_branch_the_director_works_at(): void
    {
        $f = (new GovernedStockFixture($this->branch))->build('10.000');
        DB::table('inventory_reorder_levels')->insert(['organisation_id' => $this->organisation->id, 'inventory_location_id' => $f['location']->id, 'inventory_sku_id' => $f['sku']->id, 'reorder_level' => '20.000', 'updated_by_user_id' => $f['proposer']->id, 'created_at' => now(), 'updated_at' => now()]);
        $director = $this->actor('director');

        $low = $this->page($director, ['question' => 'stock.low', 'branch' => 'all'])['answer'];
        $this->assertSame([['Cheras', 'Synthetic governed item', $f['sku']->sku_code, 1]], array_map(fn (array $row): array => array_column($row, 'value'), $low['rows']));
        $this->assertSame('1 item is at or below its reorder level at Cheras.', $low['sentence']);

        $expiring = $this->page($director, ['question' => 'stock.expiring', 'branch' => 'CHERAS'])['answer'];
        $this->assertSame('10.000 unit', $expiring['rows'][0][4]['value']);
        $this->assertSame('10.000', DB::table('inventory_stock_balances')->where('inventory_location_id', $f['location']->id)->value('quantity'), 'nothing in stock changes');
    }

    public function test_the_week_question_reads_this_week_and_the_same_days_last_week(): void
    {
        $director = $this->actor('director');

        $answer = $this->page($director, ['question' => 'money.week', 'branch' => 'CHERAS', 'date' => '2026-11-05'])['answer'];

        $this->assertSame(['branch' => 'Cheras', 'date' => null, 'from' => '2026-11-02', 'to' => '2026-11-05'], $answer['scope']);
        $this->assertSame('Net sales at Cheras from 2 Nov 2026 to 5 Nov 2026: RM 0.00, the same as the same days last week (26 Oct 2026 to 29 Oct 2026, RM 0.00).', $answer['sentence']);
        $this->assertSame(2, DB::table('ai_agent_run_steps')->where('kind', 'data_read')->where('tool_code', 'insights.sales_summary')->count());
    }

    public function test_money_and_panel_answers_work_with_no_money_yet(): void
    {
        $director = $this->actor('director');

        $this->assertSame('Net sales on 5 Nov 2026 at Cheras: RM 0.00. Received RM 0.00 (cash RM 0.00, non-cash RM 0.00); Panel RM 0.00 and pay later RM 0.00 are not received yet.', $this->page($director, ['question' => 'money.day'])['answer']['sentence']);
        $this->assertSame('RM 0.00 received on 5 Nov 2026 at Cheras through 0 payment methods; RM 0.00 of it in cash.', $this->page($director, ['question' => 'money.receipts_by_method'])['answer']['sentence']);
        foreach (['panel.unclaimed', 'panel.overdue', 'panel.accepted_unpaid'] as $question) {
            $this->assertSame('Nothing to show: every Panel is up to date.', $this->page($director, ['question' => $question])['answer']['sentence'], $question);
        }
        $this->assertSame(5, AiAgentRun::query()->where('status', 'completed')->count());
    }

    /** Give the user a second, non-primary branch assignment. */
    private function alsoAssign(User $user, string $code): void
    {
        StaffBranchAssignmentBootstrapper::create($user->staffProfile, $this->branchCode($code), ['is_primary' => false, 'assignment_type' => 'permanent', 'valid_from' => now()->subDay()->toDateString(), 'valid_until' => null]);
    }

    private function branchCode(string $code): Branch
    {
        return Branch::query()->where('organisation_id', $this->organisation->id)->where('code', $code)->firstOrFail();
    }

    public function test_branch_scoped_answers_cover_only_branches_the_director_works_at(): void
    {
        $director = $this->actor('director');
        $this->assertSame(['CHERAS'], array_column($this->page($director)['branches'], 'code'));

        $money = $this->page($director, ['question' => 'money.day', 'branch' => 'all'])['answer'];
        $this->assertSame(['Cheras'], array_map(fn (array $row): string => $row[0]['value'], $money['rows']));
        $this->assertSame('Cheras', $money['scope']['branch']);
        $panel = $this->page($director, ['question' => 'panel.unclaimed', 'branch' => 'all'])['answer'];
        $this->assertSame('Cheras', $panel['scope']['branch']);
        $this->assertSame(['CHERAS'], DB::table('ai_agent_run_steps')->where('tool_code', 'panel.claims_summary')->pluck('summary')->map(fn (string $s): string => str_replace('Read panel.claims_summary for ', '', $s))->all());

        $this->alsoAssign($director, 'PUCHONG');
        $this->assertSame(['CHERAS', 'PUCHONG'], array_column($this->page($director)['branches'], 'code'));
        $money = $this->page($director, ['question' => 'money.day', 'branch' => 'all'])['answer'];
        $this->assertSame(['Cheras', 'Puchong'], array_map(fn (array $row): string => $row[0]['value'], $money['rows']));
        $this->assertSame(['All branches'], [$money['totals'][0]['value']]);
        $this->assertSame('Cheras, Puchong', $money['scope']['branch']);

        // The organisation-wide clinic figures still cover every branch.
        $this->assertCount(3, $this->page($director, ['question' => 'clinic.patients_seen', 'branch' => 'all'])['answer']['rows']);
        $runs = AiAgentRun::query()->count();
        $refused = $this->page($director, ['question' => 'money.day', 'branch' => 'SUNGAI_BESI']);
        $this->assertSame([null, 'Choose a branch you work at, or all branches.'], [$refused['answer'], $refused['error']]);
        $this->assertSame($runs, AiAgentRun::query()->count(), 'no run starts for a branch the Director does not work at');
    }

    public function test_bad_input_is_refused_before_any_run_starts(): void
    {
        $director = $this->actor('director');
        $cases = [
            [['question' => 'clinic.everything'], 'Choose one of the questions.'],
            [['question' => 'clinic.patients_seen', 'date' => '2026-11-06'], 'Choose a date from 2020 up to today.'],
            [['question' => 'clinic.patients_seen', 'date' => '2026-02-30'], 'Choose a date from 2020 up to today.'],
            [['question' => 'clinic.patients_seen', 'date' => '2019-12-31'], 'Choose a date from 2020 up to today.'],
            [['question' => 'clinic.patients_seen', 'branch' => 'NOWHERE'], 'Choose a branch you work at, or all branches.'],
        ];
        foreach ($cases as [$query, $message]) {
            $props = $this->page($director, $query);
            $this->assertSame([null, $message], [$props['answer'], $props['error']], json_encode($query));
        }
        $this->assertSame(0, AiAgentRun::query()->count());
    }

    public function test_a_read_that_is_refused_marks_the_run_failed_and_shows_why(): void
    {
        $director = $this->actor('director');
        $agentId = DB::table('ai_agents')->where('code', DirectorAssistant::AGENT)->value('id');
        AiAgentTool::query()->where('ai_agent_id', $agentId)->where('tool_code', 'panel.claims_summary')->delete();

        $props = $this->page($director, ['question' => 'panel.unclaimed']);

        $this->assertSame([null, 'This agent may not use that data source.'], [$props['answer'], $props['error']]);
        $run = AiAgentRun::query()->sole();
        $this->assertSame(['failed', 'quick_answer.failed'], [$run->status, $run->error_code]);
    }

    public function test_a_disabled_agent_answers_nothing(): void
    {
        DB::table('ai_agents')->where('code', DirectorAssistant::AGENT)->update(['is_enabled' => false]);

        $props = $this->page($this->actor('director'), ['question' => 'clinic.patients_seen']);

        $this->assertNull($props['answer']);
        $this->assertNotNull($props['error']);
        $this->assertSame(0, AiAgentRun::query()->count());
    }
}

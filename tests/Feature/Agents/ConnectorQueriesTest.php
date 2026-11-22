<?php

namespace Tests\Feature\Agents;

use App\Domain\Agents\Connector\ConnectorAgent;
use App\Domain\Agents\Connector\ConnectorQuery;
use App\Domain\Agents\Connector\ConnectorTools;
use App\Domain\Agents\Connector\Mcp\ConnectorServer;
use App\Domain\Agents\Connector\Queries\ShapesAnswers;
use App\Domain\Agents\Models\AiAgentTool;
use App\Domain\Agents\Tools\AgentToolRegistry;
use App\Domain\Agents\Tools\ClinicDaySummaryTool;
use App\Domain\Agents\Tools\MoneyDaySummaryTool;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Laravel\Passport\Passport;
use Tests\Feature\Visit\VisitTestCase;

/** CN-2: the seven connector questions that read existing data sources. */
class ConnectorQueriesTest extends VisitTestCase
{
    private const QUESTIONS = ['clinic_day_summary', 'money_day_summary', 'sales_summary', 'panel_claims_outstanding', 'stock_alerts', 'stock_balances_and_counts', 'system_health', 'visits_by_state', 'queue_now', 'panel_claims_made'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['agents.connector_enabled' => true]);
        $keys = sys_get_temp_dir().'/kpone-connector-keys-'.getmypid();
        if (! is_dir($keys)) {
            mkdir($keys, 0700, true);
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            file_put_contents($keys.'/oauth-private.key', $private);
            file_put_contents($keys.'/oauth-public.key', openssl_pkey_get_details($key)['key']);
            chmod($keys.'/oauth-private.key', 0600);
            chmod($keys.'/oauth-public.key', 0600);
        }
        Passport::loadKeysFrom($keys);
    }

    /** @param array<string, mixed> $arguments */
    private function ask(User $user, string $tool, array $arguments = [])
    {
        Passport::actingAs($user, ['mcp:use'], 'api');

        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]], [
            'Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-11-25', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => $tool,
        ]);
    }

    /** @return array<string, mixed> */
    private function answer(User $user, string $tool, array $arguments = []): array
    {
        $response = $this->ask($user, $tool, $arguments)->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        return json_decode((string) $response->json('result.content.0.text'), true);
    }

    private function refusal(User $user, string $tool, array $arguments = []): string
    {
        $response = $this->ask($user, $tool, $arguments)->assertOk();
        $this->assertTrue((bool) $response->json('result.isError'));

        return (string) $response->json('result.content.0.text');
    }

    public function test_the_server_lists_every_question_as_read_only(): void
    {
        $tools = (new \ReflectionClass(ConnectorServer::class))->getDefaultProperties()['tools'];
        $this->assertCount(11, $tools);
        foreach ($tools as $class) {
            $array = app($class)->toArray();
            $this->assertTrue($array['annotations']['readOnlyHint'] ?? false, $class);
        }
        $names = array_map(fn (string $class): string => app($class)->name(), $tools);
        $this->assertEqualsCanonicalizing(['list_branches', ...self::QUESTIONS], $names);
    }

    public function test_the_connector_agent_allowlist_is_exactly_the_connectors_sources_and_all_are_known(): void
    {
        $agent = ConnectorAgent::ensure($this->organisation->id);

        $this->assertEqualsCanonicalizing(ConnectorTools::codes(), AiAgentTool::query()->where('ai_agent_id', $agent->id)->pluck('tool_code')->all());
        $known = array_keys(app(AgentToolRegistry::class)->all());
        foreach (ConnectorTools::queries() as $class) {
            $query = app($class);
            $this->assertInstanceOf(ConnectorQuery::class, $query);
            $this->assertNotSame('', $query->definitions());
            foreach ($query->sources() as $source) {
                $this->assertContains($source, $known);
            }
        }
    }

    public function test_every_question_answers_for_the_director_with_definitions_and_no_personal_keys(): void
    {
        $director = $this->actor('director');
        foreach (self::QUESTIONS as $tool) {
            $answer = $this->answer($director, $tool);
            $this->assertArrayHasKey('definitions', $answer, $tool);
            $this->assertArrayHasKey('asOf', $answer, $tool);
            $this->assertTrue(isset($answer['branches']) || isset($answer['health']), $tool);
        }
    }

    public function test_clinic_day_summary_has_no_doctor_rows_and_matches_its_source(): void
    {
        $director = $this->actor('director');

        $entry = $this->answer($director, 'clinic_day_summary')['branches'][0];
        $source = app(ClinicDaySummaryTool::class)->run($director, $this->branch, [])['branches'][0];

        $this->assertArrayNotHasKey('doctors', $entry);
        unset($source['doctors']);
        $this->assertSame($source, $entry);
    }

    public function test_money_day_summary_matches_its_source(): void
    {
        $director = $this->actor('director');
        $entry = $this->answer($director, 'money_day_summary', ['date' => now()->toDateString()])['branches'][0];
        $source = app(MoneyDaySummaryTool::class)->run($director, $this->branch, ['date' => now()->toDateString()])['branches'][0];

        foreach (['netSalesSen', 'discountsSen', 'cashNetSen', 'nonCashNetSen', 'totalReceivedSen', 'panelApprovedSen', 'payLaterDueSen'] as $key) {
            $this->assertSame($source[$key], $entry[$key], $key);
        }
    }

    public function test_only_branches_the_director_works_at_are_answered_and_another_branch_is_refused(): void
    {
        $director = $this->actor('director');
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();

        $this->assertSame(['CHERAS'], array_column($this->answer($director, 'stock_alerts')['branches'], 'branch'));
        $this->assertStringContainsString('not available', $this->refusal($director, 'stock_alerts', ['branch' => $other->code]));
        $this->assertSame('branch_not_allowed', AuditLog::query()->where('event', 'ai_connector.tool_called')->latest('id')->firstOrFail()->metadata['reason']);
    }

    public function test_dates_are_checked_and_ranges_are_limited_to_31_days(): void
    {
        $director = $this->actor('director');

        $this->assertStringContainsString('at most 31 days', $this->refusal($director, 'sales_summary', ['from' => '2026-01-01', 'to' => '2026-03-01']));
        $this->assertStringContainsString('not valid', $this->refusal($director, 'sales_summary', ['from' => '2026-02-10', 'to' => '2026-02-01']));
        $this->assertStringContainsString('not valid', $this->refusal($director, 'money_day_summary', ['date' => 'yesterday']));
        $this->assertStringContainsString('at most 31 days', $this->refusal($director, 'panel_claims_outstanding', ['from' => '2026-01-01', 'to' => '2026-03-01']));
        $this->answer($director, 'sales_summary', ['from' => '2026-02-01', 'to' => '2026-02-28']);
    }

    public function test_the_audit_row_holds_the_dates_and_no_figures(): void
    {
        $director = $this->actor('director');
        $this->answer($director, 'money_day_summary', ['date' => '2026-02-03']);

        $audit = AuditLog::query()->where('event', 'ai_connector.tool_called')->latest('id')->firstOrFail();
        $this->assertSame('connector.money_day_summary', $audit->metadata['tool']);
        $this->assertSame(['date' => '2026-02-03'], $audit->metadata['dates']);
        $this->assertSame(['tool', 'dates', 'outcome', 'rows'], array_keys($audit->metadata));
        $this->assertStringNotContainsString('Sen', json_encode($audit->metadata));
    }

    public function test_malformed_input_is_refused_without_a_run_or_using_the_daily_limit(): void
    {
        $director = $this->actor('director');
        $runs = \App\Domain\Agents\Models\AiAgentRun::query()->count();

        foreach ([['date' => ['x']], ['date' => 20260101]] as $bad) {
            $this->refusal($director, 'money_day_summary', $bad);
        }
        $this->refusal($director, 'sales_summary', ['from' => ['a']]);
        $this->refusal($director, 'sales_summary', ['to' => '2026-02-01']);
        $this->refusal($director, 'panel_claims_made', ['to' => '2026-02-01']);

        $this->assertSame($runs, \App\Domain\Agents\Models\AiAgentRun::query()->count());
        $this->assertSame(0, \Illuminate\Support\Facades\RateLimiter::attempts('connector-day:'.$director->id));
        $audit = AuditLog::query()->where('event', 'ai_connector.tool_called')->latest('id')->firstOrFail();
        $this->assertSame('invalid_input', $audit->metadata['reason']);
        $this->assertStringNotContainsString('Array', json_encode($audit->metadata));
    }

    public function test_other_roles_create_no_run_or_audit_row(): void
    {
        $runs = \App\Domain\Agents\Models\AiAgentRun::query()->count();
        $audits = AuditLog::query()->where('event', 'ai_connector.tool_called')->count();
        $this->ask($this->actor('ca_supervisor'), 'clinic_day_summary')->assertForbidden();

        $this->assertSame($runs, \App\Domain\Agents\Models\AiAgentRun::query()->count());
        $this->assertSame($audits, AuditLog::query()->where('event', 'ai_connector.tool_called')->count());
    }

    public function test_other_roles_are_refused_every_question(): void
    {
        foreach (['ca_supervisor', 'finance_officer', 'technical_admin'] as $role) {
            $user = $this->actor($role);
            foreach (self::QUESTIONS as $tool) {
                $this->ask($user, $tool)->assertForbidden();
            }
        }
    }

    public function test_row_caps_and_staff_typed_text_cleaning(): void
    {
        $helper = new class
        {
            use ShapesAnswers;

            /** @return array{0: list<array<string, mixed>>, 1: array{shown: int, total: int}} */
            public function capped(array $rows): array
            {
                return $this->cap($rows);
            }

            public function clean(string $text): string
            {
                return $this->label($text);
            }
        };

        [$rows, $limits] = $helper->capped(array_map(fn (int $i): array => ['n' => $i], range(1, 40)));
        $this->assertCount(25, $rows);
        $this->assertSame(['shown' => 25, 'total' => 40], $limits);
        $this->assertSame('Panel A ignore this', $helper->clean("Panel A\n\x00ignore this"));
        $this->assertSame(80, mb_strlen($helper->clean(str_repeat('x', 200))));
    }

    public function test_connector_query_classes_never_call_a_write_method(): void
    {
        $files = glob(app_path('Domain/Agents/Connector/Queries/*.php')) ?: [];
        $this->assertGreaterThanOrEqual(8, count($files));
        foreach ($files as $file) {
            $source = file_get_contents($file);
            foreach (['->save(', '->update(', '->delete(', '->insert(', '->create(', 'forceFill(', 'DB::statement', 'DB::insert', 'DB::update', 'DB::delete', 'DB::table'] as $write) {
                $this->assertStringNotContainsString($write, $source, basename($file).' must stay read-only: '.$write);
            }
        }
    }
}

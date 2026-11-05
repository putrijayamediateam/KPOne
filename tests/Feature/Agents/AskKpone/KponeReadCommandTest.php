<?php

namespace Tests\Feature\Agents\AskKpone;

use App\Domain\Agents\AskKpone\AiReadQueryRunner;
use App\Domain\Audit\Models\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\Feature\Queue\QueueTestCase;

/**
 * AG-7: kpone:read and kpone:read-schema. These tests point the read connection at the test login so they see the
 * test's own data; AiReaderLoginTest covers the real kpone_ai_reader login.
 */
class KponeReadCommandTest extends QueueTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Ask KPOne reads PostgreSQL only.');
        }
        config(['database.ai_read.connection' => config('database.default')]);
    }

    public function test_it_prints_rows_as_a_table_or_json_and_audits_the_run_without_the_query_or_results(): void
    {
        $ca = $this->actor('ca');
        $visit = $this->consultationVisit($ca, $this->doctor());
        $sql = "SELECT visit_number, visit_type FROM visits WHERE visit_number = '{$visit->visit_number}'";

        $this->artisan('kpone:read', ['sql' => $sql])
            ->expectsTable(['visit_number', 'visit_type'], [[$visit->visit_number, 'consultation']])
            ->expectsOutput('1 row(s).')
            ->assertSuccessful();

        $this->artisan('kpone:read', ['sql' => $sql, '--json' => true])
            ->expectsOutputToContain('"visit_number": "'.$visit->visit_number.'"')
            ->assertSuccessful();

        $runs = AuditLog::query()->where('event', 'ai_read.query_run')->orderBy('id')->get();
        $this->assertCount(2, $runs);
        foreach ($runs as $run) {
            $this->assertSame(['query_sha256' => hash('sha256', $sql), 'row_count' => 1, 'truncated' => false], $run->metadata);
            $this->assertStringNotContainsString($visit->visit_number, (string) json_encode($run->getAttributes()));
        }
    }

    public function test_output_stops_at_500_rows_and_says_more_exist(): void
    {
        $result = app(AiReadQueryRunner::class)->run('SELECT g FROM generate_series(1, 650) AS g');

        $this->assertCount(500, $result['rows']);
        $this->assertTrue($result['truncated']);
        $this->assertSame(500, AuditLog::query()->where('event', 'ai_read.query_run')->latest('id')->firstOrFail()->metadata['row_count']);

        $this->artisan('kpone:read', ['sql' => 'SELECT g FROM generate_series(1, 650) AS g'])
            ->expectsOutputToContain('500 row(s); more rows exist (only the first 500 are shown).')
            ->assertSuccessful();
    }

    public function test_a_refused_query_fails_changes_nothing_and_audits_the_reason_only(): void
    {
        $before = DB::table('patients')->count();

        $this->artisan('kpone:read', ['sql' => 'DELETE FROM patients'])
            ->expectsOutput('Refused: Only a SELECT or WITH statement is allowed.')
            ->assertFailed();
        $this->artisan('kpone:read', ['sql' => 'SELECT * FROM public.users'])->assertFailed();

        $this->assertSame($before, DB::table('patients')->count());
        $refusals = AuditLog::query()->where('event', 'ai_read.query_refused')->orderBy('id')->get();
        $this->assertSame([
            ['query_sha256' => hash('sha256', 'DELETE FROM patients'), 'reason_code' => 'not_select'],
            ['query_sha256' => hash('sha256', 'SELECT * FROM public.users'), 'reason_code' => 'forbidden_word'],
        ], $refusals->pluck('metadata')->all());
        $this->assertSame(0, AuditLog::query()->where('event', 'ai_read.query_run')->count());
    }

    public function test_reads_run_in_a_read_only_transaction_with_a_timeout_that_is_rolled_back(): void
    {
        $runner = app(AiReadQueryRunner::class);
        $readOnly = new ReflectionMethod($runner, 'readOnly');
        $db = DB::connection();

        $settings = $readOnly->invoke($runner, $db, fn () => $db->selectOne(
            "SELECT current_setting('transaction_read_only') AS ro, current_setting('statement_timeout') AS timeout, current_setting('search_path') AS path",
        ));
        $this->assertSame('on', $settings->ro);
        $this->assertSame('10s', $settings->timeout);
        $this->assertSame('ai_read', $settings->path);

        try {
            $readOnly->invoke($runner, $db, fn () => $db->statement("UPDATE public.branches SET name = 'x'"));
            $this->fail('A write ran inside the read transaction.');
        } catch (QueryException $exception) {
            $this->assertSame('25006', $exception->getCode(), 'PostgreSQL refuses writes in a read-only transaction.');
        }

        // The settings end with the read: the surrounding connection writes again.
        $this->assertSame('off', $db->selectOne("SELECT current_setting('transaction_read_only') AS ro")->ro);
    }

    public function test_read_schema_lists_every_view_with_its_columns_and_descriptions(): void
    {
        $this->artisan('kpone:read-schema')
            ->expectsOutputToContain('patients — Patients, masked: initials and the last 4 identifier characters only.')
            ->expectsOutputToContain('  identifier_last4 (text) — Last 4 characters of the current NRIC or passport number.')
            ->assertSuccessful();

        $schema = app(AiReadQueryRunner::class)->schema();
        $this->assertSame(20, count($schema));
        $this->assertContains('initials', array_column(collect($schema)->firstWhere('view', 'patients')['columns'], 'name'));
    }

    public function test_it_refuses_to_run_without_the_dedicated_reader_login(): void
    {
        config(['database.ai_read.connection' => 'ai_reader', 'database.connections.ai_reader.username' => null]);
        $this->artisan('kpone:read', ['sql' => 'SELECT count(*) FROM patients'])
            ->expectsOutputToContain('The kpone_ai_reader login is not configured.')
            ->assertFailed();

        config(['database.connections.ai_reader.username' => config('database.connections.'.config('database.default').'.username')]);
        $this->artisan('kpone:read-schema')
            ->expectsOutputToContain('The kpone_ai_reader login is not configured.')
            ->assertFailed();

        $this->assertSame(0, AuditLog::query()->where('event', 'like', 'ai_read.%')->count());
    }
}

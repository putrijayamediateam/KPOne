<?php

namespace Tests\Feature\Agents\AskKpone;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

/**
 * AG-7: the kpone_ai_reader login created exactly as docs/DEVELOPMENT.md documents can read the ai_read views
 * and nothing else, and cannot write. The login is created under a per-test name and dropped afterwards.
 */
class AiReaderLoginTest extends TestCase
{
    use RefreshDatabase;

    private string $role = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The reader login is a PostgreSQL role.');
        }
        $can = DB::selectOne('SELECT rolsuper OR rolcreaterole AS can FROM pg_roles WHERE rolname = current_user');
        if (! $can?->can) {
            $this->markTestSkipped('The test database login cannot create roles.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->role !== '') {
            DB::purge('ai_reader');
            $admin = $this->admin();
            try {
                $admin->unprepared("DROP OWNED BY {$this->role}");
                $admin->unprepared("DROP ROLE IF EXISTS {$this->role}");
            } finally {
                DB::purge('ai_read_admin');
            }
        }
        parent::tearDown();
    }

    public function test_the_documented_login_reads_ai_read_only_and_cannot_write(): void
    {
        $this->role = 'kpone_ai_reader_t'.Str::lower(Str::random(8));
        $password = Str::random(32);
        $database = (string) DB::connection()->getDatabaseName();
        $owner = (string) DB::selectOne('SELECT current_user AS u')->u;

        $admin = $this->admin();
        foreach ($this->documentedStatements() as $statement) {
            $admin->unprepared(strtr($statement, [
                'kpone_ai_reader' => $this->role,
                'replace-with-a-different-strong-password' => $password,
                'DATABASE kpone ' => 'DATABASE "'.$database.'" ',
                'FOR ROLE kpone ' => 'FOR ROLE "'.$owner.'" ',
            ]));
        }

        config([
            'database.connections.ai_reader.username' => $this->role,
            'database.connections.ai_reader.password' => $password,
            'database.connections.ai_reader.host' => config('database.connections.pgsql.host'),
            'database.connections.ai_reader.port' => config('database.connections.pgsql.port'),
            'database.connections.ai_reader.database' => $database,
            'database.connections.ai_reader.sslmode' => config('database.connections.pgsql.sslmode'),
        ]);
        DB::purge('ai_reader');
        $reader = DB::connection('ai_reader');

        $this->assertSame('on', $reader->selectOne('SHOW default_transaction_read_only')->default_transaction_read_only);
        $this->assertSame('10s', $reader->selectOne('SHOW statement_timeout')->statement_timeout);
        $this->assertIsNumeric($reader->selectOne('SELECT count(*) AS n FROM patients')->n);
        $this->assertIsNumeric($reader->selectOne('SELECT count(*) AS n FROM ai_read.visits')->n);

        foreach ([
            'SELECT * FROM public.users',
            'SELECT * FROM public.patients',
            'SELECT * FROM public.audit_logs',
        ] as $sql) {
            $this->assertSqlState('42501', fn () => $reader->select($sql), $sql);
        }
        $this->assertSqlState('25006', fn () => $reader->statement('CREATE TABLE ai_read.x (id int)'), 'create');
        $this->assertSqlState('25006', fn () => $reader->statement('CREATE TEMP TABLE x (id int)'), 'temp');

        $this->artisan('kpone:read', ['sql' => 'SELECT count(*) AS n FROM branches'])
            ->expectsOutput('1 row(s).')
            ->assertSuccessful();
    }

    private function assertSqlState(string $state, callable $call, string $label): void
    {
        try {
            $call();
            $this->fail("The reader login was allowed: {$label}");
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->getCode(), $label);
        }
    }

    /** @return list<string> the SQL statements of the Ask KPOne section of docs/DEVELOPMENT.md */
    private function documentedStatements(): array
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 4).'/docs/DEVELOPMENT.md');
        $section = Str::between($doc, '## Ask KPOne read login (AG-7)', "\n## ");
        $this->assertSame(1, preg_match('/```sql\n(.*?)```/s', $section, $match), 'The documented reader login SQL is missing.');

        $statements = array_values(array_filter(array_map('trim', explode(";\n", $match[1]))));
        $this->assertCount(8, $statements);

        return array_map(fn (string $s): string => rtrim($s, ';'), $statements);
    }

    /** A separate, auto-committing connection, so the login exists outside the test transaction. */
    private function admin(): Connection
    {
        config(['database.connections.ai_read_admin' => config('database.connections.'.config('database.default'))]);

        try {
            return DB::connection('ai_read_admin');
        } catch (Throwable $exception) {
            $this->markTestSkipped('No separate admin connection: '.$exception->getMessage());
        }
    }
}

<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Owner report, 17 Nov 2026: on a laptop whose PostgreSQL runs in Kuala Lumpur time, every clock-in photo was refused
 * as "took too long". Laravel writes dates without an offset, so a server session time zone other than UTC shifts
 * every stored timestamptz by 8 hours. The pgsql connection now runs SET TIME ZONE 'UTC' itself (DB_TIMEZONE).
 */
class DatabaseTimezoneTest extends TestCase
{
    public function test_the_pgsql_and_ai_reader_connections_are_pinned_to_utc(): void
    {
        $this->assertSame('UTC', config('database.connections.pgsql.timezone'));
        // The Ask KPOne read-only connection (AG-7) reads the same tables, so it is pinned the same way.
        $this->assertSame('UTC', config('database.connections.ai_reader.timezone'));
    }

    public function test_a_database_set_to_kuala_lumpur_still_keeps_times_in_utc(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The session time zone is PostgreSQL only.');
        }

        // Two extra connections to this test's own database, outside the test's transaction: one as configured, and
        // one without the time zone setting, to show the database default really is Kuala Lumpur.
        $base = DB::connection()->getConfig();
        unset($base['name']);
        config([
            'database.connections.pgsql_tz_pinned' => $base,
            'database.connections.pgsql_tz_unpinned' => array_diff_key($base, ['timezone' => true]),
        ]);
        $database = DB::connection()->getDatabaseName();
        $this->assertMatchesRegularExpression('/(_test|_testing)(_test_\d+)?$/', $database);

        $admin = DB::connection('pgsql_tz_pinned');
        $admin->statement(sprintf("ALTER DATABASE %s SET timezone TO 'Asia/Kuala_Lumpur'", $admin->getQueryGrammar()->wrap($database)));

        try {
            DB::purge('pgsql_tz_pinned');
            DB::purge('pgsql_tz_unpinned');
            $pinned = DB::connection('pgsql_tz_pinned');
            $unpinned = DB::connection('pgsql_tz_unpinned');

            $this->assertSame('Asia/Kuala_Lumpur', $unpinned->selectOne('SHOW timezone')->TimeZone);
            $this->assertSame('UTC', $pinned->selectOne('SHOW timezone')->TimeZone);

            // A capture pass is good for five minutes. Written through the query builder and read back, it must still
            // lie five minutes ahead, as ClockPhotoService::attach() checks it.
            $now = CarbonImmutable::now('UTC');
            $expires = $now->addMinutes(5);
            foreach ([$pinned, $unpinned] as $connection) {
                $connection->statement('CREATE TEMPORARY TABLE tz_check (expires_at timestamptz NOT NULL)');
                $connection->table('tz_check')->insert(['expires_at' => $expires]);
            }

            $read = fn ($connection): CarbonImmutable => CarbonImmutable::parse((string) $connection->table('tz_check')->value('expires_at'));
            $this->assertFalse($read($pinned)->lessThan($now), 'A fresh pass reads as already expired.');
            $this->assertSame($expires->getTimestamp(), $read($pinned)->getTimestamp());
            // Without the setting the same pass reads 8 hours early: the bug the owner saw.
            $this->assertTrue($read($unpinned)->lessThan($now));
            $this->assertSame(8 * 3600, $expires->getTimestamp() - $read($unpinned)->getTimestamp());
        } finally {
            DB::purge('pgsql_tz_unpinned');
            DB::connection('pgsql_tz_pinned')->statement(sprintf('ALTER DATABASE %s RESET timezone', DB::connection('pgsql_tz_pinned')->getQueryGrammar()->wrap($database)));
            DB::purge('pgsql_tz_pinned');
        }
    }
}

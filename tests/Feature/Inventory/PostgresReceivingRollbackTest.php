<?php

namespace Tests\Feature\Inventory;

use App\Domain\Organisation\Inventory\Services\ProcurementService;
use Database\Seeders\KPOneReferenceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\VerifiedReceivingFixture;
use Tests\TestCase;

class PostgresReceivingRollbackTest extends TestCase
{
    public function test_current_schema_refuses_old_rollback_and_receipt_drop_without_losing_any_rows(): void
    {
        $original = (string) config('database.default');
        $config = DB::connection()->getConfig();
        $this->assertSame('pgsql', $config['driver']);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertTrue(app()->environment('testing'));
        $this->assertMatchesRegularExpression('/test/i', $config['database']);
        $database = 'kpone_stk3_rollback_'.strtolower(Str::random(16)).'_testing';
        DB::statement('CREATE DATABASE "'.$database.'" ENCODING \'UTF8\' TEMPLATE template0');
        try {
            config(['database.connections.receiving_rollback' => [...$config, 'database' => $database, 'name' => 'receiving_rollback', 'url' => null]]);
            DB::setDefaultConnection('receiving_rollback');
            Schema::clearResolvedInstance('db.schema');
            app()->forgetInstance('db.schema');
            $connection = DB::connection();
            if ($connection->getConfig('host') !== $config['host'] || (string) $connection->getConfig('port') !== (string) $config['port'] || $connection->getDatabaseName() !== $database) {
                throw new RuntimeException('Refusing a rollback test outside its child database.');
            }
            $effective = $connection->selectOne("select current_database() as database, host(inet_server_addr()) as host, inet_server_port() as port, current_setting('server_encoding') as encoding");
            $this->assertSame($database, $effective->database);
            $this->assertSame($config['host'], $effective->host);
            $this->assertSame((string) $config['port'], (string) $effective->port);
            $this->assertSame('UTF8', $effective->encoding);
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'receiving_rollback', '--force' => true]));
            $this->seed(KPOneReferenceSeeder::class);
            $f = (new VerifiedReceivingFixture)->build();
            $receipt = app(ProcurementService::class)->receive($f['receiver'], $f['order'], $f['payload']);
            $this->assertSame(1, DB::table('inventory_receipt_delivery_evidence')->where('goods_receipt_id', $receipt->id)->count());
            $snapshot = $this->allRows();
            $migration = '2026_09_11_000100_create_inventory_operations';
            try {
                app('migrator')->rollback([database_path('migrations/'.$migration.'.php')], ['batch' => DB::table('migrations')->where('migration', $migration)->value('batch')]);
                $this->fail('Rollback erased retained receipt evidence.');
            } catch (RuntimeException $exception) {
                // The real down() refuses populated evidence before reaching its DROP.
                $this->assertStringContainsString('Inventory operations rollback refused because retained evidence exists', $exception->getMessage());
            }
            $this->assertSame($snapshot, $this->allRows());
            try {
                // Pin the exact PostgreSQL refusal of the same DROP step as well.
                DB::transaction(fn () => Schema::dropIfExists('inventory_goods_receipts'));
                $this->fail('PostgreSQL allowed the referenced receipt table to be dropped.');
            } catch (QueryException $exception) {
                $this->assertSame('2BP01', $exception->errorInfo[0]);
                $this->assertStringContainsString('inventory_receipt_delivery_evidence_goods_receipt_id_foreign', $exception->getMessage());
            }
            $this->assertSame($snapshot, $this->allRows());
        } finally {
            DB::purge('receiving_rollback');
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
            app()->forgetInstance('db.schema');
            DB::statement('DROP DATABASE "'.$database.'" WITH (FORCE)');
        }
    }

    /** @return array<string,string> */
    private function allRows(): array
    {
        $result = [];
        foreach (DB::select("select tablename from pg_tables where schemaname='public' order by tablename") as $table) {
            $columns = array_column(DB::select('select column_name from information_schema.columns where table_schema=? and table_name=? order by ordinal_position', ['public', $table->tablename]), 'column_name');
            $query = DB::table($table->tablename);
            foreach (in_array('id', $columns, true) ? ['id'] : $columns as $column) {
                $query->orderBy($column);
            }
            $result[$table->tablename] = $query->get()->toJson();
        }

        return $result;
    }
}

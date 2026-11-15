<?php

namespace Tests\Feature;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PostgresReferralNumberTest extends TestCase
{
    public function test_concurrent_allocators_wait_and_never_reuse_a_branch_year_number(): void
    {
        $connection = config('database.connections.'.config('database.default'));
        if (DB::getDriverName() !== 'pgsql' || ! preg_match('/(?:^|_)(test|testing)(?:_|$)/i', $connection['database'])) {
            throw new \RuntimeException('An isolated PostgreSQL testing database is required.');
        }
        // This non-transactional worker fixture must not seed global role grants:
        // later concurrency tests intentionally exercise direct-permission loss.
        $grants = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $organisation = Organisation::query()->firstOrNew(['code' => 'KLINIK_PUTRIJAYA']);
        if (! $organisation->exists) {
            $organisation->forceFill(['name' => 'Synthetic Referral Counter', 'is_active' => true])->save();
        }
        $branch = Branch::query()->firstOrNew(['organisation_id' => $organisation->id, 'code' => 'CHERAS']);
        if (! $branch->exists) {
            $branch->forceFill(['organisation_id' => $organisation->id, 'name' => 'Synthetic Counter Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        }
        $last = DB::table('referral_number_counters')->where('branch_id', $branch->id)->where('year', 2038)->value('last_number') ?? 0;
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'APP_CONFIG_CACHE' => sys_get_temp_dir().'/doc04-worker-no-config.php', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'] ?? '', 'DB_URL' => '', 'DB_SSLMODE' => $connection['sslmode'] ?? 'disable', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'];
        $input = new InputStream;
        $first = new Process([PHP_BINARY, base_path('tests/Support/PostgresReferralNumberWorker.php'), (string) $branch->id, 'hold'], base_path(), $environment, $input, 30);
        $second = new Process([PHP_BINARY, base_path('tests/Support/PostgresReferralNumberWorker.php'), (string) $branch->id], base_path(), $environment, timeout: 30);
        try {
            $first->start();
            $this->assertTrue($first->waitUntil(fn ($type, $output) => str_contains($output, 'REF/KP/CHR/2038/')));
            $second->start();
            usleep(200000);
            $this->assertTrue($second->isRunning());
            $input->write("COMMIT\n");
            $input->close();
            $first->wait();
            $second->wait();
            $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
            $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
            $this->assertSame(sprintf('REF/KP/CHR/2038/%06d', $last + 1), trim($first->getOutput()));
            $this->assertSame(sprintf('REF/KP/CHR/2038/%06d', $last + 2), trim($second->getOutput()));
            $this->assertSame($last + 2, DB::table('referral_number_counters')->where('branch_id', $branch->id)->where('year', 2038)->value('last_number'));
            $this->assertSame($grants, DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson());
        } finally {
            if (! $input->isClosed()) {
                $input->close();
            }
            $first->stop(1);
            $second->stop(1);
        }
    }
}

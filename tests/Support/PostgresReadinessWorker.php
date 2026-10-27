<?php

declare(strict_types=1);

use App\Domain\Access\BranchAccessService;
use App\Domain\Queue\Readiness\CallHoldService;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\IsolatedTestStorage;

require dirname(__DIR__, 2).'/vendor/autoload.php';
IsolatedTestStorage::beforeBootstrap();
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
IsolatedTestStorage::configure($app);
try {
    if (! app()->environment('testing') || DB::getDriverName() !== 'pgsql' || ! preg_match('/(?:^|_)test(?:ing)?(?:_|$)/i', DB::getDatabaseName())) {
        exit(65);
    }
    [, $mode, $actorId, $visitId, $branchId, $visitVersion, $queueVersion, $flagId, $flagVersion, $name] = $argv;
    DB::statement("select set_config('application_name', ?, false)", [$name]);
    fwrite(STDOUT, 'READY '.DB::scalar('select pg_backend_pid()').PHP_EOL);
    fflush(STDOUT);
    if (trim((string) fgets(STDIN)) !== 'GO') {
        exit(66);
    }
    app('session')->start();
    session([BranchAccessService::SESSION_KEY => (int) $branchId]);
    $actor = User::findOrFail((int) $actorId);
    $visit = Visit::findOrFail((int) $visitId);
    $input = ['expected_branch_id' => (int) $branchId, 'visit_lock_version' => (int) $visitVersion, 'queue_lock_version' => (int) $queueVersion, 'idempotency_key' => (string) Str::uuid()];
    try {
        if ($mode === 'call') {
            app(QueueEntryService::class)->call($actor, $visit, $input);
        } else {
            app(CallHoldService::class)->change($actor, $visit, [...$input, ...($mode === 'set' ? ['reason_code' => 'awaiting_panel_confirmation'] : ['call_hold_id' => (int) $flagId, 'lock_version' => (int) $flagVersion])], $mode === 'clear');
        }
        fwrite(STDOUT, strtoupper($mode).'_OK'.PHP_EOL);
    } catch (ValidationException $e) {
        fwrite(STDOUT, strtoupper($mode).'_REFUSED'.PHP_EOL);
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e::class.': '.$e->getMessage().PHP_EOL);
    exit(1);
}

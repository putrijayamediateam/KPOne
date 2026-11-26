<?php

declare(strict_types=1);

use App\Domain\Access\BranchAccessService;
use App\Domain\Queue\Services\RedZoneAlertService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
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
    [, $actorId, $visitId, $branchId, $name] = $argv;
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
    try {
        app(RedZoneAlertService::class)->take($actor, $visit, (int) $branchId);
        fwrite(STDOUT, 'TAKE_OK'.PHP_EOL);
    } catch (ValidationException|AuthorizationException $e) {
        fwrite(STDOUT, 'TAKE_REFUSED'.PHP_EOL);
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e::class.': '.$e->getMessage().PHP_EOL);
    exit(1);
}

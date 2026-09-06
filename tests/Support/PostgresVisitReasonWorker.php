<?php

declare(strict_types=1);

use App\Domain\Access\BranchAccessService;
use App\Domain\Visit\Services\VisitReasonService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$application = require dirname(__DIR__, 2).'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();

$connection = DB::connection();
$database = (string) $connection->getDatabaseName();
if (! app()->environment('testing') || $connection->getDriverName() !== 'pgsql'
    || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', $database) !== 1) {
    exit(65);
}

[, $actorId, $branchId, $name, $applicationName] = $argv;
$connection->statement("select set_config('application_name', ?, false)", [$applicationName]);
app('session')->start();
session([BranchAccessService::SESSION_KEY => (int) $branchId]);
$connection->beginTransaction();
$pid = (int) $connection->scalar('select pg_backend_pid()');
fwrite(STDOUT, "READY {$pid}".PHP_EOL);
fflush(STDOUT);
if (trim((string) fgets(STDIN)) !== 'GO') {
    exit(66);
}

$actor = User::query()->findOrFail((int) $actorId);
try {
    $reason = app(VisitReasonService::class)->create($actor, $name);
} catch (AuthorizationException) {
    $connection->rollBack();
    fwrite(STDOUT, 'DENIED'.PHP_EOL);
    fflush(STDOUT);
    exit(0);
}
fwrite(STDOUT, "CREATED {$reason->public_id}".PHP_EOL);
fflush(STDOUT);
if (trim((string) fgets(STDIN)) !== 'COMMIT') {
    exit(67);
}
$connection->commit();
exit(0);

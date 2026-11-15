<?php

declare(strict_types=1);

use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Identity\Services\BranchAssignmentService;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$application = require dirname(__DIR__, 2).'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();

try {
    if (count($argv) !== 5
        || ! ctype_digit($argv[1])
        || ! ctype_digit($argv[2])
        || ! ctype_digit($argv[3])
        || preg_match('/\Akpone-pg-test-[a-f0-9]+\z/', $argv[4]) !== 1) {
        exit(64);
    }

    $connection = DB::connection();
    $databaseName = (string) $connection->getDatabaseName();

    if (! app()->environment('testing')
        || $connection->getDriverName() !== 'pgsql'
        || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', $databaseName) !== 1) {
        exit(65);
    }

    $connection->statement("select set_config('application_name', ?, false)", [$argv[4]]);
    $backendPid = (int) $connection->scalar('select pg_backend_pid()');

    fwrite(STDOUT, "READY {$backendPid}".PHP_EOL);
    fflush(STDOUT);

    $signal = fgets(STDIN);

    if ($signal === false || trim($signal) !== 'GO') {
        exit(66);
    }

    app(BranchAssignmentService::class)->changePrimary(
        StaffProfile::query()->findOrFail((int) $argv[2]),
        StaffBranchAssignment::query()->findOrFail((int) $argv[3]),
        User::query()->findOrFail((int) $argv[1]),
    );

    fwrite(STDOUT, "DONE {$backendPid}".PHP_EOL);
    fflush(STDOUT);

    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.PHP_EOL);
    exit(1);
}

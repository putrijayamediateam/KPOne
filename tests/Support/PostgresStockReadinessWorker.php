<?php

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\InventoryReadinessService;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $database, $actorId, $branchId, $opening, $retry] = $argv;
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== $database || ! str_contains($database, '_stk_race_')) {
    exit(65);
}
$actor = User::findOrFail((int) $actorId);
session([BranchAccessService::SESSION_KEY => (int) $branchId]);
fwrite(STDOUT, 'READY '.DB::selectOne('select pg_backend_pid() as pid')->pid.PHP_EOL);
fflush(STDOUT);
if (trim((string) fgets(STDIN)) !== 'GO') {
    exit(66);
}
try {
    app(InventoryReadinessService::class)->verify($actor, 'opening', $opening, ['expected_branch_id' => (int) $branchId, 'lock_version' => 0, 'retry_key' => $retry]);
    fwrite(STDOUT, 'VERIFIED'.PHP_EOL);
} catch (ValidationException) {
    fwrite(STDOUT, 'REFUSED'.PHP_EOL);
}

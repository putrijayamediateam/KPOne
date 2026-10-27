<?php

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $database, $action, $encoded] = $argv;
if (! app()->environment('testing') || preg_match('/^kpone_stk2_race_[a-z0-9]+_testing$/D', $database) !== 1 || DB::connection()->getDatabaseName() !== $database) {
    exit(65);
}
$data = json_decode(base64_decode($encoded, true), true, 512, JSON_THROW_ON_ERROR);
session([BranchAccessService::SESSION_KEY => $data['branch_id']]);
echo 'READY '.DB::selectOne('select pg_backend_pid() as pid')->pid.PHP_EOL;
flush();
if (trim((string) fgets(STDIN)) !== 'GO') {
    exit(66);
}
try {
    $actor = User::findOrFail($data['actor_id']);
    $case = DispensaryCase::findOrFail($data['case_id']);
    if ($action === 'save') {
        app(DispensaryService::class)->addItem($actor, $case, $data['payload']);
    } else {
        app(DispensaryService::class)->complete($actor, $case, ['expected_branch_id' => $data['branch_id'], 'case_lock_version' => $case->lock_version]);
    }
    echo 'SUCCEEDED'.PHP_EOL;
} catch (ValidationException $e) {
    if (! isset($e->errors()['allocations']) || ! str_contains($e->errors()['allocations'][0], 'held')) {
        throw $e;
    }
    echo 'REFUSED_HELD'.PHP_EOL;
}

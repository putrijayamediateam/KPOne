<?php

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Organisation\Inventory\Services\GovernedStockService;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $database, $mode, $encoded] = $argv;
$c = DB::connection()->getConfig();
if (! app()->environment('testing') || preg_match('/^kpone_stk5_race_[a-z0-9]+_testing$/D', $database) !== 1 || $c['database'] !== $database) {
    exit(65);
}
$identity = DB::selectOne("SELECT current_database() as database, current_setting('server_encoding') as encoding, pg_backend_pid() as pid");
if ($identity->database !== $database || $identity->encoding !== 'UTF8') {
    exit(65);
}
$data = json_decode(base64_decode($encoded, true), true, 512, JSON_THROW_ON_ERROR);
session([BranchAccessService::SESSION_KEY => $data['branch_id']]);
echo 'READY '.$identity->pid.PHP_EOL;
flush();
if (trim((string) fgets(STDIN)) !== 'GO') {
    exit(66);
}
try {
    $actor = User::findOrFail($data['actor_id']);
    if ($mode === 'dispense') {
        $case = DispensaryCase::findOrFail($data['case_id']);
        app(DispensaryService::class)->complete($actor, $case, ['expected_branch_id' => $data['branch_id'], 'case_lock_version' => $case->lock_version]);
    } else {
        app(GovernedStockService::class)->act($actor, $data['case_id'], 'approved', $data['payload']);
    }
    echo 'SUCCEEDED'.PHP_EOL;
} catch (ValidationException $e) {
    $field = array_key_first($e->errors());
    if (! in_array($field, ['snapshot', 'stock', 'quantity', 'allocations'], true)) {
        throw $e;
    }
    echo 'REFUSED '.$field.PHP_EOL;
}

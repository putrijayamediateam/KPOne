<?php

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// DSP-23 contention worker: runs one Verify or Send back against a disposable race database while the test holds the
// competing transaction, so the test can prove the worker waits on a lock and then sees the committed result.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $database, $mode, $encoded] = $argv;
$c = DB::connection()->getConfig();
if (! app()->environment('testing') || preg_match('/^kpone_dsp23_race_[a-z0-9]+_testing$/D', $database) !== 1 || $c['database'] !== $database) {
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
    Auth::setUser($actor);
    $case = DispensaryCase::query()->findOrFail($data['case_id']);
    $service = app(DispensaryService::class);
    $mode === 'verify'
        ? $service->verifyDoctorCheck($actor, $case, $data['payload'])
        : $service->sendBackDoctorCheck($actor, $case, $data['payload']);
    echo 'SUCCEEDED'.PHP_EOL;
} catch (ValidationException $e) {
    $field = array_key_first($e->errors());
    if (! in_array($field, ['case_lock_version', 'case', 'allocations'], true)) {
        throw $e;
    }
    echo 'REFUSED '.$field.PHP_EOL;
}

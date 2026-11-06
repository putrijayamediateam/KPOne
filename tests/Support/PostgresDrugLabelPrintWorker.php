<?php

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Dispensary\Labels\DrugLabelService;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// DSP-6b contention worker: records one label print against a disposable race database while the test holds the
// competing print, so the test can prove the worker waits on the print's row locks and then takes the next print number.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $database, $encoded] = $argv;
$c = DB::connection()->getConfig();
if (! app()->environment('testing') || preg_match('/^kpone_dsp6_race_[a-z0-9]+_testing$/D', $database) !== 1 || $c['database'] !== $database) {
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
$actor = User::findOrFail($data['actor_id']);
$case = DispensaryCase::query()->findOrFail($data['case_id']);
$reprints = app(DrugLabelService::class)->recordPrint($actor, $case, null, $data['token'], $data['branch_id']);
echo 'PRINTED '.json_encode($reprints, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;

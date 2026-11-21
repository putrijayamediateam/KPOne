<?php

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryItem;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// DSP-24 contention worker: the CA saves one line in whole bottles against a disposable race database while the test
// holds a competing STK-52 check, so the test can prove the save waits on the SKU lock and then sees the new version.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $database, $encoded] = $argv;
$c = DB::connection()->getConfig();
if (! app()->environment('testing') || preg_match('/^kpone_dsp24_stk_pack_race_[a-z0-9]+_testing$/D', $database) !== 1 || $c['database'] !== $database) {
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
    $item = DispensaryItem::query()->findOrFail($data['item_id']);
    app(DispensaryService::class)->editItem($actor, $case, $item, $data['payload']);
    echo 'SAVED'.PHP_EOL;
} catch (ValidationException $e) {
    echo 'REFUSED '.array_key_first($e->errors()).' '.collect($e->errors())->flatten()->first().PHP_EOL;
}

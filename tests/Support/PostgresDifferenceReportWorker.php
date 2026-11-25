<?php

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\DifferenceReportService;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $database, $encoded] = $argv;
$c = DB::connection()->getConfig();
if (! app()->environment('testing') || preg_match('/^kpone_stk45_race_[a-z0-9]+_testing$/D', $database) !== 1 || $c['database'] !== $database) {
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
$result = app(DifferenceReportService::class)->report(User::findOrFail($data['actor_id']), $data['payload']);
echo ($result['openedCase'] ? 'OPENED ' : 'JOINED ').$result['caseId'].PHP_EOL;

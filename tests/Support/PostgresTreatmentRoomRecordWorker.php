<?php

declare(strict_types=1);

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Clinical\TreatmentRoom\TreatmentRoomService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::getDriverName() !== 'pgsql' || ! str_ends_with(DB::getDatabaseName(), '_test')) {
    exit(65);
}
$input = json_decode(base64_decode($argv[1]), true, flags: JSON_THROW_ON_ERROR);
app('session')->start();
session([BranchAccessService::SESSION_KEY => $input['attributes']['expected_branch_id']]);
echo 'READY '.DB::scalar('select pg_backend_pid()').PHP_EOL;
flush();
try {
    $actor = User::findOrFail($input['actor']);
    $visit = Visit::findOrFail($input['visit']);
    if ($input['operation'] === 'complete') {
        app(CompleteConsultationService::class)->complete($actor, $visit, $input['attributes']);
    } else {
        app(TreatmentRoomService::class)->{$input['operation']}($actor, $visit, $input['attributes']);
    }
    echo 'SUCCEEDED'.PHP_EOL;
} catch (ValidationException $exception) {
    echo 'STALE '.implode(',', array_keys($exception->errors())).PHP_EOL;
}

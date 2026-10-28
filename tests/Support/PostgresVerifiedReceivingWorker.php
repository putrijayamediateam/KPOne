<?php

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Models\PurchaseOrder;
use App\Domain\Organisation\Inventory\Services\ProcurementService;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script,$database,$encoded] = $argv;
if (! app()->environment('testing') || preg_match('/^kpone_stk3_race_[a-z0-9]+_testing$/D', $database) !== 1 || DB::connection()->getDatabaseName() !== $database) {
    exit(65);
}
$data = json_decode(base64_decode($encoded, true), true, 512, JSON_THROW_ON_ERROR);
session([BranchAccessService::SESSION_KEY => $data['branch_id']]);
echo 'READY '.DB::selectOne('SELECT pg_backend_pid() AS pid')->pid.PHP_EOL;
flush();
if (trim((string) fgets(STDIN)) !== 'GO') {
    exit(66);
}
try {
    $receipt = app(ProcurementService::class)->receive(User::findOrFail($data['actor_id']), PurchaseOrder::findOrFail($data['order_id']), $data['payload']);
    echo 'RECEIVED '.$receipt->id.PHP_EOL;
} catch (ValidationException $e) {
    $field = array_key_first($e->errors());
    if (! in_array($field, ['lock_version', 'delivery_reference'], true)) {
        throw $e;
    }
    echo 'REFUSED '.$field.PHP_EOL;
}

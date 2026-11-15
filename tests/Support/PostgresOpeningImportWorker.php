<?php

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Inventory\Services\OpeningImportService;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
// mode: "single" records one opening; "import" records the same opening through a one-row CSV import.
[$script, $database, $mode, $actorId, $branchId, $location, $sku, $batch, $batchNumber, $expiry, $key] = $argv;
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== $database || ! str_contains($database, '_stk_import_race_')) {
    exit(65);
}
$actor = User::findOrFail((int) $actorId);
session([BranchAccessService::SESSION_KEY => (int) $branchId]);
$file = null;
$hash = null;
if ($mode === 'import') {
    $file = UploadedFile::fake()->createWithContent('opening.csv', "\xEF\xBB\xBF".implode(',', OpeningImportService::COLUMNS)."\r\n".implode(',', [$location, $sku, $batchNumber, $expiry, '7', ''])."\r\n");
    // The check is read-only and happens before the race, as on the page.
    $hash = app(OpeningImportService::class)->check($actor, (int) $branchId, $file)['hash'];
}
fwrite(STDOUT, 'READY '.DB::selectOne('select pg_backend_pid() as pid')->pid.PHP_EOL);
fflush(STDOUT);
if (trim((string) fgets(STDIN)) !== 'GO') {
    exit(66);
}
try {
    if ($mode === 'import') {
        app(OpeningImportService::class)->record($actor, ['expected_branch_id' => (int) $branchId, 'idempotency_key' => $key, 'file_hash' => $hash], $file);
    } else {
        $locationId = DB::table('inventory_locations')->where('code', $location)->value('public_id');
        $skuId = DB::table('inventory_skus')->where('sku_code', $sku)->value('public_id');
        app(InventoryMovementService::class)->openingBalance($actor, ['expected_branch_id' => (int) $branchId, 'location_public_id' => $locationId, 'sku_public_id' => $skuId, 'batch_public_id' => $batch, 'quantity' => '4']);
    }
    fwrite(STDOUT, 'RECORDED'.PHP_EOL);
} catch (ValidationException $exception) {
    fwrite(STDOUT, 'REFUSED '.collect($exception->errors())->flatten()->first().PHP_EOL);
}

<?php

use App\Domain\Organisation\Inventory\Services\PackConversionService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
// mode "propose": propose 1 bottle = <count> ml on the SKU; "check": blind-check the waiting version with <count>;
// "withdraw": the proposer withdraws the waiting version.
[$script, $database, $mode, $actorId, $subject, $count] = $argv;
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== $database || ! str_contains($database, '_stk_pack_race_')) {
    exit(65);
}
$actor = User::findOrFail((int) $actorId);
fwrite(STDOUT, 'READY '.DB::selectOne('select pg_backend_pid() as pid')->pid.PHP_EOL);
fflush(STDOUT);
if (trim((string) fgets(STDIN)) !== 'GO') {
    exit(66);
}
try {
    $service = app(PackConversionService::class);
    if ($mode === 'propose') {
        $service->propose($actor, $subject, ['expected_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'pack_unit' => 'bottle', 'base_per_pack' => (int) $count]);
        fwrite(STDOUT, 'RECORDED'.PHP_EOL);
    } elseif ($mode === 'withdraw') {
        $service->withdraw($actor, $subject, ['idempotency_key' => (string) Str::uuid()]);
        fwrite(STDOUT, 'WITHDRAWN'.PHP_EOL);
    } else {
        fwrite(STDOUT, strtoupper($service->check($actor, $subject, ['idempotency_key' => (string) Str::uuid(), 'checked_base_per_pack' => (int) $count])).PHP_EOL);
    }
} catch (ValidationException $exception) {
    fwrite(STDOUT, 'REFUSED '.collect($exception->errors())->flatten()->first().PHP_EOL);
} catch (AuthorizationException $exception) {
    fwrite(STDOUT, 'REFUSED '.$exception->getMessage().PHP_EOL);
}

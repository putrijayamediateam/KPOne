<?php

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\HandCostService;
use App\Domain\Organisation\Inventory\Services\ReceiptCorrectionService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $database, $mode, $actorId, $branchId, $encoded] = $argv;
if (! app()->environment('testing') || ! str_starts_with($database, 'kpone_hand_cost_race_') || DB::connection()->getDatabaseName() !== $database) {
    throw new RuntimeException('A dedicated synthetic hand-cost race database is required.');
}
$actor = User::findOrFail($actorId);
app(BranchAccessService::class)->select($actor, Branch::findOrFail($branchId));
$payload = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
echo 'READY '.DB::selectOne('select pg_backend_pid() as pid')->pid."\n";
flush();
if (trim((string) fgets(STDIN)) !== 'GO') {
    throw new RuntimeException('Missing race barrier release.');
}
try {
    match ($mode) {
        'enter' => app(HandCostService::class)->enter($actor, $payload['input']),
        'accept' => app(HandCostService::class)->decide($actor, $payload['public_id'], $payload['input']),
        'correct' => app(ReceiptCorrectionService::class)->reverse($actor, $payload['review'], $payload['input']),
        default => throw new RuntimeException('Unknown hand-cost race operation.'),
    };
    echo "RECORDED\n";
} catch (ValidationException $e) {
    echo 'REFUSED '.json_encode($e->errors(), JSON_THROW_ON_ERROR)."\n";
}

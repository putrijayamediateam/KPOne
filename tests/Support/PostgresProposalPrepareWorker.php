<?php

use App\Domain\Access\BranchAccessService;
use App\Domain\Agents\Proposals\AiProposal;
use App\Domain\Agents\Proposals\ProposalService;
use App\Domain\Organisation\Inventory\Services\StockRequestService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
// mode: "prepare" approves the transfer suggestion and prepares its stock request; "manual" creates a stock request by hand for
// the same stock, as on the Inventory page.
[$script, $database, $mode, $actorId, $branchId, $proposalId, $source, $destination, $sku, $quantity] = $argv;
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== $database || ! str_contains($database, '_stk_prepare_race_')) {
    exit(65);
}
config(['agents.prepare_enabled' => true]);
$actor = User::findOrFail((int) $actorId);
session([BranchAccessService::SESSION_KEY => (int) $branchId]);
fwrite(STDOUT, 'READY '.DB::selectOne('select pg_backend_pid() as pid')->pid.PHP_EOL);
fflush(STDOUT);
if (trim((string) fgets(STDIN)) !== 'GO') {
    exit(66);
}
try {
    if ($mode === 'prepare') {
        app(ProposalService::class)->approve($actor, AiProposal::findOrFail((int) $proposalId), ['lock_version' => 0, 'mode' => 'prepare',
            'edits' => ['source_location_public_id' => $source, 'destination_location_public_id' => $destination]]);
    } else {
        app(StockRequestService::class)->create($actor, ['expected_branch_id' => (int) $branchId, 'retry_key' => (string) Str::uuid(), 'source_location_public_id' => $source,
            'destination_location_public_id' => $destination, 'lines' => [['sku_public_id' => $sku, 'quantity' => $quantity]]]);
    }
    fwrite(STDOUT, 'RECORDED'.PHP_EOL);
} catch (ValidationException $exception) {
    fwrite(STDOUT, 'REFUSED '.collect($exception->errors())->flatten()->first().PHP_EOL);
} catch (AuthorizationException $exception) {
    fwrite(STDOUT, 'REFUSED '.$exception->getMessage().PHP_EOL);
}

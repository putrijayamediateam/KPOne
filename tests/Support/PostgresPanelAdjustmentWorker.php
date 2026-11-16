<?php

declare(strict_types=1);

use App\Domain\Access\BranchAccessService;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Models\PanelClaimAdjustment;
use App\Domain\Visit\Billing\Services\PanelClaimAdjustmentService;
use App\Domain\Visit\Billing\Services\PanelClaimService;
use App\Domain\Visit\Billing\Services\PaymentService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * PCA-2 contention worker: one Panel claim adjustment confirmation, one claim decision or one patient payment in its own
 * PostgreSQL session. Prints READY <pid>, waits for GO, runs the service call and prints SUCCESS <id>,
 * STALE <field>: <message> (validation refusal) or DENIED.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $db = DB::connection();
    if (! app()->environment('testing') || $db->getDriverName() !== 'pgsql' || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', (string) $db->getDatabaseName()) !== 1) {
        exit(65);
    }
    $db->statement("select set_config('application_name', ?, false)", [(string) end($argv)]);
    fwrite(STDOUT, 'READY '.(int) $db->scalar('select pg_backend_pid()').PHP_EOL);
    fflush(STDOUT);
    if (trim((string) fgets(STDIN)) !== 'GO') {
        exit(66);
    }
    $mode = $argv[1];
    $actor = User::query()->findOrFail((int) $argv[2]);
    $a = json_decode(base64_decode($argv[4], true), true, 64, JSON_THROW_ON_ERROR);
    app('session')->start();
    session([BranchAccessService::SESSION_KEY => (int) $argv[3]]);
    $a['expected_branch_id'] = (int) $argv[3];
    $result = match ($mode) {
        'confirm' => app(PanelClaimAdjustmentService::class)
            ->confirm($actor, PanelClaimAdjustment::query()->where('public_id', $a['adjustment'])->firstOrFail(), $a)->public_id,
        'submit' => app(PanelClaimService::class)
            ->submit($actor, PanelClaim::query()->where('public_id', $a['claim'])->firstOrFail(), $a)->public_id,
        'decision' => app(PanelClaimService::class)
            ->recordDecision($actor, PanelClaim::query()->where('public_id', $a['claim'])->firstOrFail(), $a)->public_id,
        'payment' => app(PaymentService::class)->add($actor, Visit::query()->where('visit_number', $a['visit'])->firstOrFail(),
            Invoice::query()->where('public_id', $a['invoice'])->firstOrFail(), $a)->public_id,
        default => throw new RuntimeException('Unknown test worker mode'),
    };
    fwrite(STDOUT, 'SUCCESS '.$result.PHP_EOL);
} catch (ValidationException $e) {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $field = (string) array_key_first($e->errors());
    fwrite(STDOUT, 'STALE '.$field.': '.str_replace(["\r", "\n"], ' ', (string) ($e->errors()[$field][0] ?? '')).PHP_EOL);
} catch (AuthorizationException|ModelNotFoundException|HttpException) {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    fwrite(STDOUT, 'DENIED'.PHP_EOL);
} catch (Throwable $e) {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    fwrite(STDERR, get_class($e).' SQLSTATE='.$e->getCode().' '.$e->getMessage().PHP_EOL);
    exit(1);
}

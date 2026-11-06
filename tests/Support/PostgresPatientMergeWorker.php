<?php

declare(strict_types=1);

use App\Domain\Access\BranchAccessService;
use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Models\PatientMergeRequest;
use App\Domain\Patient\Services\PatientMergeService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * REG-8 contention worker: one patient merge proposal or one decision (approve, reject, withdraw) in its own
 * PostgreSQL session. Prints READY <pid>, waits for GO, runs the service call and prints
 * SUCCESS <request id> <status>, STALE <field>: <message> (validation refusal) or DENIED.
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
    $a = json_decode((string) base64_decode($argv[4], true), true, 64, JSON_THROW_ON_ERROR);
    app('session')->start();
    session([BranchAccessService::SESSION_KEY => (int) $argv[3]]);
    $a['expected_branch_id'] = (int) $argv[3];
    $service = app(PatientMergeService::class);
    $request = match ($mode) {
        'propose' => $service->propose($actor, Patient::query()->findOrFail((int) $a['kept_patient_id']), $a),
        'approve', 'reject', 'withdraw' => $service->{$mode}($actor, PatientMergeRequest::query()->findOrFail((int) $a['request_id']), $a),
        default => throw new RuntimeException('Unknown test worker mode'),
    };
    fwrite(STDOUT, 'SUCCESS '.$request->id.' '.$request->status.PHP_EOL);
} catch (ValidationException $e) {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    // The refusal's field and first message, so the test can tell which rule refused it.
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

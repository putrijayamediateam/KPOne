<?php

declare(strict_types=1);

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Triage\TriageService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Models\Patient;
use App\Domain\Queue\Emergency\EmergencyAlertService;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Queue\Services\RedZoneAlertService;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitAdministrationService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Family link save worker; READY/GO protocol follows the patient merge harness.
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
    $visit = Visit::findOrFail($a['visit_id']);
    if ($mode === 'revoke') {
        DB::transaction(function () use ($actor) {
            $locked = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $locked->removeRole('resident_doctor');
        });
    } elseif ($mode === 'activate') {
        app(EmergencyAlertService::class)->activate(Branch::findOrFail((int) $argv[3]), true);
    } elseif ($mode === 'legacy') {
        app(RedZoneAlertService::class)->take($actor, $visit, (int) $argv[3]);
    } elseif ($mode === 'cancel') {
        app(VisitAdministrationService::class)->cancel($visit, $a, $actor);
    } elseif ($mode === 'call') {
        app(QueueEntryService::class)->call($actor, $visit, $a);
    } elseif ($mode === 'triage') {
        app(TriageService::class)->record($actor, $visit, $a);
    } else {
        app(EmergencyAlertService::class)->{$mode}($actor, $visit, $a);
    }
    fwrite(STDOUT, 'SUCCESS'.PHP_EOL);
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

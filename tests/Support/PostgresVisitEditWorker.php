<?php

declare(strict_types=1);

use App\Domain\Access\BranchAccessService;
use App\Domain\Queue\Readiness\CallHoldService;
use App\Domain\Queue\Services\CallNextService;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitAdministrationService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\IsolatedTestStorage;

require dirname(__DIR__, 2).'/vendor/autoload.php';
IsolatedTestStorage::beforeBootstrap();
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
IsolatedTestStorage::configure($app);
try {
    if (! app()->environment('testing') || DB::getDriverName() !== 'pgsql' || ! preg_match('/(?:^|_)test(?:ing)?(?:_|$)/i', DB::getDatabaseName())) {
        exit(65);
    }
    [, $mode, $actorId, $visitId, $branchId, $visitVersion, $queueVersion, $newDoctorId, $reasonPublicId, $name] = $argv;
    DB::statement("select set_config('application_name', ?, false)", [$name]);
    fwrite(STDOUT, 'READY '.DB::scalar('select pg_backend_pid()').PHP_EOL);
    fflush(STDOUT);
    if (trim((string) fgets(STDIN)) !== 'GO') {
        exit(66);
    }
    app('session')->start();
    session([BranchAccessService::SESSION_KEY => (int) $branchId]);
    $actor = User::findOrFail((int) $actorId);
    $visit = Visit::findOrFail((int) $visitId);
    $input = ['expected_branch_id' => (int) $branchId, 'visit_lock_version' => (int) $visitVersion, 'queue_lock_version' => (int) $queueVersion, 'idempotency_key' => (string) Str::uuid()];
    try {
        if ($mode === 'edit') {
            app(VisitAdministrationService::class)->update($visit, [
                'expected_branch_id' => (int) $branchId, 'lock_version' => (int) $visitVersion, 'queue_lock_version' => (int) $queueVersion,
                'visit_type' => $visit->visit_type, 'assigned_doctor_user_id' => (int) $newDoctorId,
                'visit_reason_public_ids' => [$reasonPublicId], 'priority' => $visit->priority,
                'coverage_type' => $visit->coverage_type, 'panel_id' => $visit->panel_id,
            ], $actor);
        } elseif ($mode === 'call') {
            app(QueueEntryService::class)->call($actor, $visit, $input);
        } elseif ($mode === 'callnext') {
            app(CallNextService::class)->call($actor, [
                'expected_branch_id' => (int) $branchId, 'doctor_user_id' => $actor->id, 'visit_number' => $visit->visit_number,
                'visit_lock_version' => (int) $visitVersion, 'queue_lock_version' => (int) $queueVersion,
            ]);
        } else {
            app(CallHoldService::class)->change($actor, $visit, [...$input, 'reason_code' => 'awaiting_panel_confirmation'], false);
        }
        fwrite(STDOUT, strtoupper($mode).'_OK'.PHP_EOL);
    } catch (ValidationException|AuthorizationException $e) {
        fwrite(STDOUT, strtoupper($mode).'_REFUSED'.PHP_EOL);
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e::class.': '.$e->getMessage().PHP_EOL);
    exit(1);
}

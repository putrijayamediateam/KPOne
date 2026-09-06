<?php

declare(strict_types=1);

use App\Domain\Access\BranchAccessService;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitAdministrationService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$application = require dirname(__DIR__, 2).'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();

try {
    $connection = DB::connection();
    $database = (string) $connection->getDatabaseName();
    if (! app()->environment('testing') || $connection->getDriverName() !== 'pgsql'
        || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', $database) !== 1) {
        exit(65);
    }

    $mode = $argv[1] ?? '';
    $applicationName = (string) end($argv);
    $connection->statement("select set_config('application_name', ?, false)", [$applicationName]);
    $pid = (int) $connection->scalar('select pg_backend_pid()');
    fwrite(STDOUT, "READY {$pid}".PHP_EOL);
    fflush(STDOUT);
    if (trim((string) fgets(STDIN)) !== 'GO') {
        exit(66);
    }

    if ($mode === 'enter' && count($argv) === 6) {
        [, , $actorId, $visitNumber, $branchId] = $argv;
        app('session')->start();
        session([BranchAccessService::SESSION_KEY => (int) $branchId]);
        $visit = Visit::query()->where('visit_number', $visitNumber)->firstOrFail();
        try {
            $entry = app(QueueEntryService::class)->enter(User::query()->findOrFail((int) $actorId), $visit, [
                'expected_branch_id' => (int) $branchId,
                'visit_lock_version' => $visit->lock_version,
            ]);
            fwrite(STDOUT, "ENTERED {$entry->queue_number}".PHP_EOL);
        } catch (ValidationException|AuthorizationException) {
            fwrite(STDOUT, 'REJECTED'.PHP_EOL);
        }
        exit(0);
    }

    if ($mode === 'call' && count($argv) === 8) {
        [, , $actorId, $visitNumber, $branchId, $visitVersion, $queueVersion] = $argv;
        app('session')->start();
        session([BranchAccessService::SESSION_KEY => (int) $branchId]);
        $visit = Visit::query()->where('visit_number', $visitNumber)->firstOrFail();
        try {
            app(QueueEntryService::class)->call(User::query()->findOrFail((int) $actorId), $visit, [
                'expected_branch_id' => (int) $branchId,
                'visit_lock_version' => (int) $visitVersion,
                'queue_lock_version' => (int) $queueVersion,
            ]);
            fwrite(STDOUT, 'CALLED'.PHP_EOL);
        } catch (ValidationException|AuthorizationException) {
            fwrite(STDOUT, 'STALE'.PHP_EOL);
        }
        exit(0);
    }

    if (in_array($mode, ['priority', 'reassign'], true) && count($argv) === 9) {
        [, , $actorId, $visitNumber, $branchId, $visitVersion, $queueVersion, $doctorId] = $argv;
        app('session')->start();
        session([BranchAccessService::SESSION_KEY => (int) $branchId]);
        $visit = Visit::query()->where('visit_number', $visitNumber)->firstOrFail();
        try {
            app(VisitAdministrationService::class)->update($visit, [
                'expected_branch_id' => (int) $branchId,
                'lock_version' => (int) $visitVersion,
                'queue_lock_version' => (int) $queueVersion,
                'visit_type' => 'consultation',
                'assigned_doctor_user_id' => $mode === 'reassign' ? (int) $doctorId : $visit->assigned_doctor_user_id,
                'visit_reason_public_ids' => $visit->reasonAssignments()->with('reason')->get()
                    ->pluck('reason.public_id')->all(),
                'priority' => $mode === 'priority' ? 'urgent' : $visit->priority,
                'coverage_type' => 'self_pay',
            ], User::query()->findOrFail((int) $actorId));
            fwrite(STDOUT, 'UPDATED'.PHP_EOL);
        } catch (ValidationException|AuthorizationException) {
            fwrite(STDOUT, 'STALE'.PHP_EOL);
        }
        exit(0);
    }

    if ($mode === 'cancel' && count($argv) === 8) {
        [, , $actorId, $visitNumber, $branchId, $visitVersion, $queueVersion] = $argv;
        app('session')->start();
        session([BranchAccessService::SESSION_KEY => (int) $branchId]);
        $visit = Visit::query()->where('visit_number', $visitNumber)->firstOrFail();
        try {
            app(VisitAdministrationService::class)->cancel($visit, [
                'expected_branch_id' => (int) $branchId,
                'lock_version' => (int) $visitVersion,
                'queue_lock_version' => $queueVersion === 'none' ? null : (int) $queueVersion,
                'cancellation_reason' => 'Synthetic PostgreSQL Queue cancellation',
            ], User::query()->findOrFail((int) $actorId));
            fwrite(STDOUT, 'CANCELLED'.PHP_EOL);
        } catch (ValidationException|AuthorizationException) {
            fwrite(STDOUT, 'STALE'.PHP_EOL);
        }
        exit(0);
    }

    exit(64);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
}

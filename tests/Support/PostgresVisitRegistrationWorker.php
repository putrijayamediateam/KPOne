<?php

declare(strict_types=1);

use App\Domain\Access\BranchAccessService;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Identity\Services\BranchAssignmentService;
use App\Domain\Identity\Services\StaffRoleService;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitAdministrationService;
use App\Domain\Visit\Services\VisitRegistrationService;
use App\Models\User;
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

    if ($mode === 'register' && count($argv) === 9) {
        [, , $actorId, $patientNumber, $branchId, $key, $confirm, $doctorId] = $argv;
        app('session')->start();
        session([BranchAccessService::SESSION_KEY => (int) $branchId]);
        try {
            $visit = app(VisitRegistrationService::class)->register(User::query()->findOrFail((int) $actorId), [
                'idempotency_key' => $key,
                'expected_branch_id' => (int) $branchId,
                'patient_number' => in_array($patientNumber, ['QUICK', 'QUICK_IDENTIFIER'], true) ? null : $patientNumber,
                'quick_patient' => in_array($patientNumber, ['QUICK', 'QUICK_IDENTIFIER'], true)
                    ? [
                        'full_name' => 'Synthetic Idempotent Quick',
                        'sex' => 'unknown',
                        'mobile_phone' => '+60123456789',
                        'duplicate_override' => true,
                        'identifiers' => $patientNumber === 'QUICK_IDENTIFIER' ? [[
                            'identifier_type' => 'passport',
                            'issuing_country_code' => 'MY',
                            'value' => 'SYNTH-CONCURRENT-001',
                        ]] : [],
                    ]
                    : null,
                'visit_type' => $doctorId === 'none' ? 'otc' : 'consultation',
                'assigned_doctor_user_id' => $doctorId === 'none' ? null : (int) $doctorId,
                'visit_reason' => $doctorId === 'none' ? null : 'Synthetic concurrent reason',
                'priority' => 'normal',
                'coverage_type' => 'self_pay',
                'confirm_repeat' => $confirm === 'yes',
            ]);
            fwrite(STDOUT, "VISIT {$visit->visit_number}".PHP_EOL);
        } catch (ValidationException $exception) {
            fwrite(STDOUT, array_key_exists('confirm_repeat', $exception->errors()) ? 'REPEAT'.PHP_EOL : 'INVALID'.PHP_EOL);
        }
        exit(0);
    }

    if ($mode === 'deactivate' && count($argv) === 4) {
        [, , $doctorId] = $argv;
        $connection->beginTransaction();
        $doctor = User::query()->whereKey((int) $doctorId)->lockForUpdate()->firstOrFail();
        $doctor->forceFill(['is_active' => false])->save();
        fwrite(STDOUT, 'LOCKED'.PHP_EOL);
        fflush(STDOUT);
        if (trim((string) fgets(STDIN)) !== 'COMMIT') {
            exit(67);
        }
        $connection->commit();
        fwrite(STDOUT, 'DEACTIVATED'.PHP_EOL);
        exit(0);
    }

    if ($mode === 'change-role' && count($argv) === 5) {
        [, , $actorId, $doctorId] = $argv;
        $connection->beginTransaction();
        $doctor = User::query()->whereKey((int) $doctorId)->lockForUpdate()->firstOrFail();
        fwrite(STDOUT, 'LOCKED'.PHP_EOL);
        fflush(STDOUT);
        if (trim((string) fgets(STDIN)) !== 'COMMIT') {
            exit(67);
        }
        app(StaffRoleService::class)->sync(
            $doctor,
            ['ca'],
            User::query()->findOrFail((int) $actorId),
        );
        $connection->commit();
        fwrite(STDOUT, 'ROLE_CHANGED'.PHP_EOL);
        exit(0);
    }

    if ($mode === 'change-assignment' && count($argv) === 6) {
        [, , $actorId, $profileId, $assignmentId] = $argv;
        $connection->beginTransaction();
        $profile = StaffProfile::query()->whereKey((int) $profileId)->lockForUpdate()->firstOrFail();
        $assignment = StaffBranchAssignment::query()->whereKey((int) $assignmentId)->firstOrFail();
        fwrite(STDOUT, 'LOCKED'.PHP_EOL);
        fflush(STDOUT);
        if (trim((string) fgets(STDIN)) !== 'COMMIT') {
            exit(67);
        }
        $futureBranchDate = now()
            ->setTimezone($assignment->branch()->firstOrFail()->timezone)
            ->addDay()
            ->toDateString();
        app(BranchAssignmentService::class)->update($assignment, [
            'valid_from' => $futureBranchDate,
            'valid_until' => $futureBranchDate,
        ], User::query()->findOrFail((int) $actorId));
        $connection->commit();
        fwrite(STDOUT, 'ASSIGNMENT_CHANGED'.PHP_EOL);
        exit(0);
    }

    if (in_array($mode, ['update', 'cancel'], true) && count($argv) === 7) {
        [, , $actorId, $visitNumber, $branchId, $version] = $argv;
        app('session')->start();
        session([BranchAccessService::SESSION_KEY => (int) $branchId]);
        $visit = Visit::query()->where('visit_number', $visitNumber)->firstOrFail();
        try {
            if ($mode === 'update') {
                app(VisitAdministrationService::class)->update($visit, [
                    'expected_branch_id' => (int) $branchId,
                    'lock_version' => (int) $version,
                    'visit_type' => 'otc',
                    'assigned_doctor_user_id' => null,
                    'visit_reason' => null,
                    'priority' => 'urgent',
                    'coverage_type' => 'self_pay',
                ], User::query()->findOrFail((int) $actorId));
            } else {
                app(VisitAdministrationService::class)->cancel($visit, [
                    'expected_branch_id' => (int) $branchId,
                    'lock_version' => (int) $version,
                    'cancellation_reason' => 'Synthetic concurrent cancellation',
                ], User::query()->findOrFail((int) $actorId));
            }
            fwrite(STDOUT, 'CHANGED'.PHP_EOL);
        } catch (ValidationException) {
            fwrite(STDOUT, 'STALE'.PHP_EOL);
        }
        exit(0);
    }

    exit(64);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
}

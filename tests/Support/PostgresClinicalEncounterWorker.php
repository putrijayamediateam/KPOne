<?php

declare(strict_types=1);

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Services\ClinicalEncounterService;
use App\Domain\Visit\Models\Visit;
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

    if ($mode === 'start' && count($argv) === 8) {
        [, , $actorId, $visitNumber, $branchId, $visitVersion, $queueVersion] = $argv;
        app('session')->start();
        session([BranchAccessService::SESSION_KEY => (int) $branchId]);
        $visit = Visit::query()->where('visit_number', $visitNumber)->firstOrFail();
        try {
            $encounter = app(ClinicalEncounterService::class)->start(
                User::query()->findOrFail((int) $actorId),
                $visit,
                [
                    'expected_branch_id' => (int) $branchId,
                    'visit_lock_version' => (int) $visitVersion,
                    'queue_lock_version' => (int) $queueVersion,
                ],
            );
            fwrite(STDOUT, "STARTED {$encounter->id}".PHP_EOL);
        } catch (ValidationException|AuthorizationException) {
            fwrite(STDOUT, 'REJECTED'.PHP_EOL);
        }
        exit(0);
    }

    if ($mode === 'update' && count($argv) === 8) {
        [, , $actorId, $visitNumber, $branchId, $encounterVersion, $token] = $argv;
        app('session')->start();
        session([BranchAccessService::SESSION_KEY => (int) $branchId]);
        $visit = Visit::query()->where('visit_number', $visitNumber)->firstOrFail();
        try {
            app(ClinicalEncounterService::class)->update(
                User::query()->findOrFail((int) $actorId),
                $visit,
                [
                    'expected_branch_id' => (int) $branchId,
                    'lock_version' => (int) $encounterVersion,
                    'clinical_note' => "Synthetic concurrent note {$token}",
                    'vitals' => [
                        'systolic_bp' => 120,
                        'diastolic_bp' => 80,
                        'pulse_bpm' => 70,
                        'temperature_celsius' => 36.8,
                        'spo2_percent' => 98,
                        'weight_kg' => 60,
                        'height_cm' => 160,
                    ],
                    'diagnoses' => [[
                        'diagnosis_text' => "Synthetic concurrent diagnosis {$token}",
                        'diagnosis_code' => null,
                        'code_system' => null,
                        'is_primary' => true,
                    ]],
                ],
            );
            fwrite(STDOUT, 'UPDATED'.PHP_EOL);
        } catch (ValidationException|AuthorizationException) {
            fwrite(STDOUT, 'STALE'.PHP_EOL);
        }
        exit(0);
    }

    if ($mode === 'reassign-direct' && count($argv) === 5) {
        [, , $visitNumber, $newDoctorId] = $argv;
        $connection->beginTransaction();
        $visit = Visit::query()->where('visit_number', $visitNumber)->lockForUpdate()->firstOrFail();
        $visit->forceFill([
            'assigned_doctor_user_id' => (int) $newDoctorId,
            'lock_version' => $visit->lock_version + 1,
        ])->save();
        fwrite(STDOUT, 'LOCKED'.PHP_EOL);
        fflush(STDOUT);
        if (trim((string) fgets(STDIN)) !== 'COMMIT') {
            exit(67);
        }
        $connection->commit();
        fwrite(STDOUT, 'REASSIGNED'.PHP_EOL);
        exit(0);
    }

    exit(64);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
}

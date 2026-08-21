<?php

declare(strict_types=1);

use App\Domain\Patient\Services\PatientAdministrationService;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$application = require dirname(__DIR__, 2).'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();

try {
    $mode = $argv[1] ?? '';
    $connection = DB::connection();
    $databaseName = (string) $connection->getDatabaseName();

    if (! app()->environment('testing')
        || $connection->getDriverName() !== 'pgsql'
        || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', $databaseName) !== 1) {
        exit(65);
    }

    if ($mode === 'service' && count($argv) === 6) {
        [$script, $mode, $actorId, $suffix, $nric, $applicationName] = $argv;
        if (! ctype_digit($actorId) || preg_match('/\A[a-z0-9]+\z/', $suffix) !== 1 || preg_match('/\A[0-9]{12}\z/', $nric) !== 1) {
            exit(64);
        }
        $connection->statement("select set_config('application_name', ?, false)", [$applicationName]);
        $pid = (int) $connection->scalar('select pg_backend_pid()');
        fwrite(STDOUT, "READY {$pid}".PHP_EOL);
        fflush(STDOUT);
        if (trim((string) fgets(STDIN)) !== 'GO') {
            exit(66);
        }

        try {
            $patient = app(PatientAdministrationService::class)->create(
                User::query()->findOrFail((int) $actorId),
                [
                    'full_name' => 'Synthetic Concurrent '.$suffix,
                    'date_of_birth' => '1990-01-01',
                    'sex' => 'unknown',
                    'duplicate_override' => true,
                    'identifiers' => [['identifier_type' => 'nric', 'value' => $nric]],
                ],
            );
            fwrite(STDOUT, "CREATED {$patient->patient_number}".PHP_EOL);
        } catch (ValidationException) {
            fwrite(STDOUT, 'DUPLICATE'.PHP_EOL);
        }
        exit(0);
    }

    if ($mode === 'raw' && count($argv) === 7) {
        [$script, $mode, $organisationId, $patientId, $value, $hold, $applicationName] = $argv;
        $connection->statement("select set_config('application_name', ?, false)", [$applicationName]);
        $pid = (int) $connection->scalar('select pg_backend_pid()');
        fwrite(STDOUT, "READY {$pid}".PHP_EOL);
        fflush(STDOUT);
        if (trim((string) fgets(STDIN)) !== 'GO') {
            exit(66);
        }
        $connection->beginTransaction();
        try {
            fwrite(STDOUT, "ATTEMPTING {$pid}".PHP_EOL);
            fflush(STDOUT);
            $connection->table('patient_identifiers')->insert([
                'organisation_id' => (int) $organisationId,
                'patient_id' => (int) $patientId,
                'identifier_type' => 'passport',
                'issuing_country_code' => 'MY',
                'normalized_value' => $value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            fwrite(STDOUT, "INSERTED {$pid}".PHP_EOL);
            fflush(STDOUT);
            if ($hold === 'hold' && trim((string) fgets(STDIN)) !== 'COMMIT') {
                exit(67);
            }
            $connection->commit();
            fwrite(STDOUT, 'COMMITTED'.PHP_EOL);
        } catch (QueryException $exception) {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            fwrite(STDOUT, 'SQLSTATE '.($exception->errorInfo[0] ?? 'unknown').PHP_EOL);
        }
        exit(0);
    }

    exit(64);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.PHP_EOL);
    exit(1);
}

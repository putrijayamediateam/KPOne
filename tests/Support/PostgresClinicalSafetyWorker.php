<?php

declare(strict_types=1);

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Models\ClinicalEncounterAllergyReview;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Clinical\Services\AllergyReviewGate;
use App\Domain\Clinical\Services\CurrentClinicalCareService;
use App\Domain\Clinical\Services\PatientAllergyService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

    if (in_array($mode, ['raw-error-hold', 'raw-error-commit'], true) && count($argv) === 6) {
        [, , $profileId, $recordId, $actorId] = $argv;
        try {
            $connection->beginTransaction();
            $connection->table('patient_allergy_records')
                ->where('id', (int) $recordId)
                ->where('patient_allergy_profile_id', (int) $profileId)
                ->update([
                    'status' => 'entered_in_error',
                    'updated_by_user_id' => (int) $actorId,
                    'entered_in_error_at' => now()->utc(),
                    'entered_in_error_by_user_id' => (int) $actorId,
                    'updated_at' => now()->utc(),
                ]);

            if ($mode === 'raw-error-hold') {
                $connection->table('patient_allergy_profiles')
                    ->where('id', (int) $profileId)
                    ->lockForUpdate()
                    ->first();
                fwrite(STDOUT, 'LOCKED'.PHP_EOL);
                fflush(STDOUT);
                if (trim((string) fgets(STDIN)) !== 'COMMIT') {
                    exit(67);
                }
            } else {
                fwrite(STDOUT, 'MUTATED'.PHP_EOL);
                fflush(STDOUT);
            }

            $connection->commit();
            fwrite(STDOUT, 'COMMITTED'.PHP_EOL);
        } catch (Throwable) {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            fwrite(STDOUT, 'REJECTED'.PHP_EOL);
        }
        exit(0);
    }

    if ($mode === 'revoke-permission' && count($argv) === 5) {
        [, , $actorId, $permission] = $argv;
        $connection->beginTransaction();
        $actor = User::query()->whereKey((int) $actorId)->lockForUpdate()->firstOrFail();
        $actor->revokePermissionTo($permission);
        fwrite(STDOUT, 'LOCKED'.PHP_EOL);
        fflush(STDOUT);
        if (trim((string) fgets(STDIN)) !== 'COMMIT') {
            exit(67);
        }
        $connection->commit();
        fwrite(STDOUT, 'REVOKED'.PHP_EOL);
        exit(0);
    }

    $actorId = (int) ($argv[2] ?? 0);
    $visitNumber = (string) ($argv[3] ?? '');
    $branchId = (int) ($argv[4] ?? 0);
    app('session')->start();
    session([BranchAccessService::SESSION_KEY => $branchId]);
    $actor = User::query()->findOrFail($actorId);
    $visit = Visit::query()->where('visit_number', $visitNumber)->firstOrFail();
    $expected = ($argv[5] ?? 'null') === 'null' ? null : (int) $argv[5];
    $base = [
        'expected_branch_id' => $branchId,
        'profile_lock_version' => $expected,
    ];

    try {
        if ($mode === 'declare' && count($argv) === 7) {
            $profile = app(PatientAllergyService::class)->declareNoKnown($actor, $visit, $base);
            fwrite(STDOUT, "DECLARED {$profile->lock_version}".PHP_EOL);
            exit(0);
        }
        if ($mode === 'add' && count($argv) === 8) {
            $record = app(PatientAllergyService::class)->add($actor, $visit, [
                ...$base,
                'allergen_text' => 'Synthetic concurrent allergen '.$argv[6],
                'category' => 'medication',
                'reaction_text' => null,
                'severity' => null,
            ]);
            fwrite(STDOUT, "ADDED {$record->public_id}".PHP_EOL);
            exit(0);
        }
        if ($mode === 'edit' && count($argv) === 9) {
            app(PatientAllergyService::class)->update($actor, $visit, (string) $argv[6], [
                ...$base,
                'allergen_text' => 'Synthetic edited allergen '.$argv[7],
                'category' => 'medication',
                'reaction_text' => null,
                'severity' => null,
            ]);
            fwrite(STDOUT, 'UPDATED'.PHP_EOL);
            exit(0);
        }
        if ($mode === 'error' && count($argv) === 8) {
            app(PatientAllergyService::class)->enterInError($actor, $visit, (string) $argv[6], $base);
            fwrite(STDOUT, 'ERRORED'.PHP_EOL);
            exit(0);
        }
        if ($mode === 'review' && count($argv) === 7) {
            $review = app(PatientAllergyService::class)->review($actor, $visit, $base);
            fwrite(STDOUT, "REVIEWED {$review->allergy_profile_lock_version_reviewed}".PHP_EOL);
            exit(0);
        }
        if ($mode === 'gate' && count($argv) === 6) {
            $careService = app(CurrentClinicalCareService::class);
            $branch = $careService->activeBranch($actor, $visit, ['expected_branch_id' => $branchId]);
            DB::transaction(function () use ($careService, $actor, $visit, $branch): void {
                $care = $careService->lock($actor, $visit, $branch, 'allergies.review.own');
                $profile = PatientAllergyProfile::query()
                    ->where('organisation_id', $actor->organisation_id)
                    ->where('patient_id', $care->patient->id)
                    ->lockForUpdate()
                    ->first();
                $review = ClinicalEncounterAllergyReview::query()
                    ->where('clinical_encounter_id', $care->encounter->id)
                    ->lockForUpdate()
                    ->first();
                app(AllergyReviewGate::class)->assertCurrent($care, $profile, $review);
            });
            fwrite(STDOUT, 'ACCEPTED'.PHP_EOL);
            exit(0);
        }
    } catch (ValidationException|AuthorizationException|ModelNotFoundException|HttpException) {
        fwrite(STDOUT, 'STALE'.PHP_EOL);
        exit(0);
    }

    exit(64);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
}

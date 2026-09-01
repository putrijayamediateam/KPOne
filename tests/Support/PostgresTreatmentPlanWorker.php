<?php

declare(strict_types=1);

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Models\ClinicalServiceCatalogueItem;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Services\ClinicalEncounterService;
use App\Domain\Clinical\Services\PatientAllergyService;
use App\Domain\Clinical\Services\TreatmentPlanService;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Queue\Models\QueueEntry;
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

    if ($mode === 'end-assignment') {
        $connection->beginTransaction();
        $profile = StaffProfile::query()->where('user_id', (int) $argv[2])->lockForUpdate()->firstOrFail();
        StaffBranchAssignment::query()
            ->where('staff_profile_id', $profile->id)
            ->where('branch_id', (int) $argv[3])
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->each(fn (StaffBranchAssignment $assignment) => $assignment->forceFill([
                'valid_until' => now()->subDay()->toDateString(),
            ])->save());
        fwrite(STDOUT, 'LOCKED'.PHP_EOL);
        fflush(STDOUT);
        if (trim((string) fgets(STDIN)) !== 'COMMIT') {
            exit(67);
        }
        $connection->commit();
        fwrite(STDOUT, 'AUTHORITY_CHANGED'.PHP_EOL);
        exit(0);
    }

    if (in_array($mode, ['revoke-permission', 'deactivate', 'remove-role'], true)) {
        $actor = User::query()->whereKey((int) $argv[2])->lockForUpdate()->firstOrFail();
        $connection->beginTransaction();
        $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        if ($mode === 'revoke-permission') {
            $actor->revokePermissionTo((string) $argv[3]);
        } elseif ($mode === 'deactivate') {
            $actor->forceFill(['is_active' => false, 'deactivated_at' => now()->utc()])->save();
        } elseif ($mode === 'remove-role') {
            $actor->removeRole('resident_doctor');
        }
        fwrite(STDOUT, 'LOCKED'.PHP_EOL);
        fflush(STDOUT);
        if (trim((string) fgets(STDIN)) !== 'COMMIT') {
            exit(67);
        }
        $connection->commit();
        fwrite(STDOUT, 'AUTHORITY_CHANGED'.PHP_EOL);
        exit(0);
    }

    if (in_array($mode, ['inactivate-medicine', 'inactivate-service'], true)) {
        $connection->beginTransaction();
        $model = $mode === 'inactivate-medicine' ? MedicineCatalogueItem::query() : ClinicalServiceCatalogueItem::query();
        $item = $model->where('public_id', (string) $argv[2])->lockForUpdate()->firstOrFail();
        $item->forceFill(['is_active' => false])->save();
        fwrite(STDOUT, 'LOCKED'.PHP_EOL);
        fflush(STDOUT);
        if (trim((string) fgets(STDIN)) !== 'COMMIT') {
            exit(67);
        }
        $connection->commit();
        fwrite(STDOUT, 'CATALOGUE_INACTIVATED'.PHP_EOL);
        exit(0);
    }

    if ($mode === 'reassign') {
        $connection->beginTransaction();
        $visit = Visit::query()->where('visit_number', (string) $argv[2])->lockForUpdate()->firstOrFail();
        $visit->forceFill(['assigned_doctor_user_id' => (int) $argv[3]])->save();
        fwrite(STDOUT, 'LOCKED'.PHP_EOL);
        fflush(STDOUT);
        if (trim((string) fgets(STDIN)) !== 'COMMIT') {
            exit(67);
        }
        $connection->commit();
        fwrite(STDOUT, 'REASSIGNED'.PHP_EOL);
        exit(0);
    }

    if (in_array($mode, ['remove-queue', 'change-encounter-owner'], true)) {
        $connection->beginTransaction();
        $visit = Visit::query()->where('visit_number', (string) $argv[2])->lockForUpdate()->firstOrFail();
        $queue = QueueEntry::query()->where('visit_id', $visit->id)->lockForUpdate()->firstOrFail();
        if ($mode === 'remove-queue') {
            $queue->forceFill([
                'status' => QueueEntry::STATUS_REMOVED,
                'removed_at' => now()->utc(),
                'updated_by_user_id' => (int) $argv[3],
                'lock_version' => $queue->lock_version + 1,
            ])->save();
        } else {
            $encounter = ClinicalEncounter::query()->where('visit_id', $visit->id)->lockForUpdate()->firstOrFail();
            DB::table('clinical_encounters')->whereKey($encounter->id)->update([
                'attending_clinician_user_id' => (int) $argv[3],
                'updated_at' => now()->utc(),
            ]);
        }
        fwrite(STDOUT, 'LOCKED'.PHP_EOL);
        fflush(STDOUT);
        if (trim((string) fgets(STDIN)) !== 'COMMIT') {
            exit(67);
        }
        $connection->commit();
        fwrite(STDOUT, 'CLINICAL_STATE_CHANGED'.PHP_EOL);
        exit(0);
    }

    $actor = User::query()->findOrFail((int) $argv[2]);
    $visit = Visit::query()->where('visit_number', (string) $argv[3])->firstOrFail();
    $branchId = (int) $argv[4];
    $expected = $argv[5] === 'null' ? null : (int) $argv[5];
    $cataloguePublicId = (string) $argv[6];
    $suffix = (string) ($argv[7] ?? 'BASE');
    app('session')->start();
    session([BranchAccessService::SESSION_KEY => $branchId]);

    $payload = [
        'expected_branch_id' => $branchId,
        'lock_version' => $expected,
        'medicines' => [],
        'services' => [],
    ];
    if ($mode === 'save-note') {
        try {
            app(ClinicalEncounterService::class)->update($actor, $visit, [
                'expected_branch_id' => $branchId,
                'lock_version' => $expected,
                'clinical_note' => 'Synthetic independent Treatment Plan race note '.$suffix,
                'vitals' => [],
                'diagnoses' => [],
            ]);
            fwrite(STDOUT, 'NOTE_SAVED'.PHP_EOL);
        } catch (ValidationException) {
            fwrite(STDOUT, 'STALE'.PHP_EOL);
        } catch (AuthorizationException|ModelNotFoundException|HttpException) {
            fwrite(STDOUT, 'DENIED'.PHP_EOL);
        }
        exit(0);
    }

    if ($mode === 'add-allergy') {
        try {
            app(PatientAllergyService::class)->add($actor, $visit, [
                'expected_branch_id' => $branchId,
                'profile_lock_version' => $expected,
                'allergen_text' => 'Synthetic concurrent Treatment Plan allergen '.$suffix,
                'category' => 'medication',
                'reaction_text' => null,
                'severity' => null,
            ]);
            fwrite(STDOUT, 'ALLERGY_MUTATED'.PHP_EOL);
        } catch (ValidationException) {
            fwrite(STDOUT, 'STALE'.PHP_EOL);
        } catch (AuthorizationException|ModelNotFoundException|HttpException) {
            fwrite(STDOUT, 'DENIED'.PHP_EOL);
        }
        exit(0);
    }

    if ($mode === 'save-service') {
        $payload['services'][] = [
            'public_id' => null,
            'catalogue_public_id' => $cataloguePublicId,
            'quantity_ordered' => 1,
            'clinical_instruction' => 'Synthetic concurrent instruction '.$suffix,
        ];
    } elseif ($mode === 'update-service') {
        $payload['services'][] = [
            'public_id' => $cataloguePublicId,
            'catalogue_public_id' => null,
            'quantity_ordered' => 1,
            'clinical_instruction' => 'Synthetic concurrent instruction '.$suffix,
        ];
    } elseif ($mode === 'save-medicine') {
        $payload['medicines'][] = [
            'public_id' => null,
            'catalogue_public_id' => $cataloguePublicId,
            'quantity_ordered' => 1,
            'dosage' => 'Synthetic dosage '.$suffix,
            'frequency' => 'Synthetic frequency',
            'duration' => null,
            'route' => null,
            'administration_instruction' => null,
            'indication' => null,
            'precaution' => null,
        ];
    } elseif ($mode === 'update-medicine') {
        $payload['medicines'][] = [
            'public_id' => $cataloguePublicId,
            'catalogue_public_id' => null,
            'quantity_ordered' => 1,
            'dosage' => 'Synthetic dosage '.$suffix,
            'frequency' => 'Synthetic frequency',
            'duration' => null,
            'route' => null,
            'administration_instruction' => null,
            'indication' => null,
            'precaution' => null,
        ];
    } elseif ($mode !== 'withdraw-all') {
        exit(64);
    }

    try {
        $plan = app(TreatmentPlanService::class)->save($actor, $visit, $payload);
        fwrite(STDOUT, 'SAVED '.($plan?->lock_version ?? 0).PHP_EOL);
    } catch (ValidationException) {
        fwrite(STDOUT, 'STALE'.PHP_EOL);
    } catch (AuthorizationException|ModelNotFoundException|HttpException) {
        fwrite(STDOUT, 'DENIED'.PHP_EOL);
    }
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
}

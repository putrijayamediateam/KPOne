<?php

declare(strict_types=1);

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryItem;
use App\Domain\Clinical\Dispensary\Models\DispensaryItemException;
use App\Domain\Clinical\Dispensary\Services\DispensaryHandoffService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Clinical\Models\PatientAllergyProfileVersion;
use App\Domain\Clinical\Models\TreatmentPlan;
use App\Domain\Clinical\Services\TreatmentPlanService;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Patient\Models\Patient;
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

    $mode = (string) ($argv[1] ?? '');
    $applicationName = (string) end($argv);
    $connection->statement("select set_config('application_name', ?, false)", [$applicationName]);
    fwrite(STDOUT, 'READY '.(int) $connection->scalar('select pg_backend_pid()').PHP_EOL);
    fflush(STDOUT);
    if (trim((string) fgets(STDIN)) !== 'GO') {
        exit(66);
    }

    if (in_array($mode, ['deactivate', 'revoke', 'end-assignment', 'state-loss', 'plan-version', 'allergy-mutate'], true)) {
        $connection->beginTransaction();
        if ($mode === 'deactivate') {
            User::query()->whereKey((int) $argv[2])->lockForUpdate()->firstOrFail()
                ->forceFill(['is_active' => false, 'deactivated_at' => now()->utc()])->save();
        } elseif ($mode === 'revoke') {
            User::query()->whereKey((int) $argv[2])->lockForUpdate()->firstOrFail()
                ->revokePermissionTo((string) $argv[3]);
        } elseif ($mode === 'end-assignment') {
            User::query()->whereKey((int) $argv[2])->lockForUpdate()->firstOrFail();
            $profile = StaffProfile::query()->where('user_id', (int) $argv[2])->lockForUpdate()->firstOrFail();
            StaffBranchAssignment::query()->where('staff_profile_id', $profile->id)->where('branch_id', (int) $argv[3])
                ->orderBy('id')->lockForUpdate()->get()->each(fn ($assignment) => $assignment
                ->forceFill(['valid_until' => now()->subDay()->toDateString()])->save());
        } elseif ($mode === 'state-loss') {
            User::query()->whereKey((int) $argv[4])->lockForUpdate()->firstOrFail();
            $profile = StaffProfile::query()->where('user_id', (int) $argv[4])->lockForUpdate()->firstOrFail();
            StaffBranchAssignment::query()->where('staff_profile_id', $profile->id)->orderBy('id')->lockForUpdate()->get();
            $visitStub = Visit::query()->where('visit_number', (string) $argv[2])->firstOrFail();
            Patient::query()->whereKey($visitStub->patient_id)->lockForUpdate()->firstOrFail();
            $visit = Visit::query()->whereKey($visitStub->id)->lockForUpdate()->firstOrFail();
            $kind = (string) $argv[3];
            if ($kind === 'visit') {
                $visit->forceFill([
                    'status' => Visit::STATUS_CANCELLED,
                    'cancelled_at' => now()->utc(),
                    'cancelled_by_user_id' => (int) $argv[4],
                    'cancellation_reason' => 'Synthetic Phase 3A state-loss race',
                ])->save();
            } elseif ($kind === 'queue') {
                QueueEntry::query()->where('visit_id', $visit->id)->lockForUpdate()->firstOrFail()
                    ->forceFill(['removal_reason' => 'state_changed'])->save();
            } else {
                ClinicalEncounter::query()->where('visit_id', $visit->id)->lockForUpdate()->firstOrFail()
                    ->forceFill(['attending_clinician_user_id' => (int) $argv[4]])->save();
            }
        } elseif ($mode === 'plan-version') {
            $plan = TreatmentPlan::query()->whereKey((int) $argv[2])->lockForUpdate()->firstOrFail();
            DB::table('treatment_plans')->where('id', $plan->id)->increment('lock_version');
        } else {
            $profile = PatientAllergyProfile::query()->whereKey((int) $argv[2])->lockForUpdate()->firstOrFail();
            $changedAt = now()->utc();
            $profile->forceFill([
                'status' => PatientAllergyProfile::STATUS_UNKNOWN,
                'reviewed_at' => null,
                'reviewed_by_user_id' => null,
                'lock_version' => $profile->lock_version + 1,
            ])->save();
            $version = new PatientAllergyProfileVersion;
            $version->forceFill([
                'organisation_id' => $profile->organisation_id,
                'patient_allergy_profile_id' => $profile->id,
                'version' => $profile->lock_version,
                'resulting_status' => $profile->status,
                'changed_at' => $changedAt,
                'changed_by_user_id' => $profile->updated_by_user_id,
            ])->save();
        }
        fwrite(STDOUT, 'LOCKED'.PHP_EOL);
        fflush(STDOUT);
        if (trim((string) fgets(STDIN)) !== 'COMMIT') {
            exit(67);
        }
        $connection->commit();
        fwrite(STDOUT, 'STATE_CHANGED'.PHP_EOL);
        exit(0);
    }

    $actor = User::query()->findOrFail((int) $argv[2]);
    $branchId = (int) $argv[3];
    app('session')->start();
    session([BranchAccessService::SESSION_KEY => $branchId]);

    if ($mode === 'send') {
        $visit = Visit::query()->where('visit_number', (string) $argv[4])->firstOrFail();
        app(DispensaryHandoffService::class)->send($actor, $visit, ['expected_branch_id' => $branchId, 'lock_version' => (int) $argv[5]]);
        fwrite(STDOUT, 'SENT'.PHP_EOL);
    } elseif ($mode === 'plan-edit') {
        $visit = Visit::query()->where('visit_number', (string) $argv[4])->firstOrFail();
        $plan = TreatmentPlan::query()->whereKey((int) $argv[5])->with('medicineOrders')->firstOrFail();
        $order = $plan->medicineOrders->firstOrFail();
        app(TreatmentPlanService::class)->save($actor, $visit, [
            'expected_branch_id' => $branchId, 'lock_version' => (int) $argv[6],
            'medicines' => [[
                'public_id' => $order->public_id, 'catalogue_public_id' => null,
                'quantity_ordered' => $order->quantity_ordered, 'dosage' => 'Concurrent safe edit',
                'frequency' => $order->frequency, 'duration' => $order->duration, 'route' => $order->route,
                'administration_instruction' => $order->administration_instruction,
                'indication' => $order->indication, 'precaution' => $order->precaution,
            ]], 'services' => [],
        ]);
        fwrite(STDOUT, 'PLAN_SAVED'.PHP_EOL);
    } elseif (in_array($mode, ['start', 'complete', 'return'], true)) {
        $case = DispensaryCase::query()->where('public_id', (string) $argv[4])->firstOrFail();
        $payload = ['expected_branch_id' => $branchId, 'case_lock_version' => (int) $argv[5]];
        $method = $mode === 'return' ? 'returnToDoctor' : $mode;
        app(DispensaryService::class)->{$method}($actor, $case, $payload);
        fwrite(STDOUT, strtoupper($mode).'D'.PHP_EOL);
    } elseif (in_array($mode, ['update-item', 'proposal'], true)) {
        $case = DispensaryCase::query()->where('public_id', (string) $argv[4])->firstOrFail();
        $item = DispensaryItem::query()->where('public_id', (string) $argv[6])->firstOrFail();
        app(DispensaryService::class)->updateItem($actor, $case, $item, [
            'expected_branch_id' => $branchId, 'case_lock_version' => (int) $argv[5],
            'item_lock_version' => (int) $argv[7], 'status' => (string) $argv[8],
            'quantity_dispensed' => (string) $argv[9], 'reason' => (string) $argv[10], 'allocations' => [],
        ]);
        fwrite(STDOUT, 'ITEM_UPDATED'.PHP_EOL);
    } elseif ($mode === 'acknowledge') {
        $exception = DispensaryItemException::query()->where('public_id', (string) $argv[4])->firstOrFail();
        app(DispensaryService::class)->acknowledge($actor, $exception, [
            'case_lock_version' => (int) $argv[5], 'item_lock_version' => (int) $argv[6],
        ]);
        fwrite(STDOUT, 'ACKNOWLEDGED'.PHP_EOL);
    } elseif ($mode === 'transfer') {
        app(InventoryMovementService::class)->transfer($actor, [
            'expected_branch_id' => $branchId, 'source_location_public_id' => (string) $argv[4],
            'destination_location_public_id' => (string) $argv[5], 'sku_public_id' => (string) $argv[6],
            'batch_public_id' => (string) $argv[7], 'quantity' => (string) $argv[8],
        ]);
        fwrite(STDOUT, 'TRANSFERRED'.PHP_EOL);
    } else {
        exit(68);
    }
} catch (ValidationException $exception) {
    fwrite(STDOUT, 'STALE '.implode(',', array_keys($exception->errors())).PHP_EOL);
} catch (AuthorizationException|ModelNotFoundException|HttpException $exception) {
    fwrite(STDOUT, 'DENIED '.class_basename($exception).PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).' SQLSTATE='.(string) $exception->getCode().' '.$exception->getMessage().PHP_EOL);
    exit(70);
}

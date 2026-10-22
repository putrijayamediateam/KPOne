<?php

namespace App\Domain\Patient\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Patient\Models\Patient;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PatientWhatsAppService
{
    public function destination(User $actor, string $context, string $key): string
    {
        abort_unless(in_array($context, ['patient', 'claim'], true), 404);

        return DB::transaction(function () use ($actor, $context, $key): string {
            $actor = $actor->fresh();
            abort_unless($actor && $actor->is_active, 403);
            $selectedBranch = session(BranchAccessService::SESSION_KEY);
            $branch = app(BranchAccessService::class)->activeBranch($actor);
            abort_unless($branch && $branch->organisation_id === $actor->organisation_id, 403);
            abort_if($selectedBranch !== null && (int) $selectedBranch !== $branch->id, 403);
            abort_unless($actor->organisation()->where('is_active', true)->exists(), 403);
            $permission = $context === 'claim' ? 'panel.claims.patient_identity.view.branch' : 'patients.view.organisation';
            $actor = app(TransactionalActorAuthority::class)->lock($actor, $branch, $permission);
            // Use the freshly loaded, locked grant rows, not the permission
            // registrar's potentially cached role-to-permission map.
            $currentPermissions = $actor->getAllPermissions()->pluck('name');
            abort_unless($currentPermissions->contains($permission), 403);
            if ($context === 'claim') {
                abort_unless($currentPermissions->contains('panel.claims.manage.branch'), 403);
                abort_unless(Str::isUuid($key), 404);
                $claim = PanelClaim::query()->where('public_id', $key)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->firstOrFail(['patient_id']);
                $patient = Patient::query()->whereKey($claim->patient_id)->where('organisation_id', $actor->organisation_id)->firstOrFail(['id', 'organisation_id', 'mobile_phone']);
            } else {
                $patient = Patient::query()->where('patient_number', $key)->where('organisation_id', $actor->organisation_id)->firstOrFail(['id', 'organisation_id', 'mobile_phone']);
            }
            $number = WhatsAppNumber::normalize($patient->mobile_phone);
            abort_if($number === null, 422, 'No usable saved contact number.');
            $audit = app(AuditRecorder::class)->record('patient.whatsapp_opened', $patient, ['patient_id' => $patient->id], $actor, $branch);
            abort_if($audit === null, 503);

            return 'https://wa.me/'.$number;
        });
    }
}

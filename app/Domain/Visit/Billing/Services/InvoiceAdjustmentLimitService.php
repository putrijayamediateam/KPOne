<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\InvoiceAdjustmentLimit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InvoiceAdjustmentLimitService
{
    public function __construct(private TransactionalActorAuthority $authority, private AuditRecorder $audit) {}

    /**
     * Append a revision, including a null (not configured) revision.
     *
     * @param  array<string,mixed>  $input
     */
    public function save(User $actor, array $input): void
    {
        Validator::make($input, ['idempotency_key' => ['required', 'uuid'], 'lock_version' => ['required', 'integer', 'min:0']])->validate();
        $limit = ($input['limit_sen'] ?? null) === null ? null : ExactMoney::sen($input['limit_sen'], 'limit_sen');
        DB::transaction(function () use ($actor, $input, $limit): void {
            $branch = $this->authority->branch($actor, $input['expected_branch_id'] ?? null);
            $actor = $this->authority->lock($actor, $branch, 'billing.approval_limits.manage.organisation');
            Branch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', json_encode([$actor->id, $branch->id, $limit, (int) $input['lock_version']], JSON_THROW_ON_ERROR));
            $previous = InvoiceAdjustmentLimit::query()->where('organisation_id', $actor->organisation_id)->where('idempotency_key', $input['idempotency_key'])->first();
            if ($previous) {
                if (! hash_equals($previous->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This retry key belongs to a different threshold request.']);
                }

                return;
            }
            $threshold = InvoiceAdjustmentService::threshold($branch->id);
            if ($threshold['lockVersion'] !== (int) $input['lock_version']) {
                throw ValidationException::withMessages(['limit_sen' => 'The threshold changed. Reload before continuing.']);
            }
            $row = new InvoiceAdjustmentLimit;
            $row->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'actor_user_id' => $actor->id,
                'limit_sen' => $limit, 'lock_version' => $threshold['lockVersion'] + 1, 'idempotency_key' => $input['idempotency_key'], 'payload_hash' => $hash])->save();
            $this->audit->record('billing.adjustment_threshold_changed', $branch, ['record_version' => $row->lock_version, 'configured' => $limit !== null], $actor, $branch);
        }, 3);
    }
}

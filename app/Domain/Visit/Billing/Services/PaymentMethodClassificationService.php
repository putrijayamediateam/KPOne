<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PaymentMethodClassificationService
{
    public function __construct(private BranchAccessService $branches, private TransactionalActorAuthority $authority, private AuditRecorder $audit) {}

    /** @param array<string,mixed> $input */
    public function change(User $actor, PaymentMethod $method, array $input): PaymentMethod
    {
        $values = Validator::make($input, ['classification' => ['present', 'nullable', Rule::in(['cash', 'non_cash'])], 'lock_version' => ['required', 'integer', 'min:0']])->validate();

        return DB::transaction(function () use ($actor, $method, $values): PaymentMethod {
            $branch = $this->branches->activeBranch($actor);
            abort_unless($branch && $branch->is_active && DB::table('organisations')->where('id', $branch->organisation_id)->where('is_active', true)->exists(), 403);
            $actor = $this->authority->lock($actor, $branch, PaymentMethodAdministrationService::PERMISSION);
            if (! $actor->getAllPermissions()->pluck('name')->contains(PaymentMethodAdministrationService::PERMISSION)) {
                throw new AuthorizationException('You may not perform this operation.');
            }
            $locked = PaymentMethod::query()->whereKey($method->id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
            if ($locked->lock_version !== (int) $values['lock_version']) {
                throw ValidationException::withMessages(['lock_version' => 'This classification changed. Reload before saving.']);
            }
            if ($locked->classification === $values['classification']) {
                return $locked;
            }
            $locked->forceFill(['classification' => $values['classification'], 'lock_version' => $locked->lock_version + 1])->save();
            abort_if($this->audit->record('payment_method.classification_changed', $locked, ['method_id' => $locked->id, 'changed_fields' => ['classification']], $actor) === null, 503, 'Unable to record this change. Try again later.');

            return $locked;
        }, 3);
    }
}

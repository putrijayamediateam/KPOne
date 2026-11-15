<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class BillingApprovalLimitAdministrationService
{
    public const PERMISSION = 'billing.approval_limits.manage.organisation';

    /** @var array<string, string> */
    private const CAPABILITIES = [
        'panel' => 'coverage.approve.branch',
        'deferment' => 'outstanding.approve.branch',
    ];

    public function __construct(
        private AuditRecorder $audit,
        private BranchAccessService $branchAccess,
    ) {}

    /**
     * @return array<int, array{id:int, name:string, email:string, canPanelApprove:bool, canDefermentApprove:bool, panelLimitSen:int|null, defermentLimitSen:int|null}>
     */
    public function rows(User $actor, Branch $branch): array
    {
        $this->authorize($actor);
        abort_unless($this->branchAccess->canSelect($actor, $branch), 404);

        $users = User::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $limits = DB::table('billing_approval_limits')
            ->where('organisation_id', $actor->organisation_id)
            ->where('branch_id', $branch->id)
            ->whereIn('capability', array_keys(self::CAPABILITIES))
            ->get(['user_id', 'capability', 'limit_sen'])
            ->groupBy('user_id');

        $rows = [];
        foreach ($users as $user) {
            if (! $this->branchAccess->hasEffectiveAssignment($user, $branch)) {
                continue;
            }

            $canPanelApprove = $user->can(self::CAPABILITIES['panel']);
            $canDefermentApprove = $user->can(self::CAPABILITIES['deferment']);
            if (! $canPanelApprove && ! $canDefermentApprove) {
                continue;
            }

            $byCapability = collect($limits->get($user->id, collect()))->keyBy('capability');
            $rows[] = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'canPanelApprove' => $canPanelApprove,
                'canDefermentApprove' => $canDefermentApprove,
                'panelLimitSen' => $canPanelApprove ? $this->limitValue($byCapability->get('panel')) : null,
                'defermentLimitSen' => $canDefermentApprove ? $this->limitValue($byCapability->get('deferment')) : null,
            ];
        }

        return $rows;
    }

    /** @param array<string, mixed> $attributes */
    public function setLimit(User $actor, Branch $branch, array $attributes): void
    {
        $this->authorize($actor);
        $userId = filter_var($attributes['user_id'] ?? null, FILTER_VALIDATE_INT);
        $limitSen = filter_var($attributes['limit_sen'] ?? null, FILTER_VALIDATE_INT);
        $capability = is_string($attributes['capability'] ?? null) ? $attributes['capability'] : null;

        if (! $userId) {
            $this->invalid('user_id', 'Choose an approver.');
        }
        if (! $capability || ! array_key_exists($capability, self::CAPABILITIES)) {
            $this->invalid('capability', 'Choose Panel or Pay later approval.');
        }
        if ($limitSen === false || $limitSen < 0 || $limitSen > 999999999999) {
            $this->invalid('limit_sen', 'Enter a limit from 0 to 999999999999 sen.');
        }

        DB::transaction(function () use ($actor, $branch, $userId, $capability, $limitSen): void {
            $lockedBranch = $this->lockBranch($actor, $branch);
            $target = $this->lockTarget($actor, $lockedBranch, $userId, $capability);

            DB::table('billing_approval_limits')->updateOrInsert(
                [
                    'organisation_id' => $actor->organisation_id,
                    'branch_id' => $lockedBranch->id,
                    'user_id' => $target->id,
                    'capability' => $capability,
                ],
                ['limit_sen' => $limitSen],
            );

            $this->audit->record('billing.approval_limit.set', $lockedBranch, [
                'target_user_id' => $target->id,
                'capability' => $capability,
                'limit_sen' => $limitSen,
            ], $actor, $lockedBranch);
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function clearLimit(User $actor, Branch $branch, array $attributes): void
    {
        $this->authorize($actor);
        $userId = filter_var($attributes['user_id'] ?? null, FILTER_VALIDATE_INT);
        $capability = is_string($attributes['capability'] ?? null) ? $attributes['capability'] : null;
        if (! $userId) {
            $this->invalid('user_id', 'Choose an approver.');
        }
        if (! $capability || ! array_key_exists($capability, self::CAPABILITIES)) {
            $this->invalid('capability', 'Choose Panel or Pay later approval.');
        }

        DB::transaction(function () use ($actor, $branch, $userId, $capability): void {
            $lockedBranch = $this->lockBranch($actor, $branch);
            $target = $this->lockTarget($actor, $lockedBranch, $userId, $capability);
            DB::table('billing_approval_limits')
                ->where('organisation_id', $actor->organisation_id)
                ->where('branch_id', $lockedBranch->id)
                ->where('user_id', $target->id)
                ->where('capability', $capability)
                ->delete();

            $this->audit->record('billing.approval_limit.cleared', $lockedBranch, [
                'target_user_id' => $target->id,
                'capability' => $capability,
            ], $actor, $lockedBranch);
        }, 3);
    }

    private function authorize(User $actor): void
    {
        Gate::forUser($actor)->authorize(self::PERMISSION);
        if (! $actor->is_active || ! $actor->organisation_id) {
            throw new AuthorizationException('You may not manage billing approval limits.');
        }
    }

    private function lockBranch(User $actor, Branch $branch): Branch
    {
        abort_unless($this->branchAccess->canSelect($actor, $branch), 404);

        return Branch::query()
            ->whereKey($branch->id)
            ->where('organisation_id', $actor->organisation_id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->firstOr(function (): never {
                throw new ModelNotFoundException;
            });
    }

    private function lockTarget(User $actor, Branch $branch, int $userId, string $capability): User
    {
        $target = User::query()
            ->whereKey($userId)
            ->where('organisation_id', $actor->organisation_id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->firstOr(function (): never {
                throw new ModelNotFoundException;
            });

        if (! $this->branchAccess->hasEffectiveAssignment($target, $branch)) {
            $this->invalid('user_id', 'The selected approver is not effectively assigned to this branch.');
        }
        if (! $target->can(self::CAPABILITIES[$capability])) {
            $this->invalid('user_id', 'The selected approver does not hold the required approval permission.');
        }

        return $target;
    }

    private function limitValue(mixed $row): ?int
    {
        $value = $row->limit_sen ?? null;
        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

<?php

namespace App\Domain\Approvals;

use App\Domain\Access\BranchAccessService;
use App\Domain\Approvals\Sources\AttendanceCorrectionSource;
use App\Domain\Approvals\Sources\CashCloseSource;
use App\Domain\Approvals\Sources\LeaveRequestSource;
use App\Domain\Approvals\Sources\MemoToReadSource;
use App\Domain\Approvals\Sources\OvertimeRequestSource;
use App\Domain\Approvals\Sources\PanelPriceChangeSource;
use App\Domain\Approvals\Sources\PatientMergeSource;
use App\Domain\Approvals\Sources\PurchaseRequestSource;
use App\Domain\Approvals\Sources\SopToReadSource;
use App\Domain\Approvals\Sources\StaffClaimSource;
use App\Domain\Approvals\Sources\SupplierInvoiceSource;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;

/**
 * APPR-1: the one registry of sources. A team adds its source class here (and its contract test). Visibility is decided
 * fresh on every call from the person's current permissions and effective branch assignment; it is never cached.
 */
final class ApprovalSources
{
    /** @var list<class-string<ApprovalSource>> */
    public const REGISTERED = [
        CashCloseSource::class,
        SupplierInvoiceSource::class,
        PurchaseRequestSource::class,
        PanelPriceChangeSource::class,
        PatientMergeSource::class,
        LeaveRequestSource::class,
        AttendanceCorrectionSource::class,
        OvertimeRequestSource::class,
        StaffClaimSource::class,
        SopToReadSource::class,
        MemoToReadSource::class,
    ];

    public function __construct(private BranchAccessService $branches) {}

    /** @return list<ApprovalSource> */
    public function all(): array
    {
        return array_map(static fn (string $class): ApprovalSource => app($class), self::REGISTERED);
    }

    /**
     * The decide permissions of every registered APPROVAL source (tasks do not count): who gets the number, the account
     * menu row and the Clinic management row.
     *
     * @return list<string>
     */
    public function approvalPermissions(): array
    {
        $permissions = [];
        foreach ($this->all() as $source) {
            if ($source->kind() === ApprovalSource::APPROVAL) {
                array_push($permissions, ...$source->permissions());
            }
        }

        return array_values(array_unique($permissions));
    }

    /**
     * The sources this person may see now: they hold a decide permission and, for a branch source, have an effective
     * assignment at the branch.
     *
     * @return list<ApprovalSource>
     */
    public function visibleTo(User $actor, ?Branch $branch): array
    {
        return array_values(array_filter($this->all(), function (ApprovalSource $source) use ($actor, $branch): bool {
            if (! $actor->hasAnyPermission($source->permissions())) {
                return false;
            }
            if (! $source->branchScoped()) {
                return true;
            }

            return $branch !== null && $branch->organisation_id === $actor->organisation_id && $this->branches->hasEffectiveAssignment($actor, $branch);
        }));
    }
}

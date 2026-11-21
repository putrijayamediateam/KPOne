<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PNL-28a: Prepared Panel claims created by the system when a Panel bill is approved, and by the
 * "Create claims for waiting invoices" button. A claim failure never affects the approval.
 */
final class PanelClaimAutoCreator
{
    public const BATCH = 50;

    /** Plain-English reasons for the result panel (the code stays in a muted title). */
    public const REASONS = [
        'already_claimed' => 'Claim already exists',
        'not_approved' => 'Panel share is not approved',
        'reissued' => 'Invoice was reissued',
        'held_for_correction' => 'Held for correction',
        'share_zero' => 'Panel share is zero',
        'share_above_total' => 'Panel share is above the invoice total',
        'error' => 'Could not be created. Try again.',
    ];

    public function __construct(private PanelClaimService $claims, private PanelClaimEligibility $eligibility, private TransactionalActorAuthority $authority, private AuditRecorder $audit) {}

    /** The system step after an approval has committed. Never throws. */
    public function afterApproval(User $approver, string $allocationPublicId): void
    {
        if (! config('panel-claims.auto_create', true)) {
            return;
        }
        try {
            $allocation = CoverageAllocation::query()->where('public_id', $allocationPublicId)->where('organisation_id', $approver->organisation_id)->first();
            if ($allocation === null) {
                return;
            }
            $this->claims->prepareAutomatically($approver, $allocation, true);
        } catch (Throwable $e) {
            Log::warning('panel_claim.auto_create_failed', ['allocation' => $allocationPublicId, 'exception' => $e::class]);
            try {
                DB::transaction(fn () => $this->audit->record('panel_claim.auto_create_failed', CoverageAllocation::query()->where('public_id', $allocationPublicId)->firstOrFail(), ['code' => 'error'], $approver, null));
            } catch (Throwable) {
                // The failure is already logged; the Pending tab still lists the invoice.
            }
        }
    }

    /**
     * The button: up to BATCH of the oldest approved Panel shares at the active branch that have no claim.
     *
     * @return array{created: int, skipped: list<array{invoiceNumber: string, code: string, reason: string}>, remaining: int}
     */
    public function createWaiting(User $actor, int $expectedBranchId, int $limit = self::BATCH): array
    {
        $branch = DB::transaction(function () use (&$actor, $expectedBranchId) {
            $branch = $this->authority->branch($actor, $expectedBranchId);
            $actor = $this->authority->lock($actor, $branch, 'panel.claims.manage.branch');

            return $branch;
        });

        $waiting = $this->eligibility->query($actor->organisation_id, $branch->id)
            ->orderBy('v.registered_at')->orderBy('c.id')
            ->limit($limit)->get(['c.public_id', 'i.invoice_number']);
        $remaining = max(0, $this->eligibility->query($actor->organisation_id, $branch->id)->count() - $waiting->count());

        $created = 0;
        $skipped = [];
        foreach ($waiting as $row) {
            $code = 'error';
            try {
                $allocation = CoverageAllocation::query()->where('public_id', $row->public_id)->where('organisation_id', $actor->organisation_id)->firstOrFail();
                $result = $this->claims->prepareAutomatically($actor, $allocation, false);
                if ($result instanceof PanelClaim) {
                    $created++;

                    continue;
                }
                $code = $result;
            } catch (Throwable $e) {
                Log::warning('panel_claim.bulk_auto_create_failed', ['exception' => $e::class]);
            }
            $skipped[] = ['invoiceNumber' => (string) $row->invoice_number, 'code' => $code, 'reason' => self::REASONS[$code] ?? self::REASONS['error']];
        }

        DB::transaction(fn () => $this->audit->record('panel_claim.bulk_auto_created', $branch, ['created' => $created, 'skipped' => count($skipped), 'remaining' => $remaining], $actor, $branch));

        return ['created' => $created, 'skipped' => $skipped, 'remaining' => $remaining];
    }
}

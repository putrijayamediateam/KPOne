<?php

namespace App\Domain\Organisation\BusinessDetails;

use App\Domain\Access\BranchAccessService;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Services\BillingContextService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class BranchLetterheadService
{
    public function __construct(private BusinessDetailsAccess $access, private BranchAccessService $branches, private BranchLetterheadAssembler $assembler, private BillingContextService $billing) {}

    public function forActiveBranch(User $actor): BranchLetterhead
    {
        $branch = $this->branches->activeBranch($actor);
        abort_unless($branch !== null, 404);

        return DB::transaction(function () use ($actor, $branch): BranchLetterhead {
            [, $organisation, $branch] = $this->access->lock($actor, $branch->id);

            return $this->assembler->assemble($branch, fn (string $asset): string => route('business-details.logo', ['branch' => $branch->id, 'asset' => $asset], absolute: false));
        }, 3);
    }

    public function forInvoice(User $actor, Visit $visit, Invoice $invoice): BranchLetterhead
    {
        $branch = $this->branches->activeBranch($actor);
        abort_unless($branch !== null, 404);

        return DB::transaction(function () use ($actor, $visit, $invoice, $branch): BranchLetterhead {
            [, $visit, $branch] = $this->billing->lock($actor, $visit, $branch->id, 'billing.print.branch');
            $invoice = Invoice::query()->whereKey($invoice->id)->where('organisation_id', $visit->organisation_id)
                ->where('branch_id', $branch->id)->where('visit_id', $visit->id)->firstOrFail();
            abort_if($invoice->status === 'draft', 404);

            return $this->assembler->assemble($branch, fn (string $asset): string => route('billing.letterhead-logo', [$visit, $invoice, $asset], absolute: false));
        }, 3);
    }
}

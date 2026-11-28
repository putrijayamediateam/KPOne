<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Visit\Billing\Models\FamilyBillExclusion;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * BIL-39: "Remove from family bill" and "Put back". Changes no money, invoice or visit data: it only opens or closes a
 * family_bill_exclusions row for one member invoice. Permission billing.build.branch (no new permission). Repeating a
 * request leaves the state as it is (a second remove or put back records nothing).
 */
final class FamilyBillExclusionService
{
    public function __construct(
        private BillingContextService $context,
        private FamilyBillService $family,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $input */
    public function remove(User $actor, Visit $anchor, array $input): void
    {
        $this->change($actor, $anchor, $input, true);
    }

    /** @param array<string, mixed> $input */
    public function restore(User $actor, Visit $anchor, array $input): void
    {
        $this->change($actor, $anchor, $input, false);
    }

    /** @param array<string, mixed> $input */
    private function change(User $actor, Visit $anchor, array $input, bool $remove): void
    {
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer'],
            'invoice_public_id' => ['required', 'uuid'],
        ])->validate();

        DB::transaction(function () use ($actor, $anchor, $data, $remove): void {
            // The member's own visit is locked (patient, then visit), as on their Billing page; then the invoice row.
            $invoice = Invoice::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $data['invoice_public_id'])->first();
            $member = $invoice ? Visit::query()->whereKey($invoice->visit_id)->first() : null;
            abort_unless($member && $anchor->family_group_id !== null && $member->family_group_id === $anchor->family_group_id && $member->branch_id === $anchor->branch_id, 404);
            [$actor, $member, $branch] = $this->context->lock($actor, $member, $data['expected_branch_id'], 'billing.build.branch');
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $open = FamilyBillExclusion::query()->where('invoice_id', $invoice->id)->whereNull('restored_at')->lockForUpdate()->first();

            if ($remove) {
                if ($open) {
                    return; // already removed
                }
                if (! $this->family->openInvoices($anchor)->contains('id', $invoice->id)) {
                    throw ValidationException::withMessages(['family' => FamilyBillPaymentService::CHANGED]);
                }
                $row = new FamilyBillExclusion;
                $row->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id,
                    'invoice_id' => $invoice->id, 'excluded_by_user_id' => $actor->id, 'excluded_at' => now()->utc()])->save();
                $this->audit->record('billing.family_bill_member_removed', $invoice, ['family_group_id' => $anchor->family_group_id], $actor, $branch);

                return;
            }

            if (! $open) {
                return; // already back
            }
            $open->forceFill(['restored_by_user_id' => $actor->id, 'restored_at' => now()->utc()])->save();
            $this->audit->record('billing.family_bill_member_restored', $invoice, ['family_group_id' => $anchor->family_group_id], $actor, $branch);
        }, 3);
    }
}

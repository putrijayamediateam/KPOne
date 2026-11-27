<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\BillingWorkAccess;
use App\Domain\Access\BranchAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class BillingWorkDirectoryService
{
    public function __construct(private BranchAccessService $branches, private TransactionalActorAuthority $authority, private BillingWorkAccess $access, private FinancialLedger $ledger) {}

    /** Fixed server modes; browser filters cannot choose authority or branch scope.
     * @return array<string, mixed>
     */
    public function search(User $actor, bool $panel, int $page, mixed $search = null): array
    {
        $validated = Validator::make(['q' => is_string($search) ? trim($search) : $search], [
            'q' => ['nullable', 'string', 'max:120'],
        ])->validate();
        $search = (string) ($validated['q'] ?? '');

        return DB::transaction(function () use ($actor, $panel, $page, $search): array {
            $branch = $this->branches->activeBranch($actor);
            abort_unless($branch && $branch->organisation_id === $actor->organisation_id, 404);
            $actor = $this->authority->lock($actor, $branch, $actor->can('billing.summary.branch') ? 'billing.summary.branch' : 'billing.view.branch');
            abort_unless($panel ? $this->access->panel($actor) : $this->access->finance($actor), 403);

            $query = Invoice::query()->join('visits as v', 'v.id', '=', 'invoices.visit_id')
                ->join('patients as p', 'p.id', '=', 'invoices.patient_id')
                ->where('invoices.organisation_id', $actor->organisation_id)->where('invoices.branch_id', $branch->id)
                ->where('v.organisation_id', $actor->organisation_id)->where('v.branch_id', $branch->id)
                ->where('p.organisation_id', $actor->organisation_id)->whereColumn('v.patient_id', 'p.id')
                ->whereColumn('invoices.current_visit_guard', 'v.id')->where('invoices.status', 'finalized')
                ->select(['invoices.id', 'invoices.invoice_number', 'invoices.status', 'invoices.total_sen', 'invoices.subtotal_sen', 'v.visit_number', 'p.full_name as patient_name']);
            if ($panel) {
                $query->join('coverage_allocations as c', 'c.current_invoice_guard', '=', 'invoices.id')
                    ->whereColumn('c.invoice_id', 'invoices.id')->where('c.organisation_id', $actor->organisation_id)
                    ->where('c.branch_id', $branch->id)->whereIn('c.status', ['proposed', 'approved'])
                    // PCA-2: an approved share shows its effective amount (confirmed Panel claim adjustments included).
                    ->addSelect([DB::raw("CASE WHEN c.status = 'approved' THEN kpone_panel_effective(invoices.id) ELSE c.amount_sen END as panel_amount"), 'c.status as panel_status', 'c.created_at as requested_at']);
            }
            if ($search !== '') {
                $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $query->where(fn ($matches) => $matches->where('p.full_name', 'ilike', $pattern)
                    ->orWhere('invoices.invoice_number', 'ilike', $pattern)
                    ->orWhere('v.visit_number', 'ilike', $pattern));
            }
            $results = $query->orderByDesc('invoices.id')->paginate(25, page: $page);

            return ['mode' => $panel ? 'panel' : 'finance', 'branch' => $branch->name, 'branchId' => (int) $branch->id, 'filters' => ['q' => $search],
                'data' => $results->getCollection()->map(function (Invoice $invoice) use ($panel, $branch): array {
                    $row = ['invoiceNumber' => $invoice->invoice_number, 'visitNumber' => $invoice->getAttribute('visit_number'),
                        'patientName' => $invoice->getAttribute('patient_name'), 'branch' => $branch->name,
                        'status' => $panel ? $invoice->getAttribute('panel_status') : $invoice->status,
                        'reviewUrl' => route('billing.show', $invoice->getAttribute('visit_number'))];

                    return $panel ? [...$row, 'amountSen' => (int) $invoice->getAttribute('panel_amount'),
                        'requestedAt' => CarbonImmutable::parse($invoice->getAttribute('requested_at'))->setTimezone($branch->timezone)->format('j M Y, g:i A')]
                        : [...$row, 'state' => $this->ledger->state($invoice)];
                })->all(), 'total' => $results->total(), 'currentPage' => $results->currentPage(), 'lastPage' => $results->lastPage()];
        });
    }
}

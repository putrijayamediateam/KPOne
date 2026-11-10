<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * BIL-3 (owner decision 2026-11-05): the branch's approved pay-later amounts still to collect, oldest due date first,
 * so staff can collect when the patient returns. Read-only: it never changes a receivable, invoice or receipt.
 * Shows the patient name and KP number only (no identity document, contact or reason text).
 */
final class PayLaterCollectionService
{
    public const PER_PAGE = 20;

    public function __construct(private BranchAccessService $branches, private TransactionalActorAuthority $authority, private FinancialLedger $ledger) {}

    /** @return array<string, mixed> */
    public function page(User $actor, int $page, bool $overdueOnly): array
    {
        return DB::transaction(function () use ($actor, $page, $overdueOnly): array {
            $branch = $this->branches->activeBranch($actor);
            abort_unless($branch && $branch->organisation_id === $actor->organisation_id, 404);
            $actor = $this->authority->lock($actor, $branch, 'outstanding.view.branch');
            $today = CarbonImmutable::now($branch->timezone)->startOfDay();

            $query = $this->eligible($actor->organisation_id, $branch->id);
            $all = $this->totals(clone $query, $today);
            if ($overdueOnly) {
                $query->where('r.due_date', '<', $today->toDateString());
            }
            $shown = $this->totals(clone $query, $today);
            $lastPage = max(1, (int) ceil($shown['count'] / self::PER_PAGE));
            $page = min(max(1, $page), $lastPage);
            $rows = $query->select(['r.id', 'i.id as invoice_id', 'r.amount_sen', 'r.remaining_sen', 'r.due_date', 'i.invoice_number', 'v.visit_number', 'v.registered_at', 'p.full_name', 'p.patient_number',
                // REG-5: a bill of a merged-away record lists under the kept record, noting the old KP number.
                'kp.full_name as kept_full_name', 'kp.patient_number as kept_patient_number'])
                ->orderBy('r.due_date')->orderBy('r.approved_at')->orderBy('r.id')
                ->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get();

            return ['branch' => $branch->name, 'today' => $today->format('j M Y'), 'overdueOnly' => $overdueOnly,
                'all' => $all, 'shown' => $shown, 'currentPage' => $page, 'lastPage' => $lastPage,
                // BIL-17: money due after a receipt correction (BIL-16) is paid before pay-later, so the list says so. Kept
                // beside the rows (visit number => sen, only when above zero) so each row keeps its fields.
                'dueNow' => (function () use ($rows): array {
                    if ($rows->isEmpty()) {
                        return [];
                    }
                    $states = $this->ledger->states(Invoice::query()->whereIn('id', $rows->pluck('invoice_id'))->get());

                    return $rows->mapWithKeys(fn (object $row): array => [(string) $row->visit_number => (int) ($states[(int) $row->invoice_id]['due_now'] ?? 0)])
                        ->filter(fn (int $sen): bool => $sen > 0)->all();
                })(),
                'data' => $rows->map(function (object $row) use ($branch, $today): array {
                    $due = CarbonImmutable::parse($row->due_date, $branch->timezone)->startOfDay();

                    return ['patientName' => $row->kept_full_name ?? $row->full_name, 'patientNumber' => $row->kept_patient_number ?? $row->patient_number,
                        'mergedFrom' => $row->kept_patient_number === null ? null : $row->patient_number, 'visitNumber' => $row->visit_number,
                        'invoiceNumber' => $row->invoice_number,
                        'visitDate' => CarbonImmutable::parse($row->registered_at, 'UTC')->setTimezone($branch->timezone)->format('j M Y'),
                        'dueDate' => $due->format('j M Y'), 'daysOverdue' => $due->lessThan($today) ? (int) $due->diffInDays($today) : 0,
                        'approvedSen' => (int) $row->amount_sen, 'remainingSen' => (int) $row->remaining_sen,
                        'url' => route('billing.show', $row->visit_number, false)];
                })->all()];
        });
    }

    /** Approved, not yet fully settled pay-later on a current finalised invoice of this branch. */
    private function eligible(int $organisationId, int $branchId): Builder
    {
        return DB::table('patient_receivables as r')
            ->join('invoices as i', 'i.id', '=', 'r.invoice_id')
            ->join('visits as v', 'v.id', '=', 'i.visit_id')
            ->join('patients as p', 'p.id', '=', 'i.patient_id')
            ->leftJoin('patients as kp', fn ($join) => $join->on('kp.id', '=', 'p.merged_into_patient_id')->on('kp.organisation_id', '=', 'p.organisation_id'))
            ->where('r.organisation_id', $organisationId)->where('r.branch_id', $branchId)
            ->where('r.status', 'approved')->where('r.remaining_sen', '>', 0)->whereColumn('r.current_invoice_guard', 'i.id')
            ->where('i.organisation_id', $organisationId)->where('i.branch_id', $branchId)->where('i.status', 'finalized')
            ->whereColumn('i.current_visit_guard', 'v.id')
            ->where('v.organisation_id', $organisationId)->where('v.branch_id', $branchId)->whereColumn('v.patient_id', 'p.id')
            ->where('p.organisation_id', $organisationId);
    }

    /** @return array{count: int, totalSen: int, overdueCount: int, overdueSen: int} */
    private function totals(Builder $query, CarbonImmutable $today): array
    {
        $row = $query->selectRaw('count(*) as count, coalesce(sum(r.remaining_sen), 0) as total')
            ->selectRaw('coalesce(sum(case when r.due_date < ? then 1 else 0 end), 0) as overdue_count', [$today->toDateString()])
            ->selectRaw('coalesce(sum(case when r.due_date < ? then r.remaining_sen else 0 end), 0) as overdue_total', [$today->toDateString()])
            ->first();

        return ['count' => (int) $row->count, 'totalSen' => (int) $row->total, 'overdueCount' => (int) $row->overdue_count, 'overdueSen' => (int) $row->overdue_total];
    }
}

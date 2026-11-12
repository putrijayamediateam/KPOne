<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * BIL-3 (owner decision 2026-11-05): the branch's approved pay-later amounts still to collect, oldest due date first,
 * so staff can collect when the patient returns. Read-only: it never changes a receivable, invoice or receipt.
 * Shows the patient name and KP number only (no identity document, contact or reason text).
 *
 * BIL-23 (owner decisions 2026-11-11): "Bills to collect" also lists money due now on a completed visit, using the
 * same amount as the Billing page's Outstanding bill section (OutstandingBillService). Due-now money counts as past
 * due from the day after the visit. An open visit's due-now money is being paid at the counter, so it is not listed.
 */
final class PayLaterCollectionService
{
    public const PER_PAGE = 20;

    public function __construct(private BranchAccessService $branches, private TransactionalActorAuthority $authority) {}

    /** @return array<string, mixed> */
    public function page(User $actor, int $page, bool $overdueOnly): array
    {
        return DB::transaction(function () use ($actor, $page, $overdueOnly): array {
            $branch = $this->branches->activeBranch($actor);
            abort_unless($branch && $branch->organisation_id === $actor->organisation_id, 404);
            $actor = $this->authority->lock($actor, $branch, 'outstanding.view.branch');
            $today = CarbonImmutable::now($branch->timezone)->startOfDay();

            $query = $this->eligible($actor->organisation_id, $branch->id, $branch->timezone);
            $all = $this->totals(clone $query, $today);
            if ($overdueOnly) {
                $query->where('b.effective_due', '<', $today->toDateString());
            }
            $shown = $this->totals(clone $query, $today);
            $lastPage = max(1, (int) ceil($shown['count'] / self::PER_PAGE));
            $page = min(max(1, $page), $lastPage);
            $rows = $query->select('b.*')
                ->orderBy('b.effective_due')->orderByRaw('b.approved_at asc nulls last')->orderBy('b.invoice_id')
                ->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get();

            return ['branch' => $branch->name, 'today' => $today->format('j M Y'), 'overdueOnly' => $overdueOnly,
                'all' => $all, 'shown' => $shown, 'currentPage' => $page, 'lastPage' => $lastPage,
                // BIL-17 / BIL-23: money due now on a completed visit, beside the rows (visit number => sen, only when
                // above zero) so each row keeps its agreed fields. A payment pays it before pay-later.
                'dueNow' => $rows->mapWithKeys(fn (object $row): array => [(string) $row->visit_number => (int) $row->due_now_sen])
                    ->filter(fn (int $sen): bool => $sen > 0)->all(),
                // BIL-23: on a bill that also has money due now, the row's date is the visit day, so the agreed pay-later
                // date travels beside the rows (visit number => date) to stay visible.
                'payLaterDue' => $rows->filter(fn (object $row): bool => (int) $row->due_now_sen > 0 && $row->pay_later_due !== null)
                    ->mapWithKeys(fn (object $row): array => [(string) $row->visit_number => CarbonImmutable::parse($row->pay_later_due, $branch->timezone)->format('j M Y')])->all(),
                'data' => $rows->map(function (object $row) use ($branch, $today): array {
                    // BIL-23: a bill with only money due now is due from its visit day (past due from the next day).
                    $due = CarbonImmutable::parse($row->effective_due, $branch->timezone)->startOfDay();

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

    /**
     * Current finalised invoices of this branch that still owe money: approved pay-later not yet settled, and (BIL-23)
     * money due now on a completed visit. The owed amount is the Outstanding bill formula (OutstandingBillService):
     * payable, less paid, plus reversed, less approved Panel; due now is that, less the pay-later still to collect.
     */
    private function eligible(int $organisationId, int $branchId, string $timezone): Builder
    {
        // BIL-24: only invoices that can still owe money are looked at. A visit is completed only when nothing is due
        // now (CompleteVisitationService), and after completion only payments (which lower it), BIL-10 refund credits
        // (which lower payable and paid equally) and receipt corrections (BIL-16, which always add a payment reversal)
        // can change what it owes. So a bill can owe money only while it has approved pay later still to collect, or
        // when its completed visit's invoice has a reversal. Any new post-completion path that raises what is owed
        // (for example superseding or writing off a receivable or Panel share) must write a payment reversal or extend
        // this filter; BillsToCollectTest pins both branches.
        // The sums stay grouped joins: payment_allocations, payment_reversals and coverage_allocations have no invoice_id
        // index, so a per-bill subquery would scan each table once per bill (measured far slower at 20,000 bills).
        $sum = fn (string $table) => DB::table($table)->where('organisation_id', $organisationId)
            ->where('branch_id', $branchId)->select('invoice_id')->selectRaw('sum(amount_sen) as amount')->groupBy('invoice_id');
        $bills = DB::table('invoices as i')
            ->join('visits as v', 'v.id', '=', 'i.visit_id')
            ->join('patients as p', 'p.id', '=', 'i.patient_id')
            ->leftJoin('patients as kp', fn ($join) => $join->on('kp.id', '=', 'p.merged_into_patient_id')->on('kp.organisation_id', '=', 'p.organisation_id'))
            ->leftJoin('patient_receivables as r', fn ($join) => $join->on('r.current_invoice_guard', '=', 'i.id')
                ->where('r.organisation_id', $organisationId)->where('r.branch_id', $branchId)->where('r.status', 'approved')->where('r.remaining_sen', '>', 0))
            ->leftJoinSub($sum('payment_allocations'), 'paid', 'paid.invoice_id', '=', 'i.id')
            ->leftJoinSub($sum('payment_reversals'), 'reversed', 'reversed.invoice_id', '=', 'i.id')
            ->leftJoinSub($sum('coverage_allocations')->where('status', 'approved'), 'covered', 'covered.invoice_id', '=', 'i.id')
            ->where('i.organisation_id', $organisationId)->where('i.branch_id', $branchId)->where('i.status', 'finalized')
            ->whereColumn('i.current_visit_guard', 'v.id')
            ->where('v.organisation_id', $organisationId)->where('v.branch_id', $branchId)->whereColumn('v.patient_id', 'p.id')
            ->where('p.organisation_id', $organisationId)
            ->where(fn ($candidate) => $candidate->whereNotNull('r.id')->orWhere(fn ($corrected) => $corrected->where('v.status', 'completed')
                ->whereExists(fn ($reversal) => $reversal->from('payment_reversals as pr')->whereColumn('pr.invoice_id', 'i.id')
                    ->where('pr.organisation_id', $organisationId)->where('pr.branch_id', $branchId))))
            ->select(['i.id as invoice_id', 'i.invoice_number', 'v.visit_number', 'v.registered_at', 'p.full_name', 'p.patient_number',
                // REG-5: a bill of a merged-away record lists under the kept record, noting the old KP number.
                'kp.full_name as kept_full_name', 'kp.patient_number as kept_patient_number', 'r.approved_at'])
            ->selectRaw('coalesce(r.amount_sen, 0) as amount_sen, coalesce(r.remaining_sen, 0) as remaining_sen')
            // The Outstanding bill formula (OutstandingBillService): payable, less paid, plus reversed, less approved Panel.
            ->selectRaw("case when v.status = 'completed' then greatest(kpone_invoice_payable(i.id) - coalesce(paid.amount, 0)
                + coalesce(reversed.amount, 0) - coalesce(covered.amount, 0) - coalesce(r.remaining_sen, 0), 0) else 0 end as due_now_sen")
            ->selectRaw('r.due_date as pay_later_due')
            // registered_at is stored as UTC without a zone: read it as UTC, then take the branch's calendar day.
            ->selectRaw("((v.registered_at at time zone 'UTC') at time zone ?)::date as visit_day", [$timezone]);

        return DB::query()->fromSub(DB::query()->fromSub($bills, 'owed')->select('owed.*')
            // The earlier of the pay-later due date and, when money is due now, the visit day.
            ->selectRaw('case when due_now_sen > 0 then least(coalesce(pay_later_due, visit_day), visit_day) else pay_later_due end as effective_due'), 'b')
            ->where(fn ($q) => $q->where('b.remaining_sen', '>', 0)->orWhere('b.due_now_sen', '>', 0));
    }

    /** @return array{count: int, totalSen: int, overdueCount: int, overdueSen: int} */
    private function totals(Builder $query, CarbonImmutable $today): array
    {
        $row = $query->selectRaw('count(*) as count, coalesce(sum(b.remaining_sen + b.due_now_sen), 0) as total')
            ->selectRaw('coalesce(sum(case when b.effective_due < ? then 1 else 0 end), 0) as overdue_count', [$today->toDateString()])
            ->selectRaw('coalesce(sum(case when b.effective_due < ? then b.remaining_sen + b.due_now_sen else 0 end), 0) as overdue_total', [$today->toDateString()])
            ->first();

        return ['count' => (int) $row->count, 'totalSen' => (int) $row->total, 'overdueCount' => (int) $row->overdue_count, 'overdueSen' => (int) $row->overdue_total];
    }
}

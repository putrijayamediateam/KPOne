<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\BillingWorkAccess;
use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class OutstandingBillService
{
    public function __construct(private BillingContextService $context, private BranchAccessService $branches, private BillingWorkAccess $work, private PanelClaimEligibility $eligibility) {}

    /** @return array<string, mixed> */
    public function summary(User $actor, Visit $visit): array
    {
        return DB::transaction(function () use ($actor, $visit): array {
            [$actor, $visit] = $this->context($actor, $visit);
            $groups = [];
            foreach (['patient', 'panel'] as $group) {
                $groups[$group] = null;
                if ($this->allows($actor, $group)) {
                    $totals = $this->totals($this->rows($actor, $visit, $group));
                    $groups[$group] = [...$totals, 'url' => route('billing.outstanding', [$visit, $group])];
                }
            }

            return ['branchId' => $visit->branch_id, ...$groups];
        });
    }

    /** Read-only, minimal financial projection. No reasons, names or clinical fields.
     * @return array<string, mixed>
     */
    public function page(User $actor, Visit $visit, string $group, int $page): array
    {
        return DB::transaction(function () use ($actor, $visit, $group, $page): array {
            [$actor, $visit, $branch] = $this->context($actor, $visit);
            abort_unless(in_array($group, ['patient', 'panel'], true) && $this->allows($actor, $group), 403);
            $query = $this->rows($actor, $visit, $group);
            // Aggregate the whole eligible set before limiting the list. Each opening/page
            // refreshes balances; selecting an invoice does not reserve it or authorize payment.
            $totals = $this->totals(clone $query);
            $lastPage = max(1, (int) ceil($totals['count'] / 10));
            $page = min(max(1, $page), $lastPage);
            $rows = $query->orderByDesc('registered_at')->orderByDesc('id')->offset(($page - 1) * 10)->limit(10)->get();

            return [...$totals, 'currentPage' => $page, 'lastPage' => $lastPage, 'data' => $rows->map(fn ($row): array => [
                'invoiceNumber' => $row->invoice_number,
                'visitDate' => CarbonImmutable::parse($row->registered_at, 'UTC')->setTimezone($branch->timezone)->format('j M Y, g:i A'),
                'amountSen' => (int) $row->amount_sen,
                'reviewRequired' => (bool) $row->review_required,
                'url' => route('billing.show', $row->visit_number),
            ])->all()];
        });
    }

    /** @return array{User, Visit, Branch} */
    private function context(User $actor, Visit $visit): array
    {
        return $this->context->lock($actor, $visit, $this->branches->activeBranch($actor)?->id,
            $actor->can('billing.view.branch') ? 'billing.view.branch' : 'billing.summary.branch');
    }

    private function allows(User $actor, string $group): bool
    {
        return $group === 'patient' ? $actor->can('outstanding.view.branch') : ($this->work->panel($actor) || $this->work->finance($actor));
    }

    /** @return array{count: int, totalSen: int} */
    private function totals(Builder $query): array
    {
        $row = DB::query()->fromSub($query, 'eligible')->selectRaw('count(*) as count, coalesce(sum(amount_sen), 0) as total')->first();

        return ['count' => (int) $row->count, 'totalSen' => (int) $row->total];
    }

    private function rows(User $actor, Visit $visit, string $group): Builder
    {
        if ($group === 'panel') {
            return DB::query()->fromSub($this->eligibility->query($actor->organisation_id, $visit->branch_id)
                ->where('i.patient_id', $visit->patient_id)->where('i.visit_id', '<>', $visit->id)
                ->select(['i.id', 'i.invoice_number', 'v.visit_number', 'v.registered_at'])
                ->selectRaw('sum(c.amount_sen) as amount_sen, false as review_required')
                ->groupBy('i.id', 'i.invoice_number', 'v.visit_number', 'v.registered_at'), 'bills');
        }
        $sum = fn (string $table) => DB::table($table)->where('organisation_id', $actor->organisation_id)
            ->where('branch_id', $visit->branch_id)->select('invoice_id')->selectRaw('sum(amount_sen) as amount')->groupBy('invoice_id');
        $query = DB::table('invoices as i')->join('visits as v', 'v.id', '=', 'i.visit_id')
            ->where('i.organisation_id', $actor->organisation_id)->where('i.branch_id', $visit->branch_id)
            ->where('v.organisation_id', $actor->organisation_id)->where('v.branch_id', $visit->branch_id)
            ->where('i.patient_id', $visit->patient_id)->whereColumn('v.patient_id', 'i.patient_id')
            ->where('i.visit_id', '<>', $visit->id)->whereColumn('i.current_visit_guard', 'v.id')->where('i.status', 'finalized')
            ->leftJoinSub($sum('payment_allocations'), 'paid', 'paid.invoice_id', '=', 'i.id')
            ->leftJoinSub($sum('payment_reversals'), 'reversed', 'reversed.invoice_id', '=', 'i.id')
            ->leftJoinSub($sum('coverage_allocations')->where('status', 'approved'), 'covered', 'covered.invoice_id', '=', 'i.id')
            ->select(['i.id', 'i.invoice_number', 'v.visit_number', 'v.registered_at'])
            // due_now + approved remaining pay-later; deferred is already part of patient debt.
            ->selectRaw('kpone_invoice_payable(i.id) - coalesce(paid.amount, 0) + coalesce(reversed.amount, 0) - coalesce(covered.amount, 0) as amount_sen')
            ->selectRaw('(i.correction_hold or i.source_stale) as review_required');

        return DB::query()->fromSub($query, 'bills')->where('amount_sen', '>', 0);
    }
}

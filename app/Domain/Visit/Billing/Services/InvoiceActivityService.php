<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\StaffAccessService;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Models\ConsultationCheckout;
use App\Domain\Clinical\Models\TreatmentPlan;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Read-only, allowlisted presentation. Never serialize audit metadata or clinical source rows. */
final class InvoiceActivityService
{
    public function __construct(private BranchAccessService $branches, private StaffAccessService $staff) {}

    /** @return array{url: string, branchId: int} */
    public function link(Visit $visit, int $branchId, ?Invoice $invoice = null, bool $completed = true): array
    {
        return ['url' => $invoice ? route('billing.activity', [$visit, $invoice], false) : route($completed ? 'visits.invoice.activity' : 'billing.visit-activity', $visit, false), 'branchId' => $branchId];
    }

    /** @return array{data: list<array<string, mixed>>, nextCursor: ?string} */
    public function page(User $actor, Visit $visit, ?Invoice $document, bool $completed, int $expectedBranch, ?string $cursor): array
    {
        $branch = $this->branches->activeBranch($actor);
        abort_unless($branch && $branch->id === $expectedBranch && $visit->organisation_id === $actor->organisation_id && $visit->branch_id === $branch->id, 404);
        $actor->load(['roles.permissions', 'permissions']);
        $financial = $actor->hasAnyPermission(['billing.view.branch', 'billing.summary.branch']);
        abort_unless($actor->is_active && ($completed ? $actor->can('visits.history.view.branch') : $financial), 403);
        abort_if($completed && $visit->status !== Visit::STATUS_COMPLETED, 404);
        $invoice = $completed
            ? ($financial ? Invoice::query()->where('visit_id', $visit->id)->where('organisation_id', $actor->organisation_id)->where('status', '!=', 'draft')->latest('id')->first() : null)
            : ($document ? Invoice::query()->whereKey($document->id)->where('visit_id', $visit->id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->firstOrFail() : Invoice::query()->where('current_visit_guard', $visit->id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->first());
        $panel = $financial && $actor->hasAnyPermission(['coverage.propose.branch', 'coverage.approve.branch']);
        $deferment = $financial && $actor->hasAnyPermission(['outstanding.view.branch', 'outstanding.request.branch', 'outstanding.approve.branch']);
        $operational = $actor->can('visits.history.view.branch');
        // Bind continuation to the actor, selected document, branch and current visibility. No permission snapshot authorizes a request.
        $scope = hash('sha256', json_encode([$actor->id, $visit->id, $branch->id, $invoice?->id, $completed, $financial, $panel, $deferment, $operational], JSON_THROW_ON_ERROR));
        $queries = [];
        $tenant = fn (string $table): Builder => DB::table($table)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id);
        $audit = $tenant('audit_logs')->where('subject_type', (new Visit)->getMorphClass())->where('subject_id', $visit->id);
        $visitEvents = ['visit.created' => 'Registration created'];
        if ($operational) {
            $visitEvents += ['visit.details.updated' => 'Registration details updated', 'visit.doctor.changed' => 'Doctor assignment updated', 'visit.coverage.changed' => 'Visit coverage updated'];
        }
        $queries[] = $this->auditRows($audit, $visitEvents, 1);
        if ($visit->completed_at && (! $invoice || ($visit->completion_evidence['invoice_public_id'] ?? null) === $invoice->public_id)) {
            $queries[] = $this->rows($tenant('visits')->where('id', $visit->id), 2, 'Visit completed', 'completed_at', 'completed_by_user_id');
        }
        if ($operational) {
            $sources = [
                [ConsultationCheckout::class, DB::table('consultation_checkouts')->where('visit_id', $visit->id)->where('organisation_id', $actor->organisation_id)->select('id'), [
                    'consultation.checked_out' => 'Consultation checked out', 'consultation.checkout_reopened' => 'Consultation checkout reopened',
                ]],
                [TreatmentPlan::class, DB::table('treatment_plans')->whereIn('clinical_encounter_id', DB::table('clinical_encounters')->where('visit_id', $visit->id)->where('organisation_id', $actor->organisation_id)->select('id'))->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->select('id'), [
                    'treatment_plan.created' => 'Treatment plan created', 'treatment_plan.updated' => 'Treatment plan updated', 'treatment_plan.sent_to_dispensary' => 'Consultation sent to Dispensary',
                ]],
                [DispensaryCase::class, DB::table('dispensary_cases')->where('visit_id', $visit->id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->select('id'), [
                    'dispensary.otc_opened' => 'OTC Dispensary opened', 'dispensary.started' => 'Dispensary started', 'dispensary.updated' => 'Dispensary fulfilment updated',
                    'dispensary.item_added' => 'Medicine line added at Dispensary', 'dispensary.item_edited' => 'Medicine line updated at Dispensary', 'dispensary.item_removed' => 'Medicine line removed at Dispensary',
                    'dispensary.service_added' => 'Service line added at Dispensary', 'dispensary.service_edited' => 'Service line updated at Dispensary', 'dispensary.service_removed' => 'Service line removed at Dispensary',
                    'dispensary.returned_to_doctor' => 'Dispensary returned to doctor', 'dispensary.partial_acknowledged' => 'Partial dispensing acknowledged', 'dispensary.completed' => 'Dispensary completed',
                ]],
            ];
            foreach ($sources as [$model, $ids, $events]) {
                $queries[] = $this->auditRows($tenant('audit_logs')->where('subject_type', (new $model)->getMorphClass())->whereIn('subject_id', $ids), $events, 1);
            }
        }
        if ($financial && $invoice) {
            $queries[] = $this->auditRows($tenant('audit_logs')->where('subject_type', $invoice->getMorphClass())->where('subject_id', $invoice->id), ['billing.draft_built' => 'Invoice draft built or refreshed'], 1);
            if ($invoice->finalized_at) {
                $queries[] = $this->rows($tenant('invoices')->where('id', $invoice->id), 3, 'Invoice finalised', 'finalized_at', 'finalized_by_user_id', 'total_sen');
            }
            if ($invoice->voided_at) {
                $queries[] = $this->rows($tenant('invoices')->where('id', $invoice->id), 4, 'Invoice voided', 'voided_at', 'voided_by_user_id');
            }
            $queries[] = $this->rows($tenant('payments')->whereIn('id', $tenant('payment_allocations')->where('invoice_id', $invoice->id)->select('payment_id')), 5, 'Payment recorded', 'received_at', 'recorded_by_user_id', 'amount_sen', 'method_snapshot');
            foreach (['add' => 'Surcharge added', 'deduct' => 'Discount added'] as $direction => $message) {
                $queries[] = $this->rows($tenant('invoice_adjustments')->where('invoice_id', $invoice->id)->where('direction', $direction), $direction === 'add' ? 11 : 12, $message, 'created_at', 'actor_user_id', 'amount_sen');
            }
            $queries[] = $this->rows($tenant('invoice_adjustment_reversals')->where('invoice_id', $invoice->id), 13, 'Invoice adjustment reversed', 'created_at', 'actor_user_id', 'amount_sen');
            $queries[] = $this->rows($tenant('payment_reversals')->where('invoice_id', $invoice->id), 6, 'Payment recording reversed (not a cash refund)', 'reversed_at', 'approved_by_user_id', 'amount_sen');
            foreach (['coverage_allocations' => $panel, 'patient_receivables' => $deferment] as $table => $allowed) {
                if (! $allowed) {
                    continue;
                }
                $name = $table === 'coverage_allocations' ? 'Panel responsibility' : 'Pay later';
                $rank = $table === 'coverage_allocations' ? 7 : 9;
                $base = $tenant($table)->where('invoice_id', $invoice->id);
                $queries[] = $this->rows(clone $base, $rank, $name.' proposed', 'created_at', 'requested_by_user_id', 'amount_sen');
                $queries[] = $this->rows((clone $base)->whereNotNull('approved_at'), $rank + 1, $name.' approved', 'approved_at', 'approved_by_user_id', 'amount_sen');
            }
        }
        $union = array_shift($queries);
        foreach ($queries as $query) {
            $union->unionAll($query);
        }
        $query = DB::query()->fromSub($union, 'activity');
        if ($cursor !== null) {
            try {
                $after = json_decode(Crypt::decryptString($cursor), true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($after) || ($after['scope'] ?? null) !== $scope || ! is_string($after['at'] ?? null) || ! is_int($after['rank'] ?? null) || ! is_int($after['id'] ?? null)) {
                    throw new \RuntimeException;
                }
                CarbonImmutable::parse($after['at']);
            } catch (Throwable) {
                throw ValidationException::withMessages(['cursor' => 'The activity page changed. Refresh the log.']);
            }
            $query->where(function (Builder $q) use ($after): void {
                $q->where('occurred_at', '<', $after['at'])->orWhere(function (Builder $q) use ($after): void {
                    $q->where('occurred_at', $after['at'])->where(function (Builder $q) use ($after): void {
                        $q->where('rank', '<', $after['rank'])->orWhere(fn (Builder $q) => $q->where('rank', $after['rank'])->where('source_id', '<', $after['id']));
                    });
                });
            });
        }
        $rows = $query->orderByDesc('occurred_at')->orderByDesc('rank')->orderByDesc('source_id')->limit(21)->get();
        $hasMore = $rows->count() > 20;
        $rows = $rows->take(20);
        $names = $this->staff->visibleUsers($actor)->whereIn('id', $rows->pluck('actor_id')->filter()->unique())->pluck('name', 'id');
        $data = $rows->map(fn ($row): array => [
            'id' => $row->rank.':'.$row->source_id,
            'message' => $row->message,
            'scope' => in_array((int) $row->rank, [1, 2], true) && $row->message !== 'Invoice draft built or refreshed' ? 'Visit' : 'Invoice',
            'amountSen' => $row->amount_sen === null ? null : (int) $row->amount_sen,
            'method' => $row->method,
            'actor' => $row->actor_id === null ? 'Unavailable' : ($names[$row->actor_id] ?? 'Staff member'),
            'occurredAt' => CarbonImmutable::parse($row->occurred_at, 'UTC')->setTimezone($branch->timezone)->format('j M Y, g:i A'),
        ])->values()->all();
        $last = $rows->last();

        return ['data' => array_values($data), 'nextCursor' => $hasMore && $last ? Crypt::encryptString(json_encode(['scope' => $scope, 'at' => $last->occurred_at, 'rank' => (int) $last->rank, 'id' => (int) $last->source_id], JSON_THROW_ON_ERROR)) : null];
    }

    /**
     * @param  literal-string  $message
     * @param  literal-string  $at
     * @param  literal-string  $actor
     * @param  literal-string|null  $amount
     * @param  literal-string|null  $method
     */
    private function rows(Builder $query, int $rank, string $message, string $at, string $actor, ?string $amount = null, ?string $method = null): Builder
    {
        return $query->selectRaw("id AS source_id, CAST(? AS INTEGER) AS rank, ? AS message, {$at} AS occurred_at, {$actor} AS actor_id, ".($amount ?? 'CAST(NULL AS BIGINT)').' AS amount_sen, '.($method ?? 'CAST(NULL AS VARCHAR)').' AS method', [$rank, $message]);
    }

    /** @param array<string, string> $events */
    private function auditRows(Builder $query, array $events, int $rank): Builder
    {
        $case = 'CASE event';
        $bindings = [$rank];
        foreach ($events as $event => $message) {
            $case .= ' WHEN ? THEN ?';
            array_push($bindings, $event, $message);
        }

        return $query->whereIn('event', array_keys($events))->selectRaw('id AS source_id, CAST(? AS INTEGER) AS rank, '.$case.' END AS message, occurred_at, actor_user_id AS actor_id, CAST(NULL AS BIGINT) AS amount_sen, CAST(NULL AS VARCHAR) AS method', $bindings);
    }
}

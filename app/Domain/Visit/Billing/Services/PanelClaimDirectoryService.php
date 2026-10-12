<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type EligibleAllocationRow object{
 *     allocation_id: string,
 *     amount_sen: int|string,
 *     panel_name_snapshot: string,
 *     invoice_number: string,
 *     invoice_total_sen: int|string,
 *     visit_number: string,
 *     patient_name: string
 * }
 */
final class PanelClaimDirectoryService
{
    public function __construct(
        private BranchAccessService $branches,
        private TransactionalActorAuthority $authority,
    ) {}

    /** @return array<string, mixed> */
    public function search(User $actor, int $allocationsPage, int $claimsPage): array
    {
        return DB::transaction(function () use ($actor, $allocationsPage, $claimsPage): array {
            [$actor, $branch] = $this->context($actor);
            $eligible = DB::table('coverage_allocations as c')
                ->join('invoices as i', 'i.id', '=', 'c.invoice_id')
                ->join('visits as v', 'v.id', '=', 'i.visit_id')
                ->join('patients as p', 'p.id', '=', 'i.patient_id')
                ->where('c.organisation_id', $actor->organisation_id)
                ->where('c.branch_id', $branch->id)
                ->where('c.status', 'approved')
                ->whereColumn('c.current_invoice_guard', 'i.id')
                ->whereColumn('c.invoice_id', 'i.id')
                ->whereColumn('i.organisation_id', 'c.organisation_id')
                ->whereColumn('i.branch_id', 'c.branch_id')
                ->whereColumn('v.organisation_id', 'i.organisation_id')
                ->whereColumn('v.branch_id', 'i.branch_id')
                ->whereColumn('p.organisation_id', 'i.organisation_id')
                ->whereColumn('p.id', 'i.patient_id')
                ->where('i.status', 'finalized')
                ->where('i.correction_hold', false)
                ->where('i.source_stale', false)
                ->whereColumn('c.amount_sen', '<=', 'i.total_sen')
                ->whereNotExists(fn (Builder $existing) => $existing->selectRaw('1')
                    ->from('panel_claims')
                    ->whereColumn('panel_claims.coverage_allocation_id', 'c.id'))
                ->select([
                    'c.public_id as allocation_id',
                    'c.amount_sen',
                    'c.panel_name_snapshot',
                    'i.invoice_number',
                    'i.total_sen as invoice_total_sen',
                    'v.visit_number',
                    'p.full_name as patient_name',
                ])
                ->orderByDesc('c.id')
                ->paginate(20, ['*'], 'allocations_page', $allocationsPage);
            $claims = PanelClaim::query()->where('organisation_id', $actor->organisation_id)
                ->where('branch_id', $branch->id)
                ->orderByDesc('created_at')
                ->paginate(20, ['*'], 'claims_page', $claimsPage);

            return [
                'branch' => ['id' => $branch->id, 'name' => $branch->name, 'today' => now()->setTimezone($branch->timezone)->toDateString()],
                'eligible' => [
                    'data' => $eligible->getCollection()->map(
                        /** @param EligibleAllocationRow $row */
                        static fn ($row): array => [
                            'allocationId' => $row->allocation_id,
                            'patientName' => $row->patient_name,
                            'visitNumber' => $row->visit_number,
                            'invoiceNumber' => $row->invoice_number,
                            'panelName' => $row->panel_name_snapshot,
                            'amountSen' => (int) $row->amount_sen,
                            'invoiceTotalSen' => (int) $row->invoice_total_sen,
                        ],
                    )->all(),
                    'total' => $eligible->total(),
                    'currentPage' => $eligible->currentPage(),
                    'lastPage' => $eligible->lastPage(),
                ],
                'claims' => [
                    'data' => $claims->getCollection()->map(fn (PanelClaim $claim): array => [
                        'publicId' => $claim->public_id,
                        'patientName' => $claim->patient_name_snapshot,
                        'visitNumber' => $claim->visit_number,
                        'invoiceNumber' => $claim->invoice_number,
                        'panelName' => $claim->panel_name_snapshot,
                        'claimAmountSen' => $claim->claim_amount_sen,
                        'status' => $this->status($claim),
                        'createdAt' => $claim->created_at?->setTimezone($branch->timezone)->format('j M Y, g:i A'),
                        'showUrl' => route('panel-claims.show', $claim),
                    ])->all(),
                    'total' => $claims->total(),
                    'currentPage' => $claims->currentPage(),
                    'lastPage' => $claims->lastPage(),
                ],
            ];
        });
    }

    /** @return array<string, mixed> */
    public function show(User $actor, PanelClaim $routeClaim): array
    {
        return DB::transaction(function () use ($actor, $routeClaim): array {
            [$actor, $branch] = $this->context($actor);
            $claim = PanelClaim::query()->whereKey($routeClaim->id)
                ->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
                ->with(['lines', 'payments'])->firstOrFail();
            $paid = (int) $claim->payments->sum('amount_sen');
            $lineRows = $claim->lines->map(static fn ($line): array => [
                'number' => $line->line_number,
                'type' => $line->line_type,
                'name' => $line->display_name,
                'code' => $line->code_snapshot,
                'unit' => $line->unit_snapshot,
                'quantity' => (string) $line->quantity,
                'unitPriceSen' => $line->unit_price_sen,
                'totalSen' => $line->line_total_sen,
                'adjustment' => false,
            ])->all();
            if ($claim->claim_amount_sen > $claim->invoice_total_sen) {
                $adjustment = $claim->claim_amount_sen - $claim->invoice_total_sen;
                $lineRows[] = [
                    'number' => count($lineRows) + 1,
                    'type' => 'adjustment',
                    'name' => 'Panel claim adjustment',
                    'code' => 'PANEL-ADJUSTMENT',
                    'unit' => 'claim',
                    'quantity' => '1.000',
                    'unitPriceSen' => $adjustment,
                    'totalSen' => $adjustment,
                    'adjustment' => true,
                ];
            }
            $events = DB::table('panel_claim_events')
                ->join('users', function ($join): void {
                    $join->on('users.id', '=', 'panel_claim_events.actor_user_id')
                        ->on('users.organisation_id', '=', 'panel_claim_events.organisation_id');
                })
                ->where('panel_claim_events.panel_claim_id', $claim->id)
                ->where('panel_claim_events.organisation_id', $actor->organisation_id)
                ->where('panel_claim_events.branch_id', $branch->id)
                ->orderBy('panel_claim_events.occurred_at')->orderBy('panel_claim_events.id')
                ->get([
                    'panel_claim_events.event_type',
                    'panel_claim_events.previous_amount_sen',
                    'panel_claim_events.amount_sen',
                    'panel_claim_events.external_reference',
                    'panel_claim_events.note',
                    'panel_claim_events.occurred_at',
                    'users.name as actor_name',
                ])->map(fn (object $event): array => [
                    'type' => (string) $event->event_type,
                    'previousAmountSen' => $event->previous_amount_sen === null ? null : (int) $event->previous_amount_sen,
                    'amountSen' => $event->amount_sen === null ? null : (int) $event->amount_sen,
                    'externalReference' => $event->external_reference === null ? null : (string) $event->external_reference,
                    'note' => $event->note === null ? null : (string) $event->note,
                    'occurredAt' => CarbonImmutable::parse((string) $event->occurred_at)->setTimezone($branch->timezone)->format('j M Y, g:i A'),
                    'actor' => (string) $event->actor_name,
                ])->all();
            $payments = $claim->payments->map(fn ($payment): array => [
                'amountSen' => $payment->amount_sen,
                'reference' => $payment->reference,
                'receivedAt' => $payment->received_at->setTimezone($branch->timezone)->format('j M Y'),
            ])->all();

            return [
                'branch' => ['id' => $branch->id, 'name' => $branch->name, 'today' => now()->setTimezone($branch->timezone)->toDateString()],
                'claim' => [
                    'publicId' => $claim->public_id,
                    'lockVersion' => $claim->lock_version,
                    'status' => $this->status($claim),
                    'patientName' => $claim->patient_name_snapshot,
                    'memberReference' => $claim->member_reference,
                    'panelName' => $claim->panel_name_snapshot,
                    'branchName' => $claim->branch_name_snapshot,
                    'visitNumber' => $claim->visit_number,
                    'visitDate' => $claim->visit_date->format('j M Y'),
                    'invoiceNumber' => $claim->invoice_number,
                    'invoiceTotalSen' => $claim->invoice_total_sen,
                    'claimAmountSen' => $claim->claim_amount_sen,
                    'adjustmentSen' => max(0, $claim->claim_amount_sen - $claim->invoice_total_sen),
                    'acceptedAmountSen' => $claim->accepted_amount_sen,
                    'paidAmountSen' => $paid,
                    'outstandingSen' => max(0, ($claim->accepted_amount_sen ?? 0) - $paid),
                    'lines' => $lineRows,
                    'events' => $events,
                    'payments' => $payments,
                    'createdAt' => $claim->created_at?->setTimezone($branch->timezone)->format('j M Y, g:i A'),
                    'canEditAmount' => in_array($claim->status, ['prepared', 'queried', 'rejected'], true) && $paid === 0,
                    'canSubmit' => in_array($claim->status, ['prepared', 'queried', 'rejected'], true) && $paid === 0,
                    'canRecordQuery' => $claim->status === 'submitted' && $paid === 0,
                    'canRecordDecision' => in_array($claim->status, ['submitted', 'queried'], true) && $paid === 0,
                    'canRecordPayment' => in_array($claim->status, ['accepted', 'partially_accepted', 'partially_paid'], true)
                        && $claim->accepted_amount_sen !== null && $paid < $claim->accepted_amount_sen,
                ],
            ];
        });
    }

    /** @return array{User, Branch} */
    private function context(User $actor): array
    {
        $branch = $this->branches->activeBranch($actor);
        abort_unless($branch && $branch->organisation_id === $actor->organisation_id, 404);
        $actor = $this->authority->lock($actor, $branch, 'panel.claims.manage.branch');

        return [$actor, $branch];
    }

    private function status(PanelClaim $claim): string
    {
        return match ($claim->status) {
            'prepared' => 'Prepared',
            'submitted' => 'Submitted',
            'queried' => 'Panel query',
            'accepted' => 'Accepted',
            'partially_accepted' => 'Partially accepted',
            'rejected' => 'Rejected',
            'partially_paid' => 'Partially paid',
            'paid' => 'Paid',
            default => 'Unknown',
        };
    }
}

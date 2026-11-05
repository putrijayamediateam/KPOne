<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\StaffAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Models\PanelClaimPayment;
use App\Domain\Visit\Billing\Models\PanelClaimPaymentReversal;
use App\Domain\Visit\Billing\Models\PanelRemittance;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * PNL-6 (owner decision 2026-11-05): reverse a wrongly recorded Panel payment. Nothing is edited: a reversal
 * row is added for each claim payment, the claim's status goes back to match what is still received, and the
 * right payment (if any) is recorded again through the usual screens. A whole payment is reversed, a split
 * Panel payment with all of its splits, and never by the person who recorded it. It is not a refund.
 */
final class PanelPaymentCorrectionService
{
    public const PERMISSION = 'panel.claim_payments.reverse.branch';

    public const REASONS = [
        'wrong_amount' => 'Wrong amount',
        'wrong_claim' => 'Wrong claim',
        'wrong_date_or_reference' => 'Wrong date or reference',
        'recorded_twice' => 'Recorded twice',
        'not_received' => 'Payment did not arrive or bounced',
        'other' => 'Other',
    ];

    public function __construct(
        private BranchAccessService $branches,
        private TransactionalActorAuthority $authority,
        private AuditRecorder $audit,
        private StaffAccessService $staff,
    ) {}

    /** @return array<string, mixed> */
    public function page(User $actor, int $page = 1): array
    {
        return DB::transaction(function () use ($actor, $page): array {
            $branch = $this->branches->activeBranch($actor);
            abort_unless($branch && $branch->organisation_id === $actor->organisation_id, 404);
            $actor = $this->authority->lock($actor, $branch, self::PERMISSION);
            $org = $actor->organisation_id;

            // Every recorded Panel payment at this branch: split Panel payments as one entry, and payments
            // recorded on a single claim. Newest first.
            $entries = DB::table('panel_remittances')->where('organisation_id', $org)->where('branch_id', $branch->id)
                ->select([DB::raw("'remittance' as kind"), 'id', 'public_id', 'panel_id', 'amount_sen', 'reference', 'received_at', 'recorded_by_user_id', 'created_at'])
                ->unionAll(DB::table('panel_claim_payments as p')
                    ->join('panel_claims as c', function ($join): void {
                        $join->on('c.id', '=', 'p.panel_claim_id')->on('c.organisation_id', '=', 'p.organisation_id')->on('c.branch_id', '=', 'p.branch_id');
                    })
                    ->where('p.organisation_id', $org)->where('p.branch_id', $branch->id)->whereNull('p.panel_remittance_id')
                    ->select([DB::raw("'payment' as kind"), 'p.id', 'p.public_id', 'c.panel_id', 'p.amount_sen', 'p.reference', 'p.received_at', 'p.recorded_by_user_id', 'p.created_at']));
            $rows = DB::query()->fromSub($entries, 'e')
                ->orderByDesc('e.received_at')->orderByDesc('e.created_at')->orderByDesc('e.kind')->orderByDesc('e.id')
                ->paginate(20, ['*'], 'page', $page);
            /** @var Collection<int, \stdClass> $items */
            $items = collect($rows->items());

            $remittanceIds = $items->where('kind', 'remittance')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $paymentIds = $items->where('kind', 'payment')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $payments = DB::table('panel_claim_payments as p')
                ->join('panel_claims as c', function ($join): void {
                    $join->on('c.id', '=', 'p.panel_claim_id')->on('c.organisation_id', '=', 'p.organisation_id')->on('c.branch_id', '=', 'p.branch_id');
                })
                ->where('p.organisation_id', $org)->where('p.branch_id', $branch->id)
                ->where(fn ($q) => $q->whereIn('p.panel_remittance_id', $remittanceIds)->orWhereIn('p.id', $paymentIds))
                ->orderBy('c.id')
                ->get(['p.id', 'p.panel_remittance_id', 'p.amount_sen', 'c.id as claim_id', 'c.public_id as claim_public_id', 'c.invoice_number', 'c.patient_name_snapshot']);
            $reversals = DB::table('panel_claim_payment_reversals')->where('organisation_id', $org)->where('branch_id', $branch->id)
                ->whereIn('panel_claim_payment_id', $payments->pluck('id')->all())
                ->get(['panel_claim_payment_id', 'reason_code', 'note', 'reversed_at', 'reversed_by_user_id'])->keyBy('panel_claim_payment_id');
            $userIds = $items->pluck('recorded_by_user_id')->merge($reversals->pluck('reversed_by_user_id'))->unique()->values()->all();
            // Staff names follow the staff-directory visibility rule (as BIL-2 receipts do); others read "Staff member".
            $users = $this->staff->visibleUsers($actor)->whereIn('id', $userIds)->pluck('name', 'id');
            $panelNames = DB::table('panels')->where('organisation_id', $org)->whereIn('id', $items->pluck('panel_id')->all())->pluck('name', 'id');

            return [
                'branch' => ['id' => $branch->id, 'name' => $branch->name],
                'reasons' => collect(self::REASONS)->map(static fn (string $label, string $code): array => ['code' => $code, 'label' => $label])->values()->all(),
                'payments' => [
                    'data' => $items->map(function (\stdClass $row) use ($payments, $reversals, $users, $panelNames, $branch, $actor): array {
                        $parts = $row->kind === 'remittance'
                            ? $payments->where('panel_remittance_id', $row->id)->values()
                            : $payments->where('id', $row->id)->values();
                        // A split Panel payment is always reversed whole, so its first split tells for all of them.
                        $reversal = $parts->isEmpty() ? null : $reversals->get($parts->first()->id);
                        $recordedBy = (int) $row->recorded_by_user_id;

                        return [
                            'kind' => (string) $row->kind,
                            'publicId' => (string) $row->public_id,
                            'panelName' => (string) ($panelNames[$row->panel_id] ?? ''),
                            'amountSen' => (int) $row->amount_sen,
                            'receivedDate' => CarbonImmutable::parse((string) $row->received_at, 'UTC')->setTimezone($branch->timezone)->format('j M Y'),
                            'reference' => $row->reference === null ? null : (string) $row->reference,
                            'recordedBy' => (string) ($users[$recordedBy] ?? 'Staff member'),
                            'recordedByMe' => $recordedBy === $actor->id,
                            'claims' => $parts->map(static fn (object $part): array => [
                                'claimNo' => (string) $part->claim_id,
                                'invoiceNumber' => (string) $part->invoice_number,
                                'patientName' => (string) $part->patient_name_snapshot,
                                'amountSen' => (int) $part->amount_sen,
                            ])->all(),
                            'reversal' => $reversal === null ? null : [
                                'reason' => self::REASONS[(string) $reversal->reason_code] ?? 'Other',
                                'note' => (string) $reversal->note,
                                'reversedBy' => (string) ($users[(int) $reversal->reversed_by_user_id] ?? 'Staff member'),
                                'reversedDate' => CarbonImmutable::parse((string) $reversal->reversed_at, 'UTC')->setTimezone($branch->timezone)->format('j M Y'),
                            ],
                        ];
                    })->all(),
                    'total' => $rows->total(),
                    'currentPage' => $rows->currentPage(),
                    'lastPage' => $rows->lastPage(),
                ],
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $a
     * @return Collection<int, PanelClaimPaymentReversal>
     */
    public function reverse(User $actor, array $a): Collection
    {
        if (is_string($a['target'] ?? null)) {
            $a['target'] = strtolower($a['target']);
        }
        if (is_string($a['note'] ?? null)) {
            $a['note'] = trim($a['note']);
        }
        Validator::make($a, [
            'expected_branch_id' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'uuid'],
            'kind' => ['required', Rule::in(['payment', 'remittance'])],
            'target' => ['required', 'uuid'],
            'reason_code' => ['required', Rule::in(array_keys(self::REASONS))],
            'note' => ['required', 'string', 'max:200'],
            'recording_error_only' => ['accepted'],
        ], [
            'reason_code.required' => 'Choose a reason.',
            'reason_code.in' => 'Choose a reason.',
            'note.required' => 'Write a short note on what was wrong.',
            'recording_error_only.accepted' => 'Confirm that this corrects a recording mistake and is not a refund.',
        ])->validate();

        return DB::transaction(function () use ($actor, $a): Collection {
            $branch = $this->authority->branch($actor, $a['expected_branch_id']);
            $actor = $this->authority->lock($actor, $branch, self::PERMISSION);
            $org = $actor->organisation_id;

            // Lock order: the Panel payment, its claim payments, then the claims by id. The two recording paths
            // lock claims and insert new payment rows but never lock existing payment rows, so there is no cycle.
            // No lock_version is asked for: a payment never changes, a second reversal is refused below and the
            // idempotency key covers a double submit.
            if ($a['kind'] === 'remittance') {
                $remittance = PanelRemittance::query()->where('organisation_id', $org)->where('branch_id', $branch->id)
                    ->where('public_id', $a['target'])->lockForUpdate()->first();
                if ($remittance === null) {
                    $this->refuse('This Panel payment is not recorded at this branch. Reload the page.');
                }
                $payments = PanelClaimPayment::query()->where('organisation_id', $org)->where('branch_id', $branch->id)
                    ->where('panel_remittance_id', $remittance->id)->orderBy('id')->lockForUpdate()->get();
            } else {
                $remittance = null;
                $payments = PanelClaimPayment::query()->where('organisation_id', $org)->where('branch_id', $branch->id)
                    ->where('public_id', $a['target'])->lockForUpdate()->get();
                if ($payments->isNotEmpty() && $payments->first()->panel_remittance_id !== null) {
                    $this->refuse('This payment is part of a split Panel payment. Reverse the whole Panel payment instead.');
                }
            }
            if ($payments->isEmpty()) {
                $this->refuse('This payment is not recorded at this branch. Reload the page.');
            }

            // Checked after the locks, so a double click with the same key returns the first result.
            $hash = hash('sha256', json_encode([$branch->id, $a['kind'], $a['target'], $a['reason_code'], $a['note']], JSON_THROW_ON_ERROR));
            $existing = PanelClaimPaymentReversal::query()->where('organisation_id', $org)
                ->where('idempotency_key', $a['idempotency_key'])->orderBy('id')->get();
            if ($existing->isNotEmpty()) {
                if ($existing->contains(fn (PanelClaimPaymentReversal $row): bool => $row->payload_hash !== $hash || $row->branch_id !== $branch->id)) {
                    $this->refuse('This correction was already made with different details. Reload the page.');
                }

                return $existing;
            }
            if (PanelClaimPaymentReversal::query()->whereIn('panel_claim_payment_id', $payments->pluck('id'))->exists()) {
                $this->refuse('This payment has already been reversed.');
            }
            if ($payments->contains(fn (PanelClaimPayment $payment): bool => $payment->recorded_by_user_id === $actor->id)) {
                $this->refuse('You recorded this payment, so someone else must reverse it.');
            }

            $claims = PanelClaim::query()->where('organisation_id', $org)->where('branch_id', $branch->id)
                ->whereIn('id', $payments->pluck('panel_claim_id')->unique()->all())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $now = CarbonImmutable::now()->utc();
            $created = collect();
            foreach ($payments as $payment) {
                $reversal = new PanelClaimPaymentReversal;
                $reversal->forceFill([
                    'public_id' => (string) Str::uuid(),
                    'organisation_id' => $org,
                    'branch_id' => $branch->id,
                    'panel_claim_id' => $payment->panel_claim_id,
                    'panel_claim_payment_id' => $payment->id,
                    'panel_remittance_id' => $payment->panel_remittance_id,
                    'amount_sen' => $payment->amount_sen,
                    'reason_code' => $a['reason_code'],
                    'note' => $a['note'],
                    'idempotency_key' => $a['idempotency_key'],
                    'payload_hash' => $hash,
                    'reversed_at' => $now,
                    'reversed_by_user_id' => $actor->id,
                ])->save();
                $created->push($reversal);
            }

            foreach ($claims as $claim) {
                $received = (int) $claim->receivedPayments()->sum('amount_sen');
                $accepted = (int) $claim->accepted_amount_sen;
                $status = match (true) {
                    $received === 0 => $accepted === $claim->claim_amount_sen ? 'accepted' : 'partially_accepted',
                    $received < $accepted => 'partially_paid',
                    default => 'paid',
                };
                $claim->forceFill(['status' => $status, 'lock_version' => $claim->lock_version + 1])->save();
                $this->audit->record('panel_claim.payment_reversed', $claim, [
                    'amount_sen' => (int) $payments->where('panel_claim_id', $claim->id)->sum('amount_sen'),
                    'reason_code' => $a['reason_code'],
                    'record_version' => $claim->lock_version,
                    'panel_remittance_id' => $remittance?->id,
                ], $actor, $branch);
            }
            if ($remittance !== null) {
                $this->audit->record('panel_remittance.reversed', $remittance, [
                    'amount_sen' => $remittance->amount_sen,
                    'claim_count' => $claims->count(),
                    'reason_code' => $a['reason_code'],
                ], $actor, $branch);
            }

            return $created;
        }, 3);
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['reversal' => $message]);
    }
}

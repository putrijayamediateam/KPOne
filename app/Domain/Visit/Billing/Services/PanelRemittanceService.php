<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Models\PanelClaimPayment;
use App\Domain\Visit\Billing\Models\PanelClaimPaymentReversal;
use App\Domain\Visit\Billing\Models\PanelRemittance;
use App\Domain\Visit\Models\Panel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * PNL-3: record one Panel payment (remittance) and split it across several accepted claims of that Panel.
 * Each split is an ordinary claim payment linked to the remittance, so claim pages, the Panel summary and
 * Insights read the same evidence. The split must equal the payment; nothing is ever edited or deleted.
 */
final class PanelRemittanceService
{
    public const PERMISSION = 'panel.remittances.record.branch';

    public const MAX_SPLITS = 50;

    public const MAX_OPEN_CLAIMS = 200;

    private const PAYABLE = ['accepted', 'partially_accepted', 'partially_paid'];

    public function __construct(
        private BranchAccessService $branches,
        private TransactionalActorAuthority $authority,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function page(User $actor, array $query, int $page = 1): array
    {
        $filters = Validator::make($query, ['panel' => ['sometimes', 'nullable', 'string', 'max:80']])->valid();

        return DB::transaction(function () use ($actor, $filters, $page): array {
            $branch = $this->branches->activeBranch($actor);
            abort_unless($branch && $branch->organisation_id === $actor->organisation_id, 404);
            $actor = $this->authority->lock($actor, $branch, self::PERMISSION);
            $today = CarbonImmutable::now($branch->timezone);
            $panel = ($filters['panel'] ?? '') === '' ? null : Panel::query()
                ->where('organisation_id', $actor->organisation_id)->where('code', (string) $filters['panel'])->first();

            $openClaims = $panel === null ? [] : $this->openClaims($actor, $branch, $panel);
            $history = PanelRemittance::query()->where('organisation_id', $actor->organisation_id)
                ->where('branch_id', $branch->id)->orderByDesc('received_at')->orderByDesc('id')
                ->paginate(20, ['*'], 'page', $page);
            $remittanceIds = array_values(array_map(static fn ($id): int => (int) $id, $history->getCollection()->pluck('id')->all()));
            $splits = DB::table('panel_claim_payments as p')
                ->join('panel_claims as c', function ($join): void {
                    $join->on('c.id', '=', 'p.panel_claim_id')->on('c.organisation_id', '=', 'p.organisation_id')->on('c.branch_id', '=', 'p.branch_id');
                })
                ->where('p.organisation_id', $actor->organisation_id)->where('p.branch_id', $branch->id)
                ->whereIn('p.panel_remittance_id', $remittanceIds)
                ->orderBy('c.id')
                ->get(['p.panel_remittance_id', 'p.amount_sen', 'c.id as claim_id', 'c.public_id', 'c.invoice_number', 'c.patient_name_snapshot'])
                ->groupBy('panel_remittance_id');
            $panelNames = DB::table('panels')->where('organisation_id', $actor->organisation_id)
                ->whereIn('id', $history->getCollection()->pluck('panel_id')->all())->pluck('name', 'id');
            $reversed = DB::table('panel_claim_payment_reversals')->where('organisation_id', $actor->organisation_id)
                ->whereIn('panel_remittance_id', $remittanceIds)->distinct()->pluck('panel_remittance_id')->flip();
            $recorders = DB::table('users')->where('organisation_id', $actor->organisation_id)
                ->whereIn('id', $history->getCollection()->pluck('recorded_by_user_id')->all())->pluck('name', 'id');

            return [
                'branch' => ['id' => $branch->id, 'name' => $branch->name, 'today' => $today->toDateString()],
                // Active Panels, plus any archived Panel that still has accepted claims waiting for payment here.
                'panels' => DB::table('panels')->where('organisation_id', $actor->organisation_id)
                    ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $panel?->id)
                        ->orWhereIn('id', PanelClaim::query()->select('panel_id')->where('organisation_id', $actor->organisation_id)
                            ->where('branch_id', $branch->id)->whereIn('status', self::PAYABLE)))
                    ->orderBy('name')->get(['code', 'name'])
                    ->map(static fn ($row): array => ['code' => (string) $row->code, 'name' => (string) $row->name])->all(),
                'filters' => ['panel' => $panel->code ?? ''],
                'maxSplits' => self::MAX_SPLITS,
                'canReverse' => $actor->can(PanelPaymentCorrectionService::PERMISSION),
                'openClaims' => array_slice($openClaims, 0, self::MAX_OPEN_CLAIMS),
                'openClaimsTruncated' => count($openClaims) > self::MAX_OPEN_CLAIMS,
                'remittances' => [
                    'data' => $history->getCollection()->map(fn (PanelRemittance $remittance): array => [
                        'publicId' => $remittance->public_id,
                        'panelName' => (string) ($panelNames[$remittance->panel_id] ?? ''),
                        'amountSen' => $remittance->amount_sen,
                        'receivedDate' => $remittance->received_at->setTimezone($branch->timezone)->format('j M Y'),
                        'reference' => $remittance->reference,
                        'recordedBy' => (string) ($recorders[$remittance->recorded_by_user_id] ?? ''),
                        'reversed' => $reversed->has($remittance->id),
                        'splits' => collect($splits->get($remittance->id, []))->map(static fn ($row): array => [
                            'claimNo' => (string) $row->claim_id,
                            'invoiceNumber' => (string) $row->invoice_number,
                            'patientName' => (string) $row->patient_name_snapshot,
                            'amountSen' => (int) $row->amount_sen,
                        ])->values()->all(),
                    ])->all(),
                    'total' => $history->total(),
                    'currentPage' => $history->currentPage(),
                    'lastPage' => $history->lastPage(),
                ],
            ];
        });
    }

    /** @return list<array<string, mixed>> */
    private function openClaims(User $actor, Branch $branch, Panel $panel): array
    {
        $received = '(select coalesce(sum(p.amount_sen), 0) from panel_claim_payments p where p.panel_claim_id = panel_claims.id and p.organisation_id = panel_claims.organisation_id and p.branch_id = panel_claims.branch_id and '.PanelClaimPaymentReversal::NOT_REVERSED_P.')';

        return array_values(PanelClaim::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
            ->where('panel_id', $panel->id)->whereIn('status', self::PAYABLE)->whereNotNull('accepted_amount_sen')
            ->whereRaw("accepted_amount_sen > {$received}")
            ->select('panel_claims.*')->selectRaw("{$received} as received_sen")
            ->orderBy('visit_date')->orderBy('id')->limit(self::MAX_OPEN_CLAIMS + 1)->get()
            ->map(static fn (PanelClaim $claim): array => [
                'publicId' => $claim->public_id,
                'claimNo' => (string) $claim->id,
                'invoiceNumber' => $claim->invoice_number,
                'patientName' => $claim->patient_name_snapshot,
                'visitDate' => $claim->visit_date->format('j M Y'),
                'acceptedSen' => (int) $claim->accepted_amount_sen,
                'receivedSen' => (int) $claim->getAttribute('received_sen'),
                'outstandingSen' => (int) $claim->accepted_amount_sen - (int) $claim->getAttribute('received_sen'),
            ])->values()->all());
    }

    /** @param array<string, mixed> $a */
    public function record(User $actor, array $a): PanelRemittance
    {
        // Claim ids are compared in lower case everywhere (the database compares UUIDs case-insensitively).
        if (is_array($a['splits'] ?? null)) {
            $a['splits'] = array_map(static fn (mixed $split): mixed => is_array($split) && is_string($split['claim'] ?? null)
                ? ['claim' => strtolower($split['claim'])] + $split : $split, $a['splits']);
        }
        Validator::make($a, [
            'expected_branch_id' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'uuid'],
            'panel' => ['required', 'string', 'max:80'],
            'amount_sen' => ['required'],
            'received_date' => ['required', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:100', 'regex:/\A[A-Za-z0-9 ._\/-]+\z/'],
            'splits' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_SPLITS],
            'splits.*' => ['required', 'array:claim,amount_sen'],
            'splits.*.claim' => ['required', 'uuid', 'distinct'],
            'splits.*.amount_sen' => ['required'],
        ], [
            'splits.required' => 'Enter how much of the payment goes to at least one claim.',
            'splits.min' => 'Enter how much of the payment goes to at least one claim.',
            'splits.max' => 'Split one payment across at most '.self::MAX_SPLITS.' claims.',
            'splits.*.claim.distinct' => 'A claim appears twice in the split.',
        ])->validate();
        $amount = ExactMoney::sen($a['amount_sen']);
        if ($amount < 1) {
            throw ValidationException::withMessages(['amount_sen' => 'A Panel payment must be positive.']);
        }
        /** @var list<array{claim: string, amount_sen: mixed}> $requested */
        $requested = $a['splits'];
        $splits = [];
        foreach ($requested as $index => $split) {
            $part = ExactMoney::sen($split['amount_sen'], "splits.{$index}.amount_sen");
            if ($part < 1) {
                throw ValidationException::withMessages(["splits.{$index}.amount_sen" => 'Each split must be positive. Leave out claims that get nothing.']);
            }
            $splits[(string) $split['claim']] = $part;
        }
        if (array_sum($splits) !== $amount) {
            throw ValidationException::withMessages(['splits' => 'The split adds up to RM '.number_format(array_sum($splits) / 100, 2).', but the payment is RM '.number_format($amount / 100, 2).'. They must be equal.']);
        }
        $reference = ($a['reference'] ?? null) === null || trim((string) $a['reference']) === '' ? null : trim((string) $a['reference']);
        ksort($splits);

        try {
            return $this->recordLocked($actor, $a, $amount, $splits, $reference);
        } catch (UniqueConstraintViolationException $e) {
            // Two simultaneous submits with one key: the first one won. Return it only if it is the same payment.
            $existing = PanelRemittance::query()->where('organisation_id', $actor->organisation_id)
                ->where('idempotency_key', (string) $a['idempotency_key'])->first();
            if ($existing === null) {
                throw $e;
            }

            return $this->recordLocked($actor, $a, $amount, $splits, $reference);
        }
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, int>  $splits
     */
    private function recordLocked(User $actor, array $a, int $amount, array $splits, ?string $reference): PanelRemittance
    {
        return DB::transaction(function () use ($actor, $a, $amount, $splits, $reference): PanelRemittance {
            $branch = $this->authority->branch($actor, $a['expected_branch_id']);
            $actor = $this->authority->lock($actor, $branch, self::PERMISSION);
            $date = CarbonImmutable::createFromFormat('!Y-m-d', (string) $a['received_date'], $branch->timezone);
            if (! $date || $date->format('Y-m-d') !== $a['received_date'] || $date->toDateString() > CarbonImmutable::now($branch->timezone)->toDateString()) {
                throw ValidationException::withMessages(['received_date' => 'Enter the date the Panel paid, today or earlier.']);
            }
            $receivedAt = $date->setTime(12, 0)->utc();
            $hash = hash('sha256', json_encode([
                $branch->id, (string) $a['panel'], $amount, $receivedAt->toIso8601String(), $reference, $splits,
            ], JSON_THROW_ON_ERROR));

            $existing = PanelRemittance::query()->where('organisation_id', $actor->organisation_id)
                ->where('idempotency_key', $a['idempotency_key'])->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->branch_id !== $branch->id || $existing->payload_hash !== $hash) {
                    throw ValidationException::withMessages(['remittance' => 'This payment was already recorded with different details. Reload the page.']);
                }

                return $existing;
            }

            $panel = Panel::query()->where('organisation_id', $actor->organisation_id)->where('code', (string) $a['panel'])->first();
            if ($panel === null) {
                throw ValidationException::withMessages(['panel' => 'Choose a Panel.']);
            }
            // One lock order: claims by id.
            $claims = PanelClaim::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
                ->whereIn('public_id', array_keys($splits))->orderBy('id')->lockForUpdate()->get();
            if ($claims->count() !== count($splits)) {
                throw ValidationException::withMessages(['splits' => 'A selected claim is no longer available at this branch. Reload the page.']);
            }
            $plan = [];
            foreach ($claims as $claim) {
                $part = $splits[$claim->public_id];
                if ($claim->panel_id !== $panel->id) {
                    throw ValidationException::withMessages(['splits' => 'Claim '.$claim->id.' is not a claim of '.$panel->name.'.']);
                }
                if (! in_array($claim->status, self::PAYABLE, true) || $claim->accepted_amount_sen === null) {
                    throw ValidationException::withMessages(['splits' => 'Claim '.$claim->id.' has no accepted amount waiting for payment.']);
                }
                $received = (int) $claim->receivedPayments()->sum('amount_sen');
                $outstanding = $claim->accepted_amount_sen - $received;
                if ($part > $outstanding) {
                    throw ValidationException::withMessages(['splits' => 'Claim '.$claim->id.': RM '.number_format($part / 100, 2).' is more than the RM '.number_format($outstanding / 100, 2).' still unpaid.']);
                }
                $plan[] = [$claim, $part, $received];
            }

            $remittance = new PanelRemittance;
            $remittance->forceFill([
                'public_id' => (string) Str::uuid(),
                'organisation_id' => $actor->organisation_id,
                'branch_id' => $branch->id,
                'panel_id' => $panel->id,
                'amount_sen' => $amount,
                'currency' => 'MYR',
                'reference' => $reference,
                'idempotency_key' => $a['idempotency_key'],
                'payload_hash' => $hash,
                'received_at' => $receivedAt,
                'recorded_by_user_id' => $actor->id,
            ])->save();

            foreach ($plan as [$claim, $part, $received]) {
                $payment = new PanelClaimPayment;
                $payment->forceFill([
                    'public_id' => (string) Str::uuid(),
                    'organisation_id' => $actor->organisation_id,
                    'branch_id' => $branch->id,
                    'panel_claim_id' => $claim->id,
                    'panel_remittance_id' => $remittance->id,
                    'amount_sen' => $part,
                    'currency' => 'MYR',
                    'reference' => $reference,
                    'idempotency_key' => (string) Str::uuid(),
                    'payload_hash' => hash('sha256', $hash.'|'.$claim->public_id),
                    'received_at' => $receivedAt,
                    'recorded_by_user_id' => $actor->id,
                ])->save();
                $claim->forceFill([
                    'status' => $received + $part === $claim->accepted_amount_sen ? 'paid' : 'partially_paid',
                    'lock_version' => $claim->lock_version + 1,
                ])->save();
                $this->audit->record('panel_claim.payment_received', $claim, [
                    'amount_sen' => $part,
                    'record_version' => $claim->lock_version,
                    'panel_remittance_id' => $remittance->id,
                ], $actor, $branch);
            }
            $this->audit->record('panel_remittance.recorded', $remittance, [
                'amount_sen' => $amount,
                'claim_count' => count($plan),
            ], $actor, $branch);

            return $remittance;
        }, 3);
    }
}

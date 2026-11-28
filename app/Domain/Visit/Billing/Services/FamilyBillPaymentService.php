<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Patient\Models\Patient;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * BIL-39: one payment at the counter for the family bill. The amount is spread over the members' open invoices oldest
 * finalised first, up to each invoice's due now (owner, 27 Nov: split A, part payment A); every share is an ordinary
 * receipt through PaymentService::add (one receipt per invoice, owner answer 3 A), so each keeps its own number, print,
 * reversal and refund. All or nothing.
 *
 * Kumar's review: R1 lock order as a single payment (every member patient, then visit, by id; the day gate; the
 * organisation share lock; then the invoices by id) before the first receipt; R2 every member invoice's lock_version and
 * the family total shown must still match; R3 a retry returns the receipts already recorded before any amount check;
 * R4 a family-level fingerprint on every child receipt refuses a changed retry.
 */
final class FamilyBillPaymentService
{
    /** Fixed namespace for family payment ids and their child receipt keys (do not change once data exists). */
    private const NAMESPACE = '8a0c6f1d-5b2e-4c7a-9e13-2f6d4b8a1c55';

    public const CHANGED = 'A bill in this family changed. Reload the family bill and try again.';

    public function __construct(
        private TransactionalActorAuthority $authority,
        private FamilyBillService $family,
        private PaymentService $payments,
        private BranchDayGate $gate,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return list<Payment>
     */
    public function pay(User $actor, Visit $anchor, array $input): array
    {
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer'],
            'idempotency_key' => ['required', 'uuid'],
            'method' => ['required', 'string', 'max:40'],
            'reference' => ['nullable', 'string', 'max:100', 'regex:/\A[A-Za-z0-9 ._\/-]+\z/'],
            'amount_sen' => ['required'],
            'expected_total_sen' => ['required', 'integer', 'min:0'],
            'members' => ['required', 'array', 'min:1', 'max:20'],
            'members.*.invoice_public_id' => ['required', 'uuid', 'distinct'],
            'members.*.lock_version' => ['required', 'integer', 'min:1'],
        ])->validate();
        $amount = ExactMoney::sen($data['amount_sen']);
        if ($amount === 0) {
            throw ValidationException::withMessages(['amount_sen' => 'A payment must be positive.']);
        }
        $familyPaymentId = Uuid::uuid5(self::NAMESPACE, "kpone:family-payment:{$actor->organisation_id}:{$data['idempotency_key']}")->toString();
        $members = collect($data['members'])->map(fn (array $m): array => ['invoice' => (string) $m['invoice_public_id'], 'version' => (int) $m['lock_version']])
            ->sortBy('invoice')->values()->all();
        $hash = hash('sha256', json_encode([$actor->id, (int) $data['expected_branch_id'], $amount, $data['method'], $data['reference'] ?? null,
            (int) $data['expected_total_sen'], $members], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $anchor, $data, $amount, $familyPaymentId, $hash): array {
            $branch = $this->authority->branch($actor, $data['expected_branch_id']);
            $actor = $this->authority->lock($actor, $branch, 'payments.add.branch');
            abort_unless($anchor->organisation_id === $actor->organisation_id && $anchor->branch_id === $branch->id && $anchor->family_group_id !== null, 404);

            // R3: a retry of a recorded family payment returns its receipts before anything else is checked.
            if ($done = $this->recorded($actor, $familyPaymentId, $hash)) {
                return $done;
            }

            // R1: lock every member patient, then visit (id order), as BillingContextService::lock does for one visit.
            $invoices = $this->family->openInvoices($anchor);
            $visits = Visit::query()->whereIn('id', $invoices->pluck('visit_id'))->orderBy('id')->get();
            Patient::query()->where('organisation_id', $actor->organisation_id)->whereIn('id', $visits->pluck('patient_id')->unique())
                ->orderBy('id')->lockForUpdate()->get();
            $visits = Visit::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
                ->whereIn('id', $visits->pluck('id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            // A same-key request that waited on these locks now finds the receipts the first one recorded.
            if ($done = $this->recorded($actor, $familyPaymentId, $hash)) {
                return $done;
            }
            $this->gate->guard($branch, null, false, 'payment'); // BIL-32: a new receipt belongs to today.
            Organisation::query()->whereKey($actor->organisation_id)->sharedLock()->firstOrFail();
            $locked = Invoice::query()->whereIn('id', $invoices->pluck('id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            // R2: the family as shown must be the family now: the same open invoices, versions and total.
            $current = $this->family->openInvoices($anchor);
            $sent = collect($data['members'])->mapWithKeys(fn (array $m): array => [(string) $m['invoice_public_id'] => (int) $m['lock_version']]);
            $payable = $current->reject(fn (Invoice $invoice): bool => (bool) $invoice->correction_hold);
            if ($current->pluck('id')->sort()->values()->all() !== $locked->keys()->sort()->values()->all()
                || $payable->count() !== $sent->count()
                || $payable->contains(fn (Invoice $invoice): bool => $sent->get($invoice->public_id) !== $locked[$invoice->id]->lock_version)
                || $visits->contains(fn (Visit $visit): bool => $visit->status !== Visit::STATUS_REGISTERED)) {
                throw ValidationException::withMessages(['family' => self::CHANGED]);
            }
            $states = app(FinancialLedger::class)->states(new Collection($payable->map(fn (Invoice $i): Invoice => $locked[$i->id])->values()->all()));
            $total = array_sum(array_map(fn (array $state): int => (int) $state['due_now'], $states));
            if ($total !== (int) $data['expected_total_sen']) {
                throw ValidationException::withMessages(['family' => self::CHANGED]);
            }
            if ($amount > $total) {
                throw ValidationException::withMessages(['amount_sen' => 'The payment is more than the family owes now.']);
            }

            // Oldest finalised first (openInvoices order), each up to its due now, until the money runs out.
            $left = $amount;
            $receipts = [];
            $split = [];
            foreach ($payable as $invoice) {
                $share = min($left, (int) $states[$invoice->id]['due_now']);
                if ($share <= 0) {
                    continue;
                }
                $receipts[] = $this->payments->add($actor, $visits[$invoice->visit_id], $locked[$invoice->id], [
                    'expected_branch_id' => $branch->id,
                    'lock_version' => $locked[$invoice->id]->lock_version,
                    'amount_sen' => (string) $share,
                    'method' => $data['method'],
                    'reference' => $data['reference'] ?? null,
                    'idempotency_key' => Uuid::uuid5(self::NAMESPACE, "kpone:family-receipt:{$familyPaymentId}:{$invoice->public_id}")->toString(),
                ], ['id' => $familyPaymentId, 'hash' => $hash]);
                $split[] = ['invoice_id' => $invoice->id, 'amount_sen' => $share];
                $left -= $share;
                if ($left === 0) {
                    break;
                }
            }

            $this->audit->record('billing.family_payment_recorded', $receipts[0], [
                'family_group_id' => $anchor->family_group_id,
                'family_payment_id' => $familyPaymentId,
                'amount_sen' => $amount,
                'split' => $split,
            ], $actor, $branch);

            return $receipts;
        }, 3);
    }

    /** @return list<Payment>|null */
    private function recorded(User $actor, string $familyPaymentId, string $hash): ?array
    {
        $existing = Payment::query()->where('organisation_id', $actor->organisation_id)->where('family_payment_id', $familyPaymentId)->orderBy('id')->get();
        if ($existing->isEmpty()) {
            return null;
        }
        if ($existing->contains(fn (Payment $payment): bool => $payment->family_payload_hash !== $hash)) {
            throw ValidationException::withMessages(['payment' => 'This request key was already used.']);
        }

        return $existing->all();
    }
}

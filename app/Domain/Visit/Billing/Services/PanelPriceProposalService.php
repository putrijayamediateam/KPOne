<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceChangeProposal;
use App\Domain\Visit\Billing\Models\PriceChangeProposalEvent;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Models\Panel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Panel price changes by proposal (owner decision 27, 20-21 Nov 2026; design note
 * docs/teams/catalogue-settings/PANEL-PRICE-PROPOSALS-DESIGN.md).
 *
 * A Panel Officer proposes a price for a Panel price book with an agreement reference and an effective date. A Finance
 * Officer or Director who is not the proposer approves it, and only then does a PriceEntry exist. A past effective time at
 * approval takes effect at approval (owner answer 7 = A). Self-pay prices are not covered and keep direct publishing.
 *
 * One transaction, one lock order (actor, charge, price book, proposal). Audit holds ids and codes only; the agreement
 * reference, the decision note and the amounts never reach it.
 */
final class PanelPriceProposalService
{
    public const PROPOSE_PERMISSION = 'panel.prices.propose.organisation';

    public const APPROVE_PERMISSION = 'panel.prices.approve.organisation';

    public const CHANGED = 'This proposal has changed. Reload and review it again.';

    public const STALE_PRICE = 'The price changed after this was proposed. Propose it again.';

    public function __construct(
        private TransactionalActorAuthority $authority,
        private AuditRecorder $audit,
        private PricePublicationService $publication,
    ) {}

    /** @param array<string, mixed> $a */
    public function propose(User $actor, PriceBook $routeBook, ChargeDefinition $routeCharge, array $a): PriceChangeProposal
    {
        Validator::make($a, [
            'expected_branch_id' => ['required', 'integer', 'min:1'],
            'expected_version' => ['required', 'integer', 'min:0'],
            'agreement_reference' => ['required', 'string'],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'effective_time' => ['nullable', 'date_format:H:i'],
            'idempotency_key' => ['required', 'uuid'],
        ])->validate();
        if (! array_key_exists('amount_sen', $a) || $a['amount_sen'] === null || (is_string($a['amount_sen']) && trim($a['amount_sen']) === '')) {
            throw ValidationException::withMessages(['amount_sen' => PricePublicationService::EMPTY_AMOUNT_MESSAGE]);
        }
        $amount = ExactMoney::sen($a['amount_sen'], 'amount_sen');
        $reference = trim((string) preg_replace('/\s+/u', ' ', (string) $a['agreement_reference']));
        if (mb_strlen($reference) < 3 || mb_strlen($reference) > 120) {
            throw ValidationException::withMessages(['agreement_reference' => 'Enter the agreement reference, 3 to 120 characters.']);
        }
        $time = (string) ($a['effective_time'] ?? '00:00') ?: '00:00';
        $hash = hash('sha256', json_encode([$routeBook->id, $routeCharge->id, $amount, $reference, $a['effective_date'], $time, (int) $a['expected_version']], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $routeBook, $routeCharge, $a, $amount, $reference, $time, $hash): PriceChangeProposal {
            $branch = $this->authority->branch($actor, $a['expected_branch_id']);
            $actor = $this->authority->lock($actor, $branch, self::PROPOSE_PERMISSION);
            $existing = PriceChangeProposal::query()->where('organisation_id', $actor->organisation_id)
                ->where('proposed_by_user_id', $actor->id)->where('idempotency_key', $a['idempotency_key'])->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->payload_hash !== $hash) {
                    throw ValidationException::withMessages(['proposal' => 'This request key was already used with different details.']);
                }

                return $existing;
            }
            [$charge, $book] = $this->lockTarget($actor, $routeBook, $routeCharge);
            $effective = $this->effectiveInstant($branch, (string) $a['effective_date'], $time);
            if ($effective->lt($this->startOfBranchToday($branch))) {
                throw ValidationException::withMessages(['effective_date' => 'Choose today or a later date.']);
            }
            $version = $this->currentVersion($book, $charge);
            if ($version !== (int) $a['expected_version']) {
                throw ValidationException::withMessages(['proposal' => self::STALE_PRICE]);
            }
            $latest = $this->latestEffective($book, $charge);
            if ($latest !== null && $effective->lt($latest)) {
                throw ValidationException::withMessages(['effective_date' => 'A price taking effect later is already recorded. Choose a date after it.']);
            }
            if (PriceChangeProposal::query()->where('price_book_id', $book->id)->where('charge_definition_id', $charge->id)->where('status', 'proposed')->exists()) {
                throw ValidationException::withMessages(['proposal' => 'A price change for this Panel and charge is already waiting for a decision.']);
            }

            $proposal = new PriceChangeProposal;
            $proposal->forceFill([
                'public_id' => (string) Str::uuid(),
                'organisation_id' => $actor->organisation_id,
                'price_book_id' => $book->id,
                'charge_definition_id' => $charge->id,
                'proposed_unit_price_sen' => $amount,
                'agreement_reference' => $reference,
                'effective_at' => $effective,
                'base_version' => $version,
                'status' => 'proposed',
                'proposed_by_user_id' => $actor->id,
                'proposed_at' => now()->utc(),
                'idempotency_key' => $a['idempotency_key'],
                'payload_hash' => $hash,
                'lock_version' => 1,
            ])->save();
            $this->event($proposal, 'proposed', $actor);
            $this->audit->record('panel_price.proposed', $proposal, ['price_book_id' => $book->id, 'charge_definition_id' => $charge->id, 'base_version' => $version], $actor, $branch);

            return $proposal;
        }, 3);
    }

    /** @param array<string, mixed> $a */
    public function approve(User $actor, PriceChangeProposal $routeProposal, array $a): PriceChangeProposal
    {
        Validator::make($a, [
            'expected_branch_id' => ['required', 'integer', 'min:1'],
            'lock_version' => ['required', 'integer', 'min:1'],
        ])->validate();

        return DB::transaction(function () use ($actor, $routeProposal, $a): PriceChangeProposal {
            $branch = $this->authority->branch($actor, $a['expected_branch_id']);
            $actor = $this->authority->lock($actor, $branch, self::APPROVE_PERMISSION);
            $peek = $this->find($actor, $routeProposal);
            [$charge, $book] = $this->lockTarget($actor, $peek->priceBook, $peek->charge, requireActive: false);
            $proposal = PriceChangeProposal::query()->whereKey($peek->id)->lockForUpdate()->firstOrFail();
            if ($proposal->status === 'approved' && $proposal->decided_by_user_id === $actor->id && $proposal->lock_version === (int) $a['lock_version'] + 1) {
                return $proposal; // A retried approval does nothing.
            }
            $this->assertDecidable($proposal, $actor, (int) $a['lock_version']);
            if ($this->currentVersion($book, $charge) !== $proposal->base_version) {
                throw ValidationException::withMessages(['proposal' => self::STALE_PRICE]);
            }
            $now = now()->utc();
            $effective = $proposal->effective_at->gt($now) ? $proposal->effective_at : CarbonImmutable::instance($now);
            $latest = $this->latestEffective($book, $charge);
            if ($latest !== null && $effective->lt($latest)) {
                throw ValidationException::withMessages(['proposal' => 'A price taking effect later is already recorded. Reject this and propose it again.']);
            }
            $entry = $this->publication->publishApproved($actor, $book, $charge, $proposal->proposed_unit_price_sen, $proposal->base_version, $effective);
            $proposal->forceFill([
                'status' => 'approved', 'decided_by_user_id' => $actor->id, 'decided_at' => $now,
                'price_entry_id' => $entry->id, 'applied_effective_at' => $effective, 'lock_version' => $proposal->lock_version + 1,
            ])->save();
            $this->event($proposal, 'approved', $actor);
            $this->audit->record('panel_price.approved', $proposal, [
                'price_book_id' => $book->id, 'charge_definition_id' => $charge->id, 'price_entry_id' => $entry->id, 'record_version' => $entry->version,
                'took_effect_at_approval' => ! $proposal->effective_at->eq($effective),
            ], $actor, $branch);
            $this->audit->record('billing.price_published', $entry, ['record_version' => $entry->version, 'price_change_proposal_id' => $proposal->id], $actor, $branch);

            return $proposal;
        }, 3);
    }

    /** @param array<string, mixed> $a */
    public function reject(User $actor, PriceChangeProposal $routeProposal, array $a): PriceChangeProposal
    {
        Validator::make($a, [
            'expected_branch_id' => ['required', 'integer', 'min:1'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'reason_code' => ['required', Rule::in(array_keys(PriceChangeProposal::REJECT_REASONS))],
            'note' => ['nullable', 'string', 'max:500', Rule::requiredIf(($a['reason_code'] ?? null) === 'other')],
        ])->validate();
        $note = trim((string) ($a['note'] ?? '')) === '' ? null : trim((string) $a['note']);

        return DB::transaction(function () use ($actor, $routeProposal, $a, $note): PriceChangeProposal {
            $branch = $this->authority->branch($actor, $a['expected_branch_id']);
            $actor = $this->authority->lock($actor, $branch, self::APPROVE_PERMISSION);
            $peek = $this->find($actor, $routeProposal);
            [$charge, $book] = $this->lockTarget($actor, $peek->priceBook, $peek->charge, requireActive: false);
            $proposal = PriceChangeProposal::query()->whereKey($peek->id)->lockForUpdate()->firstOrFail();
            $this->assertDecidable($proposal, $actor, (int) $a['lock_version']);
            $proposal->forceFill([
                'status' => 'rejected', 'decided_by_user_id' => $actor->id, 'decided_at' => now()->utc(),
                'reason_code' => $a['reason_code'], 'decision_note' => $note, 'lock_version' => $proposal->lock_version + 1,
            ])->save();
            $this->event($proposal, 'rejected', $actor);
            $this->audit->record('panel_price.rejected', $proposal, ['price_book_id' => $book->id, 'charge_definition_id' => $charge->id, 'reason_code' => $a['reason_code']], $actor, $branch);

            return $proposal;
        }, 3);
    }

    /** @param array<string, mixed> $a */
    public function withdraw(User $actor, PriceChangeProposal $routeProposal, array $a): PriceChangeProposal
    {
        Validator::make($a, [
            'expected_branch_id' => ['required', 'integer', 'min:1'],
            'lock_version' => ['required', 'integer', 'min:1'],
        ])->validate();

        return DB::transaction(function () use ($actor, $routeProposal, $a): PriceChangeProposal {
            $branch = $this->authority->branch($actor, $a['expected_branch_id']);
            $actor = $this->authority->lock($actor, $branch, self::PROPOSE_PERMISSION);
            $peek = $this->find($actor, $routeProposal);
            [$charge, $book] = $this->lockTarget($actor, $peek->priceBook, $peek->charge, requireActive: false);
            $proposal = PriceChangeProposal::query()->whereKey($peek->id)->lockForUpdate()->firstOrFail();
            if ($proposal->status !== 'proposed' || $proposal->lock_version !== (int) $a['lock_version']) {
                throw ValidationException::withMessages(['proposal' => self::CHANGED]);
            }
            if ($proposal->proposed_by_user_id !== $actor->id) {
                throw ValidationException::withMessages(['proposal' => 'Only the person who proposed this can withdraw it.']);
            }
            $proposal->forceFill([
                'status' => 'withdrawn', 'decided_by_user_id' => $actor->id, 'decided_at' => now()->utc(), 'lock_version' => $proposal->lock_version + 1,
            ])->save();
            $this->event($proposal, 'withdrawn', $actor);
            $this->audit->record('panel_price.withdrawn', $proposal, ['price_book_id' => $book->id, 'charge_definition_id' => $charge->id], $actor, $branch);

            return $proposal;
        }, 3);
    }

    private function assertDecidable(PriceChangeProposal $proposal, User $actor, int $expectedLock): void
    {
        if ($proposal->status !== 'proposed' || $proposal->lock_version !== $expectedLock) {
            throw ValidationException::withMessages(['proposal' => self::CHANGED]);
        }
        if ($proposal->proposed_by_user_id === $actor->id) {
            throw ValidationException::withMessages(['proposal' => 'You proposed this. Someone else must decide.']);
        }
    }

    private function find(User $actor, PriceChangeProposal $routeProposal): PriceChangeProposal
    {
        return PriceChangeProposal::query()->whereKey($routeProposal->id)->where('organisation_id', $actor->organisation_id)->firstOrFail();
    }

    /**
     * Charge, then price book: the order PricePublicationService::publish() uses. A Panel book is organisation-level.
     *
     * @return array{ChargeDefinition, PriceBook}
     */
    private function lockTarget(User $actor, PriceBook $routeBook, ChargeDefinition $routeCharge, bool $requireActive = true): array
    {
        $charge = ChargeDefinition::query()->whereKey($routeCharge->id)->where('organisation_id', $actor->organisation_id)
            ->when($requireActive, fn ($q) => $q->where('is_active', true))->lockForUpdate()->firstOrFail();
        $book = PriceBook::query()->whereKey($routeBook->id)->where('organisation_id', $actor->organisation_id)
            ->when($requireActive, fn ($q) => $q->where('is_active', true))->where('currency', 'MYR')->lockForUpdate()->firstOrFail();
        abort_unless($book->price_tier === 'panel' && $book->branch_id === null, 404);
        if ($book->panel_id !== null) {
            Panel::query()->whereKey($book->panel_id)->where('organisation_id', $actor->organisation_id)
                ->when($requireActive, fn ($q) => $q->where('is_active', true))->firstOrFail();
        }

        return [$charge, $book];
    }

    private function currentVersion(PriceBook $book, ChargeDefinition $charge): int
    {
        return (int) PriceEntry::query()->where('price_book_id', $book->id)->where('charge_definition_id', $charge->id)->max('version');
    }

    private function latestEffective(PriceBook $book, ChargeDefinition $charge): ?CarbonImmutable
    {
        $entry = PriceEntry::query()->where('price_book_id', $book->id)->where('charge_definition_id', $charge->id)->orderByDesc('effective_at')->orderByDesc('version')->first();

        return $entry?->effective_at;
    }

    private function effectiveInstant(Branch $branch, string $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i', $date.' '.$time, $branch->timezone)->utc();
    }

    private function startOfBranchToday(Branch $branch): CarbonImmutable
    {
        return CarbonImmutable::now($branch->timezone)->startOfDay()->utc();
    }

    private function event(PriceChangeProposal $proposal, string $event, User $actor): void
    {
        $row = new PriceChangeProposalEvent;
        $row->forceFill([
            'price_change_proposal_id' => $proposal->id, 'organisation_id' => $proposal->organisation_id,
            'event' => $event, 'actor_user_id' => $actor->id, 'occurred_at' => now()->utc(),
        ])->save();
    }
}

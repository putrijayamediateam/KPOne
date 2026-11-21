<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PricePublicationService
{
    public const EMPTY_AMOUNT_MESSAGE = 'Enter an amount. Type 0.00 if this is free.';

    public function __construct(private TransactionalActorAuthority $authority, private AuditRecorder $audit) {}

    public const PANEL_BY_PROPOSAL_MESSAGE = 'Panel prices are changed by proposal. Use Panel price changes.';

    public function publish(User $actor, PriceBook $book, ChargeDefinition $charge, mixed $amountSen, int $expectedVersion, int $expectedBranch): PriceEntry
    {
        // CAT-1b: an empty amount is refused with a clear message, never read as zero.
        if ($amountSen === null || (is_string($amountSen) && trim($amountSen) === '')) {
            throw ValidationException::withMessages(['amount_sen' => self::EMPTY_AMOUNT_MESSAGE]);
        }
        $amount = ExactMoney::sen($amountSen, 'unit_price_sen');
        $branch = $this->authority->branch($actor, $expectedBranch);

        return DB::transaction(function () use ($actor, $book, $charge, $amount, $expectedVersion, $branch): PriceEntry {
            $actor = $this->authority->lock($actor, $branch, 'prices.publish.organisation');
            $charge = ChargeDefinition::query()->whereKey($charge->id)->where('organisation_id', $actor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
            $book = PriceBook::query()->whereKey($book->id)->where('organisation_id', $actor->organisation_id)->where('is_active', true)->where('currency', 'MYR')->lockForUpdate()->firstOrFail();
            abort_unless($book->branch_id === null || $book->branch_id === $branch->id, 404);
            if ($book->price_tier === 'panel') {
                // Owner decision 27 (21 Nov 2026): a Panel price changes only by proposal and approval
                // (PanelPriceProposalService), so direct publishing is closed for every Panel price book.
                throw ValidationException::withMessages(['prices' => self::PANEL_BY_PROPOSAL_MESSAGE]);
            } elseif ($book->panel_id !== null) {
                abort(404);
            }
            $version = (int) PriceEntry::query()->where('price_book_id', $book->id)->where('charge_definition_id', $charge->id)->max('version');
            if ($version !== $expectedVersion) {
                throw ValidationException::withMessages(['price' => 'The published price changed. Review before publishing.']);
            }
            $entry = new PriceEntry;
            $entry->forceFill(['organisation_id' => $actor->organisation_id, 'price_book_id' => $book->id, 'charge_definition_id' => $charge->id, 'unit_price_sen' => $amount, 'version' => $version + 1, 'effective_at' => now()->utc(), 'published_by_user_id' => $actor->id])->save();
            $this->audit->record('billing.price_published', $entry, ['record_version' => $entry->version], $actor, $branch);

            return $entry;
        }, 3);
    }

    /**
     * Writes the PriceEntry of an approved Panel price change (PanelPriceProposalService). The only way a Panel price
     * is meant to be written once direct publishing of Panel books is closed. The caller holds the charge, price book and
     * proposal locks, has checked the version and the effective time, and records its own audit.
     */
    public function publishApproved(User $actor, PriceBook $book, ChargeDefinition $charge, int $amountSen, int $expectedVersion, CarbonImmutable $effectiveAt): PriceEntry
    {
        $version = (int) PriceEntry::query()->where('price_book_id', $book->id)->where('charge_definition_id', $charge->id)->max('version');
        if ($version !== $expectedVersion) {
            throw ValidationException::withMessages(['price' => 'The price changed after this was proposed. Propose it again.']);
        }
        $entry = new PriceEntry;
        $entry->forceFill(['organisation_id' => $actor->organisation_id, 'price_book_id' => $book->id, 'charge_definition_id' => $charge->id, 'unit_price_sen' => $amountSen, 'version' => $version + 1, 'effective_at' => $effectiveAt, 'published_by_user_id' => $actor->id])->save();

        return $entry;
    }
}

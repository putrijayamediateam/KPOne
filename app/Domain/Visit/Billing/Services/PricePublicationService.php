<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PricePublicationService
{
    public function __construct(private TransactionalActorAuthority $authority, private AuditRecorder $audit) {}

    public function publish(User $actor, PriceBook $book, ChargeDefinition $charge, mixed $amountSen, int $expectedVersion, int $expectedBranch): PriceEntry
    {
        $amount = ExactMoney::sen($amountSen, 'unit_price_sen');
        $branch = $this->authority->branch($actor, $expectedBranch);

        return DB::transaction(function () use ($actor, $book, $charge, $amount, $expectedVersion, $branch): PriceEntry {
            $actor = $this->authority->lock($actor, $branch, 'prices.publish.organisation');
            $charge = ChargeDefinition::query()->whereKey($charge->id)->where('organisation_id', $actor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
            $book = PriceBook::query()->whereKey($book->id)->where('organisation_id', $actor->organisation_id)->where('is_active', true)->where('currency', 'MYR')->lockForUpdate()->firstOrFail();
            abort_unless($book->branch_id === null || $book->branch_id === $branch->id, 404);
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
}

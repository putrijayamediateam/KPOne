<?php

namespace Tests\Support;

use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Test fixture: Panel prices are never published directly (owner decision 27), so tests that need a Panel price in
 * place write the price entry the way an approved proposal would, with the next version and the current time.
 */
final class PanelPriceFixture
{
    public static function insert(PriceBook $book, ChargeDefinition $charge, int $sen, User $by): PriceEntry
    {
        $version = (int) DB::table('price_entries')->where('price_book_id', $book->id)->where('charge_definition_id', $charge->id)->max('version');
        $entry = new PriceEntry;
        $entry->forceFill([
            'organisation_id' => $book->organisation_id, 'price_book_id' => $book->id, 'charge_definition_id' => $charge->id,
            'unit_price_sen' => $sen, 'version' => $version + 1, 'effective_at' => now()->utc(), 'published_by_user_id' => $by->id,
        ])->save();

        return $entry;
    }
}

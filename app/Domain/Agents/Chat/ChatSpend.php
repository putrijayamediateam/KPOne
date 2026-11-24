<?php

namespace App\Domain\Agents\Chat;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The monthly spend cap (the owner's RM 50, 2026-11-24). Spend is an estimate from the provider's reported token use and the prices in
 * config/ai_chat.php, counted for the calendar month in Kuala Lumpur time. Before the provider is called, a worst-case amount is reserved
 * under a database lock, so two messages at once cannot both pass the cap; afterwards the reservation is settled to the real cost, also
 * when the answer failed after tokens were spent.
 */
final class ChatSpend
{
    public static function costSen(int $tokensIn, int $tokensOut): int
    {
        $usd = $tokensIn / 1_000_000 * (float) config('ai_chat.price_in_per_million_usd') + $tokensOut / 1_000_000 * (float) config('ai_chat.price_out_per_million_usd');

        return (int) ceil($usd * (float) config('ai_chat.usd_to_myr') * 100);
    }

    public static function monthStart(): CarbonInterface
    {
        return now('Asia/Kuala_Lumpur')->startOfMonth();
    }

    public static function spentSen(int $organisationId): int
    {
        return (int) AiChatUsage::query()->where('organisation_id', $organisationId)->where('created_at', '>=', self::monthStart()->utc())->sum('cost_sen');
    }

    /** True when one more message could not be reserved (spent plus the reservation would pass the cap). */
    public static function reached(int $organisationId): bool
    {
        return self::spentSen($organisationId) + (int) config('ai_chat.reserve_sen', 100) > (int) config('ai_chat.monthly_cap_sen');
    }

    /** @throws ChatRefused when the cap is reached */
    public static function reserve(int $organisationId, int $userId): AiChatUsage
    {
        return DB::transaction(function () use ($organisationId, $userId): AiChatUsage {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ['ai-chat-usage:'.$organisationId]);
            }
            if (self::reached($organisationId)) {
                throw new ChatRefused('limit_reached', 'The monthly AI limit is reached. It resets on '.self::resetsOn().'.', 429);
            }

            return AiChatUsage::query()->create([
                'organisation_id' => $organisationId, 'user_id' => $userId, 'state' => 'reserved',
                'cost_sen' => (int) config('ai_chat.reserve_sen', 100), 'created_at' => now(),
            ]);
        });
    }

    public static function settle(AiChatUsage $usage, int $tokensIn, int $tokensOut, string $outcome): int
    {
        $cost = $tokensIn === 0 && $tokensOut === 0 ? 0 : self::costSen($tokensIn, $tokensOut);
        $usage->forceFill(['state' => 'settled', 'cost_sen' => $cost, 'tokens_in' => $tokensIn, 'tokens_out' => $tokensOut, 'outcome' => $outcome, 'settled_at' => now()])->save();

        return $cost;
    }

    /** "1 Dec" style date the cap resets, in Kuala Lumpur time. */
    public static function resetsOn(): string
    {
        return self::monthStart()->addMonth()->format('j M');
    }
}

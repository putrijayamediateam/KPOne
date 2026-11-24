<?php

namespace App\Domain\Agents\Chat;

use Carbon\CarbonInterface;

/**
 * The monthly spend cap (the owner's RM 50, 2026-11-24). Spend is an estimate from the provider's reported token use and the prices
 * in config/ai_chat.php, counted for the calendar month in Kuala Lumpur time. The chatbox refuses new questions once the cap is reached.
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
        return (int) AiChatMessage::query()->where('organisation_id', $organisationId)
            ->where('created_at', '>=', self::monthStart()->utc())->sum('cost_sen');
    }

    public static function reached(int $organisationId): bool
    {
        return self::spentSen($organisationId) >= (int) config('ai_chat.monthly_cap_sen');
    }

    /** "1 Dec" style date the cap resets, in Kuala Lumpur time. */
    public static function resetsOn(): string
    {
        return self::monthStart()->addMonth()->format('j M');
    }
}

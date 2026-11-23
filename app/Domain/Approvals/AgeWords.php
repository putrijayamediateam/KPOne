<?php

namespace App\Domain\Approvals;

use Carbon\CarbonImmutable;

/** Age in words only (no colours): "today", "1 day", "3 days". Whole days of 24 hours, never negative. */
final class AgeWords
{
    public static function days(CarbonImmutable $since, CarbonImmutable $now): string
    {
        $days = max(0, intdiv($now->getTimestamp() - $since->getTimestamp(), 86400));

        return match (true) {
            $days === 0 => 'today',
            $days === 1 => '1 day',
            default => $days.' days',
        };
    }
}

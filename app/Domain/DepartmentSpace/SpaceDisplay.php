<?php

namespace App\Domain\DepartmentSpace;

use App\Domain\Queue\Display\ShortDisplayName;
use Carbon\CarbonImmutable;

/** How the spaces show a patient and a wait: shared by every space so they read the same everywhere. */
final class SpaceDisplay
{
    /** The shared waiting-time rule (resources/js/lib/waiting-time.ts): amber from 30 minutes, red from 60. */
    public const WAIT_LONG_MINUTES = 30;

    public const WAIT_VERY_LONG_MINUTES = 60;

    /** "Ahmad I." from "Ahmad Ismail bin Ali": the first name and the initial of the next, never the full name. */
    public static function shortName(string $fullName): string
    {
        $words = explode(' ', (string) ShortDisplayName::from($fullName));
        if (count($words) < 2 || $words[1] === '') {
            return $words[0] !== '' ? $words[0] : 'Patient';
        }

        return $words[0].' '.mb_strtoupper(mb_substr($words[1], 0, 1)).'.';
    }

    /** "Waiting 144 min, Very long wait"; from 24 hours "Waiting 6 days 5 h, Very long wait": the words as well as the colour. */
    public static function waitText(int $minutes): string
    {
        $label = $minutes >= self::WAIT_VERY_LONG_MINUTES ? ', Very long wait' : ($minutes >= self::WAIT_LONG_MINUTES ? ', Long wait' : '');

        return 'Waiting '.self::duration($minutes).$label;
    }

    /** "45 min" under 24 hours, then "6 days 5 h" (or "1 day"). */
    public static function duration(int $minutes): string
    {
        if ($minutes < 1440) {
            return $minutes.' min';
        }
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);

        return $days.($days === 1 ? ' day' : ' days').($hours > 0 ? ' '.$hours.' h' : '');
    }

    /** "Due 3 Nov 2026, 9 days overdue (very overdue)": the words as well as the colour; very overdue from 30 days. */
    public static function dueText(string $dueDate, int $daysOverdue): string
    {
        if ($daysOverdue <= 0) {
            return 'Due '.$dueDate;
        }

        return 'Due '.$dueDate.', '.$daysOverdue.($daysOverdue === 1 ? ' day' : ' days').' overdue'.($daysOverdue >= 30 ? ' (very overdue)' : '');
    }

    /** "Expires 3 Dec 2026, in 22 days" or "Expired 9 days ago": the words as well as the colour. */
    public static function expiryText(string $expiry, string $today): string
    {
        $date = CarbonImmutable::parse($expiry)->startOfDay();
        $now = CarbonImmutable::parse($today)->startOfDay();
        $days = (int) $now->diffInDays($date, false);
        $label = $date->format('j M Y');
        if ($days > 0) {
            return 'Expires '.$label.', in '.$days.($days === 1 ? ' day' : ' days');
        }
        if ($days === 0) {
            return 'Expires '.$label.', today';
        }

        return 'Expired '.abs($days).(abs($days) === 1 ? ' day' : ' days').' ago';
    }

    /** "RM 1,250.50" from sen. */
    public static function rm(int $sen): string
    {
        return ($sen < 0 ? '-' : '').'RM '.number_format(abs($sen) / 100, 2);
    }
}

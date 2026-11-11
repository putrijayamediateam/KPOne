<?php

namespace App\Domain\DepartmentSpace;

use App\Domain\Queue\Display\ShortDisplayName;

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

    /** "Waiting 144 min, Very long wait": the words as well as the colour. */
    public static function waitText(int $minutes): string
    {
        $label = $minutes >= self::WAIT_VERY_LONG_MINUTES ? ', Very long wait' : ($minutes >= self::WAIT_LONG_MINUTES ? ', Long wait' : '');

        return 'Waiting '.$minutes.' min'.$label;
    }
}

<?php

namespace App\Domain\Queue;

/** How a queue number is shown to people: a series letter and three digits, for example A-001 or B-014. */
final class QueueNumberFormat
{
    public const CONSULTATION = 'A';

    public const OTC = 'B';

    public static function format(int $number, string $series = self::CONSULTATION): string
    {
        return sprintf('%s-%03d', $series, $number);
    }
}

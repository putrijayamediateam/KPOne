<?php

namespace App\Domain\Visit\Billing\Services;

use Carbon\CarbonImmutable;

/**
 * A Panel's claim submission rule, evaluated in the branch timezone.
 *
 * days_after_visit: N days after the visit date (the original rule).
 * daily: by a time, 0-3 days after the visit date.
 * weekly: by a time, on the next given weekday after the visit date (1 Monday ... 7 Sunday).
 * monthly: by a time, on day 1-28 of the month after the visit, or (offset 0) the last day of the visit month.
 */
final class PanelClaimDueRule
{
    public const RULES = ['days_after_visit', 'daily', 'weekly', 'monthly'];

    private const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    /** @param  object  $rule a Panel row carrying claim_due_rule, claim_due_days, claim_due_time and claim_due_offset */
    public static function dueAt(object $rule, CarbonImmutable $visitLocal): CarbonImmutable
    {
        $kind = (string) ($rule->claim_due_rule ?? 'days_after_visit');
        $day = $visitLocal->startOfDay();
        if ($kind === 'days_after_visit' || ! isset($rule->claim_due_time, $rule->claim_due_offset)) {
            return $day->addDays((int) ($rule->claim_due_days ?? 30));
        }
        $offset = (int) $rule->claim_due_offset;
        $date = match ($kind) {
            'daily' => $day->addDays($offset),
            'weekly' => $day->addDays(((($offset - $day->isoWeekday()) % 7) + 7) % 7 ?: 7),
            default => $offset === 0 ? $day->endOfMonth()->startOfDay() : $day->startOfMonth()->addMonthNoOverflow()->day($offset),
        };
        [$hour, $minute] = array_map('intval', explode(':', (string) $rule->claim_due_time));

        return $date->setTime($hour, $minute);
    }

    /** @param  object  $rule a Panel row carrying claim_due_rule, claim_due_days, claim_due_time and claim_due_offset */
    public static function isOverdue(object $rule, CarbonImmutable $dueAt, CarbonImmutable $now): bool
    {
        // The original rule counts whole days: late only once the due date is before today.
        return (string) ($rule->claim_due_rule ?? 'days_after_visit') === 'days_after_visit'
            ? $dueAt->lt($now->startOfDay())
            : $dueAt->lt($now);
    }

    /** @param  object  $rule a Panel row carrying claim_due_rule, claim_due_days, claim_due_time and claim_due_offset */
    public static function describe(object $rule): string
    {
        $kind = (string) ($rule->claim_due_rule ?? 'days_after_visit');
        if ($kind === 'days_after_visit' || ! isset($rule->claim_due_time, $rule->claim_due_offset)) {
            $days = (int) ($rule->claim_due_days ?? 30);

            return $days === 0 ? 'Submit on the day of the visit' : "Submit {$days} days after the visit";
        }
        $time = CarbonImmutable::createFromFormat('H:i', (string) $rule->claim_due_time)->format('g:i A');
        $offset = (int) $rule->claim_due_offset;

        return match ($kind) {
            'daily' => "Submit by {$time} ".(['on the day of the visit', 'the day after the visit', '2 days after the visit', '3 days after the visit'][$offset] ?? 'after the visit'),
            'weekly' => "Submit by {$time} on the next ".(self::WEEKDAYS[$offset] ?? '').' after the visit',
            default => $offset === 0
                ? "Submit by {$time} on the last day of the visit month"
                : "Submit by {$time} on day {$offset} of the month after the visit",
        };
    }
}

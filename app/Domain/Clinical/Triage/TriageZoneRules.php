<?php

namespace App\Domain\Clinical\Triage;

/**
 * TRI-1 section 5.1: fixed rules, no AI and no scoring. Any red flag ticked, or a reading at or past a threshold the
 * guideline marks red: red. Otherwise any yellow sign or a yellow threshold: yellow. Otherwise green. A blank reading
 * never counts as normal and never crosses a threshold. Numbers are compared in whole hundredths, never floats.
 */
final class TriageZoneRules
{
    public const ZONES = ['green' => 1, 'yellow' => 2, 'red' => 3];

    /**
     * @param  array<string, int|string|float|null>  $readings
     * @param  list<string>  $redFlags
     * @param  list<string>  $yellowSigns
     * @param  list<array{reading:string, age_band:string, direction:string, limit_value:string, zone:string}>  $thresholds
     * @return array{zone:string, highlights:array<string, string>, missing:list<string>}
     */
    public static function suggest(array $readings, array $redFlags, array $yellowSigns, string $ageBand, array $thresholds): array
    {
        $bands = $ageBand === 'unknown' ? ['adult', 'child'] : [$ageBand];
        $zone = 'green';
        $highlights = [];
        foreach ($thresholds as $threshold) {
            if (! in_array($threshold['age_band'], $bands, true)) {
                continue;
            }
            $value = $readings[$threshold['reading']] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $reading = self::hundredths($value);
            $limit = self::hundredths($threshold['limit_value']);
            $crossed = $threshold['direction'] === 'very_high' ? $reading >= $limit : $reading <= $limit;
            if (! $crossed) {
                continue;
            }
            $highlights[$threshold['reading']] = $threshold['direction'];
            $zone = self::higher($zone, $threshold['zone']);
        }
        if ($yellowSigns !== []) {
            $zone = self::higher($zone, 'yellow');
        }
        if ($redFlags !== []) {
            $zone = 'red';
        }
        $missing = array_values(array_filter(
            VisitTriageAssessment::READINGS,
            fn (string $field): bool => ($readings[$field] ?? null) === null || $readings[$field] === '',
        ));
        ksort($highlights);

        return ['zone' => $zone, 'highlights' => $highlights, 'missing' => $missing];
    }

    public static function rank(string $zone): int
    {
        return self::ZONES[$zone];
    }

    public static function higher(string $a, string $b): string
    {
        return self::rank($b) > self::rank($a) ? $b : $a;
    }

    /** "38", "38.0", 38.5 and "38.50" in whole hundredths; at most two decimals (validated before this point). */
    public static function hundredths(int|string|float $value): int
    {
        $text = is_float($value) ? number_format($value, 2, '.', '') : trim((string) $value);
        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $text, $m) !== 1) {
            throw new \InvalidArgumentException('Invalid triage reading.');
        }

        return ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }
}

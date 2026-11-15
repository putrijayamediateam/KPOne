<?php

namespace App\Domain\Organisation\Compliance\Services;

use Carbon\CarbonImmutable;

/**
 * HR-11 status words, worked out when a page opens and never stored (HR-5a rule; Daniel: the time is in the words).
 * Not a certification: it only restates the dates a person typed.
 */
final class ComplianceStatus
{
    /**
     * @param  object|null  $inForce  the version in force: expires_on
     * @return array{code: string, words: string, short: string}
     */
    public static function licence(bool $expires, ?int $warnFromDays, ?object $inForce, string $today): array
    {
        if ($inForce === null) {
            return ['code' => 'not_recorded', 'words' => 'Not recorded', 'short' => 'Not recorded'];
        }
        $expiresOn = self::day(data_get($inForce, 'expires_on'));
        if (! $expires || $expiresOn === null) {
            return ['code' => 'no_expiry', 'words' => 'No expiry', 'short' => 'No expiry'];
        }
        $days = (int) CarbonImmutable::parse($today)->diffInDays(CarbonImmutable::parse($expiresOn), false);
        if ($days < 0) {
            return ['code' => 'expired', 'words' => $days === -1 ? 'Expired yesterday' : 'Expired '.abs($days).' days ago', 'short' => 'Expired'];
        }
        if ($days === 0) {
            return ['code' => 'expiring', 'words' => 'Expires today', 'short' => 'Today'];
        }
        if ($days <= ($warnFromDays ?? 0)) {
            return ['code' => 'expiring', 'words' => $days === 1 ? 'Expires tomorrow' : "Expires in {$days} days", 'short' => $days === 1 ? '1 day' : "{$days} days"];
        }

        return ['code' => 'valid', 'words' => 'Valid', 'short' => 'Valid'];
    }

    public static function day(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }
}

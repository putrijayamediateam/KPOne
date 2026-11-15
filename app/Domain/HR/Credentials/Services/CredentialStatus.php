<?php

namespace App\Domain\HR\Credentials\Services;

use Carbon\CarbonImmutable;

/**
 * HR-5a status (HR-PLAN 12.3 rule 3), worked out when a page opens and never stored. The time is in the words
 * (Daniel: "Expires in 21 days", "Expired 3 days ago"); the page adds the date beside them.
 */
final class CredentialStatus
{
    /**
     * @param  object|null  $inForce  the accepted version: issued_on, expires_on, health_done
     * @return array{code: string, words: string, short: string}
     */
    public static function of(bool $healthItem, bool $expires, int $warnFromDays, ?object $inForce, string $today): array
    {
        if ($inForce === null) {
            return ['code' => 'not_recorded', 'words' => 'Not recorded', 'short' => 'Not recorded'];
        }
        if ($healthItem) {
            return data_get($inForce, 'health_done') ? ['code' => 'done', 'words' => 'Done', 'short' => 'Done'] : ['code' => 'not_done', 'words' => 'Not done', 'short' => 'Not done'];
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
        if ($days <= $warnFromDays) {
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

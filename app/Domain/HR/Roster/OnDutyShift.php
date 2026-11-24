<?php

namespace App\Domain\HR\Roster;

use Carbon\CarbonImmutable;

/**
 * One approved roster shift as other domains may see it (HR-22a): ids, role names and times only. No names, leave types,
 * reasons, notes or pay.
 */
final readonly class OnDutyShift
{
    /** @param list<string> $roles */
    public function __construct(
        public int $userId,
        public array $roles,
        public bool $isLocum,
        public bool $isDoctor,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public string $timezone,
        public ?int $roomId,
        public ?string $station,
    ) {}
}

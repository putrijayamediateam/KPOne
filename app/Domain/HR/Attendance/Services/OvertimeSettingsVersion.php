<?php

namespace App\Domain\HR\Attendance\Services;

/** One saved version of the clinic's overtime settings (HR-3b). Multipliers are kept as two-decimal strings. */
final readonly class OvertimeSettingsVersion
{
    /** @param list<string> $roles */
    public function __construct(
        public int $id,
        public string $effectiveFrom,
        public string $normalMultiplier,
        public string $restDayMultiplier,
        public string $publicHolidayMultiplier,
        public int $nearingHours,
        public int $limitHours,
        public array $roles,
        public int $createdByUserId,
    ) {}

    /** @param list<string> $roles */
    public static function fromRow(object $row, array $roles): self
    {
        return new self((int) data_get($row, 'id'), substr((string) data_get($row, 'effective_from'), 0, 10),
            number_format((float) data_get($row, 'normal_multiplier'), 2, '.', ''), number_format((float) data_get($row, 'rest_day_multiplier'), 2, '.', ''),
            number_format((float) data_get($row, 'public_holiday_multiplier'), 2, '.', ''), (int) data_get($row, 'nearing_hours'),
            (int) data_get($row, 'limit_hours'), $roles, (int) data_get($row, 'created_by_user_id'));
    }

    public function multiplier(string $dayType): string
    {
        return match ($dayType) {
            'public_holiday' => $this->publicHolidayMultiplier,
            'rest_day' => $this->restDayMultiplier,
            default => $this->normalMultiplier,
        };
    }
}

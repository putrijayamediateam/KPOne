<?php

namespace App\Domain\HR\Attendance\Services;

/** One saved version of the clinic's attendance settings. */
final readonly class AttendanceSettingsVersion
{
    public function __construct(
        public int $id,
        public string $effectiveFrom,
        public int $graceMinutes,
        public int $forgottenWindowMinutes,
        public int $correctionDays,
        public int $createdByUserId,
    ) {}

    public static function fromRow(object $row): self
    {
        return new self((int) data_get($row, 'id'), substr((string) data_get($row, 'effective_from'), 0, 10), (int) data_get($row, 'grace_minutes'),
            (int) data_get($row, 'forgotten_window_minutes'), (int) data_get($row, 'correction_days'), (int) data_get($row, 'created_by_user_id'));
    }
}

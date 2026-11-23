<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR-20 "On call" (owner, 18 Nov 2026): a roster shift or an open shift may be an on-call session. Only the session list
 * in the two CHECK constraints widens; every other rule is the same text as before. Rolling back is refused while any
 * on-call shift or open shift exists.
 */
return new class extends Migration
{
    private const ROSTER = <<<'SQL'
ALTER TABLE hr_roster_shifts ADD CONSTRAINT hr_roster_shifts_shape CHECK (
 ends_at > starts_at AND ends_at - starts_at <= interval '16 hours'
 AND break_minutes <= 180 AND break_minutes * interval '1 minute' < ends_at - starts_at
 AND session_type IN (%s)
 AND lock_version > 0 AND length(timezone) > 0
)
SQL;

    private const OPEN = "ALTER TABLE hr_open_shifts ADD CONSTRAINT hr_open_shifts_shape CHECK (
            ends_at > starts_at AND ends_at - starts_at <= interval '16 hours'
            AND break_minutes <= 180 AND break_minutes * interval '1 minute' < ends_at - starts_at
            AND session_type IN (%s)
            AND role_needed IN ('resident_doctor', 'ca', 'other') AND length(timezone) > 0
            AND status IN ('open', 'filled', 'withdrawn') AND lock_version >= 1
            AND ((status = 'open') = (closed_at IS NULL AND closed_by_user_id IS NULL)))";

    private const BEFORE = "'morning', 'afternoon', 'evening', 'night', 'full_day', 'other'";

    public function up(): void
    {
        $this->apply(self::BEFORE.", 'on_call'");
    }

    public function down(): void
    {
        if (DB::table('hr_roster_shifts')->where('session_type', 'on_call')->exists() || DB::table('hr_open_shifts')->where('session_type', 'on_call')->exists()) {
            throw new RuntimeException('Refusing to roll back HR-20: on-call shifts exist.');
        }
        $this->apply(self::BEFORE);
    }

    private function apply(string $sessions): void
    {
        DB::statement('ALTER TABLE hr_roster_shifts DROP CONSTRAINT hr_roster_shifts_shape');
        DB::statement(sprintf(self::ROSTER, $sessions));
        DB::statement('ALTER TABLE hr_open_shifts DROP CONSTRAINT hr_open_shifts_shape');
        DB::statement(sprintf(self::OPEN, $sessions));
    }
};

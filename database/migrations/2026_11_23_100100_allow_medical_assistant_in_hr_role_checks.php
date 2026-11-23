<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MA-1a (owner decision 23 Nov 2026, MA-ROLE-DESIGN sections 3.4 and 7): the Medical Assistant can be chosen as a memo,
 * SOP or credential-type audience and as the role an open shift needs, like any other human role. The owner also asked
 * (23 Nov 2026) to add the three ROLE-1 roles (Branch Manager, Account Assistant, Store Admin) to the three audience
 * checks, which ROLE-1 had left out although they are in RosterPermissions::HUMAN_ROLES. Every other value is unchanged.
 * `hr_open_shifts.role_needed` was varchar(16), one character short of 'medical_assistant', so it becomes varchar(32)
 * (Imran's review); `down()` leaves it wide, which is harmless. It sits after the HR tables (2026-11-10 and 2026-11-13),
 * which is why it cannot use the Patients & Registration range. `down()` refuses while a row uses one of the added roles.
 */
return new class extends Migration
{
    private const ROLES = "'director', 'resident_doctor', 'ca', 'ca_supervisor', 'panel_officer', 'finance_officer', 'administrator', 'business_development', 'marketing', 'hr_manager', 'technical_admin'";

    /** @var array<string, string> table => constraint */
    private const AUDIENCES = [
        'hr_memo_roles' => 'hr_memo_roles_role',
        'hr_sop_audiences' => 'hr_sop_audiences_role',
        'hr_credential_type_roles' => 'hr_credential_type_roles_role',
    ];

    private const OPEN = "ALTER TABLE hr_open_shifts ADD CONSTRAINT hr_open_shifts_shape CHECK (
            ends_at > starts_at AND ends_at - starts_at <= interval '16 hours'
            AND break_minutes <= 180 AND break_minutes * interval '1 minute' < ends_at - starts_at
            AND session_type IN ('morning', 'afternoon', 'evening', 'night', 'full_day', 'other', 'on_call')
            AND role_needed IN (%s) AND length(timezone) > 0
            AND status IN ('open', 'filled', 'withdrawn') AND lock_version >= 1
            AND ((status = 'open') = (closed_at IS NULL AND closed_by_user_id IS NULL)))";

    /** @var list<string> */
    private const ADDED = ['branch_manager', 'account_assistant', 'store_admin', 'medical_assistant'];

    public function up(): void
    {
        DB::statement('ALTER TABLE hr_open_shifts ALTER COLUMN role_needed TYPE varchar(32)');
        $this->apply(self::ROLES.", 'branch_manager', 'account_assistant', 'store_admin', 'medical_assistant'", "'resident_doctor', 'ca', 'medical_assistant', 'other'");
    }

    public function down(): void
    {
        foreach (array_keys(self::AUDIENCES) as $table) {
            if (DB::table($table)->whereIn('role', self::ADDED)->exists()) {
                throw new RuntimeException("Refusing to roll back MA-1a: {$table} names a role this migration added.");
            }
        }
        if (DB::table('hr_open_shifts')->where('role_needed', 'medical_assistant')->exists()) {
            throw new RuntimeException('Refusing to roll back MA-1a: an open shift needs a Medical Assistant.');
        }
        $this->apply(self::ROLES, "'resident_doctor', 'ca', 'other'");
    }

    private function apply(string $audienceRoles, string $openRoles): void
    {
        foreach (self::AUDIENCES as $table => $constraint) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK (role IN ({$audienceRoles}))");
        }
        DB::statement('ALTER TABLE hr_open_shifts DROP CONSTRAINT hr_open_shifts_shape');
        DB::statement(sprintf(self::OPEN, $openRoles));
    }
};

<?php

use App\Domain\HR\Payroll\PayProfileMtd;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-7e: a pay profile's MTD details (tax category, children for relief, and whether the staff member or spouse is
 * disabled) were stored in plain columns. The disability flags are health data, so the four columns become one
 * encrypted value (owner decision via B.E.R.N.I.S.S, 2026-11-10, PDPA pack decision 13). Existing rows are encrypted
 * here, under this migration's own lock, then the plain columns are dropped. The profile still never changes after it
 * is entered: the new value is guarded the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_pay_profiles', function (Blueprint $t): void {
            $t->text('mtd_details_encrypted')->nullable();
        });

        // Encrypt what exists. The profile guards refuse any update, so only this migration bypasses them, briefly.
        DB::statement('LOCK TABLE hr_pay_profiles IN SHARE ROW EXCLUSIVE MODE');
        DB::statement('ALTER TABLE hr_pay_profiles DISABLE TRIGGER USER');
        DB::table('hr_pay_profiles')->whereNotNull('mtd_category')->orderBy('id')
            ->get(['id', 'mtd_category', 'mtd_children', 'mtd_disabled_self', 'mtd_disabled_spouse'])
            ->each(fn (stdClass $p) => DB::table('hr_pay_profiles')->where('id', $p->id)->update(['mtd_details_encrypted' => PayProfileMtd::encrypt([
                'category' => (int) $p->mtd_category, 'children' => (int) $p->mtd_children,
                'disabled_self' => (bool) $p->mtd_disabled_self, 'disabled_spouse' => (bool) $p->mtd_disabled_spouse])]));
        DB::statement('ALTER TABLE hr_pay_profiles ENABLE TRIGGER USER');

        DB::unprepared(<<<'SQL'
DROP TRIGGER hr_pay_profiles_mtd_guard ON hr_pay_profiles;
DROP FUNCTION guard_hr_pay_profile_mtd();
ALTER TABLE hr_pay_profiles DROP CONSTRAINT hr_pay_profiles_mtd_shape;
ALTER TABLE hr_pay_profiles DROP COLUMN mtd_category, DROP COLUMN mtd_children, DROP COLUMN mtd_disabled_self, DROP COLUMN mtd_disabled_spouse;
ALTER TABLE hr_pay_profiles ADD CONSTRAINT hr_pay_profiles_mtd_shape CHECK (mtd_details_encrypted IS NULL OR mtd);

-- A pay profile's MTD details never change after it is entered, as the rest of the profile (HR-7a, HR-7d).
CREATE OR REPLACE FUNCTION guard_hr_pay_profile_mtd() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.mtd_details_encrypted IS DISTINCT FROM OLD.mtd_details_encrypted THEN
  RAISE EXCEPTION 'A pay profile never changes after it is entered; enter a new one' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_pay_profiles_mtd_guard BEFORE UPDATE ON hr_pay_profiles FOR EACH ROW EXECUTE FUNCTION guard_hr_pay_profile_mtd();
SQL);
    }

    public function down(): void {} // The plain values are not restored: health data stays encrypted.
};

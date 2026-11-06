<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-7a payroll setup (HR-PLAN 14.1, 14.2 and 14.4). Pay groups, pay item types, rate settings, clinic-entered
 * statutory banded tables and each person's pay profile. Nothing is calculated yet: runs are HR-7b. KPOne ships no
 * statutory number, divisor or pay item. A statutory table counts only once someone other than the person who entered
 * it checks it, and a pay profile only once someone other than the person who entered it, and other than the worker,
 * confirms it. A checked table, a confirmed profile and every version never change; a correction is a new one. Pay
 * amounts are encrypted. Nothing is deleted or truncated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_pay_groups', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->string('name', 60);
            $t->unsignedSmallInteger('pay_day')->nullable(); // day of the month; null: not set
            $t->boolean('is_active');
            $t->unsignedBigInteger('created_by_user_id');
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign('organisation_id', 'hr_pay_groups_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign(['created_by_user_id', 'organisation_id'], 'hr_pay_groups_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['id', 'organisation_id'], 'hr_pay_groups_scope_unique');
        });

        Schema::create('hr_pay_item_types', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedInteger('current_version');
            $t->unsignedBigInteger('created_by_user_id');
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign('organisation_id', 'hr_pay_item_types_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign(['created_by_user_id', 'organisation_id'], 'hr_pay_item_types_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['id', 'organisation_id'], 'hr_pay_item_types_scope_unique');
        });

        Schema::create('hr_pay_item_type_versions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('item_type_id');
            $t->unsignedInteger('version');
            $t->string('name', 60);
            $t->string('kind', 10); // earnings | deduction | refund
            $t->boolean('counts_epf');
            $t->boolean('counts_socso');
            $t->boolean('counts_eis');
            $t->boolean('counts_mtd');
            $t->boolean('counts_hrd');
            $t->boolean('is_active');
            $t->unsignedBigInteger('set_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign(['item_type_id', 'organisation_id'], 'hr_pay_item_type_versions_type_fk')->references(['id', 'organisation_id'])->on('hr_pay_item_types')->restrictOnDelete();
            $t->foreign(['set_by_user_id', 'organisation_id'], 'hr_pay_item_type_versions_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['item_type_id', 'version'], 'hr_pay_item_type_versions_once');
        });

        Schema::create('hr_rate_settings', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedInteger('version');
            $t->unsignedInteger('daily_divisor_hundredths'); // basic ÷ this = a day's rate (2600 = 26 days)
            $t->unsignedInteger('hourly_divisor_hundredths'); // a day's rate ÷ this = an hour's rate (800 = 8 hours)
            $t->unsignedBigInteger('set_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign('organisation_id', 'hr_rate_settings_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign(['set_by_user_id', 'organisation_id'], 'hr_rate_settings_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['organisation_id', 'version'], 'hr_rate_settings_once');
        });

        Schema::create('hr_statutory_tables', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->string('scheme', 6); // epf | socso | eis | hrd
            $t->string('category', 60);
            $t->date('effective_from');
            $t->string('rounding', 12); // none | nearest_sen | up_ringgit
            $t->string('source_note', 200);
            $t->string('status', 10)->default('entered'); // entered | checked | rejected | withdrawn | replaced
            $t->unsignedBigInteger('entered_by_user_id');
            $t->unsignedBigInteger('decided_by_user_id')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->string('decision_reason', 16)->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign('organisation_id', 'hr_statutory_tables_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            foreach (['entered_by_user_id', 'decided_by_user_id'] as $column) {
                $t->foreign([$column, 'organisation_id'], "hr_statutory_tables_{$column}_fk")->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
            $t->unique(['id', 'organisation_id'], 'hr_statutory_tables_scope_unique');
            $t->index(['organisation_id', 'scheme', 'status'], 'hr_statutory_tables_scheme');
        });

        Schema::create('hr_statutory_table_rows', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('table_id');
            $t->unsignedSmallInteger('position');
            $t->unsignedBigInteger('wage_from_sen');
            $t->unsignedBigInteger('wage_to_sen')->nullable(); // null: and above
            $t->string('employee_kind', 5); // fixed (sen) | rate (basis points)
            $t->unsignedBigInteger('employee_value');
            $t->string('employer_kind', 5);
            $t->unsignedBigInteger('employer_value');
            $t->foreign(['table_id', 'organisation_id'], 'hr_statutory_table_rows_table_fk')->references(['id', 'organisation_id'])->on('hr_statutory_tables')->restrictOnDelete();
            $t->unique(['table_id', 'position'], 'hr_statutory_table_rows_position');
        });

        Schema::create('hr_pay_profiles', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('worker_id');
            $t->unsignedBigInteger('pay_group_id');
            $t->string('pay_type', 8); // monthly | hourly
            $t->text('rate_encrypted'); // basic monthly salary or hourly rate, in sen
            $t->boolean('epf');
            $t->string('epf_category', 60)->nullable();
            $t->boolean('socso');
            $t->string('socso_category', 60)->nullable();
            $t->boolean('eis');
            $t->boolean('mtd');
            $t->boolean('hrd');
            $t->date('effective_from');
            $t->string('status', 10)->default('waiting'); // waiting | confirmed | rejected | withdrawn | replaced
            $t->unsignedBigInteger('entered_by_user_id');
            $t->unsignedBigInteger('decided_by_user_id')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->string('decision_reason', 16)->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign(['worker_id', 'organisation_id'], 'hr_pay_profiles_worker_fk')->references(['id', 'organisation_id'])->on('hr_workers')->restrictOnDelete();
            $t->foreign(['pay_group_id', 'organisation_id'], 'hr_pay_profiles_group_fk')->references(['id', 'organisation_id'])->on('hr_pay_groups')->restrictOnDelete();
            foreach (['entered_by_user_id', 'decided_by_user_id'] as $column) {
                $t->foreign([$column, 'organisation_id'], "hr_pay_profiles_{$column}_fk")->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
            $t->unique(['id', 'organisation_id'], 'hr_pay_profiles_scope_unique');
            $t->index(['worker_id', 'status', 'effective_from'], 'hr_pay_profiles_worker');
        });

        Schema::create('hr_pay_profile_items', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('profile_id');
            $t->unsignedSmallInteger('position');
            $t->unsignedBigInteger('item_type_id');
            $t->unsignedInteger('item_type_version');
            $t->text('amount_encrypted');
            $t->foreign(['profile_id', 'organisation_id'], 'hr_pay_profile_items_profile_fk')->references(['id', 'organisation_id'])->on('hr_pay_profiles')->restrictOnDelete();
            $t->foreign(['item_type_id', 'organisation_id'], 'hr_pay_profile_items_type_fk')->references(['id', 'organisation_id'])->on('hr_pay_item_types')->restrictOnDelete();
            $t->unique(['profile_id', 'position'], 'hr_pay_profile_items_position');
        });

        DB::statement('ALTER TABLE hr_pay_groups ADD CONSTRAINT hr_pay_groups_shape CHECK (char_length(btrim(name)) BETWEEN 2 AND 60 AND (pay_day IS NULL OR pay_day BETWEEN 1 AND 31))');
        DB::statement('CREATE UNIQUE INDEX hr_pay_groups_name_unique ON hr_pay_groups (organisation_id, lower(name))');
        DB::statement('ALTER TABLE hr_pay_item_types ADD CONSTRAINT hr_pay_item_types_shape CHECK (current_version >= 1)');
        DB::statement("ALTER TABLE hr_pay_item_type_versions ADD CONSTRAINT hr_pay_item_type_versions_shape CHECK (version >= 1 AND char_length(btrim(name)) BETWEEN 2 AND 60 AND kind IN ('earnings', 'deduction', 'refund'))");
        DB::statement('ALTER TABLE hr_rate_settings ADD CONSTRAINT hr_rate_settings_shape CHECK (version >= 1 AND daily_divisor_hundredths BETWEEN 100 AND 3100 AND hourly_divisor_hundredths BETWEEN 100 AND 2400)');
        DB::statement(<<<'SQL'
ALTER TABLE hr_statutory_tables ADD CONSTRAINT hr_statutory_tables_shape CHECK (
 scheme IN ('epf', 'socso', 'eis', 'hrd')
 AND char_length(btrim(category)) BETWEEN 2 AND 60
 AND char_length(btrim(source_note)) BETWEEN 2 AND 200
 AND rounding IN ('none', 'nearest_sen', 'up_ringgit')
 AND status IN ('entered', 'checked', 'rejected', 'withdrawn', 'replaced')
 AND (decided_by_user_id IS NULL) = (decided_at IS NULL)
 AND (status IN ('entered', 'withdrawn')) = (decided_by_user_id IS NULL)
 AND (status = 'rejected') = (decision_reason IS NOT NULL)
 AND (decision_reason IS NULL OR decision_reason IN ('wrong_figures', 'wrong_category', 'wrong_date', 'other'))
 AND (decided_by_user_id IS NULL OR decided_by_user_id <> entered_by_user_id)
)
SQL);
        DB::statement("CREATE UNIQUE INDEX hr_statutory_tables_checked_once ON hr_statutory_tables (organisation_id, scheme, lower(category), effective_from) WHERE status = 'checked'");
        DB::statement(<<<'SQL'
ALTER TABLE hr_statutory_table_rows ADD CONSTRAINT hr_statutory_table_rows_shape CHECK (
 position BETWEEN 1 AND 200
 AND wage_from_sen <= 99999999 AND (wage_to_sen IS NULL OR (wage_to_sen >= wage_from_sen AND wage_to_sen <= 99999999))
 AND employee_kind IN ('fixed', 'rate') AND employer_kind IN ('fixed', 'rate')
 AND employee_value <= CASE employee_kind WHEN 'rate' THEN 10000 ELSE 99999999 END
 AND employer_value <= CASE employer_kind WHEN 'rate' THEN 10000 ELSE 99999999 END
)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE hr_pay_profiles ADD CONSTRAINT hr_pay_profiles_shape CHECK (
 pay_type IN ('monthly', 'hourly')
 AND status IN ('waiting', 'confirmed', 'rejected', 'withdrawn', 'replaced')
 AND (epf = (epf_category IS NOT NULL)) AND (epf_category IS NULL OR char_length(btrim(epf_category)) BETWEEN 2 AND 60)
 AND (socso = (socso_category IS NOT NULL)) AND (socso_category IS NULL OR char_length(btrim(socso_category)) BETWEEN 2 AND 60)
 AND (decided_by_user_id IS NULL) = (decided_at IS NULL)
 AND (status IN ('waiting', 'withdrawn')) = (decided_by_user_id IS NULL)
 AND (status = 'rejected') = (decision_reason IS NOT NULL)
 AND (decision_reason IS NULL OR decision_reason IN ('wrong_amount', 'wrong_person', 'wrong_schemes', 'wrong_date', 'other'))
 AND (decided_by_user_id IS NULL OR decided_by_user_id <> entered_by_user_id)
)
SQL);
        DB::statement("CREATE UNIQUE INDEX hr_pay_profiles_one_waiting ON hr_pay_profiles (worker_id) WHERE status = 'waiting'");
        DB::statement("CREATE UNIQUE INDEX hr_pay_profiles_confirmed_once ON hr_pay_profiles (worker_id, effective_from) WHERE status = 'confirmed'");
        DB::statement('ALTER TABLE hr_pay_profile_items ADD CONSTRAINT hr_pay_profile_items_shape CHECK (position BETWEEN 1 AND 20 AND item_type_version >= 1)');

        DB::unprepared(<<<'SQL'
CREATE TRIGGER hr_pay_item_type_versions_append BEFORE UPDATE OR DELETE ON hr_pay_item_type_versions FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_rate_settings_append BEFORE UPDATE OR DELETE ON hr_rate_settings FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_statutory_table_rows_append BEFORE UPDATE OR DELETE ON hr_statutory_table_rows FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_pay_profile_items_append BEFORE UPDATE OR DELETE ON hr_pay_profile_items FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_pay_groups_no_delete BEFORE DELETE ON hr_pay_groups FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_pay_item_types_no_delete BEFORE DELETE ON hr_pay_item_types FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_statutory_tables_no_delete BEFORE DELETE ON hr_statutory_tables FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_pay_profiles_no_delete BEFORE DELETE ON hr_pay_profiles FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_pay_groups_truncate BEFORE TRUNCATE ON hr_pay_groups FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_pay_item_types_truncate BEFORE TRUNCATE ON hr_pay_item_types FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_pay_item_type_versions_truncate BEFORE TRUNCATE ON hr_pay_item_type_versions FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_rate_settings_truncate BEFORE TRUNCATE ON hr_rate_settings FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_statutory_tables_truncate BEFORE TRUNCATE ON hr_statutory_tables FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_statutory_table_rows_truncate BEFORE TRUNCATE ON hr_statutory_table_rows FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_pay_profiles_truncate BEFORE TRUNCATE ON hr_pay_profiles FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_pay_profile_items_truncate BEFORE TRUNCATE ON hr_pay_profile_items FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();

-- A pay group keeps its identity; its name, pay day and active flag change under lock_version.
CREATE OR REPLACE FUNCTION guard_hr_pay_group() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id
   OR NEW.created_by_user_id <> OLD.created_by_user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
  RAISE EXCEPTION 'A pay group keeps who made it' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_pay_groups_guard BEFORE UPDATE ON hr_pay_groups FOR EACH ROW EXECUTE FUNCTION guard_hr_pay_group();

-- Pay item type and rate setting versions are numbered without gaps; an item type moves only to its next version.
CREATE OR REPLACE FUNCTION guard_hr_pay_item_type_version() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE top integer;
BEGIN
 SELECT COALESCE(MAX(version), 0) INTO top FROM hr_pay_item_type_versions WHERE item_type_id = NEW.item_type_id;
 IF NEW.version <> top + 1 THEN RAISE EXCEPTION 'Pay item type versions are numbered without gaps' USING ERRCODE = '23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_pay_item_type_versions_gapless BEFORE INSERT ON hr_pay_item_type_versions FOR EACH ROW EXECUTE FUNCTION guard_hr_pay_item_type_version();

CREATE OR REPLACE FUNCTION guard_hr_pay_item_type() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id
   OR NEW.created_by_user_id <> OLD.created_by_user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
  RAISE EXCEPTION 'A pay item type keeps who made it' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.current_version <> OLD.current_version AND (NEW.current_version <> OLD.current_version + 1
   OR NOT EXISTS (SELECT 1 FROM hr_pay_item_type_versions WHERE item_type_id = NEW.id AND version = NEW.current_version)) THEN
  RAISE EXCEPTION 'A pay item type moves to its next saved version only' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_pay_item_types_guard BEFORE UPDATE ON hr_pay_item_types FOR EACH ROW EXECUTE FUNCTION guard_hr_pay_item_type();

CREATE OR REPLACE FUNCTION guard_hr_rate_settings_version() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE top integer;
BEGIN
 SELECT COALESCE(MAX(version), 0) INTO top FROM hr_rate_settings WHERE organisation_id = NEW.organisation_id;
 IF NEW.version <> top + 1 THEN RAISE EXCEPTION 'Rate settings versions are numbered without gaps' USING ERRCODE = '23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_rate_settings_gapless BEFORE INSERT ON hr_rate_settings FOR EACH ROW EXECUTE FUNCTION guard_hr_rate_settings_version();

-- A statutory table is entered, then checked, rejected or withdrawn; a checked table is only ever replaced. What was
-- entered never changes, and the checker is never the person who entered it.
CREATE OR REPLACE FUNCTION guard_hr_statutory_table() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'INSERT' THEN
  IF NEW.status <> 'entered' THEN RAISE EXCEPTION 'A statutory table is entered first, then checked' USING ERRCODE = '23514'; END IF;
  RETURN NEW;
 END IF;
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.scheme <> OLD.scheme
   OR NEW.category <> OLD.category OR NEW.effective_from <> OLD.effective_from OR NEW.rounding <> OLD.rounding OR NEW.source_note <> OLD.source_note
   OR NEW.entered_by_user_id <> OLD.entered_by_user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
  RAISE EXCEPTION 'A statutory table never changes after it is entered; enter a new one' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.status <> OLD.status AND NOT ((OLD.status = 'entered' AND NEW.status IN ('checked', 'rejected', 'withdrawn'))
   OR (OLD.status = 'checked' AND NEW.status = 'replaced')) THEN
  RAISE EXCEPTION 'A statutory table moves entered to checked, rejected or withdrawn, and checked to replaced' USING ERRCODE = 'P0001';
 END IF;
 IF OLD.status IN ('checked', 'rejected', 'replaced') AND (NEW.decided_by_user_id IS DISTINCT FROM OLD.decided_by_user_id
   OR NEW.decided_at IS DISTINCT FROM OLD.decided_at OR NEW.decision_reason IS DISTINCT FROM OLD.decision_reason) THEN
  RAISE EXCEPTION 'A checked statutory table keeps who checked it' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_statutory_tables_guard BEFORE INSERT OR UPDATE ON hr_statutory_tables FOR EACH ROW EXECUTE FUNCTION guard_hr_statutory_table();

-- Bands are written only with their table, while it is entered.
CREATE OR REPLACE FUNCTION guard_hr_statutory_table_row() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM hr_statutory_tables WHERE id = NEW.table_id AND status = 'entered') THEN
  RAISE EXCEPTION 'Bands are written only with their table, before it is checked' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_statutory_table_rows_guard BEFORE INSERT ON hr_statutory_table_rows FOR EACH ROW EXECUTE FUNCTION guard_hr_statutory_table_row();

-- A pay profile is entered for a staff member (never a locum) as waiting; it is confirmed by someone other than the
-- person who entered it and other than the worker, rejected or withdrawn; a confirmed profile is only ever replaced.
CREATE OR REPLACE FUNCTION guard_hr_pay_profile() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE w RECORD;
BEGIN
 SELECT kind, user_id INTO w FROM hr_workers WHERE id = NEW.worker_id;
 IF TG_OP = 'INSERT' THEN
  IF NEW.status <> 'waiting' THEN RAISE EXCEPTION 'A pay profile is entered as waiting for confirmation' USING ERRCODE = '23514'; END IF;
  IF w.kind <> 'staff' THEN RAISE EXCEPTION 'Locums are paid outside KPOne in this version' USING ERRCODE = '23514'; END IF;
  IF NEW.entered_by_user_id = w.user_id THEN RAISE EXCEPTION 'Nobody enters their own pay profile' USING ERRCODE = '23514'; END IF;
  RETURN NEW;
 END IF;
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.worker_id <> OLD.worker_id
   OR NEW.pay_group_id <> OLD.pay_group_id OR NEW.pay_type <> OLD.pay_type OR NEW.rate_encrypted <> OLD.rate_encrypted
   OR NEW.epf <> OLD.epf OR NEW.epf_category IS DISTINCT FROM OLD.epf_category OR NEW.socso <> OLD.socso
   OR NEW.socso_category IS DISTINCT FROM OLD.socso_category OR NEW.eis <> OLD.eis OR NEW.mtd <> OLD.mtd OR NEW.hrd <> OLD.hrd
   OR NEW.effective_from <> OLD.effective_from OR NEW.entered_by_user_id <> OLD.entered_by_user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
  RAISE EXCEPTION 'A pay profile never changes after it is entered; enter a new one' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.status <> OLD.status AND NOT ((OLD.status = 'waiting' AND NEW.status IN ('confirmed', 'rejected', 'withdrawn'))
   OR (OLD.status = 'confirmed' AND NEW.status = 'replaced')) THEN
  RAISE EXCEPTION 'A pay profile moves waiting to confirmed, rejected or withdrawn, and confirmed to replaced' USING ERRCODE = 'P0001';
 END IF;
 IF OLD.status IN ('confirmed', 'rejected', 'replaced') AND (NEW.decided_by_user_id IS DISTINCT FROM OLD.decided_by_user_id
   OR NEW.decided_at IS DISTINCT FROM OLD.decided_at OR NEW.decision_reason IS DISTINCT FROM OLD.decision_reason) THEN
  RAISE EXCEPTION 'A confirmed pay profile keeps who confirmed it' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.decided_by_user_id IS NOT NULL AND NEW.decided_by_user_id = w.user_id THEN
  RAISE EXCEPTION 'Nobody confirms their own pay profile' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_pay_profiles_guard BEFORE INSERT OR UPDATE ON hr_pay_profiles FOR EACH ROW EXECUTE FUNCTION guard_hr_pay_profile();

-- Allowances are written only with their profile, while it waits, using an existing version of an earnings type.
CREATE OR REPLACE FUNCTION guard_hr_pay_profile_item() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM hr_pay_profiles WHERE id = NEW.profile_id AND status = 'waiting') THEN
  RAISE EXCEPTION 'Allowances are written only with their pay profile, before it is confirmed' USING ERRCODE = '23514';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM hr_pay_item_type_versions WHERE item_type_id = NEW.item_type_id AND version = NEW.item_type_version AND kind = 'earnings') THEN
  RAISE EXCEPTION 'A fixed allowance uses a saved version of an earnings pay item type' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_pay_profile_items_guard BEFORE INSERT ON hr_pay_profile_items FOR EACH ROW EXECUTE FUNCTION guard_hr_pay_profile_item();
SQL);
    }

    public function down(): void
    {
        // HR payroll evidence is retained, following the evidence convention.
    }
};

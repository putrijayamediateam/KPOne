<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR-21 foreign worker permits (owner, 18 Nov 2026). No new table: the clinic adds its own credential types (for
 * example a work permit, a passport or a FOMEMA medical) through HR-5a, and marks the work permit type with this flag.
 * The flag is fixed when the type is added, as its expiry and health settings are; a work permit always expires and is
 * never a health item. The clinic sets the warning days per type; KPOne ships no number.
 */
return new class extends Migration
{
    private const GUARD = <<<'SQL'
CREATE OR REPLACE FUNCTION guard_hr_credential_type() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.code <> OLD.code
   OR NEW.expires <> OLD.expires OR NEW.health_item <> OLD.health_item OR NEW.created_by_user_id <> OLD.created_by_user_id
   OR NEW.created_at IS DISTINCT FROM OLD.created_at%s THEN
  RAISE EXCEPTION 'A credential type keeps its code and settings; archive it and add a new one' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.current_version < OLD.current_version THEN
  RAISE EXCEPTION 'A credential type version never goes back' USING ERRCODE = 'P0001';
 END IF;
 IF NOT OLD.is_active AND NEW.is_active THEN
  RAISE EXCEPTION 'An archived credential type stays archived' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
SQL;

    public function up(): void
    {
        DB::statement('ALTER TABLE hr_credential_types ADD COLUMN work_permit boolean NOT NULL DEFAULT false');
        DB::statement('ALTER TABLE hr_credential_types ADD CONSTRAINT hr_credential_types_work_permit CHECK (NOT work_permit OR (expires AND NOT health_item))');
        DB::unprepared(sprintf(self::GUARD, ' OR NEW.work_permit <> OLD.work_permit'));
    }

    public function down(): void
    {
        if (DB::table('hr_credential_types')->where('work_permit', true)->exists()) {
            throw new RuntimeException('Work permit types exist; this migration cannot be rolled back without losing the flag.');
        }
        DB::unprepared(sprintf(self::GUARD, ''));
        DB::statement('ALTER TABLE hr_credential_types DROP CONSTRAINT hr_credential_types_work_permit');
        DB::statement('ALTER TABLE hr_credential_types DROP COLUMN work_permit');
    }
};

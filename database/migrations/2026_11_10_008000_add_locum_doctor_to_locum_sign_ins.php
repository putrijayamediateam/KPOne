<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-22b (HR-PLAN 29.3; the owner's role decision under AGENTS.md, 24 Nov 2026): a locum doctor's account holds both
 * `resident_doctor` and `locum`. HR asks for that on the sign-in request (`role_requested` = `locum_doctor`); the account
 * administrator creates it through StaffProvisioningService as before. HR still creates no account and changes no role.
 *
 * The link guard is rewritten: a plain locum sign-in links an account holding `locum` only (unchanged); a locum doctor
 * sign-in links an account holding exactly `locum` and `resident_doctor`. What was asked never changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_locum_sign_ins', function (Blueprint $t): void {
            $t->string('role_requested', 16)->default('locum');
        });
        DB::statement("ALTER TABLE hr_locum_sign_ins ADD CONSTRAINT hr_locum_sign_ins_role_requested CHECK (role_requested IN ('locum', 'locum_doctor'))");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_hr_locum_sign_in() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
 allowed text[];
BEGIN
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.worker_id <> OLD.worker_id
   OR NEW.email_encrypted <> OLD.email_encrypted OR NEW.requested_by_user_id <> OLD.requested_by_user_id OR NEW.requested_at <> OLD.requested_at
   OR NEW.role_requested <> OLD.role_requested
   OR NEW.created_at IS DISTINCT FROM OLD.created_at OR NEW.lock_version <> OLD.lock_version + 1
   OR OLD.status <> 'open' OR NEW.status = 'open' THEN
  RAISE EXCEPTION 'A locum sign-in request keeps what was asked and is closed once' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.status = 'done' AND (NOT hr_user_holds(NEW.closed_by_user_id, 'staff.manage.organisation') OR NOT hr_user_holds(NEW.closed_by_user_id, 'access.manage.organisation')) THEN
  RAISE EXCEPTION 'Only someone who may create staff accounts links a locum sign-in' USING ERRCODE = '23514';
 END IF;
 IF NEW.status = 'done' THEN
  allowed := CASE WHEN NEW.role_requested = 'locum_doctor' THEN ARRAY['locum', 'resident_doctor'] ELSE ARRAY['locum'] END;
  IF NOT EXISTS (SELECT 1 FROM users u WHERE u.id = NEW.linked_user_id AND u.is_active)
    OR EXISTS (SELECT 1 FROM unnest(allowed) AS a(name) WHERE NOT EXISTS (SELECT 1 FROM model_has_roles mr JOIN roles r ON r.id = mr.role_id
      WHERE mr.model_id = NEW.linked_user_id AND mr.model_type = 'App\Models\User' AND r.name = a.name))
    OR EXISTS (SELECT 1 FROM model_has_roles mr JOIN roles r ON r.id = mr.role_id
      WHERE mr.model_id = NEW.linked_user_id AND mr.model_type = 'App\Models\User' AND r.name <> ALL (allowed))
    OR EXISTS (SELECT 1 FROM hr_workers w WHERE w.user_id = NEW.linked_user_id) THEN
   RAISE EXCEPTION 'A locum sign-in is an active account holding exactly the roles asked for, with no staff file of its own' USING ERRCODE = '23514';
  END IF;
 END IF;
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void {} // HR record evidence is retained, following the evidence convention.
};

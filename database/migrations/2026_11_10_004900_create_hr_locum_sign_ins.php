<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-15a locum sign-ins (HR-PLAN 21.3). HR asks for a sign-in for a locum staff file, with the email the locum will use;
 * the account administrator (staff.manage.organisation and access.manage.organisation, as StaffProvisioningService)
 * creates the account the usual way with the `locum` role, then links it to the file and closes the request. HR creates
 * no account. A link is set once and never removed; a locum account never also gets a staff file of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_locum_sign_ins', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('worker_id');
            $t->text('email_encrypted');
            $t->string('status', 10); // open | done | cancelled
            $t->unsignedBigInteger('requested_by_user_id');
            $t->timestampTz('requested_at');
            $t->unsignedBigInteger('closed_by_user_id')->nullable();
            $t->timestampTz('closed_at')->nullable();
            $t->unsignedBigInteger('linked_user_id')->nullable(); // set when done: the account that signs in for this locum
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign(['worker_id', 'organisation_id'], 'hr_locum_sign_ins_worker_fk')->references(['id', 'organisation_id'])->on('hr_workers')->restrictOnDelete();
            foreach (['requested_by_user_id', 'closed_by_user_id', 'linked_user_id'] as $column) {
                $t->foreign([$column, 'organisation_id'], "hr_locum_sign_ins_{$column}_fk")->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
            $t->index(['organisation_id', 'status'], 'hr_locum_sign_ins_open');
        });
        DB::statement("ALTER TABLE hr_locum_sign_ins ADD CONSTRAINT hr_locum_sign_ins_shape CHECK (
            status IN ('open', 'done', 'cancelled') AND lock_version >= 1
            AND ((status = 'open') = (closed_at IS NULL AND closed_by_user_id IS NULL))
            AND ((status = 'done') = (linked_user_id IS NOT NULL))
            AND (linked_user_id IS NULL OR linked_user_id <> closed_by_user_id))");
        // One open request per locum file, one link per file and one file per account.
        DB::statement("CREATE UNIQUE INDEX hr_locum_sign_ins_one_open ON hr_locum_sign_ins (worker_id) WHERE status = 'open'");
        DB::statement("CREATE UNIQUE INDEX hr_locum_sign_ins_one_link ON hr_locum_sign_ins (worker_id) WHERE status = 'done'");
        DB::statement("CREATE UNIQUE INDEX hr_locum_sign_ins_one_account ON hr_locum_sign_ins (linked_user_id) WHERE status = 'done'");

        DB::unprepared(<<<'SQL'
-- Whether an account holds a permission, directly or through a role.
CREATE OR REPLACE FUNCTION hr_user_holds(actor bigint, permission text) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS (SELECT 1 FROM model_has_permissions mp JOIN permissions p ON p.id = mp.permission_id
     WHERE mp.model_id = actor AND mp.model_type = 'App\Models\User' AND p.name = permission)
   OR EXISTS (SELECT 1 FROM model_has_roles mr JOIN role_has_permissions rp ON rp.role_id = mr.role_id JOIN permissions p ON p.id = rp.permission_id
     WHERE mr.model_id = actor AND mr.model_type = 'App\Models\User' AND p.name = permission)
$$;

CREATE OR REPLACE FUNCTION guard_hr_locum_sign_in_insert() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.status <> 'open' OR NEW.lock_version <> 1 OR NOT EXISTS (SELECT 1 FROM hr_workers w
     WHERE w.id = NEW.worker_id AND w.organisation_id = NEW.organisation_id AND w.kind = 'locum') THEN
  RAISE EXCEPTION 'A sign-in is asked for a locum file, as an open request' USING ERRCODE = 'P0001';
 END IF;
 IF EXISTS (SELECT 1 FROM hr_locum_sign_ins s WHERE s.worker_id = NEW.worker_id AND s.status = 'done') THEN
  RAISE EXCEPTION 'This locum already has a sign-in' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_locum_sign_ins_insert_guard BEFORE INSERT ON hr_locum_sign_ins FOR EACH ROW EXECUTE FUNCTION guard_hr_locum_sign_in_insert();

-- A request keeps what was asked and is closed once. Done links an active account whose only role is `locum`, with no
-- staff file of its own, and only by someone who may create staff accounts. (The email match is checked in the service:
-- the requested email is encrypted.)
CREATE OR REPLACE FUNCTION guard_hr_locum_sign_in() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.worker_id <> OLD.worker_id
   OR NEW.email_encrypted <> OLD.email_encrypted OR NEW.requested_by_user_id <> OLD.requested_by_user_id OR NEW.requested_at <> OLD.requested_at
   OR NEW.created_at IS DISTINCT FROM OLD.created_at OR NEW.lock_version <> OLD.lock_version + 1
   OR OLD.status <> 'open' OR NEW.status = 'open' THEN
  RAISE EXCEPTION 'A locum sign-in request keeps what was asked and is closed once' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.status = 'done' AND (NOT hr_user_holds(NEW.closed_by_user_id, 'staff.manage.organisation') OR NOT hr_user_holds(NEW.closed_by_user_id, 'access.manage.organisation')) THEN
  RAISE EXCEPTION 'Only someone who may create staff accounts links a locum sign-in' USING ERRCODE = '23514';
 END IF;
 IF NEW.status = 'done' AND (NOT EXISTS (SELECT 1 FROM users u WHERE u.id = NEW.linked_user_id AND u.is_active)
   OR NOT EXISTS (SELECT 1 FROM model_has_roles mr JOIN roles r ON r.id = mr.role_id
     WHERE mr.model_id = NEW.linked_user_id AND mr.model_type = 'App\Models\User' AND r.name = 'locum')
   OR EXISTS (SELECT 1 FROM model_has_roles mr JOIN roles r ON r.id = mr.role_id
     WHERE mr.model_id = NEW.linked_user_id AND mr.model_type = 'App\Models\User' AND r.name <> 'locum')
   OR EXISTS (SELECT 1 FROM hr_workers w WHERE w.user_id = NEW.linked_user_id)) THEN
  RAISE EXCEPTION 'A locum sign-in is an active account holding the locum role only, with no staff file of its own' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_locum_sign_ins_guard BEFORE UPDATE ON hr_locum_sign_ins FOR EACH ROW EXECUTE FUNCTION guard_hr_locum_sign_in();
CREATE TRIGGER hr_locum_sign_ins_no_delete BEFORE DELETE ON hr_locum_sign_ins FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_locum_sign_ins_no_truncate BEFORE TRUNCATE ON hr_locum_sign_ins FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();

-- A linked locum account never also gets a staff file: the locum file is theirs.
CREATE OR REPLACE FUNCTION guard_hr_worker_not_a_linked_locum() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.user_id IS NOT NULL AND EXISTS (SELECT 1 FROM hr_locum_sign_ins s WHERE s.linked_user_id = NEW.user_id AND s.status = 'done') THEN
  RAISE EXCEPTION 'This account signs in for a locum file and takes no staff file of its own' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_workers_not_a_linked_locum BEFORE INSERT ON hr_workers FOR EACH ROW EXECUTE FUNCTION guard_hr_worker_not_a_linked_locum();
SQL);
    }

    public function down(): void {} // HR record evidence is retained, following the evidence convention.
};

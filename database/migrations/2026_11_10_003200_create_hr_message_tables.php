<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-14 staff messages (HR-PLAN 15.3; owner, 2026-11-10: "start", email now with SMS and WhatsApp later, and the four
 * messages: leave decided, roster ready, payslip ready, staff claim decided or paid). Staff only; never a patient.
 * Each channel's settings are append-only versions (on or off, the clinic's price per message, a monthly cap). Each
 * person chooses channels (nothing on by default); every change is an event. A delivery row records the template
 * code, the channel, the recipient and the ids it refers to, never the rendered text. One-time codes are hashed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_message_channel_settings', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->string('channel', 10); // email | sms | whatsapp
            $t->unsignedInteger('version');
            $t->boolean('enabled');
            $t->unsignedInteger('price_sen')->nullable(); // the clinic's price per message, shown only
            $t->unsignedInteger('monthly_cap')->nullable();
            $t->unsignedBigInteger('set_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign('organisation_id', 'hr_msg_settings_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign(['set_by_user_id', 'organisation_id'], 'hr_msg_settings_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['organisation_id', 'channel', 'version'], 'hr_msg_settings_once');
        });

        Schema::create('hr_message_preferences', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('user_id');
            $t->string('channel', 10);
            $t->boolean('opted_in');
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign(['user_id', 'organisation_id'], 'hr_msg_prefs_user_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['user_id', 'channel'], 'hr_msg_prefs_once');
        });

        Schema::create('hr_message_preference_events', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('user_id');
            $t->string('channel', 10);
            $t->boolean('opted_in');
            $t->string('source', 12); // in_kpone | stop_reply
            $t->timestampTz('created_at');
            $t->foreign(['user_id', 'organisation_id'], 'hr_msg_pref_events_user_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->index(['user_id', 'channel'], 'hr_msg_pref_events_lookup');
        });

        Schema::create('hr_message_deliveries', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('user_id');
            $t->string('channel', 10);
            $t->string('template', 20);
            $t->jsonb('subject_ids'); // e.g. {"leave_request_id": 12}; never names, dates of illness, amounts or text
            $t->string('status', 12); // sending | sent | failed | skipped_cap
            $t->string('provider_message_id', 200)->nullable();
            $t->string('error_code', 40)->nullable();
            $t->date('period_month'); // the first day of the month the message counts in, for the cap and the cost
            $t->timestampsTz();
            $t->foreign(['user_id', 'organisation_id'], 'hr_msg_deliveries_user_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->index(['organisation_id', 'channel', 'period_month', 'status'], 'hr_msg_deliveries_cap');
            $t->index(['organisation_id', 'created_at'], 'hr_msg_deliveries_list');
        });

        Schema::create('hr_message_otps', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('user_id');
            $t->string('purpose', 20);
            $t->char('code_hash', 64);
            $t->timestampTz('expires_at');
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestampTz('consumed_at')->nullable();
            $t->timestampTz('created_at');
            $t->foreign(['user_id', 'organisation_id'], 'hr_msg_otps_user_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->index(['user_id', 'purpose', 'created_at'], 'hr_msg_otps_lookup');
        });

        DB::statement("ALTER TABLE hr_message_channel_settings ADD CONSTRAINT hr_msg_settings_shape CHECK (
            channel IN ('email', 'sms', 'whatsapp') AND version >= 1
            AND (monthly_cap IS NULL OR monthly_cap BETWEEN 1 AND 100000) AND (price_sen IS NULL OR price_sen <= 100000)
            AND (NOT enabled OR monthly_cap IS NOT NULL))");
        DB::statement("ALTER TABLE hr_message_preferences ADD CONSTRAINT hr_msg_prefs_shape CHECK (channel IN ('email', 'sms', 'whatsapp'))");
        DB::statement("ALTER TABLE hr_message_preference_events ADD CONSTRAINT hr_msg_pref_events_shape CHECK (channel IN ('email', 'sms', 'whatsapp') AND source IN ('in_kpone', 'stop_reply'))");
        DB::statement("ALTER TABLE hr_message_deliveries ADD CONSTRAINT hr_msg_deliveries_shape CHECK (
            channel IN ('email', 'sms', 'whatsapp') AND status IN ('sending', 'sent', 'failed', 'skipped_cap')
            AND template IN ('leave_decided', 'roster_ready', 'payslip_ready', 'claim_decided')
            AND EXTRACT(DAY FROM period_month) = 1 AND jsonb_typeof(subject_ids) = 'object')");
        DB::statement("ALTER TABLE hr_message_otps ADD CONSTRAINT hr_msg_otps_shape CHECK (code_hash ~ '^[0-9a-f]{64}$' AND attempts <= 5)");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER hr_msg_settings_append BEFORE UPDATE OR DELETE ON hr_message_channel_settings FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_msg_pref_events_append BEFORE UPDATE OR DELETE ON hr_message_preference_events FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_msg_deliveries_no_delete BEFORE DELETE ON hr_message_deliveries FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_msg_prefs_no_delete BEFORE DELETE ON hr_message_preferences FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_msg_settings_truncate BEFORE TRUNCATE ON hr_message_channel_settings FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_msg_prefs_truncate BEFORE TRUNCATE ON hr_message_preferences FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_msg_pref_events_truncate BEFORE TRUNCATE ON hr_message_preference_events FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_msg_deliveries_truncate BEFORE TRUNCATE ON hr_message_deliveries FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();

-- A delivery keeps what it refers to; only a message still sending gets its outcome, once.
CREATE OR REPLACE FUNCTION guard_hr_message_delivery() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.user_id <> OLD.user_id
   OR NEW.channel <> OLD.channel OR NEW.template <> OLD.template OR NEW.subject_ids <> OLD.subject_ids
   OR NEW.period_month <> OLD.period_month OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
  RAISE EXCEPTION 'A message delivery keeps what it refers to' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.status IS DISTINCT FROM OLD.status AND NOT (OLD.status = 'sending' AND NEW.status IN ('sent', 'failed')) THEN
  RAISE EXCEPTION 'Only a message still sending gets its outcome' USING ERRCODE = 'P0001';
 END IF;
 IF OLD.status <> 'sending' AND (NEW.provider_message_id IS DISTINCT FROM OLD.provider_message_id OR NEW.error_code IS DISTINCT FROM OLD.error_code) THEN
  RAISE EXCEPTION 'A finished message delivery does not change' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_msg_deliveries_guard BEFORE UPDATE ON hr_message_deliveries FOR EACH ROW EXECUTE FUNCTION guard_hr_message_delivery();

-- A one-time code keeps who it is for and its hash; tries only go up and a used code stays used.
CREATE OR REPLACE FUNCTION guard_hr_message_otp() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN
  RAISE EXCEPTION 'A one-time code is kept as evidence' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.id <> OLD.id OR NEW.user_id <> OLD.user_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.purpose <> OLD.purpose
   OR NEW.code_hash <> OLD.code_hash OR NEW.expires_at IS DISTINCT FROM OLD.expires_at OR NEW.created_at IS DISTINCT FROM OLD.created_at
   OR NEW.attempts < OLD.attempts OR (OLD.consumed_at IS NOT NULL AND NEW.consumed_at IS DISTINCT FROM OLD.consumed_at) THEN
  RAISE EXCEPTION 'A one-time code does not change' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_msg_otps_guard BEFORE UPDATE OR DELETE ON hr_message_otps FOR EACH ROW EXECUTE FUNCTION guard_hr_message_otp();
SQL);
    }

    public function down(): void {} // Message settings, choices and deliveries are evidence; they are kept.
};

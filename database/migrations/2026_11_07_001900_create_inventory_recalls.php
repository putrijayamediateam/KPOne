<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STK-25 (owner programme 2026-11-11, plan approved by B.E.R.N.I.S.S): a recall blocks named batch numbers of one
 * item at every branch at once, through the shared batch eligibility rule. It matches by item and batch number, so
 * the same batch received later with another expiry or in another pack size is blocked too. It is independent of
 * STK-2 branch holds. A recall is started by a Director and lifted once, never by the person who started it.
 * Nothing is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_recalls', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('inventory_sku_id')->constrained('inventory_skus')->restrictOnDelete();
            $t->string('reason', 30);
            $t->string('reference', 60)->nullable();
            $t->text('note_encrypted')->nullable();
            $t->string('status', 10);
            $t->foreignId('started_by_user_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('started_at');
            $t->foreignId('lifted_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('lifted_at')->nullable();
            $t->string('lift_reason', 30)->nullable();
            $t->text('lift_note_encrypted')->nullable();
            $t->uuid('lift_idempotency_key')->nullable();
            $t->char('lift_request_hash', 64)->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->uuid('idempotency_key');
            $t->char('request_hash', 64);
            $t->unique(['organisation_id', 'idempotency_key'], 'inventory_recalls_idempotency_unique');
            $t->index(['organisation_id', 'status', 'started_at'], 'inventory_recalls_status_index');
        });
        Schema::create('inventory_recall_batches', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('recall_id')->constrained('inventory_recalls')->restrictOnDelete();
            $t->foreignId('inventory_item_id')->constrained('inventory_items')->restrictOnDelete();
            // The batch number as matched: trimmed and in capitals.
            $t->string('batch_key', 100);
            // The batch the Director ticked, kept as evidence.
            $t->foreignId('inventory_batch_id')->constrained('inventory_batches')->restrictOnDelete();
            $t->boolean('active');
            $t->unique(['recall_id', 'batch_key'], 'inventory_recall_batches_unique');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
-- One active recall per item and batch number, at most.
CREATE UNIQUE INDEX inventory_recall_batches_one_active ON inventory_recall_batches (inventory_item_id, batch_key) WHERE active;
ALTER TABLE inventory_recalls ADD CONSTRAINT inventory_recalls_shape CHECK (
 reason IN ('supplier_recall', 'regulator_notice', 'quality_issue', 'other')
 AND (reason <> 'other' OR note_encrypted IS NOT NULL)
 AND (reference IS NULL OR length(btrim(reference)) BETWEEN 1 AND 60)
 AND status IN ('active', 'lifted') AND lock_version > 0
 AND ((status = 'lifted') = (lifted_by_user_id IS NOT NULL))
 AND ((lifted_by_user_id IS NULL) = (lifted_at IS NULL) AND (lifted_by_user_id IS NULL) = (lift_reason IS NULL))
 AND (lift_reason IS NULL OR lift_reason IN ('supplier_cleared', 'recall_withdrawn', 'recalled_in_error', 'other'))
 AND (lift_reason IS DISTINCT FROM 'other' OR lift_note_encrypted IS NOT NULL)
 AND (lift_note_encrypted IS NULL OR lifted_by_user_id IS NOT NULL)
 AND (lifted_by_user_id IS NULL OR lifted_by_user_id <> started_by_user_id)
 AND ((lifted_by_user_id IS NULL) = (lift_idempotency_key IS NULL) AND (lifted_by_user_id IS NULL) = (lift_request_hash IS NULL))
);
CREATE OR REPLACE FUNCTION guard_inventory_recall() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN
  RAISE EXCEPTION 'Recalls are kept' USING ERRCODE = '23514';
 END IF;
 IF TG_OP = 'INSERT' THEN
  IF NEW.status <> 'active' OR NEW.lifted_by_user_id IS NOT NULL THEN
   RAISE EXCEPTION 'A recall starts active' USING ERRCODE = '23514';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM inventory_skus s WHERE s.id = NEW.inventory_sku_id AND s.organisation_id = NEW.organisation_id)
   OR NOT EXISTS (SELECT 1 FROM users u WHERE u.id = NEW.started_by_user_id AND u.organisation_id = NEW.organisation_id) THEN
   RAISE EXCEPTION 'A recall belongs to one organisation' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
 END IF;
 IF OLD.status <> 'active' OR NEW.status <> 'lifted'
  OR (NEW.public_id, NEW.organisation_id, NEW.inventory_sku_id, NEW.reason, NEW.reference, NEW.note_encrypted, NEW.started_by_user_id, NEW.started_at, NEW.idempotency_key, NEW.request_hash)
   IS DISTINCT FROM (OLD.public_id, OLD.organisation_id, OLD.inventory_sku_id, OLD.reason, OLD.reference, OLD.note_encrypted, OLD.started_by_user_id, OLD.started_at, OLD.idempotency_key, OLD.request_hash)
  OR NEW.lock_version <> OLD.lock_version + 1 THEN
  RAISE EXCEPTION 'A recall is lifted once and otherwise never changes' USING ERRCODE = '23514';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM users u WHERE u.id = NEW.lifted_by_user_id AND u.organisation_id = NEW.organisation_id) THEN
  RAISE EXCEPTION 'A recall belongs to one organisation' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION guard_inventory_recall_batch() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
 recall inventory_recalls%ROWTYPE;
BEGIN
 IF TG_OP = 'DELETE' THEN
  RAISE EXCEPTION 'Recalls are kept' USING ERRCODE = '23514';
 END IF;
 SELECT * INTO recall FROM inventory_recalls r WHERE r.id = NEW.recall_id;
 IF TG_OP = 'INSERT' THEN
  IF recall.status IS DISTINCT FROM 'active' OR NOT NEW.active OR recall.organisation_id <> NEW.organisation_id
   OR NOT EXISTS (SELECT 1 FROM inventory_batches b JOIN inventory_skus s ON s.id = b.inventory_sku_id
     WHERE b.id = NEW.inventory_batch_id AND b.organisation_id = NEW.organisation_id AND b.inventory_sku_id = recall.inventory_sku_id
       AND s.inventory_item_id = NEW.inventory_item_id AND upper(btrim(b.batch_number)) = NEW.batch_key) THEN
   RAISE EXCEPTION 'A recalled batch belongs to the recall''s SKU and starts active' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
 END IF;
 -- The only change: a batch stops being recalled once its recall is lifted.
 IF NOT OLD.active OR NEW.active OR recall.status <> 'lifted'
  OR (NEW.organisation_id, NEW.recall_id, NEW.inventory_item_id, NEW.batch_key, NEW.inventory_batch_id)
   IS DISTINCT FROM (OLD.organisation_id, OLD.recall_id, OLD.inventory_item_id, OLD.batch_key, OLD.inventory_batch_id) THEN
  RAISE EXCEPTION 'A recalled batch is released only when its recall is lifted' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
-- At commit, a lifted recall has released every batch number.
CREATE OR REPLACE FUNCTION check_inventory_recall_released() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.status = 'lifted' AND EXISTS (SELECT 1 FROM inventory_recall_batches rb WHERE rb.recall_id = NEW.id AND rb.active) THEN
  RAISE EXCEPTION 'A lifted recall releases all its batches' USING ERRCODE = '23514';
 END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER inventory_recalls_released AFTER UPDATE ON inventory_recalls DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_inventory_recall_released();
CREATE OR REPLACE FUNCTION refuse_inventory_recall_truncate() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'Recalls are kept' USING ERRCODE = '23514';
END $$;
CREATE TRIGGER inventory_recalls_guard BEFORE INSERT OR UPDATE OR DELETE ON inventory_recalls FOR EACH ROW EXECUTE FUNCTION guard_inventory_recall();
CREATE TRIGGER inventory_recall_batches_guard BEFORE INSERT OR UPDATE OR DELETE ON inventory_recall_batches FOR EACH ROW EXECUTE FUNCTION guard_inventory_recall_batch();
CREATE TRIGGER inventory_recalls_no_truncate BEFORE TRUNCATE ON inventory_recalls FOR EACH STATEMENT EXECUTE FUNCTION refuse_inventory_recall_truncate();
CREATE TRIGGER inventory_recall_batches_no_truncate BEFORE TRUNCATE ON inventory_recall_batches FOR EACH STATEMENT EXECUTE FUNCTION refuse_inventory_recall_truncate();
SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS inventory_recalls_released ON inventory_recalls; DROP TRIGGER IF EXISTS inventory_recall_batches_no_truncate ON inventory_recall_batches; DROP TRIGGER IF EXISTS inventory_recalls_no_truncate ON inventory_recalls;
                DROP TRIGGER IF EXISTS inventory_recall_batches_guard ON inventory_recall_batches; DROP TRIGGER IF EXISTS inventory_recalls_guard ON inventory_recalls;');
        }
        Schema::dropIfExists('inventory_recall_batches');
        Schema::dropIfExists('inventory_recalls');
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS check_inventory_recall_released(); DROP FUNCTION IF EXISTS refuse_inventory_recall_truncate(); DROP FUNCTION IF EXISTS guard_inventory_recall_batch(); DROP FUNCTION IF EXISTS guard_inventory_recall();');
        }
    }
};

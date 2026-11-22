<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DAY-1b (owner decisions, 22 Nov 2026): a strictly personal daily routine. Titles are stored encrypted; a tick belongs to one
 * date; nothing here is audit, clinical or financial evidence, and none of it is in `ai_read`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('my_routine_lists', function ($table) {
            $table->foreignId('user_id')->primary()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });
        Schema::create('my_routine_items', function ($table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->text('title'); // encrypted by the model; the 120-character limit is checked on the plain text before encrypting
            $table->unsignedSmallInteger('position');
            $table->timestampTz('hidden_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['id', 'user_id']);
            $table->index(['user_id', 'hidden_at', 'position']);
        });
        Schema::create('my_routine_ticks', function ($table) {
            $table->id();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('user_id');
            $table->date('done_on');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['item_id', 'done_on']);
            $table->index(['user_id', 'done_on']);
            $table->foreign(['item_id', 'user_id'])->references(['id', 'user_id'])->on('my_routine_items')->cascadeOnDelete();
        });
        Schema::create('my_routine_requests', function ($table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->string('action', 12);
            $table->string('payload_hash', 64);
            $table->unsignedBigInteger('item_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['user_id', 'idempotency_key']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE my_routine_items ADD CONSTRAINT my_routine_items_position_check CHECK (position BETWEEN 1 AND 1000)');
        DB::statement("ALTER TABLE my_routine_requests ADD CONSTRAINT my_routine_requests_action_check CHECK (action IN ('add', 'restore'))");
        // At most 20 visible items per person, also when two requests race (the service takes the person's row lock first).
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION kp_my_routine_item_limit() RETURNS trigger AS $$
BEGIN
    IF NEW.hidden_at IS NULL AND (SELECT count(*) FROM my_routine_items WHERE user_id = NEW.user_id AND hidden_at IS NULL AND id <> COALESCE(NEW.id, 0)) >= 20 THEN
        RAISE EXCEPTION 'A routine holds at most 20 items';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
        DB::unprepared('CREATE TRIGGER my_routine_item_limit BEFORE INSERT OR UPDATE OF hidden_at ON my_routine_items FOR EACH ROW EXECUTE FUNCTION kp_my_routine_item_limit()');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS my_routine_item_limit ON my_routine_items');
            DB::unprepared('DROP FUNCTION IF EXISTS kp_my_routine_item_limit()');
        }
        Schema::dropIfExists('my_routine_requests');
        Schema::dropIfExists('my_routine_ticks');
        Schema::dropIfExists('my_routine_items');
        Schema::dropIfExists('my_routine_lists');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Q-2c (owner decision 2026-11-06): a finished step keeps waiting for the doctor, and the next step may start
 * beside it. The rule "one open step per queue entry" becomes "one step in progress per queue entry"; done steps
 * stay open until the doctor resumes or opens the consultation, or the entry leaves Serving.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
DROP INDEX IF EXISTS treatment_step_one_open;
CREATE UNIQUE INDEX treatment_step_one_started ON queue_treatment_steps(queue_entry_id) WHERE state = 'started';
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Refuses (unique violation) while an entry has more than one open step, rather than closing evidence.
        DB::unprepared(<<<'SQL'
DROP INDEX IF EXISTS treatment_step_one_started;
CREATE UNIQUE INDEX treatment_step_one_open ON queue_treatment_steps(queue_entry_id) WHERE closed_at IS NULL;
SQL);
    }
};

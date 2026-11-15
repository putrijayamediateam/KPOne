<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hardening (TEAM.md section 7, an HR-2a known limit): the leave ledger, the request actions and the HR-2b evidence files
 * pointed at requests, openings and adjustments by id alone, so the organisation was checked only in the service. Each
 * of these foreign keys now also carries the organisation, as the rest of HR does, so PostgreSQL itself refuses a row that
 * points at another organisation's request, opening or adjustment.
 *
 * Additive in effect: no column or row changes. Each referenced table gains a unique (id, organisation_id) key, and each
 * single-column foreign key is replaced by a composite one. A row already pointing across organisations would make this
 * migration fail loudly, rather than be kept.
 */
return new class extends Migration
{
    /** @var list<array{table: string, column: string, name: string, references: string}> */
    private const KEYS = [
        ['table' => 'hr_leave_request_actions', 'column' => 'leave_request_id', 'name' => 'hr_leave_request_actions_request_fk', 'references' => 'hr_leave_requests'],
        ['table' => 'hr_leave_ledger', 'column' => 'leave_request_id', 'name' => 'hr_leave_ledger_request_fk', 'references' => 'hr_leave_requests'],
        ['table' => 'hr_leave_ledger', 'column' => 'leave_opening_id', 'name' => 'hr_leave_ledger_opening_fk', 'references' => 'hr_leave_openings'],
        ['table' => 'hr_leave_ledger', 'column' => 'leave_adjustment_id', 'name' => 'hr_leave_ledger_adjustment_fk', 'references' => 'hr_leave_adjustments'],
        ['table' => 'hr_leave_evidence_files', 'column' => 'leave_request_id', 'name' => 'hr_leave_evidence_files_request_fk', 'references' => 'hr_leave_requests'],
    ];

    private const SCOPES = ['hr_leave_requests', 'hr_leave_openings', 'hr_leave_adjustments'];

    public function up(): void
    {
        foreach (self::SCOPES as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_scope_unique UNIQUE (id, organisation_id)");
        }
        foreach (self::KEYS as $key) {
            DB::statement("ALTER TABLE {$key['table']} DROP CONSTRAINT {$key['name']}");
            DB::statement("ALTER TABLE {$key['table']} ADD CONSTRAINT {$key['name']} FOREIGN KEY ({$key['column']}, organisation_id)"
                ." REFERENCES {$key['references']} (id, organisation_id) ON DELETE RESTRICT");
        }
    }

    public function down(): void
    {
        foreach (self::KEYS as $key) {
            DB::statement("ALTER TABLE {$key['table']} DROP CONSTRAINT {$key['name']}");
            DB::statement("ALTER TABLE {$key['table']} ADD CONSTRAINT {$key['name']} FOREIGN KEY ({$key['column']})"
                ." REFERENCES {$key['references']} (id) ON DELETE RESTRICT");
        }
        foreach (self::SCOPES as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_scope_unique");
        }
    }
};

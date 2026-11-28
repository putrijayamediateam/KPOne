<?php

namespace Tests\Feature\Insights;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Visit\VisitTestCase;

/** INS-BM-4: the attending-doctor lookups on consultation checkouts are served by an index, not a table scan. */
final class ConsultationCheckoutDoctorIndexTest extends VisitTestCase
{
    public function test_checkouts_are_indexed_by_attending_doctor_branch_and_creation_time(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The index definition is checked on PostgreSQL.');
        }

        $definition = DB::table('pg_indexes')
            ->where('tablename', 'consultation_checkouts')
            ->where('indexname', 'consultation_checkouts_attending_branch_created_index')
            ->value('indexdef');

        $this->assertIsString($definition);
        $this->assertStringContainsString('(attending_doctor_user_id, branch_id, created_at)', $definition);
    }
}

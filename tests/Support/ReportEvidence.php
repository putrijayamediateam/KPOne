<?php

namespace Tests\Support;

use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use Illuminate\Support\Facades\DB;

trait ReportEvidence
{
    protected function anotherReportInvoice(): array
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        app(CompleteConsultationService::class)->complete($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version, 'encounter_lock_version' => $encounter->lock_version, 'lock_version' => null, 'service_deliveries' => []]);
        $this->selectBranch($ca);
        $builder = app(BillingBuilderService::class);
        $invoice = $builder->build($ca, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => null]);
        $invoice = $builder->finalize($ca, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version]);

        return [$doctor, $ca, $visit, $invoice];
    }

    private function sourceEvidence(): array
    {
        $result = [];
        foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $table) {
            if (in_array($table->tablename, ['audit_logs', 'sessions', 'cache', 'cache_locks'], true)) {
                continue;
            }
            $columns = DB::getSchemaBuilder()->getColumnListing($table->tablename);
            $query = DB::table($table->tablename);
            foreach (in_array('id', $columns, true) ? ['id'] : $columns as $column) {
                $query->orderBy($column);
            }
            $result[$table->tablename] = $query->get()->toJson();
        }

        return $result;
    }

    private function decodeReportCsv(string $content): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString("\r\n", $content);
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, substr($content, 3));
        rewind($stream);
        $metadata = [];
        while (($row = fgetcsv($stream, escape: '')) !== false && $row !== [null]) {
            $metadata[$row[0]] = $row[1];
        }
        $columns = fgetcsv($stream, escape: '');
        $rows = [];
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return [$metadata, $columns, $rows];
    }
}

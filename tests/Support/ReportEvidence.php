<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait ReportEvidence
{
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

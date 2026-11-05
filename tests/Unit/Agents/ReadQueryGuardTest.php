<?php

namespace Tests\Unit\Agents;

use App\Domain\Agents\AskKpone\ReadQueryGuard;
use App\Domain\Agents\AskKpone\ReadQueryRefused;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** AG-7: the text check in front of kpone:read accepts one SELECT over ai_read views and nothing else. */
class ReadQueryGuardTest extends TestCase
{
    private function guard(): ReadQueryGuard
    {
        return new ReadQueryGuard(['patients', 'visits', 'branches', 'invoices', 'consultations']);
    }

    /** @return array<string, array{string}> */
    public static function accepted(): array
    {
        return [
            'simple count' => ['SELECT count(*) FROM patients'],
            'trailing semicolon' => ['select count(*) from visits;'],
            'qualified view' => ['SELECT * FROM ai_read.visits v WHERE v.status = \'completed\''],
            'join' => ['SELECT b.name, count(*) FROM visits v JOIN branches b ON b.id = v.branch_id GROUP BY b.name ORDER BY 2 DESC'],
            'comma list' => ['SELECT count(*) FROM visits v, branches b WHERE b.id = v.branch_id'],
            'cte' => ['WITH done AS (SELECT * FROM visits WHERE status = \'completed\') SELECT count(*) FROM done'],
            'cte with columns' => ['WITH t (n) AS (SELECT count(*) FROM invoices) SELECT n FROM t'],
            'subquery' => ['SELECT * FROM (SELECT visit_type, count(*) AS n FROM visits GROUP BY 1) AS t WHERE n > 1'],
            'extract from' => ['SELECT extract(year FROM registered_at) AS y, count(*) FROM visits GROUP BY 1'],
            'is distinct from' => ['SELECT count(*) FROM visits WHERE panel_id IS DISTINCT FROM NULL'],
            'string with keyword' => ['SELECT count(*) FROM visits WHERE status = \'delete; drop table x\''],
            'quoted quote' => ['SELECT count(*) FROM patients WHERE initials = \'O\'\'B.\''],
            'generate series' => ['SELECT g FROM generate_series(1, 3) AS g'],
            'union' => ['SELECT id FROM visits UNION ALL SELECT id FROM invoices'],
            'lateral' => ['SELECT p.id, x.n FROM patients p, LATERAL (SELECT count(*) AS n FROM visits v WHERE v.patient_id = p.id) x'],
        ];
    }

    #[DataProvider('accepted')]
    public function test_it_accepts_one_select_over_ai_read_views(string $sql): void
    {
        $this->assertSame(rtrim(rtrim($sql), ';'), $this->guard()->check($sql));
    }

    /** @return array<string, array{string, string}> */
    public static function refused(): array
    {
        return [
            'empty' => ['  ', 'empty'],
            'insert' => ['INSERT INTO patients VALUES (1)', 'not_select'],
            'update' => ['UPDATE visits SET status = \'x\'', 'not_select'],
            'delete' => ['DELETE FROM visits', 'not_select'],
            'ddl' => ['DROP VIEW patients', 'not_select'],
            'create' => ['CREATE TABLE x (id int)', 'not_select'],
            'writing cte' => ['WITH x AS (DELETE FROM visits RETURNING id) SELECT * FROM x', 'forbidden_word'],
            'update in cte' => ['WITH x AS (UPDATE visits SET status = \'a\' RETURNING id) SELECT * FROM x', 'forbidden_word'],
            'select into' => ['SELECT * INTO copy_of_visits FROM visits', 'forbidden_word'],
            'for update' => ['SELECT * FROM visits FOR UPDATE', 'forbidden_word'],
            'two statements' => ['SELECT 1 FROM visits; SELECT 2 FROM visits', 'multiple'],
            'statement after string' => ['SELECT \'a\'; DELETE FROM visits', 'multiple'],
            'line comment' => ['SELECT 1 FROM visits -- hidden', 'comment'],
            'block comment' => ['SELECT /* x */ 1 FROM visits', 'comment'],
            'dollar quote' => ['SELECT $$x$$ FROM visits', 'character'],
            'double quote' => ['SELECT * FROM "patients"', 'character'],
            'backslash' => ['SELECT E\'\\x\' FROM visits', 'character'],
            'public schema' => ['SELECT * FROM public.users', 'forbidden_word'],
            'other schema' => ['SELECT * FROM audit.logs', 'outside_ai_read'],
            'catalog' => ['SELECT * FROM pg_catalog.pg_roles', 'forbidden_function'],
            'catalog bare' => ['SELECT * FROM pg_user', 'forbidden_function'],
            'information schema' => ['SELECT * FROM information_schema.tables', 'forbidden_word'],
            'system function' => ['SELECT pg_read_file(\'x\') FROM visits', 'forbidden_function'],
            'sleep' => ['SELECT pg_sleep(30)', 'forbidden_function'],
            'xml query' => ['SELECT query_to_xml(\'select 1\', true, true, \'\')', 'forbidden_function'],
            'settings' => ['SELECT current_setting(\'is_superuser\')', 'forbidden_word'],
            'set config' => ['SELECT set_config(\'search_path\', \'x\', true)', 'forbidden_word'],
            'dblink' => ['SELECT * FROM dblink(\'x\', \'y\')', 'forbidden_word'],
            'unknown table' => ['SELECT * FROM users', 'unknown_view'],
            'unknown table in join' => ['SELECT * FROM visits v JOIN users u ON u.id = v.id', 'unknown_view'],
            'unknown table after comma' => ['SELECT * FROM visits v, users u', 'unknown_view'],
            'unknown table after subquery' => ['SELECT * FROM (SELECT 1 FROM visits) t, users u', 'unknown_view'],
            'unknown table in subquery' => ['SELECT * FROM visits WHERE id IN (SELECT id FROM users)', 'unknown_view'],
            'unknown ai_read view' => ['SELECT * FROM ai_read.users', 'unknown_view'],
            'table function' => ['SELECT * FROM lo_import(\'x\')', 'forbidden_function'],
            'other table function' => ['SELECT * FROM json_populate_record(null, \'{}\')', 'table_function'],
            'explain' => ['EXPLAIN SELECT * FROM visits', 'not_select'],
            'copy' => ['COPY visits TO STDOUT', 'not_select'],
            'unclosed string' => ['SELECT \'x FROM visits', 'string'],
            'unbalanced' => ['SELECT count(* FROM visits', 'parenthesis'],
            'sql in ts_stat' => ['SELECT ts_stat(\'SELECT to_tsvector(full_name) FROM public.patients\')', 'forbidden_word'],
            'sql in ts_rewrite' => ['SELECT ts_rewrite(\'a\'::tsquery, \'SELECT 1\')', 'forbidden_word'],
            'table syntax in cte' => ['WITH a AS (TABLE audit.logs) SELECT * FROM a', 'forbidden_word'],
            'comma after join on' => ['SELECT * FROM visits v JOIN branches b ON true, audit.logs', 'outside_ai_read'],
            'unknown after join on' => ['SELECT * FROM visits v JOIN branches b ON b.id = v.branch_id, users', 'unknown_view'],
            'unknown after join using' => ['SELECT * FROM visits v JOIN invoices i USING (id), users', 'unknown_view'],
            'rows from' => ['SELECT * FROM ROWS FROM (generate_series(1, 2)) AS t', 'unknown_view'],
            'lateral function' => ['SELECT * FROM visits v JOIN LATERAL json_each(\'{}\') j ON true', 'table_function'],
            'current user' => ['SELECT current_user FROM visits', 'forbidden_word'],
            'too long' => ['SELECT 1 FROM visits WHERE id IN ('.str_repeat('1,', 6000).'1)', 'too_long'],
        ];
    }

    #[DataProvider('refused')]
    public function test_it_refuses_anything_else(string $sql, string $reason): void
    {
        try {
            $this->guard()->check($sql);
            $this->fail('The query was accepted: '.$sql);
        } catch (ReadQueryRefused $refused) {
            $this->assertSame($reason, $refused->reasonCode);
        }
    }
}

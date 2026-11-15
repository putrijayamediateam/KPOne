<?php

namespace App\Domain\Agents\AskKpone;

use App\Domain\Audit\AuditRecorder;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * AG-7 (contract section 13): runs one checked SELECT against the masked `ai_read` views for `kpone:read`.
 * Three layers keep it read-only: ReadQueryGuard (text check), a transaction set read-only with a statement
 * timeout that is always rolled back, and the `kpone_ai_reader` login that can SELECT only from `ai_read`.
 * Every run and every refusal writes an audit row with a hash of the query and the row count, never the
 * query text or any result.
 */
final class AiReadQueryRunner
{
    public const ROW_LIMIT = 500;

    public const STATEMENT_TIMEOUT_MS = 10000;

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, truncated: bool}
     *
     * @throws ReadQueryRefused
     */
    public function run(string $sql): array
    {
        $hash = hash('sha256', $sql);
        $db = $this->connection();

        try {
            $statement = (new ReadQueryGuard($this->viewNames($db)))->check($sql);
        } catch (ReadQueryRefused $refused) {
            $this->audit->record('ai_read.query_refused', metadata: [
                'query_sha256' => $hash,
                'reason_code' => $refused->reasonCode,
            ]);

            throw $refused;
        }

        try {
            $result = $this->readOnly($db, fn () => $this->fetch($db, 'SELECT * FROM ('.$statement.') AS kpone_read LIMIT '.(self::ROW_LIMIT + 1)));
        } catch (ReadQueryRefused $refused) {
            $this->audit->record('ai_read.query_refused', metadata: ['query_sha256' => $hash, 'reason_code' => $refused->reasonCode]);

            throw $refused;
        } catch (PDOException $failure) {
            // Probing leaves a trace too: a query that passes the guard but fails in PostgreSQL is audited by its state code.
            $this->audit->record('ai_read.query_failed', metadata: ['query_sha256' => $hash, 'sqlstate' => (string) $failure->getCode()]);
            $detail = strtok((string) ($failure->errorInfo[2] ?? $failure->getMessage()), "\n");

            throw new RuntimeException('The query failed: '.($detail === false ? (string) $failure->getCode() : $detail));
        }
        ['columns' => $columns, 'rows' => $rows] = $result;

        $truncated = count($rows) > self::ROW_LIMIT;
        $rows = array_slice($rows, 0, self::ROW_LIMIT);

        // The audit row is written before anything is shown; if it cannot be written, nothing is released.
        $this->audit->record('ai_read.query_run', metadata: [
            'query_sha256' => $hash,
            'row_count' => count($rows),
            'truncated' => $truncated,
        ]);

        return [
            'columns' => $columns,
            'rows' => $rows,
            'truncated' => $truncated,
        ];
    }

    /** @return list<array{view: string, description: ?string, columns: list<array{name: string, type: string, description: ?string}>}> */
    public function schema(): array
    {
        $db = $this->connection();
        $rows = $this->readOnly($db, fn () => $db->select(<<<'SQL'
            SELECT c.table_name AS view, obj_description(format('ai_read.%I', c.table_name)::regclass, 'pg_class') AS view_description,
                c.column_name AS name, c.data_type AS type,
                col_description(format('ai_read.%I', c.table_name)::regclass, c.ordinal_position::integer) AS description
            FROM information_schema.columns c
            JOIN information_schema.views v ON v.table_schema = c.table_schema AND v.table_name = c.table_name
            WHERE c.table_schema = 'ai_read'
            ORDER BY c.table_name, c.ordinal_position
            SQL));

        $views = [];
        foreach ($rows as $row) {
            $views[$row->view] ??= ['view' => (string) $row->view, 'description' => $row->view_description, 'columns' => []];
            $views[$row->view]['columns'][] = ['name' => (string) $row->name, 'type' => (string) $row->type, 'description' => $row->description];
        }

        return array_values($views);
    }

    /**
     * Runs the wrapped query and keeps every column: two output columns with the same name would otherwise collapse
     * into one and lose data silently, so such a query is refused.
     *
     * @return array{columns: list<string>, rows: list<array<string, mixed>>}
     */
    private function fetch(ConnectionInterface $db, string $sql): array
    {
        if (! $db instanceof Connection) {
            throw new RuntimeException('Ask KPOne needs a PDO database connection.');
        }
        $statement = $db->getPdo()->query($sql);
        if ($statement === false) {
            throw new RuntimeException('The query could not be run.');
        }

        $columns = [];
        for ($i = 0; $i < $statement->columnCount(); $i++) {
            $meta = $statement->getColumnMeta($i);
            $columns[] = (string) ($meta['name'] ?? $i);
        }
        if (count(array_unique($columns)) !== count($columns)) {
            throw new ReadQueryRefused('duplicate_columns', 'Two result columns have the same name. Give each column its own name with AS.');
        }

        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as $values) {
            $rows[] = array_combine($columns, $values);
        }

        return ['columns' => $columns, 'rows' => $rows];
    }

    /** @return list<string> */
    private function viewNames(ConnectionInterface $db): array
    {
        $rows = $this->readOnly($db, fn () => $db->select(
            "SELECT table_name FROM information_schema.views WHERE table_schema = 'ai_read' ORDER BY table_name",
        ));

        return array_values(array_map(fn (object $row): string => (string) data_get($row, 'table_name'), $rows));
    }

    /**
     * Runs $read in a read-only transaction with a statement timeout and the ai_read search path, then always
     * rolls it back.
     *
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    private function readOnly(ConnectionInterface $db, callable $read): mixed
    {
        $db->beginTransaction();
        try {
            $db->statement('SET LOCAL transaction_read_only = on');
            $db->statement('SET LOCAL statement_timeout = '.self::STATEMENT_TIMEOUT_MS);
            $db->statement('SET LOCAL search_path = ai_read');
            $this->assertReaderOnly($db);

            return $read();
        } finally {
            try {
                $db->rollBack();
            } catch (Throwable) {
                // The connection is already out of the transaction (for example after a timeout); nothing to undo.
            }
        }
    }

    /**
     * The login in use must not be able to read the operational tables directly or be a superuser; otherwise the
     * text guard would be the only protection. Tests that read their own data through the test login say so
     * explicitly with database.ai_read.allow_privileged_login.
     */
    private function assertReaderOnly(ConnectionInterface $db): void
    {
        if (config('database.ai_read.allow_privileged_login') === true) {
            return;
        }
        $login = $db->selectOne("SELECT rolsuper AS superuser, has_table_privilege('public.patients', 'SELECT') AS reads_tables FROM pg_roles WHERE rolname = current_user");
        if ($login === null || data_get($login, 'superuser') || data_get($login, 'reads_tables')) {
            throw new RuntimeException('The read login can read more than the ai_read views. Use the kpone_ai_reader login described in docs/DEVELOPMENT.md.');
        }
    }

    private function connection(): ConnectionInterface
    {
        $name = (string) config('database.ai_read.connection');
        $config = config('database.connections.'.$name);
        if (! is_array($config) || ($config['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException('Ask KPOne needs the PostgreSQL ai_reader connection.');
        }

        // The dedicated login is required; this path never falls back to the application's own database login.
        if ($name === 'ai_reader') {
            $username = (string) ($config['username'] ?? '');
            $main = (string) config('database.connections.'.config('database.default').'.username');
            if ($username === '' || $username === $main) {
                throw new RuntimeException(
                    'The kpone_ai_reader login is not configured. Set AI_READER_DB_USERNAME and AI_READER_DB_PASSWORD; see docs/DEVELOPMENT.md.',
                );
            }
        }

        return DB::connection($name);
    }
}

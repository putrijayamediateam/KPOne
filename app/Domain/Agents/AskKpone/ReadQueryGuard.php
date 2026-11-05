<?php

namespace App\Domain\Agents\AskKpone;

/**
 * AG-7 (contract section 13): the text check in front of `kpone:read`. It accepts exactly one SELECT or WITH
 * statement whose relations are views of schema `ai_read` (or its own common table expressions) and refuses
 * everything else. It is one of three layers: the `kpone_ai_reader` login can only SELECT from `ai_read`, and
 * the query runs in a read-only transaction with a statement timeout.
 */
final class ReadQueryGuard
{
    public const MAX_LENGTH = 10000;

    private const FORBIDDEN_WORDS = [
        'insert', 'update', 'delete', 'merge', 'upsert', 'truncate', 'drop', 'alter', 'create', 'grant', 'revoke',
        'copy', 'call', 'do', 'execute', 'exec', 'prepare', 'deallocate', 'lock', 'vacuum', 'analyze', 'analyse',
        'set', 'reset', 'listen', 'notify', 'unlisten', 'comment', 'refresh', 'reindex', 'cluster', 'discard',
        'checkpoint', 'load', 'import', 'security', 'into', 'returning', 'share', 'nowait', 'savepoint', 'begin',
        'commit', 'rollback', 'release', 'start', 'abort', 'declare', 'fetch', 'move', 'close', 'explain',
        'public', 'information_schema', 'dblink', 'current_setting', 'set_config', 'session_user', 'current_user',
        'current_role', 'table', 'ts_stat', 'ts_rewrite',
    ];

    /** Functions whose `(... FROM ...)` syntax is not a relation. */
    private const FROM_FUNCTIONS = ['extract', 'substring', 'trim', 'overlay', 'position'];

    /** Words that close a FROM list at their parenthesis level. */
    private const CLAUSE_ENDS = ['where', 'group', 'order', 'having', 'limit', 'offset', 'union', 'intersect', 'except', 'window', 'select'];

    /** Set-returning functions allowed after FROM or JOIN. */
    private const FROM_TABLE_FUNCTIONS = ['generate_series', 'unnest'];

    /** @param list<string> $views the view names of schema ai_read */
    public function __construct(private readonly array $views) {}

    /**
     * Returns the statement without a trailing semicolon, or throws.
     *
     * @throws ReadQueryRefused
     */
    public function check(string $sql): string
    {
        $sql = trim($sql);
        if ($sql === '') {
            throw new ReadQueryRefused('empty', 'Give one SELECT statement.');
        }
        if (strlen($sql) > self::MAX_LENGTH) {
            throw new ReadQueryRefused('too_long', 'The query is longer than '.self::MAX_LENGTH.' characters.');
        }
        if (str_contains($sql, '--') || str_contains($sql, '/*')) {
            throw new ReadQueryRefused('comment', 'Comments are not allowed.');
        }
        if (preg_match('/[\\\\"$`\x00]/', $sql) === 1) {
            throw new ReadQueryRefused('character', 'Backslashes, double quotes, dollar quoting and backticks are not allowed.');
        }

        $statement = rtrim($sql);
        if (str_ends_with($statement, ';')) {
            $statement = rtrim(substr($statement, 0, -1));
        }

        // Replace string literals ('' escapes included) by an empty literal so their text is never read as SQL.
        $code = preg_replace("/'(?:[^']|'')*'/", "''", $statement);
        if ($code === null || substr_count($code, "'") % 2 !== 0) {
            throw new ReadQueryRefused('string', 'A string literal is not closed.');
        }
        if (str_contains($code, ';')) {
            throw new ReadQueryRefused('multiple', 'Only one statement is allowed.');
        }

        $tokens = $this->tokens(strtolower($code));
        if ($tokens === [] || ! in_array($tokens[0], ['select', 'with'], true)) {
            throw new ReadQueryRefused('not_select', 'Only a SELECT or WITH statement is allowed.');
        }

        foreach ($tokens as $token) {
            if (in_array($token, self::FORBIDDEN_WORDS, true)) {
                throw new ReadQueryRefused('forbidden_word', 'The query uses a word that is not allowed: '.strtoupper($token).'.');
            }
            if (str_starts_with($token, 'pg_') || str_starts_with($token, 'lo_') || str_contains($token, 'to_xml')
                || str_starts_with($token, 'xml')) {
                throw new ReadQueryRefused('forbidden_function', 'System and XML functions are not allowed: '.$token.'.');
            }
        }

        $this->checkRelations($tokens);

        return $statement;
    }

    /** @return list<string> */
    private function tokens(string $code): array
    {
        preg_match_all("/[a-z_][a-z0-9_]*|''|\d+(?:\.\d+)?|::|<=|>=|<>|!=|\|\||[^\s]/", $code, $matches);

        return $matches[0];
    }

    /** @param list<string> $tokens */
    private function checkRelations(array $tokens): void
    {
        $ctes = $this->cteNames($tokens);
        // One frame per parenthesis level: the token before "(" and whether a FROM list is open at that level.
        $frames = [['fn' => '', 'from' => false]];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $top = count($frames) - 1;
            if ($token === '(') {
                $frames[] = ['fn' => $tokens[$i - 1] ?? '', 'from' => false];

                continue;
            }
            if ($token === ')') {
                if ($top === 0) {
                    throw new ReadQueryRefused('parenthesis', 'The parentheses do not match.');
                }
                array_pop($frames);

                continue;
            }
            if ($token === 'from') {
                $previous = $tokens[$i - 1] ?? '';
                if ($previous === 'distinct' || in_array($frames[$top]['fn'], self::FROM_FUNCTIONS, true)) {
                    continue;
                }
                $frames[$top]['from'] = true;
                $i = $this->checkRelation($tokens, $i + 1, $ctes);

                continue;
            }
            if ($token === 'join' || ($token === ',' && $frames[$top]['from'])) {
                $i = $this->checkRelation($tokens, $i + 1, $ctes);

                continue;
            }
            if (in_array($token, self::CLAUSE_ENDS, true)) {
                $frames[$top]['from'] = false;
            }
        }
        if (count($frames) !== 1) {
            throw new ReadQueryRefused('parenthesis', 'The parentheses do not match.');
        }
    }

    /**
     * Checks the relation starting at $i and returns the index of its last token, so the scan continues after it
     * (a subquery or table function returns before its "(" so the scan enters it).
     *
     * @param  list<string>  $tokens
     * @param  list<string>  $ctes
     */
    private function checkRelation(array $tokens, int $i, array $ctes): int
    {
        while (in_array($tokens[$i] ?? '', ['lateral', 'only'], true)) {
            $i++;
        }
        $token = $tokens[$i] ?? '';
        $next = $tokens[$i + 1] ?? '';

        if ($token === '(') {
            return $i - 1;
        }
        if ($token === 'ai_read' && $next === '.') {
            $name = $tokens[$i + 2] ?? '';
            if (! in_array($name, $this->views, true)) {
                throw new ReadQueryRefused('unknown_view', 'There is no view ai_read.'.$name.'. Run kpone:read-schema.');
            }

            return $i + 2;
        }
        if ($next === '.') {
            throw new ReadQueryRefused('outside_ai_read', 'Only views of schema ai_read may be read.');
        }
        if ($next === '(') {
            if (! in_array($token, self::FROM_TABLE_FUNCTIONS, true)) {
                throw new ReadQueryRefused('table_function', 'This function cannot be used as a table: '.$token.'.');
            }

            return $i;
        }
        if (in_array($token, $this->views, true) || in_array($token, $ctes, true)) {
            return $i;
        }

        throw new ReadQueryRefused('unknown_view', 'There is no view '.($token === '' ? '(missing)' : $token).' in ai_read. Run kpone:read-schema.');
    }

    /**
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function cteNames(array $tokens): array
    {
        $names = [];
        $count = count($tokens);
        for ($i = 1; $i < $count; $i++) {
            if ($tokens[$i] !== 'as') {
                continue;
            }
            $next = $i + 1;
            while (in_array($tokens[$next] ?? '', ['not', 'materialized'], true)) {
                $next++;
            }
            if (($tokens[$next] ?? '') !== '(') {
                continue;
            }
            $name = $tokens[$i - 1];
            if ($name === ')') {
                // name (col, ...) AS (...)
                $depth = 0;
                for ($k = $i - 1; $k >= 0; $k--) {
                    $depth += $tokens[$k] === ')' ? 1 : ($tokens[$k] === '(' ? -1 : 0);
                    if ($depth === 0) {
                        $name = $tokens[$k - 1] ?? '';

                        break;
                    }
                }
            }
            if (preg_match('/^[a-z_][a-z0-9_]*$/', $name) === 1) {
                $names[] = $name;
            }
        }

        return $names;
    }
}

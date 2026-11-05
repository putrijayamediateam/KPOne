<?php

namespace App\Console\Commands;

use App\Domain\Agents\AskKpone\AiReadQueryRunner;
use App\Domain\Agents\AskKpone\ReadQueryRefused;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * AG-7 Ask KPOne: runs one SELECT against the masked ai_read views and prints at most 500 rows.
 */
class ReadKpone extends Command
{
    protected $signature = 'kpone:read
        {sql : One SELECT or WITH statement against the ai_read views}
        {--json : Print the rows as JSON instead of a table}';

    protected $description = 'Ask KPOne: run one read-only SELECT against the masked ai_read views (at most 500 rows).';

    public function handle(AiReadQueryRunner $runner): int
    {
        try {
            $result = $runner->run((string) $this->argument('sql'));
        } catch (ReadQueryRefused $refused) {
            $this->error('Refused: '.$refused->getMessage());

            return self::FAILURE;
        } catch (RuntimeException $failure) {
            $this->error($failure->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'rows' => $result['rows'],
                'row_count' => count($result['rows']),
                'truncated' => $result['truncated'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($result['rows'] === []) {
            $this->line('No rows.');
        } else {
            $this->table($result['columns'], array_map(
                fn (array $row): array => array_map(fn (mixed $value): string => $value === null ? 'NULL' : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value), $row),
                $result['rows'],
            ));
        }
        $this->line(count($result['rows']).' row(s)'.($result['truncated'] ? '; more rows exist (only the first '.AiReadQueryRunner::ROW_LIMIT.' are shown)' : '').'.');

        return self::SUCCESS;
    }
}

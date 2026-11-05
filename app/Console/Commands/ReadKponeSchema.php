<?php

namespace App\Console\Commands;

use App\Domain\Agents\AskKpone\AiReadQueryRunner;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * AG-7 Ask KPOne: lists the masked ai_read views and their columns with a short description.
 */
class ReadKponeSchema extends Command
{
    protected $signature = 'kpone:read-schema {--json : Print the schema as JSON}';

    protected $description = 'Ask KPOne: list the masked ai_read views and their columns.';

    public function handle(AiReadQueryRunner $runner): int
    {
        try {
            $views = $runner->schema();
        } catch (RuntimeException $failure) {
            $this->error($failure->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($views, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('Masked read-only views in schema ai_read. Amounts ending in _sen are in sen (100 sen = RM 1).');
        foreach ($views as $view) {
            $this->newLine();
            $this->line('<info>'.$view['view'].'</info> — '.($view['description'] ?? ''));
            foreach ($view['columns'] as $column) {
                $this->line('  '.$column['name'].' ('.$column['type'].')'.($column['description'] ? ' — '.$column['description'] : ''));
            }
        }

        return self::SUCCESS;
    }
}

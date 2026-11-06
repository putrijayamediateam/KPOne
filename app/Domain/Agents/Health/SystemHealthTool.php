<?php

namespace App\Domain\Agents\Health;

use App\Domain\Agents\Tools\AgentTool;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * `system.health` (AG-10, contract section 5): counts only. Error-level application log entries since a time
 * (only each line's timestamp and level are read; log text is never kept or returned), failed scheduled agent
 * runs, pending database migrations and failed queue jobs when that table exists. Registered by tagging in
 * AppServiceProvider, so AgentToolRegistry is unchanged.
 */
final class SystemHealthTool implements AgentTool
{
    public const LEVELS = ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    /** At most this many bytes are read from the end of each log file, so a huge log never stalls a run. */
    public const MAX_BYTES_PER_FILE = 8 * 1024 * 1024;

    public const DEFAULT_LOOKBACK_HOURS = 24;

    /**
     * Laravel's Monolog line start: `[2026-11-06 03:12:01] production.ERROR: …`, optionally with fractions and a
     * UTC offset. Only the timestamp, offset and level groups are used.
     */
    private const LINE = '/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})(?:\.\d+)?([+-]\d{2}:?\d{2}|Z)?\]\s+[\w.-]+\.(ERROR|CRITICAL|ALERT|EMERGENCY):/';

    public function __construct(private ?string $logDirectory = null) {}

    public function code(): string
    {
        return 'system.health';
    }

    public function requiredPermission(): string
    {
        return 'ai_agents.view.organisation';
    }

    public function inputSchema(): array
    {
        return ['since' => ['sometimes', 'date']];
    }

    public function run(User $requester, ?Branch $branch, array $input): array
    {
        $now = CarbonImmutable::now('UTC');
        $since = isset($input['since']) ? CarbonImmutable::parse((string) $input['since'])->utc() : $now->subHours(self::DEFAULT_LOOKBACK_HOURS);
        $since = $since->greaterThan($now) ? $now : $since;

        $log = $this->countLogErrors($since);
        $failedRuns = DB::table('ai_agent_runs')->where('organisation_id', $requester->organisation_id)->where('status', 'failed')
            ->whereNull('requested_by_user_id')->where('finished_at', '>=', $since)->count();
        $pending = $this->pendingMigrations();
        // failed_jobs has no organisation; KPOne runs one organisation per installation.
        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->where('failed_at', '>=', $since)->count() : null;

        return [
            'since' => $since->toIso8601ZuluString(),
            'logFiles' => $log['files'],
            'logReadLimited' => $log['limited'],
            'errorLogEntries' => $log['counts'],
            'failedScheduledRuns' => $failedRuns,
            'pendingMigrations' => $pending,
            'failedQueueJobs' => $failedJobs,
            // A missing or unreadable log is never "all clear": the errors may simply not be visible.
            'allClear' => in_array($log['files'], ['found', 'none_since'], true) && $log['counts']['total'] === 0
                && $failedRuns === 0 && $pending === 0 && ($failedJobs ?? 0) === 0,
        ];
    }

    /** @return array{files: string, limited: bool, counts: array{error: int, critical: int, alert: int, emergency: int, total: int}} */
    private function countLogErrors(CarbonImmutable $since): array
    {
        $counts = ['error' => 0, 'critical' => 0, 'alert' => 0, 'emergency' => 0, 'total' => 0];
        $directory = $this->logDirectory ?? dirname((string) config('logging.channels.single.path', storage_path('logs/laravel.log')));
        $files = is_dir($directory) ? (glob($directory.DIRECTORY_SEPARATOR.'laravel*.log') ?: []) : [];
        $files = array_values(array_filter($files, fn (string $file): bool => is_file($file) && (int) filemtime($file) >= $since->getTimestamp()));
        if ($files === []) {
            return ['files' => is_dir($directory) && glob($directory.DIRECTORY_SEPARATOR.'laravel*.log') ? 'none_since' : 'missing', 'limited' => false, 'counts' => $counts];
        }

        $limited = false;
        $timezone = (string) config('app.timezone', 'UTC');
        foreach ($files as $file) {
            $handle = @fopen($file, 'rb');
            if ($handle === false) {
                return ['files' => 'unreadable', 'limited' => $limited, 'counts' => $counts];
            }
            $size = (int) filesize($file);
            if ($size > self::MAX_BYTES_PER_FILE) {
                $limited = true;
                fseek($handle, $size - self::MAX_BYTES_PER_FILE);
                fgets($handle); // drop the partial first line
            }
            $atLineStart = true;
            while (($line = fgets($handle, 65536)) !== false) {
                // A line longer than the buffer arrives in chunks; only a chunk that starts a line can be a header.
                $startsLine = $atLineStart;
                $atLineStart = str_ends_with($line, "\n");
                if (! $startsLine || $line === '' || $line[0] !== '[' || ! preg_match(self::LINE, $line, $match)) {
                    continue;
                }
                // Only the timestamp, offset and level leave this loop; the line itself is discarded. Without an
                // offset, Monolog wrote the time in the application time zone.
                $offset = $match[2] === '' ? null : $match[2];
                $at = $offset === null ? CarbonImmutable::parse($match[1], $timezone) : CarbonImmutable::parse($match[1].$offset);
                if ($at->lessThan($since)) {
                    continue;
                }
                match ($match[3]) {
                    'ERROR' => $counts['error']++,
                    'CRITICAL' => $counts['critical']++,
                    'ALERT' => $counts['alert']++,
                    default => $counts['emergency']++,
                };
                $counts['total']++;
            }
            fclose($handle);
        }

        return ['files' => 'found', 'limited' => $limited, 'counts' => $counts];
    }

    private function pendingMigrations(): ?int
    {
        try {
            /** @var Migrator $migrator */
            $migrator = app('migrator');
            if (! $migrator->repositoryExists()) {
                return null;
            }
            $files = $migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]);

            return count(array_diff(array_keys($files), $migrator->getRepository()->getRan()));
        } catch (Throwable) {
            return null;
        }
    }
}

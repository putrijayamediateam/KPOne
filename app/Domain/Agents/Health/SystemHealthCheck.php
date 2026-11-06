<?php

namespace App\Domain\Agents\Health;

/** The result of one system health check by Faris: counts for the daily summary's one health line. */
final readonly class SystemHealthCheck
{
    public function __construct(
        public string $since,
        public int $errorLogEntries,
        public int $failedScheduledRuns,
        public ?int $pendingMigrations,
        public ?int $failedQueueJobs,
        public string $logFiles,
        public bool $logReadLimited,
        public bool $allClear,
    ) {}

    /** @param array<string, mixed> $read the `system.health` result */
    public static function fromRead(array $read): self
    {
        $counts = (array) $read['errorLogEntries'];

        return new self(
            since: (string) $read['since'],
            errorLogEntries: (int) ($counts['total'] ?? 0),
            failedScheduledRuns: (int) $read['failedScheduledRuns'],
            pendingMigrations: $read['pendingMigrations'] === null ? null : (int) $read['pendingMigrations'],
            failedQueueJobs: $read['failedQueueJobs'] === null ? null : (int) $read['failedQueueJobs'],
            logFiles: (string) $read['logFiles'],
            logReadLimited: (bool) $read['logReadLimited'],
            allClear: (bool) $read['allClear'],
        );
    }

    /** The fixed-template line: "All clear." or the counts. Never contains log text. */
    public function summary(): string
    {
        if ($this->allClear) {
            return 'All clear.';
        }
        $plural = fn (int $n, string $one, string $many): string => $n.' '.($n === 1 ? $one : $many);
        $log = match ($this->logFiles) {
            'missing' => 'log file not found',
            'unreadable' => 'log file unreadable',
            default => ($this->logReadLimited ? 'at least ' : '').$plural($this->errorLogEntries, 'error log entry', 'error log entries'),
        };
        $parts = [
            $log,
            $plural($this->failedScheduledRuns, 'failed scheduled agent run', 'failed scheduled agent runs'),
            $this->pendingMigrations === null ? 'pending migrations unknown' : $plural($this->pendingMigrations, 'pending migration', 'pending migrations'),
        ];
        if ($this->failedQueueJobs !== null) {
            $parts[] = $plural($this->failedQueueJobs, 'failed queue job', 'failed queue jobs');
        }

        return implode(', ', $parts).'.';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'since' => $this->since,
            'errorLogEntries' => $this->errorLogEntries,
            'failedScheduledRuns' => $this->failedScheduledRuns,
            'pendingMigrations' => $this->pendingMigrations,
            'failedQueueJobs' => $this->failedQueueJobs,
            'logFiles' => $this->logFiles,
            'logReadLimited' => $this->logReadLimited,
            'allClear' => $this->allClear,
            'summary' => $this->summary(),
        ];
    }
}

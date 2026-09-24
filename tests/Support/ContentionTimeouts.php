<?php

namespace Tests\Support;

/**
 * Single source of truth for the timing budgets used by the PostgreSQL
 * contention regression tests (tests/Feature/Postgres*RegressionTest.php).
 *
 * These tests spawn worker subprocesses and poll for a READY signal, then
 * for further protocol milestones (a named stdout string, or a specific
 * pg_stat_activity blocking relationship) before giving up. The previous
 * per-file hardcoded deadlines (10-12s) left too little margin on a loaded
 * Windows development machine, causing a worker that was merely slow to
 * start to be reported as failed.
 */
final class ContentionTimeouts
{
    private const DEFAULT_READY_TIMEOUT_SECONDS = 45;

    private const DEFAULT_PROTOCOL_TIMEOUT_SECONDS = 45;

    private const PROCESS_TIMEOUT_MULTIPLIER = 3;

    /**
     * How long to wait for a freshly spawned worker to report its initial
     * READY signal. Overridable via KPONE_CONTENTION_READY_TIMEOUT.
     */
    public static function readyTimeoutSeconds(): int
    {
        return self::readEnvSeconds('KPONE_CONTENTION_READY_TIMEOUT', self::DEFAULT_READY_TIMEOUT_SECONDS);
    }

    /**
     * How long to wait for any later protocol milestone: a named stdout
     * string, or an expected PostgreSQL blocking relationship. Overridable
     * via KPONE_CONTENTION_PROTOCOL_TIMEOUT.
     */
    public static function protocolTimeoutSeconds(): int
    {
        return self::readEnvSeconds('KPONE_CONTENTION_PROTOCOL_TIMEOUT', self::DEFAULT_PROTOCOL_TIMEOUT_SECONDS);
    }

    /**
     * The Symfony Process timeout applied to each worker. Enforced to be at
     * least 3x every wait budget the worker might be held to, so the
     * process is never killed by its own timeout before a wait loop above
     * it has had a chance to give up first.
     */
    public static function processTimeoutSeconds(): int
    {
        return max(
            self::readyTimeoutSeconds() * self::PROCESS_TIMEOUT_MULTIPLIER,
            self::protocolTimeoutSeconds() * self::PROCESS_TIMEOUT_MULTIPLIER,
        );
    }

    private static function readEnvSeconds(string $name, int $default): int
    {
        $value = env($name);

        if ($value === null || $value === '') {
            return $default;
        }

        $seconds = (int) $value;

        return $seconds > 0 ? $seconds : $default;
    }
}

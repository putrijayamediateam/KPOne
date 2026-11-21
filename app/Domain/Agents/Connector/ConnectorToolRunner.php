<?php

namespace App\Domain\Agents\Connector;

use App\Domain\Access\BranchAccessService;
use App\Domain\Agents\AgentPermissions;
use App\Domain\Agents\Models\AiAgentRun;
use App\Domain\Agents\Services\AgentRunRecorder;
use App\Domain\Agents\Tools\AgentToolGateway;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Runs one connector tool call (docs/agents/MCP-CONNECTOR-DESIGN.md sections 2, 6 and 7): one short run of the
 * registered "Claude connector" agent, so AgentToolGateway does the permission, branch, input and personal-data checks
 * and the run and step log shows the call. Adds what the connector needs on top: the daily limit, a 10-second statement
 * timeout, the 64 KB result cap and one audit row per call. The audit row holds the tool, branch codes, the outcome and
 * a row count, never the returned figures. Nothing here writes clinic data.
 */
final class ConnectorToolRunner
{
    public const DAILY_LIMIT = 500;

    public const MAX_BYTES = 65536;

    public const STATEMENT_TIMEOUT_MS = 10000;

    public function __construct(
        private AgentRunRecorder $recorder,
        private AgentToolGateway $gateway,
        private AuditRecorder $audit,
        private BranchAccessService $branches,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ConnectorRefused
     */
    public function run(User $user, string $toolCode, array $input = [], ?string $branchCode = null): array
    {
        $branch = null;
        $limitKey = 'connector-day:'.$user->id;
        if (RateLimiter::tooManyAttempts($limitKey, self::DAILY_LIMIT)) {
            $this->audit($user, $toolCode, null, 'refused', 'rate_limited_day');
            throw new ConnectorRefused('rate_limited_day', 'The daily limit of connector calls was reached. Try again tomorrow.');
        }
        RateLimiter::hit($limitKey, 86400);

        if ($branchCode !== null) {
            $branch = Branch::query()->where('organisation_id', $user->organisation_id)->where('code', $branchCode)->first();
            if (! $branch || ! $this->branches->hasEffectiveAssignment($user, $branch)) {
                $this->audit($user, $toolCode, null, 'refused', 'branch_not_allowed');
                throw new ConnectorRefused('branch_not_allowed', 'That branch is not available to you.');
            }
        }

        try {
            $agent = ConnectorAgent::ensure($user->organisation_id);
            $run = $this->recorder->start($agent, 'connector_call', null, $user, AgentPermissions::CONNECTOR_USE);
        } catch (AuthorizationException|ValidationException) {
            $this->audit($user, $toolCode, $branch, 'refused', 'agent_unavailable');
            throw new ConnectorRefused('agent_unavailable', 'The connector is not available right now.');
        }

        try {
            $result = DB::transaction(function () use ($run, $toolCode, $user, $branch, $input): array {
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement('SET LOCAL statement_timeout = '.self::STATEMENT_TIMEOUT_MS);
                }

                return $this->gateway->call($run, $toolCode, $user, $branch, $input);
            });
            if (strlen((string) json_encode($result)) > self::MAX_BYTES) {
                throw new ConnectorRefused('result_too_large', 'The answer was too large to send.');
            }
        } catch (ConnectorRefused $refused) {
            $this->finish($run, $user, $toolCode, $branch, 'refused', $refused->reason);
            throw $refused;
        } catch (AuthorizationException) {
            $this->finish($run, $user, $toolCode, $branch, 'refused', 'not_allowed');
            throw new ConnectorRefused('not_allowed', 'You are not allowed to see this.');
        } catch (ValidationException) {
            $this->finish($run, $user, $toolCode, $branch, 'refused', 'invalid_input');
            throw new ConnectorRefused('invalid_input', 'The request was not valid.');
        } catch (Throwable $exception) {
            report($exception);
            $this->finish($run, $user, $toolCode, $branch, 'error', 'data_source_failed');
            throw new ConnectorRefused('data_source_failed', 'The figures could not be read right now.');
        }

        $this->recorder->complete($run);
        // The audit row comes first: if it cannot be written, nothing is returned.
        abort_if($this->audit($user, $toolCode, $branch, 'ok', null, $this->rows($result)) === null, 503, 'Unable to record this call. Try again later.');

        return $result;
    }

    private function finish(AiAgentRun $run, User $user, string $toolCode, ?Branch $branch, string $outcome, string $reason): void
    {
        $this->recorder->fail($run, 'connector.'.$reason);
        $this->audit($user, $toolCode, $branch, $outcome, $reason);
    }

    /** @param array<string, mixed> $result */
    private function rows(array $result): int
    {
        $rows = 0;
        foreach ($result as $value) {
            if (is_array($value) && array_is_list($value)) {
                $rows += count($value);
            }
        }

        return $rows;
    }

    private function audit(User $user, string $toolCode, ?Branch $branch, string $outcome, ?string $reason, int $rows = 0): mixed
    {
        return $this->audit->record('ai_connector.tool_called', null, array_filter([
            'tool' => $toolCode,
            'branch_code' => $branch?->code,
            'outcome' => $outcome,
            'reason' => $reason,
            'rows' => $rows,
        ], fn (mixed $value): bool => $value !== null), $user, $branch, $user->organisation_id);
    }
}

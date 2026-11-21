<?php

namespace App\Domain\Agents\Connector;

use App\Domain\Access\BranchAccessService;
use App\Domain\Agents\AgentPermissions;
use App\Domain\Agents\Models\AiAgentRun;
use App\Domain\Agents\Services\AgentRunRecorder;
use App\Domain\Agents\Tools\AgentToolGateway;
use App\Domain\Agents\Tools\AggregatePayloadGuard;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
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
        private AggregatePayloadGuard $guard,
    ) {}

    /**
     * One data source with no branch of its own (`list_branches`).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ConnectorRefused
     */
    public function run(User $user, string $toolCode, array $input = [], ?string $branchCode = null): array
    {
        return $this->execute($user, $toolCode, $branchCode, [], fn (AiAgentRun $run, ?Branch $branch): array => $this->gateway->call($run, $toolCode, $user, $branch, $input));
    }

    /**
     * A connector question (CN-2): validate the input, then run the query once for each branch the person works at (or the
     * one asked for), or once with no branch for an organisation-wide query. Every read still goes through the gateway.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ConnectorRefused
     */
    public function runQuery(User $user, ConnectorQuery $query, array $input = []): array
    {
        $tool = 'connector.'.$query->name();
        $params = array_intersect_key($input, array_flip(['date', 'from', 'to']));

        return $this->execute($user, $tool, is_string($input['branch'] ?? null) ? $input['branch'] : null, $params, function (AiAgentRun $run, ?Branch $branch) use ($user, $query, $input): array {
            $valid = Validator::make($input, ['branch' => ['sometimes', 'nullable', 'string', 'max:30'], ...$query->rules()])->validate();
            $reads = new ConnectorReads($this->gateway, $run, $user);
            $result = ['asOf' => now()->toIso8601ZuluString(), 'definitions' => $query->definitions()];
            if ($query->organisationWide()) {
                return [...$result, ...$query->run($reads, null, $valid)];
            }
            $branches = $branch ? collect([$branch]) : $this->branches->availableBranches($user)->filter(fn (Branch $each): bool => $this->branches->hasEffectiveAssignment($user, $each))->sortBy('code')->values();
            $entries = [];
            foreach ($branches as $each) {
                $entries[] = $query->run($reads, $each, $valid);
            }

            return [...$result, 'branches' => $entries];
        });
    }

    /**
     * @param  array<string, mixed>  $params  date parameters only, for the audit row
     * @param  Closure(AiAgentRun, ?Branch): array<string, mixed>  $work
     * @return array<string, mixed>
     *
     * @throws ConnectorRefused
     */
    private function execute(User $user, string $toolCode, ?string $branchCode, array $params, Closure $work): array
    {
        $branch = null;
        $limitKey = 'connector-day:'.$user->id;
        if (RateLimiter::hit($limitKey, 86400) > self::DAILY_LIMIT) {
            $this->audit($user, $toolCode, null, 'refused', 'rate_limited_day', 0, $params);
            throw new ConnectorRefused('rate_limited_day', 'The daily limit of connector calls was reached. Try again tomorrow.');
        }

        if ($branchCode !== null) {
            $branch = Branch::query()->where('organisation_id', $user->organisation_id)->where('code', $branchCode)->first();
            if (! $branch || ! $this->branches->hasEffectiveAssignment($user, $branch)) {
                $this->audit($user, $toolCode, null, 'refused', 'branch_not_allowed', 0, $params);
                throw new ConnectorRefused('branch_not_allowed', 'That branch is not available to you.');
            }
        }

        try {
            $agent = ConnectorAgent::ensure($user->organisation_id);
            $run = $this->recorder->start($agent, 'connector_call', null, $user, AgentPermissions::CONNECTOR_USE);
        } catch (AuthorizationException|ValidationException) {
            $this->audit($user, $toolCode, $branch, 'refused', 'agent_unavailable', 0, $params);
            throw new ConnectorRefused('agent_unavailable', 'The connector is not available right now.');
        }

        try {
            $result = DB::transaction(function () use ($run, $branch, $work): array {
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement('SET LOCAL statement_timeout = '.self::STATEMENT_TIMEOUT_MS);
                }

                return $work($run, $branch);
            });
            $this->guard->assertAggregate($result);
            if (strlen((string) json_encode($result)) > self::MAX_BYTES) {
                throw new ConnectorRefused('result_too_large', 'The answer was too large to send.');
            }
        } catch (ConnectorRefused $refused) {
            $this->finish($run, $user, $toolCode, $branch, 'refused', $refused->reason, $params);
            throw $refused;
        } catch (AuthorizationException) {
            $this->finish($run, $user, $toolCode, $branch, 'refused', 'not_allowed', $params);
            throw new ConnectorRefused('not_allowed', 'You are not allowed to see this.');
        } catch (ValidationException $invalid) {
            $this->finish($run, $user, $toolCode, $branch, 'refused', 'invalid_input', $params);
            throw new ConnectorRefused('invalid_input', 'The request was not valid: '.mb_substr((string) $invalid->validator->errors()->first(), 0, 200));
        } catch (Throwable $exception) {
            report($exception);
            $this->finish($run, $user, $toolCode, $branch, 'error', 'data_source_failed', $params);
            throw new ConnectorRefused('data_source_failed', 'The figures could not be read right now.');
        }

        $this->recorder->complete($run);
        // The audit row comes first: if it cannot be written, nothing is returned.
        abort_if($this->audit($user, $toolCode, $branch, 'ok', null, $this->rows($result), $params) === null, 503, 'Unable to record this call. Try again later.');

        return $result;
    }

    /** @param array<string, mixed> $params */
    private function finish(AiAgentRun $run, User $user, string $toolCode, ?Branch $branch, string $outcome, string $reason, array $params): void
    {
        $this->recorder->fail($run, 'connector.'.$reason);
        $this->audit($user, $toolCode, $branch, $outcome, $reason, 0, $params);
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

    /** @param array<string, mixed> $params */
    private function audit(User $user, string $toolCode, ?Branch $branch, string $outcome, ?string $reason, int $rows = 0, array $params = []): mixed
    {
        return $this->audit->record('ai_connector.tool_called', null, array_filter([
            'tool' => $toolCode,
            'branch_code' => $branch?->code,
            'dates' => $params === [] ? null : array_map(fn (mixed $value): string => mb_substr((string) $value, 0, 10), $params),
            'outcome' => $outcome,
            'reason' => $reason,
            'rows' => $rows,
        ], fn (mixed $value): bool => $value !== null), $user, $branch, $user->organisation_id);
    }
}

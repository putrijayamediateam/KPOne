<?php

namespace App\Domain\Agents\Tools;

use App\Domain\Organisation\Models\Branch;
use App\Models\User;

/**
 * A read-only, aggregate data source an AI agent may read (docs/agents/CONTRACT.md section 5).
 *
 * Implementations never change data and return totals, counts and catalogue names only. Call them
 * through AgentToolGateway, which checks the requester's permission and branch and the agent's
 * allowlist before run() is reached, and scans the result for personal-data keys afterwards.
 */
interface AgentTool
{
    /** The tool code, e.g. `inventory.stock_review`. */
    public function code(): string;

    /** The requester's permission. A `.branch` permission also needs a branch the requester works at. */
    public function requiredPermission(): string;

    /** @return array<string, mixed> Laravel validation rules for the input. */
    public function inputSchema(): array;

    /**
     * @param  array<string, mixed>  $input  already validated against inputSchema()
     * @return array<string, mixed>
     */
    public function run(User $requester, ?Branch $branch, array $input): array;
}

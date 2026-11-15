<?php

namespace App\Domain\Agents\Tools;

use App\Domain\Organisation\Models\Branch;

/**
 * A data source a scheduled ("system") agent run may read (contract section 5): its figures depend only on the
 * organisation and branch, never on a person's own permissions. AgentToolGateway::callAsSystem() reads only
 * tools that implement this. run() for a person and runForOrganisation() return the same result.
 */
interface SystemReadableTool extends AgentTool
{
    /**
     * @param  array<string, mixed>  $input  already validated against inputSchema()
     * @return array<string, mixed>
     */
    public function runForOrganisation(int $organisationId, ?Branch $branch, array $input): array;
}

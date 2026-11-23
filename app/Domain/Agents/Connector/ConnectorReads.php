<?php

namespace App\Domain\Agents\Connector;

use App\Domain\Agents\Models\AiAgentRun;
use App\Domain\Agents\Tools\AgentToolGateway;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;

/** What a ConnectorQuery may do: read one agent data source for the signed-in person, inside the call's own run. */
final class ConnectorReads
{
    public function __construct(private AgentToolGateway $gateway, private AiAgentRun $run, private User $user) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function call(string $source, ?Branch $branch, array $input = []): array
    {
        return $this->gateway->call($this->run, $source, $this->user, $branch, $input);
    }
}

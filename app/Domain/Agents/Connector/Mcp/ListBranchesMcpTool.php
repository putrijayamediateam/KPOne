<?php

namespace App\Domain\Agents\Connector\Mcp;

use App\Domain\Agents\Connector\ConnectorRefused;
use App\Domain\Agents\Connector\ConnectorToolRunner;
use App\Domain\Agents\Connector\ListBranchesTool;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_branches')]
#[Description('Lists the KPOne clinic branches you work at (code and label). Read-only.')]
#[IsReadOnly]
final class ListBranchesMcpTool extends Tool
{
    public function handle(Request $request, ConnectorToolRunner $runner, ListBranchesTool $tool): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return Response::error('Sign in again.');
        }

        try {
            return Response::json($runner->run($user, $tool->code()));
        } catch (ConnectorRefused $refused) {
            return Response::error($refused->getMessage());
        }
    }
}

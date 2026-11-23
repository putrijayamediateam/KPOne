<?php

namespace App\Domain\Agents\Connector\Mcp;

use App\Domain\Agents\Connector\ConnectorQuery;
use App\Domain\Agents\Connector\ConnectorRefused;
use App\Domain\Agents\Connector\ConnectorToolRunner;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/** A connector question exposed as an MCP tool. Everything is checked, run and audited by ConnectorToolRunner. */
abstract class QueryMcpTool extends Tool
{
    abstract protected function query(): ConnectorQuery;

    public function handle(Request $request, ConnectorToolRunner $runner): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return Response::error('Sign in again.');
        }

        try {
            return Response::json($runner->runQuery($user, $this->query(), $request->all()));
        } catch (ConnectorRefused $refused) {
            return Response::error($refused->getMessage());
        }
    }

    /** @return array<string, Type> */
    protected function branchField(JsonSchema $schema): array
    {
        return ['branch' => $schema->string()->description('Branch code from list_branches (for example CHERAS). Leave out for every branch you work at.')];
    }

    /** @return array<string, Type> */
    protected function dateField(JsonSchema $schema): array
    {
        return ['date' => $schema->string()->description('Branch-local date, YYYY-MM-DD. Leave out for today.')];
    }

    /** @return array<string, Type> */
    protected function rangeFields(JsonSchema $schema, string $default): array
    {
        return [
            'from' => $schema->string()->description('First date, YYYY-MM-DD. '.$default),
            'to' => $schema->string()->description('Last date, YYYY-MM-DD. At most 31 days from the first date.'),
        ];
    }
}

<?php

namespace App\Domain\Agents\Chat;

use App\Domain\Agents\Connector\ConnectorQuery;
use App\Domain\Agents\Connector\ConnectorRefused;
use App\Domain\Agents\Connector\ConnectorToolRunner;
use App\Domain\Agents\Connector\ConnectorTools;
use App\Models\User;

/**
 * The chatbox's tools are the connector's fixed read-only questions (docs/agents/AI-CHATBOX-DESIGN.md section 5), run through the
 * same ConnectorToolRunner, so a question gets the same agent allowlist, permission, branch, validation and personal-data checks,
 * the same audit row and the same numbers as the connector and the report pages. The chatbox adds no data source and no write.
 */
final class ChatTools
{
    public const LIST_BRANCHES = 'list_branches';

    public function __construct(private ConnectorToolRunner $runner) {}

    /** @return list<array{name: string, description: string, input_schema: array<string, mixed>}> */
    public function definitions(): array
    {
        $tools = [[
            'name' => self::LIST_BRANCHES,
            'description' => 'The branch codes and names the person may ask about.',
            'input_schema' => ['type' => 'object', 'properties' => (object) []],
        ]];
        foreach ($this->queries() as $query) {
            $properties = [];
            if (! $query->organisationWide()) {
                $properties['branch'] = ['type' => 'string', 'description' => 'A branch code from list_branches. Leave out for every branch the person works at.'];
            }
            foreach (array_keys($query->rules()) as $field) {
                $properties[$field] = ['type' => 'string', 'description' => 'A date as YYYY-MM-DD in the branch time zone.'];
            }
            $tools[] = [
                'name' => $query->name(),
                'description' => mb_substr($query->definitions(), 0, 600),
                'input_schema' => ['type' => 'object', 'properties' => $properties === [] ? (object) [] : $properties],
            ];
        }

        return $tools;
    }

    /** @return array<string, ConnectorQuery> */
    private function queries(): array
    {
        $queries = [];
        foreach (ConnectorTools::queries() as $class) {
            $query = app($class);
            $queries[$query->name()] = $query;
        }

        return $queries;
    }

    /**
     * Runs one tool the model asked for. A refusal is returned as a plain error for the model to relay; nothing is thrown.
     *
     * @param  array<string, mixed>  $input
     * @return array{result: array<string, mixed>|null, error: string|null}
     */
    public function execute(User $user, string $name, array $input): array
    {
        try {
            if ($name === self::LIST_BRANCHES) {
                return ['result' => $this->runner->run($user, 'connector.list_branches'), 'error' => null];
            }
            $query = $this->queries()[$name] ?? null;
            if (! $query) {
                return ['result' => null, 'error' => 'That question is not available.'];
            }

            return ['result' => $this->runner->runQuery($user, $query, $input), 'error' => null];
        } catch (ConnectorRefused $refused) {
            return ['result' => null, 'error' => $refused->getMessage()];
        }
    }
}

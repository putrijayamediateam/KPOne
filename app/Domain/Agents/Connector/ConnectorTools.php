<?php

namespace App\Domain\Agents\Connector;

use App\Domain\Agents\Tools\AgentTool;

/** The connector's data sources. CN-1 has one; CN-2 and CN-3 add the rest (design note section 6). */
final class ConnectorTools
{
    /** @return list<class-string<AgentTool>> */
    public static function classes(): array
    {
        return [ListBranchesTool::class];
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_map(fn (string $class): string => app($class)->code(), self::classes());
    }
}

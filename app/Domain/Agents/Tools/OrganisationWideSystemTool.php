<?php

namespace App\Domain\Agents\Tools;

/**
 * A system-readable data source that may be read for the whole organisation, with no branch (AG-12). Only a
 * branchless scheduled run may read it that way: AgentToolGateway::callAsSystem() accepts a null branch only for a
 * tool that implements this marker, instead of guessing from the tool's permission name. Today only
 * `system.health` (Faris) is organisation-wide; every other system read is for one branch.
 */
interface OrganisationWideSystemTool extends SystemReadableTool {}

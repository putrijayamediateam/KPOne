<?php

namespace App\Domain\Agents;

/**
 * Agent platform wave 1 permissions (docs/agents/CONTRACT.md section 4). None of these is people or
 * access authority, so none belongs in PermissionCatalogue::AUTHORITY_OVER_PEOPLE_AND_ACCESS.
 */
final class AgentPermissions
{
    public const VIEW = 'ai_agents.view.organisation';

    public const MANAGE = 'ai_agents.manage.organisation';

    public const ASSISTANT = 'ai_assistant.use.organisation';

    public const PROPOSALS_VIEW = 'ai_proposals.view.branch';

    public const DAILY_SUMMARY_VIEW = 'ai_daily_summary.view.branch';

    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [
            self::VIEW => ['director', 'administrator', 'technical_admin'],
            self::MANAGE => ['director'],
            self::ASSISTANT => ['director'],
            self::PROPOSALS_VIEW => ['director', 'ca_supervisor', 'finance_officer'],
            self::DAILY_SUMMARY_VIEW => ['director', 'ca_supervisor', 'finance_officer', 'administrator'],
        ];
    }
}

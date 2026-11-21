<?php

namespace App\Domain\Agents\Registry;

/**
 * The agents of docs/agents/CONTRACT.md section 8: one list, shared by the local and testing seeder and by the
 * `kpone:register-agents` command (AG-36), so the two cannot drift. Names are the owner's choice (kept, 2026-11-21);
 * every title ends in "(AI agent)".
 */
final class AgentCatalogue
{
    /** @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: list<string>}> code => [name, title, department code, kind, tools] */
    public const AGENTS = [
        'director_assistant' => ["Dato' Arif", 'Chief of Staff (AI agent)', 'LEADERSHIP', 'runtime', ['insights.today', 'insights.sales_summary', 'inventory.stock_review', 'clinic.day_summary', 'money.day_summary', 'panel.claims_summary']],
        'inventory_analyst' => ['Hana', 'Inventory Analyst (AI agent)', 'CLINIC_OPERATIONS', 'runtime', ['inventory.stock_review', 'inventory.branch_balances', 'inventory.dispensing_activity']],
        'stocktake_officer' => ['Rizal', 'Stocktake Officer (AI agent)', 'CLINIC_OPERATIONS', 'runtime', ['inventory.stock_counts']],
        'report_assistant' => ['Lina', 'Management Assistant (AI agent)', 'LEADERSHIP', 'runtime', ['clinic.day_summary', 'money.day_summary', 'panel.claims_summary', 'inventory.stock_review']],
        'technical_admin_agent' => ['Faris', 'Technical Admin (AI agent)', 'TECHNOLOGY', 'runtime', ['system.health']],
        'qa_reviewer' => ['Nadia', 'QA Reviewer (AI agent)', 'TECHNOLOGY', 'build', []],
    ];
}

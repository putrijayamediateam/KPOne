<?php

namespace Database\Seeders;

use App\Domain\Agents\Models\AiAgent;
use App\Domain\Agents\Models\AiAgentTool;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Organisation;
use Illuminate\Database\Seeder;
use LogicException;

/**
 * AG-1: the wave 1 agents of docs/agents/CONTRACT.md section 8. Local and testing only (fictional
 * agent names), never a migration. Idempotent: it fills in missing agents and allowlist entries and
 * leaves an existing agent's enabled state and lock version alone.
 */
class KPOneAgentSeeder extends Seeder
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

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('The synthetic agent seeder is restricted to local and testing environments.');
        }

        $organisation = Organisation::query()->where('code', 'KLINIK_PUTRIJAYA')->firstOrFail();
        foreach (self::AGENTS as $code => [$name, $title, $department, $kind, $tools]) {
            $agent = AiAgent::query()->firstOrNew(['organisation_id' => $organisation->id, 'code' => $code]);
            if (! $agent->exists) {
                $agent->forceFill([
                    'display_name' => $name,
                    'job_title' => $title,
                    'department_id' => Department::query()->where('organisation_id', $organisation->id)->where('code', $department)->firstOrFail()->id,
                    'kind' => $kind,
                    'is_enabled' => true,
                    'lock_version' => 0,
                ])->save();
            }
            foreach ($tools as $tool) {
                AiAgentTool::query()->firstOrCreate(['ai_agent_id' => $agent->id, 'tool_code' => $tool]);
            }
        }
    }
}

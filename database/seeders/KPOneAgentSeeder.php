<?php

namespace Database\Seeders;

use App\Domain\Agents\Models\AiAgent;
use App\Domain\Agents\Models\AiAgentTool;
use App\Domain\Agents\Registry\AgentCatalogue;
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
    /** @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: list<string>}> code => [name, title, department code, kind, tools]; see AgentCatalogue */
    public const AGENTS = AgentCatalogue::AGENTS;

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

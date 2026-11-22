<?php

namespace App\Domain\Agents\Registry;

use App\Domain\Agents\Models\AiAgent;
use App\Domain\Agents\Models\AiAgentTool;
use App\Domain\Agents\Tools\AgentToolRegistry;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Organisation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Registers the contract's agents on a real server (AG-36, docs/agents/AGENT-REGISTRATION-DESIGN.md). Idempotent: it
 * fills in what is missing and never changes an existing agent's code, name, title, kind, enabled state or lock version.
 * New agents are created disabled unless `$enable`. A tool missing from an existing agent's allowlist is added only with
 * `$addMissingTools`; a tool is never removed. A dry run reads and reports but writes nothing. It touches only `ai_agents`
 * and `ai_agent_tools` (plus one audit row) and makes no AI service call.
 */
final class AgentRegistrar
{
    public function __construct(private AgentToolRegistry $tools, private AuditRecorder $audit) {}

    /**
     * @return array{created: list<string>, tools_added: array<string, list<string>>, tools_missing: array<string, list<string>>, unchanged: list<string>, enabled: bool}
     */
    public function register(bool $enable = false, bool $addMissingTools = false, bool $dryRun = false): array
    {
        $known = array_keys($this->tools->all());
        foreach (AgentCatalogue::AGENTS as $code => [, , , , $tools]) {
            foreach ($tools as $tool) {
                if (! in_array($tool, $known, true)) {
                    throw new RuntimeException("Agent {$code} names an unknown data source [{$tool}].");
                }
            }
        }

        $run = function () use ($enable, $addMissingTools, $dryRun): array {
            $organisations = Organisation::query()->where('code', 'KLINIK_PUTRIJAYA')->lockForUpdate()->limit(2)->get();
            if ($organisations->count() !== 1) {
                throw new RuntimeException('Expected exactly one organisation with code KLINIK_PUTRIJAYA.');
            }
            $organisation = $organisations->first();
            $report = ['created' => [], 'tools_added' => [], 'tools_missing' => [], 'unchanged' => [], 'enabled' => $enable];

            foreach (AgentCatalogue::AGENTS as $code => [$name, $title, $department, $kind, $tools]) {
                $agent = AiAgent::query()->where('organisation_id', $organisation->id)->where('code', $code)->lockForUpdate()->first();
                $created = false;
                if (! $agent) {
                    $departmentId = Department::query()->where('organisation_id', $organisation->id)->where('code', $department)->value('id');
                    if ($departmentId === null) {
                        throw new RuntimeException("Department {$department} for agent {$code} does not exist.");
                    }
                    $report['created'][] = $code;
                    $created = true;
                    if (! $dryRun) {
                        $agent = AiAgent::query()->create([
                            'organisation_id' => $organisation->id,
                            'code' => $code,
                            'display_name' => $name,
                            'job_title' => $title,
                            'department_id' => $departmentId,
                            'kind' => $kind,
                            'is_enabled' => $enable,
                            'lock_version' => 0,
                        ]);
                    }
                }

                $have = $agent ? AiAgentTool::query()->where('ai_agent_id', $agent->id)->pluck('tool_code')->all() : [];
                $missing = array_values(array_diff($tools, $have));
                if ($created) {
                    if (! $dryRun && $agent) {
                        foreach ($missing as $tool) {
                            AiAgentTool::query()->create(['ai_agent_id' => $agent->id, 'tool_code' => $tool]);
                        }
                    }
                } elseif ($missing !== []) {
                    if ($addMissingTools) {
                        $report['tools_added'][$code] = $missing;
                        if (! $dryRun) {
                            foreach ($missing as $tool) {
                                AiAgentTool::query()->create(['ai_agent_id' => $agent->id, 'tool_code' => $tool]);
                            }
                        }
                    } else {
                        $report['tools_missing'][$code] = $missing;
                    }
                } else {
                    $report['unchanged'][] = $code;
                }
            }

            if (! $dryRun && ($report['created'] !== [] || $report['tools_added'] !== [])) {
                $recorded = $this->audit->record('ai_agents.registered', null, [
                    'created' => $report['created'],
                    'tools_added' => array_map('count', $report['tools_added']),
                    'enabled_on_creation' => $enable,
                ], null, null, $organisation->id);
                if ($recorded === null) {
                    throw new RuntimeException('The audit row could not be written, so nothing was registered.');
                }
            }

            return $report;
        };

        return $dryRun ? $run() : DB::transaction($run, 3);
    }
}

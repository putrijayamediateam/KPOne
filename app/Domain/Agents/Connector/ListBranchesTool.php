<?php

namespace App\Domain\Agents\Connector;

use App\Domain\Access\BranchAccessService;
use App\Domain\Agents\AgentPermissions;
use App\Domain\Agents\Tools\AgentTool;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;

/** `connector.list_branches`: the active branches the signed-in Director works at (code and label only). */
final class ListBranchesTool implements AgentTool
{
    public function __construct(private BranchAccessService $branches) {}

    public function code(): string
    {
        return 'connector.list_branches';
    }

    public function requiredPermission(): string
    {
        return AgentPermissions::CONNECTOR_USE;
    }

    public function inputSchema(): array
    {
        return [];
    }

    public function run(User $requester, ?Branch $branch, array $input): array
    {
        $rows = $this->branches->availableBranches($requester)
            ->filter(fn (Branch $candidate): bool => $this->branches->hasEffectiveAssignment($requester, $candidate))
            ->sortBy('code')
            ->map(fn (Branch $candidate): array => ['code' => $candidate->code, 'label' => mb_substr(preg_replace('/[[:cntrl:]]+/u', ' ', (string) $candidate->name) ?? '', 0, 80), 'timezone' => $candidate->timezone])
            ->values()
            ->all();

        return [
            'branches' => $rows,
            'definitions' => 'Active branches the signed-in Director has an effective assignment at. Ask other tools about a branch by its code.',
        ];
    }
}

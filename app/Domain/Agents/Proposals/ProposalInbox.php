<?php

namespace App\Domain\Agents\Proposals;

use App\Domain\Access\BranchAccessService;
use App\Domain\Agents\AgentPermissions;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Read model for the approval inbox page: the proposals of the viewer's active branch (where the viewer
 * has an effective assignment), by tab, with
 * catalogue names resolved for display. No patient or staff personal data (the decider is shown as
 * "You" or a staff number).
 */
final class ProposalInbox
{
    public const TABS = ['pending', 'approved', 'rejected'];

    public const PER_PAGE = 20;

    public function __construct(private BranchAccessService $branches, private ProposalService $proposals) {}

    /**
     * @param  array<string, mixed>  $input  `tab`, `page`
     * @return array<string, mixed>
     */
    public function page(User $actor, array $input): array
    {
        $actor = User::query()->whereKey($actor->id)->with(['roles.permissions', 'permissions'])->first();
        abort_unless($actor && $actor->is_active && $actor->can(AgentPermissions::PROPOSALS_VIEW), 403);
        $branch = $this->branches->activeBranch($actor);
        // `.branch` permission: the inbox of a branch where the viewer has an effective assignment (contract section 4).
        abort_unless($branch && $branch->organisation_id === $actor->organisation_id && $this->branches->hasEffectiveAssignment($actor, $branch), 403);
        $data = Validator::make($input, ['tab' => ['sometimes', 'in:'.implode(',', self::TABS)], 'page' => ['sometimes', 'integer', 'min:1', 'max:100000']])->validate();
        $tab = $data['tab'] ?? 'pending';
        $assigned = true;

        $base = AiProposal::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id);
        $counts = (clone $base)->whereIn('status', self::TABS)->groupBy('status')->selectRaw('status, count(*) as aggregate')->pluck('aggregate', 'status');
        $page = (clone $base)->where('status', $tab)->with(['agent:id,display_name,job_title', 'run:id,requested_by_user_id'])
            ->orderByDesc('id')->paginate(self::PER_PAGE, ['*'], 'page', (int) ($data['page'] ?? 1));
        $names = $this->names($actor->organisation_id, array_values(array_map(fn (AiProposal $p): array => $p->payload, $page->items())));

        return [
            'branch' => ['code' => $branch->code, 'label' => $branch->name],
            'tab' => $tab,
            'counts' => collect(self::TABS)->mapWithKeys(fn (string $t): array => [$t => (int) ($counts[$t] ?? 0)])->all(),
            'proposals' => collect($page->items())->map(fn (AiProposal $p): array => [
                'id' => $p->id,
                'type' => $p->type,
                'typeLabel' => ProposalTypes::get($p->type)['label'],
                'agent' => $p->agent?->display_name,
                'agentTitle' => $p->agent?->job_title,
                'status' => $p->status,
                'createdAt' => $p->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                'decidedAt' => $p->decided_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                'decidedBy' => $p->decided_by_user_id === null ? null : ($p->decided_by_user_id === $actor->id ? 'You' : 'Staff #'.$p->decided_by_user_id),
                'reason' => $p->decision_reason,
                'lockVersion' => $p->lock_version,
                'lines' => $this->lines($p, $names),
                'canDecide' => $this->proposals->canDecide($actor, $p, $assigned),
                'cannotDecideReason' => $this->proposals->cannotDecideReason($actor, $p, $assigned),
            ])->values()->all(),
            'pagination' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     * @return array{skus: array<string, array{code: string, name: string, unit: string}>, locations: array<string, string>, batches: array<string, string>}
     */
    private function names(int $organisationId, array $payloads): array
    {
        $ids = fn (string $key): array => array_values(array_unique(array_filter(array_column($payloads, $key))));

        return [
            'skus' => DB::table('inventory_skus as s')->join('inventory_items as i', 'i.id', '=', 's.inventory_item_id')->where('s.organisation_id', $organisationId)
                ->whereIn('s.public_id', $ids('sku_public_id'))->get(['s.public_id', 's.sku_code', 'i.generic_name', 's.stock_unit'])
                ->mapWithKeys(fn (\stdClass $row): array => [(string) $row->public_id => ['code' => (string) $row->sku_code, 'name' => (string) $row->generic_name, 'unit' => (string) $row->stock_unit]])->all(),
            'locations' => DB::table('inventory_locations')->where('organisation_id', $organisationId)->whereIn('public_id', $ids('location_public_id'))->pluck('name', 'public_id')->all(),
            'batches' => DB::table('inventory_batches')->where('organisation_id', $organisationId)->whereIn('public_id', $ids('batch_public_id'))->pluck('batch_number', 'public_id')->all(),
        ];
    }

    /**
     * @param  array{skus: array<string, array{code: string, name: string, unit: string}>, locations: array<string, string>, batches: array<string, string>}  $names
     * @return list<array{label: string, value: string}>
     */
    private function lines(AiProposal $proposal, array $names): array
    {
        $p = $proposal->payload;
        $sku = $names['skus'][$p['sku_public_id']] ?? null;
        $unit = $sku ? ' '.$sku['unit'] : '';
        $qty = function (string $key) use ($p): string {
            $value = (string) $p[$key];

            return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
        };
        $lines = [['label' => 'Item', 'value' => $sku ? $sku['name'].' ('.$sku['code'].')' : 'Unknown item']];
        $location = fn (): array => ['label' => 'Location', 'value' => $names['locations'][$p['location_public_id']] ?? 'Unknown location'];

        return match ($proposal->type) {
            'inventory.restock_suggestion' => [...$lines, $location(),
                ['label' => 'Now in stock', 'value' => $qty('current_quantity').$unit], ['label' => 'Reorder level', 'value' => $qty('reorder_level').$unit],
                ['label' => isset($p['max_level']) ? 'Fill to maximum ('.$qty('max_level').$unit.')' : 'Suggested order', 'value' => $qty('suggested_quantity').$unit]],
            'inventory.expiry_warning' => [...$lines, $location(), ['label' => 'Batch', 'value' => $names['batches'][$p['batch_public_id']] ?? 'Unknown batch'],
                ['label' => 'Quantity', 'value' => $qty('quantity').$unit], ['label' => 'Expires', 'value' => (string) $p['expiry_date']],
                ['label' => 'Days left', 'value' => (string) $p['days_left']],
                ['label' => 'Suggested action', 'value' => $p['suggested_action'] === 'use_first' ? 'Use this batch first' : 'Review for write-off']],
            'inventory.transfer_suggestion' => [...$lines, ['label' => 'From', 'value' => (string) $p['from_branch_code']], ['label' => 'To', 'value' => (string) $p['to_branch_code']],
                ['label' => 'Quantity', 'value' => $qty('quantity').$unit],
                ['label' => 'Sender stock / reorder level', 'value' => $qty('from_current_quantity').' / '.$qty('from_reorder_level').$unit],
                ['label' => 'Receiver stock / reorder level', 'value' => $qty('to_current_quantity').' / '.$qty('to_reorder_level').$unit]],
            default => [...$lines, $location(), ['label' => 'Quantity', 'value' => $qty('quantity').$unit],
                ['label' => 'Last dispensed', 'value' => $p['last_dispensed_date'] ?? 'Never'], ['label' => 'Days without dispensing', 'value' => (string) $p['days_without_dispensing']],
                ['label' => 'Suggested action', 'value' => $p['suggested_action'] === 'stop_reordering' ? 'Stop reordering' : 'Review']],
        };
    }
}

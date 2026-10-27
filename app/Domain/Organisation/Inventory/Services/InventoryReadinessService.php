<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class InventoryReadinessService
{
    public const VERIFY = 'inventory.opening.verify.branch';

    /** @param array<string,mixed> $input */
    public function verify(User $actor, string $kind, string $key, array $input): int
    {
        abort_unless(in_array($kind, ['unit', 'opening'], true), 404);
        $data = Validator::make($input, ['expected_branch_id' => ['required', 'integer'], 'lock_version' => ['required', 'integer', 'min:0'], 'retry_key' => ['required', 'uuid'], 'base_unit' => [$kind === 'unit' ? 'required' : 'nullable', 'string', 'max:100']])->validate();
        $authority = app(InventoryAuthorityService::class);
        $branch = $authority->activeBranch($actor, $data);
        $table = $kind === 'unit' ? 'inventory_unit_contracts' : 'inventory_opening_verifications';
        ksort($data);
        $hash = hash_hmac('sha256', json_encode([$kind, $key, $data], JSON_THROW_ON_ERROR), (string) config('app.key'));

        return DB::transaction(function () use ($actor, $authority, $branch, $kind, $key, $data, $hash, $table): int {
            $actor = $authority->lockForBranch($actor, $branch, $kind === 'unit' ? InventoryReferenceAdministrationService::PERMISSION : self::VERIFY);
            $retry = DB::table($table)->where('organisation_id', $actor->organisation_id)->where('actor_user_id', $actor->id)->where('retry_key', $data['retry_key'])->first();
            if ($retry) {
                if (! hash_equals($retry->payload_hash, $hash)) {
                    $this->refuse('retry_key', 'This retry key was used for a different verification.');
                }

                return $retry->id;
            }
            if ((int) $data['lock_version'] !== 0) {
                $this->refuse('lock_version', 'This evidence changed. Reload the readiness page.');
            }
            $values = ['organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'actor_user_id' => $actor->id, 'retry_key' => $data['retry_key'], 'payload_hash' => $hash, 'lock_version' => 1, 'created_at' => now()->utc()];
            if ($kind === 'unit') {
                $sku = InventorySku::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $key)->where('is_active', true)->lockForUpdate()->firstOrFail();
                if ($sku->stock_unit !== $data['base_unit']) {
                    $this->refuse('base_unit', 'The SKU stock unit changed. Reload and review it.');
                }
                if (DB::table($table)->where('inventory_sku_id', $sku->id)->exists()) {
                    $this->refuse('lock_version', 'This base unit is already verified.');
                }
                $values += ['inventory_sku_id' => $sku->id, 'base_unit' => $sku->stock_unit, 'direction' => 'input_to_base', 'precision' => 3, 'factor' => '1.000'];
                $subject = $sku;
            } else {
                $candidate = StockMovement::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $key)->where('movement_type', StockMovement::TYPE_OPENING)->firstOrFail();
                $sku = InventorySku::query()->whereKey($candidate->inventory_sku_id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
                $subject = StockMovement::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                $location = DB::table('inventory_locations')->where('id', $subject->destination_location_id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->first();
                abort_unless($location !== null, 404);
                abort_if($subject->actor_user_id === $actor->id, 403, 'The opening recorder cannot sign off their own count.');
                if (! DB::table('inventory_unit_contracts')->where('inventory_sku_id', $sku->id)->where('base_unit', $sku->stock_unit)->exists()) {
                    $this->refuse('unit', 'Verify the base stock unit before signing off an opening count.');
                }
                if (DB::table($table)->where('stock_movement_id', $subject->id)->exists()) {
                    $this->refuse('lock_version', 'This opening is already signed off.');
                }
                $values['stock_movement_id'] = $subject->id;
            }
            $id = DB::table($table)->insertGetId($values);
            abort_if(app(AuditRecorder::class)->record('inventory.readiness.'.$kind.'.verified', $subject, ['evidence_id' => $id], $actor, $branch) === null, 503, 'Verification could not be audited. No evidence was saved.');

            return $id;
        }, 3);
    }

    public static function assertUnambiguous(int $organisationId, int $medicineId, int $skuId, ?string $inputUnit = null): void
    {
        $ids = DB::table('medicine_catalogue_inventory_skus as mapping')->join('inventory_skus as sku', 'sku.id', '=', 'mapping.inventory_sku_id')->join('inventory_items as item', 'item.id', '=', 'sku.inventory_item_id')
            ->where('mapping.organisation_id', $organisationId)->where('mapping.medicine_catalogue_item_id', $medicineId)->where('mapping.is_active', true)->where('sku.is_active', true)->where('item.is_active', true)->limit(2)->get(['sku.id', 'sku.stock_unit', 'sku.dispensing_unit']);
        if ($ids->count() !== 1 || (int) $ids->first()->id !== $skuId) {
            throw ValidationException::withMessages(['mapping' => 'Dispensing requires exactly one active Medicine–Item–SKU path. Review the mapping; no path was guessed.']);
        }
        StockUnitQuantity::base('1', $ids->first()->stock_unit, $inputUnit ?? $ids->first()->dispensing_unit);
        StockUnitQuantity::base('1', $ids->first()->stock_unit, $ids->first()->dispensing_unit);
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function page(User $actor, array $input): array
    {
        $actor = $actor->fresh()?->load(['roles.permissions', 'permissions']);
        abort_unless($actor && $actor->is_active && $actor->can('inventory.view.branch'), 403);
        $branches = app(BranchAccessService::class);
        $branch = $branches->activeBranch($actor);
        abort_unless($branch && $branch->organisation_id === $actor->organisation_id && $branches->hasEffectiveAssignment($actor, $branch), 403);
        $data = Validator::make($input, ['q' => ['nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1', 'max:10000'], 'opening_page' => ['sometimes', 'integer', 'min:1', 'max:10000']])->validate();
        $query = DB::table('inventory_skus as sku')->where('sku.organisation_id', $actor->organisation_id)->where('sku.is_active', true)
            ->leftJoin('inventory_unit_contracts as unit', 'unit.inventory_sku_id', '=', 'sku.id')
            ->select(['sku.id', 'sku.public_id', 'sku.sku_code', 'sku.stock_unit', 'sku.purchase_unit', 'sku.dispensing_unit', 'unit.id as unit_verification_id'])
            ->when(filled($data['q'] ?? null), fn ($q) => $q->where('sku.sku_code', 'ilike', '%'.addcslashes($data['q'], '%_\\').'%'))->orderBy('sku.sku_code')->orderBy('sku.id');
        $page = $query->paginate(25, ['*'], 'page', (int) ($data['page'] ?? 1));
        $ids = collect($page->items())->pluck('id');
        $maps = DB::table('medicine_catalogue_inventory_skus as m')->join('inventory_skus as s', 's.id', '=', 'm.inventory_sku_id')->join('inventory_items as i', 'i.id', '=', 's.inventory_item_id')->where('m.organisation_id', $actor->organisation_id)->where('m.is_active', true)->where('s.is_active', true)->where('i.is_active', true);
        $counts = (clone $maps)->selectRaw('m.medicine_catalogue_item_id, count(*) as paths')->groupBy('m.medicine_catalogue_item_id');
        $mapping = (clone $maps)->joinSub($counts, 'paths', 'paths.medicine_catalogue_item_id', '=', 'm.medicine_catalogue_item_id')->whereIn('m.inventory_sku_id', $ids)->selectRaw('m.inventory_sku_id, count(*) as mappings, max(paths.paths) as maximum_paths')->groupBy('m.inventory_sku_id')->get()->keyBy('inventory_sku_id');
        $openings = DB::table('stock_movements as m')->join('inventory_locations as l', 'l.id', '=', 'm.destination_location_id')->leftJoin('inventory_opening_verifications as v', 'v.stock_movement_id', '=', 'm.id')
            ->where('m.organisation_id', $actor->organisation_id)->where('l.branch_id', $branch->id)->where('m.movement_type', StockMovement::TYPE_OPENING)->whereIn('m.inventory_sku_id', $ids);
        $totals = (clone $openings)->selectRaw('m.inventory_sku_id, count(*) as recorded, count(v.id) as verified')->groupBy('m.inventory_sku_id')->get()->keyBy('inventory_sku_id');
        $pending = (clone $openings)->join('inventory_batches as b', 'b.id', '=', 'm.inventory_batch_id')->whereNull('v.id')->orderBy('m.id')->paginate(25, ['m.public_id', 'm.inventory_sku_id', 'm.inventory_batch_id', 'm.quantity', 'm.actor_user_id', 'l.code as location_code', 'b.batch_number', 'b.expiry_date'], 'opening_page', (int) ($data['opening_page'] ?? 1));
        $rows = collect($page->items())->map(function ($row) use ($mapping, $totals): array {
            $map = $mapping->get($row->id);
            $opening = $totals->get($row->id);

            return [...(array) $row, 'mapped' => $map !== null && (int) $map->maximum_paths === 1, 'ambiguous' => $map !== null && (int) $map->maximum_paths > 1, 'opening_count' => (int) ($opening->recorded ?? 0), 'signed_count' => (int) ($opening->verified ?? 0)];
        })->all();

        return ['branchId' => $branch->id, 'branchName' => $branch->name, 'rows' => $rows, 'q' => $data['q'] ?? '', 'page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total(), 'pending' => collect($pending->items())->map(fn ($row): array => [...(array) $row, 'canVerify' => $actor->can(self::VERIFY) && $row->actor_user_id !== $actor->id])->values()->all(), 'pendingLimited' => $pending->total() > 25, 'openingPage' => $pending->currentPage(), 'openingLastPage' => $pending->lastPage(), 'canVerifyUnits' => $actor->can(InventoryReferenceAdministrationService::PERMISSION)];
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

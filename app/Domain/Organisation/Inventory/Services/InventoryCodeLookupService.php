<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Support\Gs1ElementString;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * STK-9 scanning (owner decisions 2026-11-05). Resolves a scanned or typed code to one SKU of the actor's
 * organisation: first by SKU barcode (trimmed, case-insensitive), then by exact SKU code. Read only: a scan only
 * finds stock and never authorises or posts anything. Codes map locally; there is no external lookup.
 *
 * DSP-18: a GS1 element string (bracketed, or raw with the GS separator) is matched by its GTIN against the stored
 * barcode forms, and the read batch and expiry are returned under `gs1` for the page to point at. Nothing is filled,
 * selected or created from them.
 */
final class InventoryCodeLookupService
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{status: string, code: string, sku?: array<string, mixed>, skus?: list<string>, gs1?: array{gtin: string, batch: string|null, batch_certain: bool, expiry: string|null}}
     */
    public function find(User $actor, array $input): array
    {
        $actor = $actor->fresh()?->load(['roles.permissions', 'permissions']);
        abort_unless($actor && $actor->is_active && $actor->can('inventory.view.branch'), 403);
        $branches = app(BranchAccessService::class);
        $branch = $branches->activeBranch($actor);
        abort_unless($branch && $branch->organisation_id === $actor->organisation_id && $branches->hasEffectiveAssignment($actor, $branch), 403);
        $data = Validator::make($input, ['code' => ['required', 'string', 'max:100', 'not_regex:/(?!\x{1D})[\p{Cc}\p{Cf}]/u']])->validate();
        $code = trim((string) $data['code']);
        abort_if(trim($code, "\x1D") === '', 422);
        $gs1 = Gs1ElementString::parse($code);
        $extra = $gs1 === null ? [] : ['gs1' => $gs1];
        $codes = $gs1 === null ? [mb_strtoupper($code)] : Gs1ElementString::barcodeForms($gs1['gtin']);

        $base = fn () => DB::table('inventory_skus as s')->join('inventory_items as i', 'i.id', '=', 's.inventory_item_id')
            ->where('s.organisation_id', $actor->organisation_id)
            ->select(['s.public_id', 's.sku_code', 's.barcode', 's.stock_unit', 's.is_active', 'i.generic_name as item_name']);
        $matches = $base()->whereNotNull('s.barcode')->whereRaw('upper(trim(s.barcode)) in ('.implode(',', array_fill(0, count($codes), '?')).')', $codes)->orderBy('s.sku_code')->limit(6)->get();
        if ($matches->count() > 1) {
            return ['status' => 'shared', 'code' => $code, 'skus' => array_values($matches->pluck('sku_code')->map(fn ($value): string => (string) $value)->all())] + $extra;
        }
        $match = $matches->first() ?? $base()->whereRaw('upper(s.sku_code) = ?', [mb_strtoupper($code)])->first();
        if ($match === null) {
            return ['status' => 'not_found', 'code' => $code] + $extra;
        }

        return ['status' => 'found', 'code' => $code, 'sku' => ['public_id' => (string) $match->public_id, 'sku_code' => (string) $match->sku_code, 'item_name' => (string) $match->item_name,
            'stock_unit' => (string) $match->stock_unit, 'active' => (bool) $match->is_active]] + $extra;
    }

    /**
     * Barcodes held by more than one SKU of the organisation (legacy data from before the STK-9 rule), as stored.
     *
     * @return array{rows: list<array{code: string, skus: list<array{public_id: string, sku_code: string, item_name: string}>}>, more: bool}
     */
    public function sharedCodes(int $organisationId, int $limit = 50): array
    {
        $groups = DB::table('inventory_skus')->where('organisation_id', $organisationId)->whereNotNull('barcode')->whereRaw("trim(barcode) <> ''")
            ->selectRaw('upper(trim(barcode)) as normalised, min(trim(barcode)) as code')->groupByRaw('upper(trim(barcode))')->havingRaw('count(*) > 1')
            ->orderByRaw('upper(trim(barcode))')->limit($limit + 1)->get();
        $more = $groups->count() > $limit;
        $groups = $groups->take($limit);
        $holders = $groups->isEmpty() ? collect() : DB::table('inventory_skus as s')->join('inventory_items as i', 'i.id', '=', 's.inventory_item_id')
            ->where('s.organisation_id', $organisationId)->whereNotNull('s.barcode')
            ->whereRaw('upper(trim(s.barcode)) in ('.implode(',', array_fill(0, $groups->count(), '?')).')', $groups->pluck('normalised')->map(fn ($value): string => (string) $value)->all())
            ->orderBy('s.sku_code')->get(['s.public_id', 's.sku_code', 's.barcode', 'i.generic_name as item_name'])->groupBy(fn ($row): string => mb_strtoupper(trim((string) $row->barcode)));
        $rows = [];
        foreach ($groups as $group) {
            $skus = [];
            foreach ($holders->get((string) $group->normalised, collect()) as $holder) {
                $skus[] = ['public_id' => (string) $holder->public_id, 'sku_code' => (string) $holder->sku_code, 'item_name' => (string) $holder->item_name];
            }
            $rows[] = ['code' => (string) $group->code, 'skus' => $skus];
        }

        return ['rows' => $rows, 'more' => $more];
    }
}

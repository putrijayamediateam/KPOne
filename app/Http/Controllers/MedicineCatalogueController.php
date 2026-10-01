<?php

namespace App\Http\Controllers;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Services\MedicineAdministrationService;
use App\Domain\Clinical\Services\UnifiedCatalogueSetupService;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\InventorySupplier;
use App\Domain\Organisation\Inventory\Models\MedicineCatalogueInventorySku;
use App\Domain\Organisation\Inventory\Services\InventoryReferenceAdministrationService;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\PricingReferenceAdministrationService;
use App\Domain\Visit\Models\Panel;
use App\Http\Requests\MedicineCatalogueStoreRequest;
use App\Http\Requests\MedicineCatalogueUpdateRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MedicineCatalogueController extends Controller
{
    public function index(Request $request, BranchAccessService $branches): Response
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);
        $actor = $request->user();

        $query = MedicineCatalogueItem::query()
            ->where('organisation_id', $actor->organisation_id);
        if (filled($data['search'] ?? null)) {
            $needle = trim($data['search']);
            $query->where(function ($inner) use ($needle): void {
                $inner->whereRaw('LOWER(code) LIKE ?', ['%'.strtolower($needle).'%'])
                    ->orWhereRaw('LOWER(display_name) LIKE ?', ['%'.strtolower($needle).'%']);
            });
        }
        if (($data['status'] ?? null) === 'active') {
            $query->where('is_active', true);
        } elseif (($data['status'] ?? null) === 'inactive') {
            $query->where('is_active', false);
        }

        $paginator = $query->orderBy('display_name')->paginate(25, page: (int) ($data['page'] ?? 1));

        return Inertia::render('Medicine/Index', [
            'medicines' => [
                'data' => $paginator->getCollection()->map(fn (MedicineCatalogueItem $medicine): array => [
                    'publicId' => $medicine->public_id,
                    'code' => $medicine->code,
                    'displayName' => $medicine->display_name,
                    'strengthText' => $medicine->strength_text,
                    'dosageForm' => $medicine->dosage_form,
                    'orderUnit' => $medicine->order_unit,
                    'genericName' => $medicine->generic_name,
                    'category' => $medicine->category,
                    'groupName' => $medicine->group_name,
                    'defaultDosageAmount' => $medicine->default_dosage_amount,
                    'defaultDosageUnit' => $medicine->default_dosage_unit,
                    'defaultInstruction' => $medicine->default_instruction,
                    'defaultPrecaution' => $medicine->default_precaution,
                    'defaultFrequency' => $medicine->default_frequency,
                    'defaultDuration' => $medicine->default_duration,
                    'defaultIndication' => $medicine->default_indication,
                    'isActive' => $medicine->is_active,
                ])->values(),
                'total' => $paginator->total(),
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
            ],
            'filters' => [
                'search' => $data['search'] ?? '',
                'status' => $data['status'] ?? '',
            ],
            'setup' => $this->setupOptions($request->user(), $branches),
        ]);
    }

    /** @return array<string, mixed> */
    private function setupOptions(User $actor, BranchAccessService $branches): array
    {
        $availableBranches = $branches->availableBranches($actor);
        $canManageInventory = $actor->can(InventoryReferenceAdministrationService::PERMISSION);

        return [
            'canManageInventory' => $canManageInventory,
            'canManageSupplier' => $actor->can('inventory.suppliers.manage.organisation'),
            'canPublishPrices' => $actor->can('prices.publish.organisation'),
            'canManagePrices' => $actor->can(PricingReferenceAdministrationService::PERMISSION),
            'canReceiveStock' => $actor->can('inventory.opening_balance.branch'),
            'activeBranchId' => $branches->activeBranch($actor)?->id,
            'branches' => $availableBranches->map(fn ($branch): array => [
                'id' => $branch->id,
                'name' => $branch->name,
            ])->values(),
            'locations' => $canManageInventory
                ? InventoryLocation::query()->where('organisation_id', $actor->organisation_id)
                    ->where('is_active', true)
                    ->where(fn ($query) => $query->whereNull('branch_id')->orWhereIn('branch_id', $availableBranches->pluck('id')))
                    ->orderBy('name')->limit(100)->get()
                    ->map(fn (InventoryLocation $location): array => [
                        'publicId' => $location->public_id,
                        'name' => $location->name,
                        'branchId' => $location->branch_id,
                        'type' => $location->type,
                    ])->values()
                : [],
            'suppliers' => $actor->can('inventory.suppliers.manage.organisation')
                ? InventorySupplier::query()->where('organisation_id', $actor->organisation_id)
                    ->where('is_active', true)->orderBy('name')->limit(100)->get()
                    ->map(fn (InventorySupplier $supplier): array => [
                        'publicId' => $supplier->public_id,
                        'code' => $supplier->code,
                        'name' => $supplier->name,
                    ])->values()
                : [],
            'panels' => Panel::query()->where('organisation_id', $actor->organisation_id)
                ->where('is_active', true)->orderBy('name')->limit(100)->get()
                ->map(fn (Panel $panel): array => [
                    'id' => $panel->id,
                    'name' => $panel->name,
                ])->values(),
        ];
    }

    public function searchInventorySkus(Request $request): JsonResponse
    {
        $data = $request->validate(['query' => ['nullable', 'string', 'max:100']]);
        $actor = $request->user();
        abort_unless($actor->can(InventoryReferenceAdministrationService::PERMISSION), 403);

        $query = InventorySku::query()
            ->where('inventory_skus.organisation_id', $actor->organisation_id)
            ->where('inventory_skus.is_active', true)
            ->with('item');
        if (filled($data['query'] ?? null)) {
            $needle = '%'.mb_strtolower(trim($data['query'])).'%';
            $query->where(function ($builder) use ($needle): void {
                $builder->whereRaw('LOWER(inventory_skus.sku_code) LIKE ?', [$needle])
                    ->orWhereHas('item', fn ($itemQuery) => $itemQuery->whereRaw('LOWER(generic_name) LIKE ?', [$needle]));
            });
        }

        return response()->json(['data' => $query->orderBy('inventory_skus.sku_code')->limit(50)->get()
            ->map(fn (InventorySku $sku): array => [
                'value' => $sku->public_id,
                'label' => $sku->sku_code.' · '.$sku->item->generic_name,
            ])->values()]);
    }

    public function editSetup(Request $request, MedicineCatalogueItem $medicine): JsonResponse
    {
        $actor = $request->user();
        abort_unless($medicine->organisation_id === $actor->organisation_id, 404);

        $mapping = MedicineCatalogueInventorySku::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('medicine_catalogue_item_id', $medicine->id)
            ->where('is_active', true)
            ->with('sku.item')
            ->first();
        $sku = $mapping?->sku;
        $charge = ChargeDefinition::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('medicine_catalogue_item_id', $medicine->id)
            ->first();
        $books = PriceBook::query()
            ->where('organisation_id', $actor->organisation_id)
            ->whereNull('branch_id')
            ->where(function ($query) use ($actor, $charge): void {
                if (! $actor->can(PricingReferenceAdministrationService::PERMISSION)) {
                    $query->whereRaw('1 = 0');

                    return;
                }
                $query->whereIn('scope_key', ['organisation', 'panel:default']);
                if ($charge !== null) {
                    $query->orWhereIn('id', PriceEntry::query()
                        ->select('price_book_id')
                        ->where('organisation_id', $actor->organisation_id)
                        ->where('charge_definition_id', $charge->id));
                }
            })
            ->orderBy('id')
            ->get();
        $entries = $charge === null
            ? collect()
            : PriceEntry::query()
                ->where('organisation_id', $actor->organisation_id)
                ->where('charge_definition_id', $charge->id)
                ->orderByDesc('version')
                ->get()
                ->unique('price_book_id')
                ->keyBy('price_book_id');
        $latestFor = static fn (PriceBook $book): ?PriceEntry => $entries->get($book->id) instanceof PriceEntry
            ? $entries->get($book->id)
            : null;
        $toRm = static fn (?PriceEntry $entry): ?string => $entry === null
            ? null
            : intdiv($entry->unit_price_sen, 100).'.'.str_pad((string) ($entry->unit_price_sen % 100), 2, '0', STR_PAD_LEFT);
        $selfPay = $books->firstWhere('scope_key', 'organisation');
        $panelDefault = $books->firstWhere('scope_key', 'panel:default');
        $overrides = $books
            ->filter(fn (PriceBook $book): bool => preg_match('/^panel:\d+$/', $book->scope_key) === 1)
            ->map(function (PriceBook $book) use ($latestFor, $toRm, $actor): ?array {
                $entry = $latestFor($book);
                if ($entry === null) {
                    return null;
                }
                $panel = Panel::query()
                    ->where('organisation_id', $actor->organisation_id)
                    ->find($book->panel_id);

                return [
                    'panel_id' => (string) $book->panel_id,
                    'panel_name' => $panel->name ?? 'Inactive Panel',
                    'amount_rm' => $toRm($entry) ?? '',
                    'version' => $entry->version,
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'medicine' => [
                'code' => $medicine->code,
                'display_name' => $medicine->display_name,
                'strength_text' => $medicine->strength_text ?? '',
                'dosage_form' => $medicine->dosage_form ?? '',
                'order_unit' => $medicine->order_unit,
                'generic_name' => $medicine->generic_name ?? '',
                'category' => $medicine->category ?? '',
                'group_name' => $medicine->group_name ?? '',
                'default_dosage_amount' => $medicine->default_dosage_amount ?? '',
                'default_dosage_unit' => $medicine->default_dosage_unit ?? '',
                'default_instruction' => $medicine->default_instruction ?? '',
                'default_precaution' => $medicine->default_precaution ?? '',
                'default_frequency' => $medicine->default_frequency ?? '',
                'default_duration' => $medicine->default_duration ?? '',
                'default_indication' => $medicine->default_indication ?? '',
            ],
            'sku' => $sku === null ? null : [
                'public_id' => $sku->public_id,
                'sku_code' => $sku->sku_code,
                'barcode' => $sku->barcode ?? '',
                'pack_size' => $sku->pack_size,
                'purchase_unit' => $sku->purchase_unit,
                'stock_unit' => $sku->stock_unit,
                'dispensing_unit' => $sku->dispensing_unit,
                'unit_conversion' => $sku->unit_conversion,
                'storage_type' => $sku->storage_type,
                'minimum_temperature' => $sku->minimum_temperature,
                'maximum_temperature' => $sku->maximum_temperature,
                'cold_chain_required' => $sku->cold_chain_required,
                'do_not_freeze' => $sku->do_not_freeze,
                'protect_from_light' => $sku->protect_from_light,
                'batch_tracking_required' => $sku->batch_tracking_required,
                'expiry_tracking_required' => $sku->expiry_tracking_required,
                'route' => $sku->item->route ?? '',
                'manufacturer' => $sku->item->manufacturer ?? '',
                'mal_number' => $sku->item->mal_number ?? '',
                'generic_name' => $sku->item->generic_name,
            ],
            'prices' => [
                'self_pay_rm' => $selfPay instanceof PriceBook ? $toRm($latestFor($selfPay)) : null,
                'panel_default_rm' => $panelDefault instanceof PriceBook ? $toRm($latestFor($panelDefault)) : null,
                'panel_overrides' => $overrides,
            ],
        ]);
    }

    public function store(MedicineCatalogueStoreRequest $request, UnifiedCatalogueSetupService $service): RedirectResponse
    {
        $service->createMedicine($request->user(), $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Medicine created.')]);

        return to_route('medicines.index');
    }

    public function update(MedicineCatalogueUpdateRequest $request, MedicineCatalogueItem $medicine, UnifiedCatalogueSetupService $service): RedirectResponse
    {
        $service->updateMedicine($request->user(), $medicine, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Medicine updated.')]);

        return to_route('medicines.index');
    }

    public function activate(Request $request, MedicineCatalogueItem $medicine, MedicineAdministrationService $service): RedirectResponse
    {
        $service->activate($request->user(), $medicine);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Medicine reactivated.')]);

        return to_route('medicines.index');
    }

    public function deactivate(Request $request, MedicineCatalogueItem $medicine, MedicineAdministrationService $service): RedirectResponse
    {
        $service->deactivate($request->user(), $medicine);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Medicine deactivated.')]);

        return to_route('medicines.index');
    }
}

<?php

namespace App\Http\Controllers;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Models\ClinicalServiceCatalogueItem;
use App\Domain\Clinical\Services\ClinicalServiceCatalogueAdministrationService;
use App\Domain\Clinical\Services\UnifiedCatalogueSetupService;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\ConsultationTariffAdministrationService;
use App\Domain\Visit\Billing\Services\CurrentCatalogueTariffReader;
use App\Domain\Visit\Billing\Services\PricingReferenceAdministrationService;
use App\Domain\Visit\Models\Panel;
use App\Http\Requests\ClinicalServiceCatalogueStoreRequest;
use App\Http\Requests\ClinicalServiceCatalogueUpdateRequest;
use App\Http\Requests\ConsultationTariffStoreRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ClinicalServiceCatalogueController extends Controller
{
    public function index(Request $request, BranchAccessService $branches, CurrentCatalogueTariffReader $tariffs): Response
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);
        $actor = $request->user();

        $query = ClinicalServiceCatalogueItem::query()
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
        $serviceRows = $paginator->getCollection();
        $catalogueTariffs = $tariffs->forClinicalServices(
            $actor,
            array_values($serviceRows->pluck('id')->map(fn (mixed $id): int => (int) $id)->all()),
        );
        $consultationCharge = ChargeDefinition::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('source_key', 'consultation')
            ->first();
        $consultationBooks = PriceBook::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where(function ($books): void {
                $books->where('scope_key', 'organisation')
                    ->orWhere('scope_key', 'panel:default')
                    ->orWhere('scope_key', 'like', 'panel:%');
            })
            ->orderBy('id')
            ->get();
        $latestConsultationEntries = $consultationCharge === null
            ? collect()
            : PriceEntry::query()
                ->where('organisation_id', $actor->organisation_id)
                ->where('charge_definition_id', $consultationCharge->id)
                ->orderByDesc('version')
                ->get()
                ->unique('price_book_id')
                ->keyBy('price_book_id');
        $amountRm = static function (?PriceEntry $entry): ?string {
            if ($entry === null) {
                return null;
            }

            return intdiv($entry->unit_price_sen, 100).'.'.str_pad(
                (string) ($entry->unit_price_sen % 100),
                2,
                '0',
                STR_PAD_LEFT,
            );
        };
        $defaultSelfPay = $consultationBooks->firstWhere('scope_key', 'organisation');
        $defaultPanel = $consultationBooks->firstWhere('scope_key', 'panel:default');
        $latestForBook = static function (?PriceBook $book) use ($latestConsultationEntries): ?PriceEntry {
            if ($book === null) {
                return null;
            }
            $entry = $latestConsultationEntries->get($book->id);

            return $entry instanceof PriceEntry ? $entry : null;
        };
        $consultationPanelOverrides = $consultationBooks
            ->filter(fn (PriceBook $book): bool => preg_match('/^panel:\d+$/', $book->scope_key) === 1)
            ->map(function (PriceBook $book) use ($latestForBook, $amountRm): ?array {
                $entry = $latestForBook($book);
                if ($entry === null) {
                    return null;
                }

                $panel = Panel::query()->whereKey($book->panel_id)->where('organisation_id', $book->organisation_id)->first();

                return [
                    'panelId' => (string) $book->panel_id,
                    'panelName' => $panel->name ?? 'Inactive Panel',
                    'amountRm' => $amountRm($entry),
                    'version' => $entry->version,
                ];
            })
            ->filter()
            ->values();

        return Inertia::render('ClinicalService/Index', [
            'services' => [
                'data' => $serviceRows->map(fn (ClinicalServiceCatalogueItem $service): array => [
                    'publicId' => $service->public_id,
                    'code' => $service->code,
                    'displayName' => $service->display_name,
                    'orderUnit' => $service->order_unit,
                    'category' => $service->category,
                    'selfPayAmountRm' => $catalogueTariffs[$service->id]['selfPayAmountRm'] ?? null,
                    'panelDefaultAmountRm' => $catalogueTariffs[$service->id]['panelDefaultAmountRm'] ?? null,
                    'isActive' => $service->is_active,
                ])->values(),
                'total' => $paginator->total(),
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
            ],
            'filters' => [
                'search' => $data['search'] ?? '',
                'status' => $data['status'] ?? '',
            ],
            'setup' => [
                'canManagePrices' => $actor->can(PricingReferenceAdministrationService::PERMISSION),
                'canPublishPrices' => $actor->can('prices.publish.organisation'),
                'activeBranchId' => $branches->activeBranch($actor)?->id,
                'branches' => $branches->availableBranches($actor)->map(fn ($branch): array => [
                    'id' => $branch->id,
                    'name' => $branch->name,
                ])->values(),
                'panels' => Panel::query()->where('organisation_id', $actor->organisation_id)
                    ->where('is_active', true)->orderBy('name')->limit(100)->get()
                    ->map(fn (Panel $panel): array => [
                        'id' => $panel->id,
                        'name' => $panel->name,
                    ])->values(),
            ],
            'consultationTariff' => [
                'chargeExists' => $consultationCharge !== null,
                'code' => $consultationCharge->code ?? 'CONSULTATION',
                'displayName' => $consultationCharge->display_name ?? 'Consultation fee',
                'isActive' => $consultationCharge->is_active ?? false,
                'selfPayAmountRm' => $amountRm($latestForBook($defaultSelfPay)),
                'selfPayVersion' => $latestForBook($defaultSelfPay)->version ?? 0,
                'panelDefaultAmountRm' => $amountRm($latestForBook($defaultPanel)),
                'panelDefaultVersion' => $latestForBook($defaultPanel)->version ?? 0,
                'panelOverrides' => $consultationPanelOverrides,
            ],
        ]);
    }

    public function storeConsultationTariff(
        ConsultationTariffStoreRequest $request,
        ConsultationTariffAdministrationService $service,
    ): RedirectResponse {
        $service->publish($request->user(), $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Consultation tariffs saved.')]);

        return to_route('clinical-services.index');
    }

    public function store(ClinicalServiceCatalogueStoreRequest $request, UnifiedCatalogueSetupService $service): RedirectResponse
    {
        $service->createClinicalService($request->user(), $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Clinical service created.')]);

        return to_route('clinical-services.index');
    }

    public function editSetup(Request $request, ClinicalServiceCatalogueItem $clinicalService): JsonResponse
    {
        $actor = $request->user();
        abort_unless($clinicalService->organisation_id === $actor->organisation_id, 404);

        $charge = ChargeDefinition::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('clinical_service_catalogue_item_id', $clinicalService->id)
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
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'service' => [
                'code' => $clinicalService->code,
                'display_name' => $clinicalService->display_name,
                'order_unit' => $clinicalService->order_unit,
                'category' => $clinicalService->category ?? '',
            ],
            'prices' => [
                'self_pay_rm' => $selfPay instanceof PriceBook ? $toRm($latestFor($selfPay)) : null,
                'panel_default_rm' => $panelDefault instanceof PriceBook ? $toRm($latestFor($panelDefault)) : null,
                'panel_overrides' => $overrides,
            ],
        ]);
    }

    public function update(ClinicalServiceCatalogueUpdateRequest $request, ClinicalServiceCatalogueItem $clinicalService, UnifiedCatalogueSetupService $service): RedirectResponse
    {
        $service->updateClinicalService($request->user(), $clinicalService, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Clinical service updated.')]);

        return to_route('clinical-services.index');
    }

    public function activate(Request $request, ClinicalServiceCatalogueItem $clinicalService, ClinicalServiceCatalogueAdministrationService $service): RedirectResponse
    {
        $service->activate($request->user(), $clinicalService);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Clinical service reactivated.')]);

        return to_route('clinical-services.index');
    }

    public function deactivate(Request $request, ClinicalServiceCatalogueItem $clinicalService, ClinicalServiceCatalogueAdministrationService $service): RedirectResponse
    {
        $service->deactivate($request->user(), $clinicalService);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Clinical service deactivated.')]);

        return to_route('clinical-services.index');
    }
}

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
use App\Domain\Visit\Billing\Services\PricingReferenceAdministrationService;
use App\Domain\Visit\Models\Panel;
use App\Http\Requests\ClinicalServiceCatalogueStoreRequest;
use App\Http\Requests\ClinicalServiceCatalogueUpdateRequest;
use App\Http\Requests\ConsultationTariffStoreRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ClinicalServiceCatalogueController extends Controller
{
    public function index(Request $request, BranchAccessService $branches): Response
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
                'data' => $paginator->getCollection()->map(fn (ClinicalServiceCatalogueItem $service): array => [
                    'publicId' => $service->public_id,
                    'code' => $service->code,
                    'displayName' => $service->display_name,
                    'orderUnit' => $service->order_unit,
                    'category' => $service->category,
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

    public function update(ClinicalServiceCatalogueUpdateRequest $request, ClinicalServiceCatalogueItem $clinicalService, ClinicalServiceCatalogueAdministrationService $service): RedirectResponse
    {
        $service->update($request->user(), $clinicalService, $request->validated());
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

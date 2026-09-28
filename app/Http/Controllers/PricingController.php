<?php

namespace App\Http\Controllers;

use App\Domain\Clinical\Models\ClinicalServiceCatalogueItem;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\PricePublicationService;
use App\Domain\Visit\Billing\Services\PricingReferenceAdministrationService;
use App\Http\Requests\PriceBookStoreRequest;
use App\Http\Requests\PricePublishRequest;
use App\Http\Requests\PricingChargeStoreRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PricingController extends Controller
{
    public function index(Request $request): Response
    {
        $actor = $request->user();
        $organisationId = $actor->organisation_id;

        $charges = ChargeDefinition::query()
            ->where('organisation_id', $organisationId)
            ->orderBy('display_name')
            ->get();
        $latestEntries = PriceEntry::query()
            ->where('organisation_id', $organisationId)
            ->whereIn('charge_definition_id', $charges->pluck('id'))
            ->orderByDesc('version')
            ->get()
            ->groupBy('charge_definition_id')
            ->map(fn ($entries) => $entries->first());

        return Inertia::render('Pricing/Index', [
            'canPublish' => Gate::forUser($actor)->allows('prices.publish.organisation'),
            'branches' => Branch::query()
                ->where('organisation_id', $organisationId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (Branch $branch): array => ['id' => $branch->id, 'name' => $branch->name])
                ->values(),
            'medicines' => MedicineCatalogueItem::query()
                ->where('organisation_id', $organisationId)
                ->where('is_active', true)
                ->orderBy('display_name')
                ->get()
                ->map(fn (MedicineCatalogueItem $m): array => ['publicId' => $m->public_id, 'displayName' => $m->display_name, 'code' => $m->code])
                ->values(),
            'services' => ClinicalServiceCatalogueItem::query()
                ->where('organisation_id', $organisationId)
                ->where('is_active', true)
                ->orderBy('display_name')
                ->get()
                ->map(fn (ClinicalServiceCatalogueItem $s): array => ['publicId' => $s->public_id, 'displayName' => $s->display_name, 'code' => $s->code])
                ->values(),
            'priceBooks' => PriceBook::query()
                ->where('organisation_id', $organisationId)
                ->orderBy('name')
                ->get()
                ->map(fn (PriceBook $book): array => [
                    'publicId' => $book->public_id,
                    'name' => $book->name,
                    'currency' => $book->currency,
                    'branchId' => $book->branch_id,
                    'scopeKey' => $book->scope_key,
                    'isActive' => $book->is_active,
                ])->values(),
            'charges' => $charges->map(function (ChargeDefinition $charge) use ($latestEntries): array {
                $latest = $latestEntries->get($charge->id);

                return [
                    'publicId' => $charge->public_id,
                    'code' => $charge->code,
                    'displayName' => $charge->display_name,
                    'type' => $charge->type,
                    'unit' => $charge->unit,
                    'isActive' => $charge->is_active,
                    'currentPrice' => $latest ? [
                        'priceBookPublicId' => optional(PriceBook::query()->find($latest->price_book_id))->public_id,
                        'amountSen' => $latest->unit_price_sen,
                        'version' => $latest->version,
                    ] : null,
                ];
            })->values(),
        ]);
    }

    public function storeCharge(PricingChargeStoreRequest $request, PricingReferenceAdministrationService $service): RedirectResponse
    {
        $data = $request->validated();
        $actor = $request->user();
        $attributes = ['code' => $data['code'], 'display_name' => $data['display_name']];

        match ($data['type']) {
            'consultation' => $service->createConsultationCharge($actor, $attributes),
            'medicine' => $service->createMedicineCharge(
                $actor,
                MedicineCatalogueItem::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $data['medicine_public_id'])->firstOrFail(),
                $attributes,
            ),
            'service' => $service->createServiceCharge(
                $actor,
                ClinicalServiceCatalogueItem::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $data['service_public_id'])->firstOrFail(),
                $attributes,
            ),
            default => throw new \InvalidArgumentException('Unsupported charge type.'),
        };
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Charge definition created.')]);

        return to_route('pricing.index');
    }

    public function activateCharge(Request $request, ChargeDefinition $charge, PricingReferenceAdministrationService $service): RedirectResponse
    {
        $service->activateCharge($request->user(), $charge);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Charge reactivated.')]);

        return to_route('pricing.index');
    }

    public function deactivateCharge(Request $request, ChargeDefinition $charge, PricingReferenceAdministrationService $service): RedirectResponse
    {
        $service->deactivateCharge($request->user(), $charge);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Charge deactivated.')]);

        return to_route('pricing.index');
    }

    public function storePriceBook(PriceBookStoreRequest $request, PricingReferenceAdministrationService $service): RedirectResponse
    {
        $data = $request->validated();
        $branch = filled($data['branch_id'] ?? null)
            ? Branch::query()->where('organisation_id', $request->user()->organisation_id)->where('id', (int) $data['branch_id'])->firstOrFail()
            : null;
        $service->createPriceBook($request->user(), $branch, ['name' => $data['name'], 'currency' => $data['currency']]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Price Book created.')]);

        return to_route('pricing.index');
    }

    public function activatePriceBook(Request $request, PriceBook $priceBook, PricingReferenceAdministrationService $service): RedirectResponse
    {
        $service->activatePriceBook($request->user(), $priceBook);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Price Book reactivated.')]);

        return to_route('pricing.index');
    }

    public function deactivatePriceBook(Request $request, PriceBook $priceBook, PricingReferenceAdministrationService $service): RedirectResponse
    {
        $service->deactivatePriceBook($request->user(), $priceBook);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Price Book deactivated.')]);

        return to_route('pricing.index');
    }

    public function publish(PricePublishRequest $request, ChargeDefinition $charge, PricePublicationService $service): RedirectResponse
    {
        $data = $request->validated();
        $book = PriceBook::query()
            ->where('organisation_id', $request->user()->organisation_id)
            ->where('public_id', $data['price_book_public_id'])
            ->firstOrFail();
        $service->publish($request->user(), $book, $charge, $data['amount_sen'], $data['expected_version'], $data['expected_branch_id']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Price published.')]);

        return to_route('pricing.index');
    }
}

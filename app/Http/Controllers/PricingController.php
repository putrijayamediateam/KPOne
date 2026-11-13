<?php

namespace App\Http\Controllers;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Models\ClinicalServiceCatalogueItem;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\PriceHistoryService;
use App\Domain\Visit\Billing\Services\PriceListCheckService;
use App\Domain\Visit\Billing\Services\PricePublicationService;
use App\Domain\Visit\Billing\Services\PricingReferenceAdministrationService;
use App\Http\Requests\PriceBookStoreRequest;
use App\Http\Requests\PricePublishRequest;
use App\Http\Requests\PricingChargeStoreRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PricingController extends Controller
{
    public function index(Request $request, BranchAccessService $branches): Response
    {
        $actor = $request->user();
        $organisationId = $actor->organisation_id;

        $charges = ChargeDefinition::query()
            ->where('organisation_id', $organisationId)
            ->orderBy('display_name')
            ->get();
        $books = PriceBook::query()
            ->where('organisation_id', $organisationId)
            ->orderBy('name')
            ->get();
        $activeBookIds = $books->where('is_active', true)->pluck('id')->all();
        $bookPublicIds = $books->pluck('public_id', 'id');
        // CAT-6: only active books count, as in billing (PriceResolutionService), and publishing to an inactive book
        // is refused anyway.
        $entries = PriceEntry::query()
            ->where('organisation_id', $organisationId)
            ->whereIn('charge_definition_id', $charges->pluck('id'))
            ->whereIn('price_book_id', $activeBookIds)
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->get();
        $branch = $branches->activeBranch($actor);
        $timezone = $branch !== null ? $branch->timezone : (string) config('app.timezone');
        // CAT-6: "Current price" is the price that took effect most recently and is in effect now (billing never uses
        // a price whose effective time is still ahead). The publish form keeps the newest version of that book as its
        // expected version, so publishing is unchanged. A charge whose only prices start later shows no current
        // amount, with the first one as the scheduled line.
        $now = CarbonImmutable::now('UTC');
        $latestEntries = $entries
            ->groupBy('charge_definition_id')
            ->map(function ($forCharge) use ($now, $timezone) {
                $at = fn (PriceEntry $entry): CarbonImmutable => CarbonImmutable::parse((string) $entry->getRawOriginal('effective_at'), 'UTC');
                $isFuture = fn (PriceEntry $entry): bool => $at($entry)->greaterThan($now);
                $inEffect = $forCharge->first(fn (PriceEntry $entry): bool => ! $isFuture($entry));
                $bookId = $inEffect !== null ? $inEffect->price_book_id : $forCharge->last()->price_book_id;
                $inBook = $forCharge->where('price_book_id', $bookId);

                // The next version in that book that takes effect later, if any (shown as a muted line).
                $scheduled = $inBook->filter($isFuture)->sortBy(fn (PriceEntry $entry): string => $at($entry)->format('Y-m-d H:i:s.u').sprintf('%020d', $entry->id))->first();

                return (object) [
                    'price_book_id' => $bookId,
                    'unit_price_sen' => $inEffect?->unit_price_sen,
                    'version' => (int) $inBook->max('version'),
                    'scheduled' => $scheduled === null ? null : [
                        'amountSen' => (int) $scheduled->unit_price_sen,
                        'effectiveAt' => $at($scheduled)->toIso8601String(),
                        'effectiveLabel' => $at($scheduled)->setTimezone($timezone)->format('j M Y, g:i A'),
                    ],
                ];
            });

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
            'priceBooks' => $books
                ->map(fn (PriceBook $book): array => [
                    'publicId' => $book->public_id,
                    'name' => $book->name,
                    'currency' => $book->currency,
                    'branchId' => $book->branch_id,
                    'scopeKey' => $book->scope_key,
                    'isActive' => $book->is_active,
                ])->values(),
            'charges' => $charges->map(function (ChargeDefinition $charge) use ($latestEntries, $bookPublicIds): array {
                $latest = $latestEntries->get($charge->id);

                return [
                    'publicId' => $charge->public_id,
                    'code' => $charge->code,
                    'displayName' => $charge->display_name,
                    'type' => $charge->type,
                    'unit' => $charge->unit,
                    'isActive' => $charge->is_active,
                    'currentPrice' => $latest ? [
                        'priceBookPublicId' => $bookPublicIds->get($latest->price_book_id),
                        'amountSen' => $latest->unit_price_sen,
                        'version' => $latest->version,
                    ] : null,
                    'scheduledPrice' => $latest?->scheduled,
                ];
            })->values(),
        ]);
    }

    /** CAT-2: read-only price list check. Writes nothing. */
    public function priceCheck(Request $request, BranchAccessService $branches, PriceListCheckService $checks): Response
    {
        try {
            $data = $request->validate([
                'search' => ['nullable', 'string', 'max:100'],
                'type' => ['nullable', Rule::in(['consultation', 'medicine', 'service'])],
                'attention' => ['nullable', 'boolean'],
                'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            ]);
        } catch (ValidationException $exception) {
            // Stay on this page with the message, never back() to wherever the session last was.
            throw $exception->redirectTo(route('pricing.price-check'));
        }
        $actor = $request->user();
        $branch = $branches->activeBranch($actor);
        $filters = [
            'search' => $data['search'] ?? null,
            'type' => $data['type'] ?? null,
            'attention' => (bool) ($data['attention'] ?? false),
        ];

        return Inertia::render('Pricing/PriceCheck', [
            'branch' => $branch ? ['id' => $branch->id, 'name' => $branch->name] : null,
            'filters' => $filters,
            'result' => $branch ? $checks->check($actor, $branch, $filters, (int) ($data['page'] ?? 1)) : null,
        ]);
    }

    public function history(Request $request, ChargeDefinition $charge, BranchAccessService $branches, PriceHistoryService $history): Response
    {
        try {
            $data = $request->validate([
                'book' => ['nullable', 'uuid'],
                'from' => ['nullable', Rule::in(['price-check'])],
                'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            ], [
                'book.uuid' => 'Choose a price book from the list.',
                'from.in' => 'That page link is not recognised. Open the history from Pricing.',
                'page.*' => 'That page number is not valid. Go back to the first page.',
            ]);
        } catch (ValidationException $exception) {
            // Stay on this page with the message, never back() to wherever the session last was.
            // Keep the way back when it was valid.
            $from = $request->query('from') === 'price-check' ? ['from' => 'price-check'] : [];
            throw $exception->redirectTo(route('pricing.charges.history', ['charge' => $charge, ...$from]));
        }
        $actor = $request->user();
        $branch = $branches->activeBranch($actor);
        $result = $history->history($actor, $charge, $branch, $data['book'] ?? null, (int) ($data['page'] ?? 1));

        return Inertia::render('Pricing/PriceHistory', [
            'filters' => ['book' => $result['book']],
            'from' => $data['from'] ?? null,
            // The time zone the "In effect from" times are shown in (the active branch's, as in the history service), so
            // a page can name it. A name only; no branch or patient data.
            'timezone' => $branch !== null ? $branch->timezone : (string) config('app.timezone'),
            'result' => $result,
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

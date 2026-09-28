<?php

namespace App\Http\Controllers;

use App\Domain\Clinical\Models\ClinicalServiceCatalogueItem;
use App\Domain\Clinical\Services\ClinicalServiceCatalogueAdministrationService;
use App\Http\Requests\ClinicalServiceCatalogueStoreRequest;
use App\Http\Requests\ClinicalServiceCatalogueUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ClinicalServiceCatalogueController extends Controller
{
    public function index(Request $request): Response
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

        return Inertia::render('ClinicalService/Index', [
            'services' => [
                'data' => $paginator->getCollection()->map(fn (ClinicalServiceCatalogueItem $service): array => [
                    'publicId' => $service->public_id,
                    'code' => $service->code,
                    'displayName' => $service->display_name,
                    'orderUnit' => $service->order_unit,
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
        ]);
    }

    public function store(ClinicalServiceCatalogueStoreRequest $request, ClinicalServiceCatalogueAdministrationService $service): RedirectResponse
    {
        $service->create($request->user(), $request->validated());
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

<?php

namespace App\Http\Controllers;

use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Services\MedicineAdministrationService;
use App\Http\Requests\MedicineCatalogueStoreRequest;
use App\Http\Requests\MedicineCatalogueUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MedicineCatalogueController extends Controller
{
    public function index(Request $request): Response
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
        ]);
    }

    public function store(MedicineCatalogueStoreRequest $request, MedicineAdministrationService $service): RedirectResponse
    {
        $service->create($request->user(), $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Medicine created.')]);

        return to_route('medicines.index');
    }

    public function update(MedicineCatalogueUpdateRequest $request, MedicineCatalogueItem $medicine, MedicineAdministrationService $service): RedirectResponse
    {
        $service->update($request->user(), $medicine, $request->validated());
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

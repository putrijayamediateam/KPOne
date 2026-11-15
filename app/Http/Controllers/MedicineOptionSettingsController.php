<?php

namespace App\Http\Controllers;

use App\Domain\Clinical\Services\MedicineOptionSettingsService;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MedicineOptionSettingsController extends Controller
{
    public function index(Request $request, MedicineOptionSettingsService $service): Response
    {
        $data = $request->validate(['type' => ['sometimes', 'string'], 'q' => ['sometimes', 'nullable', 'string', 'max:200'], 'page' => ['sometimes', 'integer', 'min:1', 'max:10000']]);

        return Inertia::render('InventoryServicesSettings/MedicineDetails', $service->index($this->actor($request), $data['type'] ?? 'medicine_group', trim((string) ($data['q'] ?? '')), (int) ($data['page'] ?? 1)));
    }

    public function store(Request $request, string $type, MedicineOptionSettingsService $service): RedirectResponse
    {
        try {
            $service->create($this->actor($request), $type, $request->all());
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('medicine-details.index', ['type' => $type]));
        }

        return to_route('medicine-details.index', ['type' => $type])->with('success', 'Options added.');
    }

    public function rename(Request $request, string $type, int $option, MedicineOptionSettingsService $service): RedirectResponse
    {
        return $this->update($request, $type, $option, $service, true);
    }

    public function active(Request $request, string $type, int $option, MedicineOptionSettingsService $service): RedirectResponse
    {
        return $this->update($request, $type, $option, $service, false);
    }

    private function update(Request $request, string $type, int $option, MedicineOptionSettingsService $service, bool $rename): RedirectResponse
    {
        try {
            $service->update($this->actor($request), $type, $option, $request->all(), $rename);
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('medicine-details.index', ['type' => $type]));
        }

        return to_route('medicine-details.index', ['type' => $type]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}

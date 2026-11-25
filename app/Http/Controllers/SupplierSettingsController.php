<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Inventory\Models\InventorySupplier;
use App\Domain\Organisation\Inventory\Services\SupplierSettingsDirectoryService;
use App\Domain\Organisation\Inventory\Services\SupplierSettingsService;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SupplierSettingsController extends Controller
{
    public function index(Request $request, SupplierSettingsDirectoryService $directory): Response
    {
        $data = $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:200'], 'page' => ['sometimes', 'integer', 'min:1', 'max:10000']]);

        return Inertia::render('InventoryServicesSettings/Suppliers', $directory->index($this->actor($request), trim((string) ($data['q'] ?? '')), (int) ($data['page'] ?? 1)));
    }

    public function store(Request $request, SupplierSettingsService $service): RedirectResponse
    {
        try {
            $service->create($this->actor($request), $request->all());
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('supplier-settings.index'));
        }

        return to_route('supplier-settings.index')->with('success', 'Supplier added.');
    }

    public function active(Request $request, InventorySupplier $supplier, SupplierSettingsService $service): RedirectResponse
    {
        try {
            $service->setActive($this->actor($request), $supplier, $request->all());
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('supplier-settings.index'));
        }

        return to_route('supplier-settings.index');
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}

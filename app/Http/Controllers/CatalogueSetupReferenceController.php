<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Inventory\Services\InventoryReferenceAdministrationService;
use App\Domain\Organisation\Inventory\Services\SupplierAdministrationService;
use App\Domain\Organisation\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CatalogueSetupReferenceController extends Controller
{
    public function storeSupplier(Request $request, SupplierAdministrationService $service): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:200'],
            'contact_name' => ['nullable', 'string', 'max:200'],
            'business_email' => ['nullable', 'email:rfc', 'max:254'],
            'business_phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+() .-]+$/'],
        ]);
        $supplier = $service->create($request->user(), $data);

        return response()->json(['data' => [
            'publicId' => $supplier->public_id,
            'code' => $supplier->code,
            'name' => $supplier->name,
        ]], 201);
    }

    public function storeLocation(
        Request $request,
        InventoryReferenceAdministrationService $service,
    ): JsonResponse {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:200'],
            'type' => ['required', Rule::in(['medical_stock', 'branch_store', 'dispensary'])],
        ]);
        $actor = $request->user();
        $branch = filled($data['branch_id'] ?? null)
            ? Branch::query()->where('organisation_id', $actor->organisation_id)
                ->whereKey((int) $data['branch_id'])->firstOrFail()
            : null;
        $location = $service->createLocation($actor, $branch, null, [
            'code' => $data['code'],
            'name' => $data['name'],
            'type' => $data['type'],
        ]);

        return response()->json(['data' => [
            'publicId' => $location->public_id,
            'name' => $location->name,
            'branchId' => $location->branch_id,
            'type' => $location->type,
        ]], 201);
    }
}

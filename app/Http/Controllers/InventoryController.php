<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Inventory\Services\InventoryDirectoryService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function __invoke(Request $request, InventoryDirectoryService $directory): Response
    {
        $data = $request->validate([
            'tab' => ['sometimes', Rule::in(['stock', 'batches', 'movements'])],
            'search' => ['nullable', 'string', 'max:100'],
            'location' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'movement_type' => ['nullable', Rule::in(['opening_balance', 'transfer', 'dispense'])],
            'batch' => ['nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);

        $filters = [
            'tab' => $data['tab'] ?? 'stock',
            'search' => isset($data['search']) ? trim($data['search']) : null,
            'location' => $data['location'] ?? null,
            'status' => $data['status'] ?? null,
            'movement_type' => $data['movement_type'] ?? null,
            'batch' => isset($data['batch']) ? trim($data['batch']) : null,
            'page' => (int) ($data['page'] ?? 1),
        ];

        return Inertia::render('Inventory/Index', [
            'inventory' => $directory->directory($request->user(), $filters),
        ]);
    }
}

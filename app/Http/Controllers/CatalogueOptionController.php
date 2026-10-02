<?php

namespace App\Http\Controllers;

use App\Domain\Clinical\Services\CatalogueOptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogueOptionController extends Controller
{
    public function index(Request $request, string $type, CatalogueOptionService $service): JsonResponse
    {
        $data = $request->validate(['query' => ['nullable', 'string', 'max:200']]);

        return response()->json(['data' => $service->search($request->user(), $type, $data['query'] ?? null)]);
    }

    public function store(Request $request, string $type, CatalogueOptionService $service): JsonResponse
    {
        $data = $request->validate(['label' => ['required', 'string', 'max:200']]);
        $option = $service->create($request->user(), $type, $data['label']);

        return response()->json(['data' => [
            'value' => $option->label,
            'label' => $option->label,
        ]], 201);
    }
}

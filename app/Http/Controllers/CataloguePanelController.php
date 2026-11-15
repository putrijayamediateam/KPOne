<?php

namespace App\Http\Controllers;

use App\Domain\Visit\Services\PanelAdministrationService;
use App\Http\Requests\CataloguePanelStoreRequest;
use Illuminate\Http\JsonResponse;

class CataloguePanelController extends Controller
{
    public function store(CataloguePanelStoreRequest $request, PanelAdministrationService $service): JsonResponse
    {
        $data = $request->validated();
        $panel = $service->create($request->user(), [
            'code' => $data['code'],
            'name' => $data['name'],
        ]);

        return response()->json(['data' => [
            'id' => $panel->id,
            'code' => $panel->code,
            'name' => $panel->name,
        ]], 201);
    }
}

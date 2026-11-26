<?php

namespace App\Http\Controllers;

use App\Domain\Queue\Emergency\EmergencyAlertService;
use App\Domain\Visit\Models\Visit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EmergencyAlertController extends Controller
{
    public function seen(Request $request, Visit $visit, EmergencyAlertService $alerts): JsonResponse
    {
        return response()->json($alerts->seen($request->user(), $visit, $request->all()))->header('Cache-Control', 'no-store, private');
    }
}

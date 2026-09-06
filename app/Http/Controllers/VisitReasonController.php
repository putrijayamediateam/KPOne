<?php

namespace App\Http\Controllers;

use App\Domain\Visit\Services\VisitReasonService;
use App\Http\Requests\StoreVisitReasonRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VisitReasonController extends Controller
{
    public function index(Request $request, VisitReasonService $reasons): JsonResponse
    {
        $query = is_string($request->query('query')) ? $request->query('query') : '';

        return response()->json(['data' => $reasons->search($request->user(), $query)]);
    }

    public function store(StoreVisitReasonRequest $request, VisitReasonService $reasons): JsonResponse
    {
        $reason = $reasons->create($request->user(), (string) $request->validated('name'));

        return response()->json(['data' => ['publicId' => $reason->public_id, 'name' => $reason->name]], 201);
    }
}

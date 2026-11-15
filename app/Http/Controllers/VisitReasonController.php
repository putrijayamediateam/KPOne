<?php

namespace App\Http\Controllers;

use App\Domain\Visit\Services\VisitReasonService;
use App\Http\Requests\StoreVisitReasonRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VisitReasonController extends Controller
{
    public function index(Request $request, VisitReasonService $reasons): JsonResponse
    {
        $query = Validator::make($request->query(), [
            'query' => ['nullable', 'string', 'max:120'],
        ])->validate()['query'] ?? '';

        return response()->json(['data' => $reasons->search($request->user(), $query)]);
    }

    public function store(StoreVisitReasonRequest $request, VisitReasonService $reasons): JsonResponse
    {
        $reason = $reasons->create($request->user(), (string) $request->validated('name'));

        return response()->json(['data' => [
            'publicId' => $reason->public_id,
            'name' => $reason->name,
            'isActive' => $reason->is_active,
        ]], $reason->wasRecentlyCreated ? 201 : 200);
    }
}

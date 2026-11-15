<?php

namespace App\Http\Controllers;

use App\Domain\Clinical\Documents\AttendanceService;
use App\Domain\Visit\Models\Visit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AttendanceController extends Controller
{
    public function index(Request $request, Visit $visit, AttendanceService $service): Response
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:10000']]);

        return Inertia::render('Attendance/Index', ['attendance' => $service->history($request->user(), $visit, (int) ($data['page'] ?? 1))]);
    }

    public function source(Request $request, Visit $visit, AttendanceService $service): JsonResponse
    {
        return response()->json($service->source($request->user(), $visit))->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $request, Visit $visit, AttendanceService $service): JsonResponse
    {
        return response()->json($service->preview($request->user(), $visit, $request->all()))->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Visit $visit, AttendanceService $service): JsonResponse
    {
        return response()->json(['id' => $service->act($request->user(), $visit, $request->all())])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Visit $visit, int $document, AttendanceService $service): \Illuminate\Http\Response
    {
        return response()->view('attendance.show', $service->copy($request->user(), $visit, $document))->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Content-Security-Policy', "default-src 'none'; img-src data:; style-src 'unsafe-inline'; script-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'")
            ->header('X-Content-Type-Options', 'nosniff')->header('Referrer-Policy', 'no-referrer');
    }
}

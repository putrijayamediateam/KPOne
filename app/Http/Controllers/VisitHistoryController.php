<?php

namespace App\Http\Controllers;

use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitHistoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VisitHistoryController extends Controller
{
    public function __invoke(Request $request, Visit $visit, VisitHistoryService $history): Response
    {
        return Inertia::render('Visits/History', ['history' => $history->detail($request->user(), $visit)]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Inventory\Services\BranchReplenishmentService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** STK-31: read-only suggestions to send stock to another branch. The rules live in BranchReplenishmentService. */
final class BranchReplenishmentController extends Controller
{
    public function index(Request $request, BranchReplenishmentService $service): Response
    {
        return Inertia::render('Inventory/BranchReplenishment', ['suggestions' => $service->page($request->user(), $request->query())]);
    }
}

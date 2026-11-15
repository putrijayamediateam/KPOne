<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Inventory\Services\ReorderSuggestionService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** STK-16: read-only reorder suggestions. The rules live in ReorderSuggestionService. */
final class ReorderSuggestionController extends Controller
{
    public function index(Request $request, ReorderSuggestionService $service): Response
    {
        return Inertia::render('Inventory/ReorderSuggestions', ['suggestions' => $service->page($request->user(), $request->query())]);
    }
}

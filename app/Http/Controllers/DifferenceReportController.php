<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Inventory\Services\DifferenceReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** STK-45: Report a difference on a stock line. It returns to the page it came from; nothing here moves stock. */
final class DifferenceReportController extends Controller
{
    private const RETURN_TO = ['inventory' => 'inventory.index', 'stock-review' => 'inventory.stock-review'];

    public function store(Request $request, DifferenceReportService $service): RedirectResponse
    {
        $route = self::RETURN_TO[(string) $request->input('return_to')] ?? 'inventory.index';
        $query = array_filter((array) $request->input('return_query', []), fn ($value, $key): bool => is_string($key) && in_array($key, ['tab', 'search', 'location', 'status', 'batch', 'page', 'kind', 'review_page'], true) && (is_string($value) || is_int($value)), ARRAY_FILTER_USE_BOTH);
        try {
            $result = $service->report($request->user(), $request->only(['expected_branch_id', 'retry_key', 'balance_id', 'balance_version', 'observed', 'rough_quantity', 'note']));
        } catch (ValidationException $e) {
            throw $e->redirectTo(route($route, $query));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $result['openedCase'] ? 'Sent. A stock count was opened for this batch.' : 'Sent. Added to the stock count already open for this batch.']);

        return to_route($route, $query);
    }
}

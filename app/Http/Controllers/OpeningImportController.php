<?php

namespace App\Http\Controllers;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\OpeningImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** STK-35: import opening stock from one CSV. The rules live in OpeningImportService. */
final class OpeningImportController extends Controller
{
    public function index(Request $request, OpeningImportService $service): Response
    {
        $branch = app(BranchAccessService::class)->activeBranch($request->user());
        abort_unless($branch !== null, 403);
        // The same checks as Check and Record: active, CA Supervisor, assigned to the active branch today.
        $service->context($request->user(), $branch->id);

        return Inertia::render('Inventory/OpeningImport', ['import' => [
            'branchName' => $branch->name, 'expectedBranchId' => $branch->id,
            'columns' => OpeningImportService::COLUMNS, 'maxRows' => OpeningImportService::MAX_ROWS,
        ]]);
    }

    /** Checks the file and returns each row's result. Writes nothing. */
    public function check(Request $request, OpeningImportService $service): JsonResponse
    {
        $review = $service->check($request->user(), $request->input('expected_branch_id'), $request->file('file'));

        return response()->json([
            'hash' => $review['hash'],
            'errors' => $review['errors'],
            'newBatches' => $review['newBatches'],
            // Internal ids never leave the server.
            'rows' => array_map(fn (array $row): array => array_intersect_key($row, array_flip(['line', 'location', 'sku', 'stock_unit', 'batch_number', 'new_batch', 'expiry_date', 'quantity', 'unit_cost_sen', 'problems'])), $review['rows']),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, OpeningImportService $service): RedirectResponse
    {
        try {
            $result = $service->record($request->user(), $request->only(['expected_branch_id', 'idempotency_key', 'file_hash']), $request->file('file'));
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('inventory.opening-import'));
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $result['recorded'].' opening '.($result['recorded'] === 1 ? 'line' : 'lines').' recorded. Each needs sign-off on Pilot readiness by someone else.']);

        return to_route('inventory.readiness');
    }

    public function template(OpeningImportService $service): HttpResponse
    {
        return response($service->template(), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="opening-stock-template.csv"', 'Cache-Control' => 'private, no-store']);
    }
}

<?php

namespace App\Http\Controllers;

use App\Domain\Visit\Billing\Services\CashClosingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** BIL-4: daily cash closing (count, then approve or return by someone else). Rules live in CashClosingService. */
class CashClosingController extends Controller
{
    public function index(Request $request, CashClosingService $service): Response
    {
        $date = $request->query('date');
        try {
            $closing = $service->page($request->user(), is_string($date) && $date !== '' ? $date : null);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('cash-closings.index'));
        }

        return Inertia::render('Billing/CashClosings', ['cashClosing' => $closing]);
    }

    /** BIL-7: read-only month view. A bad or future month shows the current month (handled in the service). */
    public function month(Request $request, CashClosingService $service): Response
    {
        $month = $request->query('month');

        return Inertia::render('Billing/CashClosingMonth', ['cashClosingMonth' => $service->month($request->user(), is_string($month) ? $month : null)]);
    }

    /** BIL-15: set or change the branch's cash closing start date, from the month page. */
    public function startDate(Request $request, CashClosingService $service): RedirectResponse
    {
        $month = $request->input('month');
        $back = route('cash-closings.month', is_string($month) && preg_match('/\A\d{4}-\d{2}\z/', $month) === 1 ? ['month' => $month] : []);
        try {
            $service->setStartDate($request->user(), $request->only(['expected_branch_id', 'starts_on', 'expected_revision', 'idempotency_key']));
        } catch (ValidationException $exception) {
            throw $exception->redirectTo($back);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cash closing start date saved.']);

        return redirect($back);
    }

    public function store(Request $request, CashClosingService $service): RedirectResponse
    {
        $back = $this->back($request->input('business_date'));
        try {
            $service->count($request->user(), $request->only(['expected_branch_id', 'business_date', 'counted_cash', 'variance_reason', 'idempotency_key']));
        } catch (ValidationException $exception) {
            throw $exception->redirectTo($back);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cash count recorded. Someone else must now approve it.']);

        return redirect($back);
    }

    public function decide(Request $request, CashClosingService $service, string $closing, string $decision): RedirectResponse
    {
        $back = $this->back($request->input('business_date'));
        try {
            $service->decide($request->user(), $closing, $decision, $request->only(['expected_branch_id', 'reason', 'idempotency_key']));
        } catch (ValidationException $exception) {
            throw $exception->redirectTo($back);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $decision === 'approved' ? 'Cash closing approved.' : 'Cash count returned for a new count.']);

        return redirect($back);
    }

    private function back(mixed $date): string
    {
        return route('cash-closings.index', is_string($date) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) === 1 ? ['date' => $date] : []);
    }
}

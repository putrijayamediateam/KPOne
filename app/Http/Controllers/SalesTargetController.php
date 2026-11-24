<?php

namespace App\Http\Controllers;

use App\Domain\Visit\Billing\Services\SalesTargetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** BIL-30: monthly sales targets per branch and per doctor. Rules live in SalesTargetService. */
class SalesTargetController extends Controller
{
    public function index(Request $request, SalesTargetService $service): Response
    {
        $month = $request->query('month');

        return Inertia::render('Billing/SalesTargets', ['salesTargets' => $service->page($request->user(), is_string($month) ? $month : null)]);
    }

    public function store(Request $request, SalesTargetService $service): RedirectResponse
    {
        $month = $request->input('month');
        $back = route('sales-targets.index', is_string($month) && preg_match('/\A\d{4}-\d{2}\z/', $month) === 1 ? ['month' => $month] : []);
        try {
            $revision = $service->save($request->user(), $request->only(['expected_branch_id', 'branch_id', 'month', 'doctor_user_id', 'amount', 'lock_version', 'reason', 'idempotency_key']));
        } catch (ValidationException $exception) {
            throw $exception->redirectTo($back);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => match ($revision->action) {
            'cleared' => 'Target cleared.',
            'changed' => 'Target changed.',
            default => 'Target set.',
        }]);

        return redirect($back);
    }
}

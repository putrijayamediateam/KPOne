<?php

namespace App\Http\Controllers;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\RfqService;
use App\Domain\Organisation\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** STK-48: requests for quotation. Every rule lives in RfqService and PostgreSQL. */
final class RfqController extends Controller
{
    public function index(Request $request, RfqService $service, BranchAccessService $branches): Response
    {
        return Inertia::render('Inventory/Rfqs', ['rfqs' => $service->page($request->user(), $this->branch($request, $branches), $request->query())]);
    }

    public function store(Request $request, RfqService $service): RedirectResponse
    {
        try {
            $round = $service->start($request->user(), $request->only(['expected_branch_id', 'idempotency_key', 'destination_location_public_id', 'quote_by_date', 'supplier_public_ids', 'lines']));
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('inventory.rfqs'));
        }

        return redirect(route('inventory.rfqs.round', ['round' => $round]))->with('success', 'Quote round started. Print each RFQ and send it to the supplier.');
    }

    public function round(Request $request, string $round, RfqService $service, BranchAccessService $branches): Response
    {
        return Inertia::render('Inventory/RfqRound', ['rfq' => $service->round($request->user(), $this->branch($request, $branches), $round)]);
    }

    public function roundAct(Request $request, string $round, string $action, RfqService $service): RedirectResponse
    {
        $back = route('inventory.rfqs.round', ['round' => $round]);
        try {
            if ($action === 'convert') {
                $result = $service->convert($request->user(), $round, $request->only(['expected_branch_id', 'lock_version', 'idempotency_key']));
                $left = count($result['leftOff']);

                return redirect($back)->with('success', 'Draft purchase order made. Submit it from Inventory > Operations.'.($left > 0 ? " {$left} ".($left === 1 ? 'item was' : 'items were').' left off.' : ''));
            }
            $service->cancelRound($request->user(), $round, $request->only(['expected_branch_id', 'lock_version', 'reason', 'note']));
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        }

        return redirect($back)->with('success', 'Quote round cancelled.');
    }

    public function act(Request $request, string $rfq, string $action, RfqService $service): RedirectResponse
    {
        $round = (string) $request->input('round_public_id', '');
        $back = preg_match('/^[0-9a-f-]{36}$/i', $round) === 1 ? route('inventory.rfqs.round', ['round' => $round]) : route('inventory.rfqs');
        try {
            match ($action) {
                'sent' => $service->markSent($request->user(), $rfq, $request->only(['expected_branch_id', 'lock_version'])),
                'quote' => $service->recordQuote($request->user(), $rfq, $request->only(['expected_branch_id', 'lock_version', 'idempotency_key', 'lead_time_days', 'valid_until', 'quote_reference', 'lines'])),
                'choose' => $service->choose($request->user(), $rfq, $request->only(['expected_branch_id', 'lock_version', 'reason', 'note', 'still_valid_confirmed'])),
                default => $service->cancelRfq($request->user(), $rfq, $request->only(['expected_branch_id', 'lock_version', 'reason', 'note'])),
            };
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        }

        return redirect($back)->with('success', match ($action) {
            'sent' => 'Marked as sent.', 'quote' => 'Quote saved.', 'choose' => 'Quote chosen.', default => 'RFQ cancelled.',
        });
    }

    public function print(Request $request, string $rfq, RfqService $service): Response
    {
        return Inertia::render('Inventory/RfqPrint', $service->print($request->user(), $rfq));
    }

    private function branch(Request $request, BranchAccessService $branches): Branch
    {
        $branch = $branches->activeBranch($request->user());
        abort_unless($branch !== null && $branch->organisation_id === $request->user()->organisation_id && $branches->hasEffectiveAssignment($request->user(), $branch), 404);

        return $branch;
    }
}

<?php

namespace App\Http\Controllers;

use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceChangeProposal;
use App\Domain\Visit\Billing\Services\PanelPriceChangesPage;
use App\Domain\Visit\Billing\Services\PanelPriceProposalService;
use App\Domain\Visit\Billing\Services\PanelPriceTarget;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Panel price changes: a Panel Officer proposes, a Finance Officer or Director decides. */
class PanelPriceChangesController extends Controller
{
    public function index(Request $request, PanelPriceChangesPage $page): Response
    {
        return Inertia::render('PanelPriceChanges/Index', $page->page(
            $this->actor($request),
            is_string($request->query('panel')) ? $request->query('panel') : null,
            is_string($request->query('charge')) ? $request->query('charge') : null,
            max(1, min(10000, (int) $request->query('decided_page', 1))),
        ));
    }

    public function store(Request $request, PanelPriceProposalService $service): RedirectResponse
    {
        $key = (string) $request->input('panel');
        abort_unless($key === 'default' || ctype_digit($key), 404);
        $target = PanelPriceTarget::forPanel($key === 'default' ? null : (int) $key);
        $charge = ChargeDefinition::query()->where('public_id', (string) $request->input('charge'))->where('organisation_id', $this->actor($request)->organisation_id)->firstOrFail();

        return $this->run(fn () => $service->propose($this->actor($request), $target, $charge, $request->only([
            'expected_branch_id', 'expected_version', 'amount_sen', 'agreement_reference', 'effective_date', 'effective_time', 'idempotency_key',
        ])), 'Price change proposed. Someone else must approve it before it applies.');
    }

    public function approve(Request $request, PriceChangeProposal $proposal, PanelPriceProposalService $service): RedirectResponse
    {
        return $this->run(fn () => $service->approve($this->actor($request), $proposal, $request->only(['expected_branch_id', 'lock_version'])), 'Price change approved.');
    }

    /** PNL-30: approve the ticked waiting proposals in one press; each is decided on its own (see approveMany). */
    public function approveSelected(Request $request, PanelPriceProposalService $service): RedirectResponse
    {
        $items = $request->input('items');
        $items = is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
        $back = route('panel-price-changes.index');
        set_time_limit(180);
        try {
            $result = $service->approveMany($this->actor($request), (int) $request->input('expected_branch_id'), $items);
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        }
        $count = $result['approved'];
        if ($count > 0) {
            Inertia::flash('toast', ['type' => 'success', 'message' => $count === 1 ? '1 price change approved.' : $count.' price changes approved.']);
        }
        if ($result['skipped'] !== []) {
            $first = collect($result['skipped'])->pluck('message')->unique()->take(3)->implode(' ');

            return redirect($back)->withErrors(['bulk' => count($result['skipped']).' could not be approved and are still waiting. '.$first]);
        }

        return redirect($back);
    }

    public function reject(Request $request, PriceChangeProposal $proposal, PanelPriceProposalService $service): RedirectResponse
    {
        return $this->run(fn () => $service->reject($this->actor($request), $proposal, $request->only(['expected_branch_id', 'lock_version', 'reason_code', 'note'])), 'Price change rejected.');
    }

    public function withdraw(Request $request, PriceChangeProposal $proposal, PanelPriceProposalService $service): RedirectResponse
    {
        return $this->run(fn () => $service->withdraw($this->actor($request), $proposal, $request->only(['expected_branch_id', 'lock_version'])), 'Price change withdrawn.');
    }

    /** @param callable(): mixed $action */
    private function run(callable $action, string $message): RedirectResponse
    {
        $back = route('panel-price-changes.index');
        try {
            $action();
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect($back);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}

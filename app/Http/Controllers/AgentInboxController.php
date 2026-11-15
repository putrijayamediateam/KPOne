<?php

namespace App\Http\Controllers;

use App\Domain\Agents\Proposals\AiProposal;
use App\Domain\Agents\Proposals\ProposalInbox;
use App\Domain\Agents\Proposals\ProposalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** AG-2: the approval inbox. Deciding records the decision only; nothing in stock changes. */
class AgentInboxController extends Controller
{
    public function index(Request $request, ProposalInbox $inbox): Response
    {
        return Inertia::render('Agents/Inbox', $inbox->page($request->user(), $request->only(['tab', 'page'])));
    }

    public function approve(Request $request, AiProposal $proposal, ProposalService $proposals): RedirectResponse
    {
        return $this->decide($request, $proposal, fn () => $proposals->approve($request->user(), $proposal, $request->only(['lock_version', 'reason'])), 'Proposal approved. Nothing in stock has changed; act on it from the Inventory screens.');
    }

    public function reject(Request $request, AiProposal $proposal, ProposalService $proposals): RedirectResponse
    {
        return $this->decide($request, $proposal, fn () => $proposals->reject($request->user(), $proposal, $request->only(['lock_version', 'reason'])), 'Proposal rejected.');
    }

    private function decide(Request $request, AiProposal $proposal, callable $action, string $message): RedirectResponse
    {
        abort_unless($proposal->organisation_id === $request->user()->organisation_id, 404);
        $back = route('agents.inbox', array_filter([
            'tab' => in_array($request->query('tab'), ProposalInbox::TABS, true) ? $request->query('tab') : 'pending',
            'page' => filter_var($request->query('page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 2]]) ?: null,
        ]));
        try {
            $action();
        } catch (ValidationException $exception) {
            throw $exception->redirectTo($back);
        } catch (AuthorizationException $exception) {
            throw ValidationException::withMessages(['status' => $exception->getMessage()])->redirectTo($back);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect($back);
    }
}

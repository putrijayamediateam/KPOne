<?php

namespace App\Http\Controllers;

use App\Domain\Visit\Billing\Services\PanelClaimPackIssueService;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PNL-25: recorded, frozen Panel claim packs. "Print pack" posts here (a POST with an idempotency key), records the
 * print and opens the frozen copy; the old live pack page stays as a preview. A refused request returns to the page it
 * came from, never to "previous".
 */
class PanelClaimPackCopiesController extends Controller
{
    public function issue(Request $request, PanelClaimPackIssueService $service): RedirectResponse
    {
        $actor = $this->actor($request);
        $from = $this->from($request);
        try {
            $print = $service->issue($actor, $request->only(['claims', 'idempotency_key']));
        } catch (ValidationException $exception) {
            throw $exception->redirectTo($this->back($from));
        }

        return to_route('panel-claims.pack.copy', ['print' => $print->public_id, 'from' => $from]);
    }

    public function reprint(Request $request, string $document, PanelClaimPackIssueService $service): RedirectResponse
    {
        $actor = $this->actor($request);
        $from = $this->from($request);
        try {
            $print = $service->reprint($actor, $document, $request->only(['idempotency_key']));
        } catch (ValidationException $exception) {
            throw $exception->redirectTo($this->back($from));
        }

        return to_route('panel-claims.pack.copy', ['print' => $print->public_id, 'from' => $from]);
    }

    public function show(Request $request, string $print, PanelClaimPackIssueService $service): Response
    {
        $actor = $this->actor($request);
        $from = $this->from($request);
        try {
            $data = $service->show($actor, $print);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo($this->back($from));
        }

        return Inertia::render('PanelClaims/Pack', $data + [
            'backUrl' => $this->back($from),
            'backLabel' => $from !== null ? 'Back to claim' : 'Back to Panel Claims',
        ]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    /** The claim page the request came from (a claim's public id), or null for the list. */
    private function from(Request $request): ?string
    {
        $from = $request->input('from', $request->query('from'));

        return is_string($from) && Str::isUuid($from) ? strtolower($from) : null;
    }

    private function back(?string $from): string
    {
        return $from !== null ? route('panel-claims.show', ['claim' => $from]) : route('panel-claims.index');
    }
}

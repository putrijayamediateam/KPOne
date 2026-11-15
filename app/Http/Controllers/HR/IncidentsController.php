<?php

namespace App\Http\Controllers\HR;

use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\Incidents\Services\IncidentService;
use App\Domain\HR\Incidents\Services\IncidentsPageService;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** HR-10d incident reports. Thin: every rule lives in app/Domain/HR/Incidents. */
class IncidentsController extends Controller
{
    public function index(Request $request, IncidentsPageService $pages): Response
    {
        return Inertia::render('HR/Incidents', ['incidents' => $pages->page($request->user(), $request->only(['tab']))]);
    }

    /** One report: the reporter's own view, or a manager's (recorded before it is shown). */
    public function show(Request $request, IncidentsPageService $pages, string $incident): Response
    {
        try {
            $props = $pages->show($request->user(), $incident);
        } catch (AuthorizationException) {
            $this->denied($request);
            abort(403);
        }

        return Inertia::render('HR/Incident', ['incident' => $props]);
    }

    public function store(Request $request, IncidentService $incidents): RedirectResponse
    {
        $id = null;
        $response = $this->run($request, route('hr.incidents.index', ['tab' => 'mine']), 'Report sent to HR.', function () use ($request, $incidents, &$id): void {
            $id = $incidents->report($request->user(), $request->only(['branch_id', 'occurred_on', 'occurred_time', 'category', 'director_only', 'description', 'idempotency_key']),
                IncidentService::preparePhotos($request->file('photos')));
        });

        return is_string($id) ? redirect()->route('hr.incidents.show', $id) : $response;
    }

    public function photo(Request $request, IncidentService $incidents, string $incident, string $photo): \Illuminate\Http\Response
    {
        try {
            $opened = $incidents->openPhoto($request->user(), $incident, $photo);
        } catch (AuthorizationException) {
            $this->denied($request);
            abort(403);
        }

        return response($opened['content'], 200, ['Content-Type' => $opened['mime'], 'Content-Disposition' => 'inline; filename="'.$opened['filename'].'"',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'", 'Cache-Control' => 'no-store, private']);
    }

    public function acknowledge(Request $request, IncidentService $incidents, string $incident): RedirectResponse
    {
        return $this->run($request, route('hr.incidents.show', $incident), 'Report acknowledged.',
            fn () => $incidents->acknowledge($request->user(), $incident, $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    public function note(Request $request, IncidentService $incidents, string $incident): RedirectResponse
    {
        return $this->run($request, route('hr.incidents.show', $incident), 'Note added.',
            fn () => $incidents->addNote($request->user(), $incident, $request->only(['note', 'idempotency_key'])));
    }

    public function action(Request $request, IncidentService $incidents, string $incident): RedirectResponse
    {
        return $this->run($request, route('hr.incidents.show', $incident), 'Action added.',
            fn () => $incidents->addAction($request->user(), $incident, $request->only(['description', 'owner_id', 'due_on', 'idempotency_key'])));
    }

    public function actionDone(Request $request, IncidentService $incidents, string $incident, string $action): RedirectResponse
    {
        return $this->run($request, route('hr.incidents.show', $incident), 'Action marked done.',
            fn () => $incidents->markActionDone($request->user(), $incident, $action, $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    public function link(Request $request, IncidentService $incidents, string $incident): RedirectResponse
    {
        return $this->run($request, route('hr.incidents.show', $incident), 'Report linked to the discipline case.',
            fn () => $incidents->linkCase($request->user(), $incident, $request->only(['case_id', 'expected_lock_version', 'idempotency_key'])));
    }

    public function close(Request $request, IncidentService $incidents, string $incident): RedirectResponse
    {
        return $this->run($request, route('hr.incidents.show', $incident), 'Report closed.',
            fn () => $incidents->close($request->user(), $incident, $request->only(['outcome', 'expected_lock_version', 'idempotency_key'])));
    }

    private function run(Request $request, string $back, string $message, callable $action): RedirectResponse
    {
        try {
            $action();
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        } catch (AuthorizationException $e) {
            $this->denied($request);
            throw ValidationException::withMessages(['incident' => $e->getMessage() !== '' ? $e->getMessage() : 'You may not do this.'])->redirectTo($back);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect($back);
    }

    private function denied(Request $request): void
    {
        app(AuditRecorder::class)->record('authorization.denied', $request->user(), ['route' => $request->route()?->getName(), 'method' => $request->method()], $request->user());
    }
}

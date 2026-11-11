<?php

namespace App\Http\Controllers\HR;

use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\Discipline\Services\DisciplinePageService;
use App\Domain\HR\Discipline\Services\DisciplineService;
use App\Domain\HR\Records\Services\DocumentService;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** HR-10c discipline. Thin: every rule lives in app/Domain/HR/Discipline. */
class DisciplineController extends Controller
{
    public function index(Request $request, DisciplinePageService $pages): Response
    {
        return Inertia::render('HR/Discipline', ['discipline' => $pages->page($request->user(), $request->only(['tab']))]);
    }

    /** One case. The view is recorded before the allegation or any note is read. */
    public function show(Request $request, DisciplinePageService $pages, string $case): Response
    {
        try {
            $props = $pages->show($request->user(), $case);
        } catch (AuthorizationException) {
            $this->denied($request);
            abort(403);
        }

        return Inertia::render('HR/DisciplineCase', ['case' => $props]);
    }

    public function store(Request $request, DisciplineService $discipline): RedirectResponse
    {
        $opened = null;
        $response = $this->run($request, route('hr.discipline.index'), 'Case opened.', function () use ($request, $discipline, &$opened): void {
            $opened = $discipline->open($request->user(), $request->only(['worker_id', 'incident_on', 'allegation', 'idempotency_key']));
        });

        return is_string($opened) ? redirect()->route('hr.discipline.show', $opened) : $response;
    }

    public function note(Request $request, DisciplineService $discipline, string $case): RedirectResponse
    {
        return $this->run($request, route('hr.discipline.show', $case), 'Note added.',
            fn () => $discipline->addNote($request->user(), $case, $request->only(['note', 'idempotency_key'])));
    }

    public function reply(Request $request, DisciplineService $discipline, string $case): RedirectResponse
    {
        return $this->run($request, route('hr.discipline.show', $case), 'Reply recorded.',
            fn () => $discipline->recordReply($request->user(), $case, $request->only(['reply', 'received_on', 'idempotency_key'])));
    }

    public function showCause(Request $request, DisciplineService $discipline, string $case): RedirectResponse
    {
        return $this->run($request, route('hr.discipline.show', $case), 'Show-cause letter linked.',
            fn () => $discipline->linkShowCause($request->user(), $case, $request->only(['letter_id', 'idempotency_key'])));
    }

    public function file(Request $request, DisciplineService $discipline, string $case): RedirectResponse
    {
        return $this->run($request, route('hr.discipline.show', $case), 'File added.',
            fn () => $discipline->addFile($request->user(), $case, DocumentService::prepare($request->file('file')), $request->only(['idempotency_key'])));
    }

    public function openFile(Request $request, DisciplineService $discipline, string $case, string $file): \Illuminate\Http\Response
    {
        try {
            $opened = $discipline->openFile($request->user(), $case, $file);
        } catch (AuthorizationException) {
            $this->denied($request);
            abort(403);
        }

        return response($opened['content'], 200, [
            'Content-Type' => $opened['mime'],
            'Content-Disposition' => ($opened['inline'] ? 'inline' : 'attachment').'; filename="'.$opened['filename'].'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function close(Request $request, DisciplineService $discipline, string $case): RedirectResponse
    {
        return $this->run($request, route('hr.discipline.show', $case), 'Outcome recorded. The case is closed.',
            fn () => $discipline->close($request->user(), $case, $request->only(['outcome', 'letter_id', 'note', 'expected_lock_version', 'idempotency_key'])));
    }

    private function run(Request $request, string $back, string $message, callable $action): RedirectResponse
    {
        try {
            $action();
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        } catch (AuthorizationException $e) {
            $this->denied($request);
            throw ValidationException::withMessages(['case' => $e->getMessage() !== '' ? $e->getMessage() : 'You may not do this.'])->redirectTo(route('hr.discipline.index'));
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect($back);
    }

    private function denied(Request $request): void
    {
        app(AuditRecorder::class)->record('authorization.denied', $request->user(), ['route' => $request->route()?->getName(), 'method' => $request->method()], $request->user());
    }
}

<?php

namespace App\Http\Controllers\HR;

use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\Applications\Services\ApplicationsPageService;
use App\Domain\HR\Applications\Services\JobApplicationService;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** HR-13 job applications and HR-17a hiring stages, staff side. Thin: every rule lives in app/Domain/HR/Applications. */
class ApplicationsController extends Controller
{
    public function index(Request $request, ApplicationsPageService $pages): Response
    {
        return Inertia::render('HR/Applications', ['applications' => $pages->page($request->user(), $request->only(['tab', 'status', 'position', 'page']))]);
    }

    public function addPosition(Request $request, JobApplicationService $applications): RedirectResponse
    {
        return $this->run($request, route('hr.applications.index', ['tab' => 'positions']), 'Position added. It shows on the form while open.',
            fn () => $applications->addPosition($request->user(), $request->only(['title', 'branch_id', 'position_type', 'idempotency_key'])));
    }

    public function updatePosition(Request $request, JobApplicationService $applications, string $position): RedirectResponse
    {
        return $this->run($request, route('hr.applications.index', ['tab' => 'positions']), 'Position saved.',
            fn () => $applications->updatePosition($request->user(), $position, $request->only(['title', 'branch_id', 'position_type', 'is_open', 'expected_lock_version', 'idempotency_key'])));
    }

    public function move(Request $request, JobApplicationService $applications, string $application): RedirectResponse
    {
        $back = route('hr.applications.index', array_filter(['tab' => 'applications', ...$request->only(['status', 'position', 'page'])]));

        return $this->run($request, $back, 'Application moved.',
            fn () => $applications->move($request->user(), $application, $request->only(['to', 'expected_lock_version', 'idempotency_key'])));
    }

    /** HR-17a: the phone-screening call, recorded as pass or reject. */
    public function screen(Request $request, JobApplicationService $applications, string $application): RedirectResponse
    {
        $back = route('hr.applications.index', array_filter(['tab' => 'applications', ...$request->only(['status', 'position', 'page'])]));

        return $this->run($request, $back, 'Phone screening recorded.',
            fn () => $applications->screen($request->user(), $application, $request->only(['outcome', 'rating', 'ticked', 'note', 'reject_reason', 'expected_lock_version', 'idempotency_key'])));
    }

    public function checklist(Request $request, JobApplicationService $applications): RedirectResponse
    {
        return $this->run($request, route('hr.applications.index', ['tab' => 'settings']), 'Call checklist saved.',
            fn () => $applications->setChecklist($request->user(), $request->only(['items', 'expected_version', 'idempotency_key'])));
    }

    public function retention(Request $request, JobApplicationService $applications): RedirectResponse
    {
        return $this->run($request, route('hr.applications.index', ['tab' => 'settings']), 'Retention saved.',
            fn () => $applications->setRetention($request->user(), $request->only(['retention_days', 'expected_version', 'idempotency_key'])));
    }

    /** The CV downloads; the open is recorded first. */
    public function cv(Request $request, JobApplicationService $applications, string $application): HttpResponse
    {
        try {
            $opened = $applications->openCv($request->user(), $application);
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('hr.applications.index'));
        } catch (AuthorizationException) {
            app(AuditRecorder::class)->record('authorization.denied', $request->user(), ['route' => $request->route()?->getName(), 'method' => $request->method()], $request->user());
            abort(403);
        }

        return response($opened['content'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$opened['filename'].'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function run(Request $request, string $back, string $message, callable $action): RedirectResponse
    {
        try {
            $action();
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        } catch (AuthorizationException $e) {
            app(AuditRecorder::class)->record('authorization.denied', $request->user(), ['route' => $request->route()?->getName(), 'method' => $request->method()], $request->user());
            throw ValidationException::withMessages(['applications' => $e->getMessage() !== '' ? $e->getMessage() : 'You may not do this.'])->redirectTo($back);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect($back);
    }
}

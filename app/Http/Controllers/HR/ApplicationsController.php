<?php

namespace App\Http\Controllers\HR;

use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\Applications\Services\ApplicationsPageService;
use App\Domain\HR\Applications\Services\DeadlineExtensions;
use App\Domain\HR\Applications\Services\InterviewDocuments;
use App\Domain\HR\Applications\Services\InterviewSlots;
use App\Domain\HR\Applications\Services\JobApplicationService;
use App\Domain\HR\Applications\Services\StaffAccountRequests;
use App\Domain\HR\Applications\Services\TalentPool;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
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
        return Inertia::render('HR/Applications', ['applications' => $pages->page($request->user(), $request->only(['tab', 'status', 'position', 'page', 'focus']))]);
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

    /** HR-17c: confirm one of the candidate's three times. */
    public function confirmInterview(Request $request, InterviewSlots $slots, string $application): RedirectResponse
    {
        return $this->run($request, $this->back($request), 'Interview confirmed. The candidate is told.',
            fn () => $slots->confirm($request->user(), $application, $request->only(['start', 'expected_lock_version', 'idempotency_key'])));
    }

    public function askAgain(Request $request, InterviewSlots $slots, string $application): RedirectResponse
    {
        return $this->run($request, $this->back($request), 'New times asked for. The candidate is told.',
            fn () => $slots->askAgain($request->user(), $application, $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    /** HR-17c follow-up (b): 3 more working days before the daily check closes a waiting application. */
    public function extendDeadline(Request $request, DeadlineExtensions $extensions, string $application): RedirectResponse
    {
        return $this->run($request, $this->back($request),
            fn (mixed $closesOn): string => is_string($closesOn) ? 'More time given. New closing date: '.CarbonImmutable::parse($closesOn)->format('D j M Y').'.' : 'More time given.',
            fn () => $extensions->extend($request->user(), $application, $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    public function cancelInterview(Request $request, InterviewSlots $slots, string $application): RedirectResponse
    {
        return $this->run($request, $this->back($request), 'Interview cancelled. The candidate is asked for new times.',
            fn () => $slots->cancel($request->user(), $application, $request->only(['reason', 'expected_lock_version', 'idempotency_key'])));
    }

    private function back(Request $request): string
    {
        return route('hr.applications.index', array_filter(['tab' => 'applications', ...$request->only(['status', 'position', 'page'])]));
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

    /** HR-17e: how long a pool candidate is kept after closing. */
    public function poolDays(Request $request, TalentPool $pool): RedirectResponse
    {
        return $this->run($request, route('hr.applications.index', ['tab' => 'settings']), 'Talent pool period saved.',
            fn () => $pool->setDays($request->user(), $request->only(['pool_days', 'expected_version', 'idempotency_key'])));
    }

    /** HR-17e: invite a pool candidate to an open position of the same type. The new private link is shown to HR once. */
    public function invite(Request $request, TalentPool $pool, string $application): RedirectResponse
    {
        $link = null;
        $response = $this->run($request, route('hr.applications.index', ['tab' => 'pool']), 'Invited. A new application starts at phone screening.',
            function () use ($request, $pool, $application, &$link): void {
                $link = $pool->invite($request->user(), $application, $request->only(['position', 'expected_lock_version', 'idempotency_key']));
            });
        if ($link !== null) {
            Inertia::flash('invitationLink', $link);
        }

        return $response;
    }

    public function requestAccount(Request $request, StaffAccountRequests $requests, string $application): RedirectResponse
    {
        return $this->run($request, $this->back($request), 'Staff account asked for. The account administrator sees it on the Staff page.',
            fn () => $requests->request($request->user(), $application, $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    public function cancelAccountRequest(Request $request, StaffAccountRequests $requests, string $accountRequest): RedirectResponse
    {
        return $this->run($request, $this->back($request), 'Request cancelled.',
            fn () => $requests->cancel($request->user(), $accountRequest, $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    /** HR-17d: a PDF the candidate can download once their interview is confirmed. */
    public function addDocument(Request $request, InterviewDocuments $documents): RedirectResponse
    {
        return $this->run($request, route('hr.applications.index', ['tab' => 'settings']), 'Document added.',
            fn () => $documents->upload($request->user(), $request->file('file'), $request->only(['position_type', 'title', 'idempotency_key'])));
    }

    public function removeDocument(Request $request, InterviewDocuments $documents, string $document): RedirectResponse
    {
        return $this->run($request, route('hr.applications.index', ['tab' => 'settings']), 'Document removed. Its file is deleted.',
            fn () => $documents->remove($request->user(), $document, $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    /** HR opens an interview document; the open is recorded first. */
    public function document(Request $request, InterviewDocuments $documents, string $document): HttpResponse
    {
        try {
            $opened = $documents->open($request->user(), $document);
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('hr.applications.index', ['tab' => 'settings']));
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

    /** An applicant's phone or email, shown once the view is audited (TEAM.md section 7 hardening). */
    public function showContact(Request $request, JobApplicationService $applications, string $application): JsonResponse
    {
        try {
            $value = $applications->showContact($request->user(), $application, $request->input('field'));
        } catch (ValidationException $e) {
            return response()->json(['errors' => array_map(fn (array $m): string => $m[0], $e->errors())], 422);
        } catch (AuthorizationException) {
            // As the CV and document handlers do (Suresh, m3).
            app(AuditRecorder::class)->record('authorization.denied', $request->user(), ['route' => $request->route()?->getName(), 'method' => $request->method()], $request->user());

            return response()->json(['errors' => ['field' => 'You can no longer show this.']], 403);
        }

        return response()->json(['value' => $value, 'shownAt' => now()->toIso8601String()])->header('Cache-Control', 'no-store, private');
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

    /** @param  string|\Closure(mixed): string  $message  a closure gets the action's result (follow-up b: the new date) */
    private function run(Request $request, string $back, string|\Closure $message, callable $action): RedirectResponse
    {
        try {
            $result = $action();
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        } catch (AuthorizationException $e) {
            app(AuditRecorder::class)->record('authorization.denied', $request->user(), ['route' => $request->route()?->getName(), 'method' => $request->method()], $request->user());
            throw ValidationException::withMessages(['applications' => $e->getMessage() !== '' ? $e->getMessage() : 'You may not do this.'])->redirectTo($back);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message instanceof \Closure ? $message($result) : $message]);

        return redirect($back);
    }
}

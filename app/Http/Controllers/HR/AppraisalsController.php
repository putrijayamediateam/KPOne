<?php

namespace App\Http\Controllers\HR;

use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\Appraisals\Services\AppraisalService;
use App\Domain\HR\Appraisals\Services\AppraisalsPageService;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** HR-10b appraisal. Thin: every rule lives in app/Domain/HR/Appraisals. */
class AppraisalsController extends Controller
{
    public function index(Request $request, AppraisalsPageService $pages): Response
    {
        return Inertia::render('HR/Appraisals', ['appraisals' => $pages->page($request->user(), $request->only(['tab', 'cycle']))]);
    }

    public function show(Request $request, AppraisalsPageService $pages, string $appraisal): Response
    {
        try {
            $props = $pages->appraisal($request->user(), $appraisal);
        } catch (AuthorizationException) {
            $this->denied($request);
        }

        return Inertia::render('HR/Appraisal', ['appraisal' => $props]);
    }

    public function saveCriteria(Request $request, AppraisalService $appraisals): RedirectResponse
    {
        return $this->run($request, route('hr.appraisals.index', ['tab' => 'criteria']), 'Criteria saved as a new version. Cycles already open keep theirs.',
            fn () => $appraisals->saveCriteria($request->user(), $request->only(['criteria', 'idempotency_key'])));
    }

    public function openCycle(Request $request, AppraisalService $appraisals): RedirectResponse
    {
        $cycle = null;
        $response = $this->run($request, route('hr.appraisals.index', ['tab' => 'cycles']), 'Cycle opened.', function () use ($request, $appraisals, &$cycle): void {
            $cycle = $appraisals->openCycle($request->user(), $request->only(['name', 'period_start', 'period_end', 'worker_ids', 'idempotency_key']));
        });

        return is_string($cycle) ? redirect(route('hr.appraisals.index', ['tab' => 'cycles', 'cycle' => $cycle])) : $response;
    }

    public function closeCycle(Request $request, AppraisalService $appraisals, string $cycle): RedirectResponse
    {
        return $this->run($request, route('hr.appraisals.index', ['tab' => 'cycles', 'cycle' => $cycle]), 'Cycle closed. Appraisals not sent stay unsent.',
            fn () => $appraisals->closeCycle($request->user(), $cycle, $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    public function save(Request $request, AppraisalService $appraisals, string $appraisal): RedirectResponse
    {
        $send = $request->boolean('send');

        return $this->run($request, route('hr.appraisals.show', $appraisal), $send ? 'Appraisal sent. The staff member can read it now.' : 'Draft saved.',
            fn () => $appraisals->saveRatings($request->user(), $appraisal, $request->only(['ratings', 'expected_lock_version', 'idempotency_key']), $send));
    }

    public function comment(Request $request, AppraisalService $appraisals, string $appraisal): RedirectResponse
    {
        return $this->run($request, route('hr.appraisals.show', $appraisal), 'Your comment is recorded.',
            fn () => $appraisals->comment($request->user(), $appraisal, $request->only(['comment', 'expected_lock_version', 'idempotency_key'])));
    }

    public function signOff(Request $request, AppraisalService $appraisals, string $appraisal): RedirectResponse
    {
        return $this->run($request, route('hr.appraisals.show', $appraisal), 'Appraisal signed off. It no longer changes.',
            fn () => $appraisals->signOff($request->user(), $appraisal, $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    private function denied(Request $request): never
    {
        app(AuditRecorder::class)->record('authorization.denied', $request->user(), ['route' => $request->route()?->getName(), 'method' => $request->method()], $request->user());
        abort(403);
    }

    private function run(Request $request, string $back, string $message, callable $action): RedirectResponse
    {
        try {
            $action();
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        } catch (AuthorizationException $e) {
            app(AuditRecorder::class)->record('authorization.denied', $request->user(), ['route' => $request->route()?->getName(), 'method' => $request->method()], $request->user());
            throw ValidationException::withMessages(['appraisal' => $e->getMessage() !== '' ? $e->getMessage() : 'You may not do this.'])->redirectTo($back);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect($back);
    }
}

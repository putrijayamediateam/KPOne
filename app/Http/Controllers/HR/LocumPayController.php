<?php

namespace App\Http\Controllers\HR;

use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\LocumPay\Services\LocumPayPageService;
use App\Domain\HR\LocumPay\Services\LocumPayService;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** HR-15g locum pay statement. Thin: every rule lives in app/Domain/HR/LocumPay. */
class LocumPayController extends Controller
{
    public function index(Request $request, LocumPayPageService $pages): Response
    {
        return Inertia::render('HR/LocumPay', ['locumPay' => $pages->page($request->user(), $request->only(['tab', 'month', 'locum']))]);
    }

    public function enterRate(Request $request, LocumPayService $pay, string $worker): RedirectResponse
    {
        return $this->run($request, 'rates', 'Rate entered. It counts once Finance confirms it.', fn () => $pay->enterRate($request->user(), $worker,
            $request->only(['kind', 'amount', 'effective_from', 'idempotency_key'])));
    }

    public function withdrawRate(Request $request, LocumPayService $pay, string $rate): RedirectResponse
    {
        return $this->run($request, 'rates', 'Rate withdrawn.', fn () => $pay->withdrawRate($request->user(), $rate,
            $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    public function confirmRate(Request $request, LocumPayService $pay, string $rate): RedirectResponse
    {
        return $this->run($request, 'rates', 'Rate confirmed.', fn () => $pay->confirmRate($request->user(), $rate,
            $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    public function rejectRate(Request $request, LocumPayService $pay, string $rate): RedirectResponse
    {
        return $this->run($request, 'rates', 'Rate not confirmed.', fn () => $pay->rejectRate($request->user(), $rate,
            $request->only(['reason', 'expected_lock_version', 'idempotency_key'])));
    }

    public function markNotWorked(Request $request, LocumPayService $pay, string $worker): RedirectResponse
    {
        return $this->run($request, 'statements', 'Shift marked not worked.', fn () => $pay->markNotWorked($request->user(), $worker,
            $request->only(['shift_id', 'reason', 'idempotency_key'])), $worker);
    }

    public function removeNotWorked(Request $request, LocumPayService $pay, string $exclusion): RedirectResponse
    {
        return $this->run($request, 'statements', 'The shift counts again.', fn () => $pay->removeNotWorked($request->user(), $exclusion,
            $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    public function addCorrection(Request $request, LocumPayService $pay, string $worker): RedirectResponse
    {
        return $this->run($request, 'statements', 'Correction added.', fn () => $pay->addCorrection($request->user(), $worker,
            $request->only(['month', 'direction', 'amount', 'reason', 'for_month', 'idempotency_key'])), $worker);
    }

    public function removeCorrection(Request $request, LocumPayService $pay, string $correction): RedirectResponse
    {
        return $this->run($request, 'statements', 'Correction removed.', fn () => $pay->removeCorrection($request->user(), $correction,
            $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    public function markPaid(Request $request, LocumPayService $pay, string $worker): RedirectResponse
    {
        return $this->run($request, 'statements', 'Recorded as paid. The month is locked.', fn () => $pay->markPaid($request->user(), $worker,
            $request->only(['month', 'paid_on', 'reference', 'fingerprint', 'idempotency_key'])), $worker);
    }

    private function run(Request $request, string $tab, string $message, callable $action, ?string $worker = null): RedirectResponse
    {
        $month = $request->input('month', $request->query('month'));
        $locum = $worker ?? $request->input('locum');
        $back = route('hr.locum-pay.index', array_filter(['tab' => $tab,
            'month' => is_string($month) && preg_match('/\A[0-9]{4}-[0-9]{2}\z/', $month) === 1 ? $month : null,
            'locum' => is_string($locum) && preg_match('/\A[0-9a-f-]{36}\z/', $locum) === 1 ? $locum : null]));
        try {
            $action();
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        } catch (AuthorizationException $e) {
            app(AuditRecorder::class)->record('authorization.denied', $request->user(), ['route' => $request->route()?->getName(), 'method' => $request->method()], $request->user());
            throw ValidationException::withMessages(['locum_pay' => $e->getMessage() !== '' ? $e->getMessage() : 'You may not do this.'])->redirectTo($back);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect($back);
    }
}

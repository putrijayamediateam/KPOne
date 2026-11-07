<?php

namespace App\Http\Controllers\HR;

use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\Payroll\Services\PayrollExportService;
use App\Domain\HR\Payroll\Services\PayrollPageService;
use App\Domain\HR\Payroll\Services\PayrollRunPageService;
use App\Domain\HR\Payroll\Services\PayrollRunService;
use App\Domain\HR\Payroll\Services\PayrollSetupService;
use App\Domain\HR\Payroll\Services\PayslipService;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** HR-7a to HR-7c payroll. Thin: every rule lives in app/Domain/HR/Payroll. */
class PayrollController extends Controller
{
    public function index(Request $request, PayrollPageService $pages): Response
    {
        return Inertia::render('HR/Payroll', ['payroll' => $pages->page($request->user(), $request->only(['tab']))]);
    }

    /** HR-7c: the bank file or the accountant summary from a locked run, audited before its bytes and never stored. */
    public function export(Request $request, PayrollExportService $exports, string $run, string $kind): StreamedResponse|RedirectResponse
    {
        $back = route('hr.payroll.index', ['tab' => 'exports']);
        try {
            $file = $kind === 'bank' ? $exports->bank($request->user(), $run) : $exports->accountant($request->user(), $run);
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        }

        return response()->streamDownload(static function () use ($file): void {
            echo $file['csv'];
        }, $file['name'], ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    /** HR-7c: one payslip, your own or any for a payroll preparer or approver. Opening it is recorded. */
    public function payslip(Request $request, PayslipService $payslips, string $payslip): Response
    {
        return Inertia::render('HR/Payslip', ['payslip' => $payslips->open($request->user(), $payslip)]);
    }

    public function showRun(Request $request, PayrollRunPageService $runs, string $run): Response
    {
        return Inertia::render('HR/PayrollRun', ['run' => $runs->show($request->user(), $run)]);
    }

    public function prepareRun(Request $request, PayrollRunService $runs): RedirectResponse
    {
        $back = route('hr.payroll.index', ['tab' => 'runs']);
        try {
            $id = $runs->prepare($request->user(), $request->only(['pay_group_id', 'month', 'kind', 'idempotency_key']));
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        } catch (AuthorizationException $e) {
            app(AuditRecorder::class)->record('authorization.denied', $request->user(), ['route' => $request->route()?->getName(), 'method' => $request->method()], $request->user());
            throw ValidationException::withMessages(['payroll' => $e->getMessage() !== '' ? $e->getMessage() : 'You may not do this.'])->redirectTo($back);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Run prepared. Check it, then send it for approval.']);

        return redirect()->route('hr.payroll.runs.show', $id);
    }

    public function runStep(Request $request, PayrollRunService $runs, string $run, string $step): RedirectResponse
    {
        $input = $request->only(['worker_id', 'mtd', 'item_type_id', 'amount', 'reason_code', 'reason', 'expected_lock_version', 'idempotency_key']);
        $messages = ['again' => 'Run prepared again.', 'mtd' => 'MTD saved.', 'manual' => 'Line added.', 'send' => 'Sent for approval. The run is frozen.',
            'approve' => 'Run approved. Lock it to finish.', 'return' => 'Run returned to draft.', 'lock' => 'Run locked. Each person\'s payslip is ready on My HR details.'];

        return $this->runOn($request, $run, $messages[$step] ?? 'Saved.', fn () => match ($step) {
            'again' => $runs->prepareAgain($request->user(), $run, $input),
            'mtd' => $runs->setMtd($request->user(), $run, $input),
            'manual' => $runs->addManual($request->user(), $run, $input),
            'send' => $runs->send($request->user(), $run, $input),
            default => $runs->decide($request->user(), $run, $step, $input),
        });
    }

    public function removeManual(Request $request, PayrollRunService $runs, string $run, string $line): RedirectResponse
    {
        return $this->runOn($request, $run, 'Line removed.', fn () => $runs->removeManual($request->user(), $run, $line,
            $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    public function saveLineTypes(Request $request, PayrollRunService $runs): RedirectResponse
    {
        return $this->run($request, 'settings', 'Run line types saved.', fn () => $runs->saveLineTypes($request->user(),
            $request->only(['overtime_type_id', 'unpaid_leave_type_id', 'claim_type_id', 'expected_version', 'idempotency_key'])));
    }

    private function runOn(Request $request, string $run, string $message, callable $action): RedirectResponse
    {
        $back = route('hr.payroll.runs.show', $run);
        try {
            $action();
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        } catch (AuthorizationException $e) {
            app(AuditRecorder::class)->record('authorization.denied', $request->user(), ['route' => $request->route()?->getName(), 'method' => $request->method()], $request->user());
            throw ValidationException::withMessages(['payroll' => $e->getMessage() !== '' ? $e->getMessage() : 'You may not do this.'])->redirectTo($back);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect($back);
    }

    public function enterProfile(Request $request, PayrollSetupService $setup): RedirectResponse
    {
        return $this->run($request, 'profiles', 'Pay profile entered. Someone else confirms it.', fn () => $setup->enterProfile($request->user(),
            $request->only(['worker_id', 'pay_group_id', 'pay_type', 'rate', 'epf', 'epf_category', 'socso', 'socso_category', 'eis', 'mtd', 'hrd',
                'effective_from', 'items', 'idempotency_key'])));
    }

    public function decideProfile(Request $request, PayrollSetupService $setup, string $profile, string $decision): RedirectResponse
    {
        return $this->run($request, 'profiles', $decision === 'confirm' ? 'Pay profile confirmed.' : 'Pay profile rejected.',
            fn () => $setup->decideProfile($request->user(), $profile, $decision, $request->only(['reason', 'expected_lock_version', 'idempotency_key'])));
    }

    public function withdrawProfile(Request $request, PayrollSetupService $setup, string $profile): RedirectResponse
    {
        return $this->run($request, 'profiles', 'Pay profile change withdrawn.',
            fn () => $setup->withdrawProfile($request->user(), $profile, $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    public function enterTable(Request $request, PayrollSetupService $setup): RedirectResponse
    {
        return $this->run($request, 'tables', 'Table entered. Someone else checks it before it counts.', fn () => $setup->enterTable($request->user(),
            $request->only(['scheme', 'category', 'effective_from', 'rounding', 'source_note', 'rows', 'idempotency_key'])));
    }

    public function decideTable(Request $request, PayrollSetupService $setup, string $table, string $decision): RedirectResponse
    {
        return $this->run($request, 'tables', $decision === 'check' ? 'Table checked. It counts from its date.' : 'Table rejected.',
            fn () => $setup->decideTable($request->user(), $table, $decision, $request->only(['reason', 'expected_lock_version', 'idempotency_key'])));
    }

    public function withdrawTable(Request $request, PayrollSetupService $setup, string $table): RedirectResponse
    {
        return $this->run($request, 'tables', 'Table withdrawn.',
            fn () => $setup->withdrawTable($request->user(), $table, $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    public function saveGroup(Request $request, PayrollSetupService $setup): RedirectResponse
    {
        return $this->run($request, 'settings', 'Pay group saved.', fn () => $setup->saveGroup($request->user(),
            $request->only(['group_id', 'name', 'pay_day', 'is_active', 'expected_lock_version', 'idempotency_key'])));
    }

    public function saveItemType(Request $request, PayrollSetupService $setup): RedirectResponse
    {
        return $this->run($request, 'settings', 'Pay item type saved.', fn () => $setup->saveItemType($request->user(),
            $request->only(['item_type_id', 'name', 'kind', 'counts_epf', 'counts_socso', 'counts_eis', 'counts_mtd', 'counts_hrd', 'is_active',
                'expected_lock_version', 'idempotency_key'])));
    }

    public function saveRates(Request $request, PayrollSetupService $setup): RedirectResponse
    {
        return $this->run($request, 'settings', 'Rate settings saved.', fn () => $setup->saveRates($request->user(),
            $request->only(['daily_divisor', 'hourly_divisor', 'expected_version', 'idempotency_key'])));
    }

    private function run(Request $request, string $tab, string $message, callable $action): RedirectResponse
    {
        $back = route('hr.payroll.index', ['tab' => $tab]);
        try {
            $action();
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        } catch (AuthorizationException $e) {
            app(AuditRecorder::class)->record('authorization.denied', $request->user(), ['route' => $request->route()?->getName(), 'method' => $request->method()], $request->user());
            throw ValidationException::withMessages(['payroll' => $e->getMessage() !== '' ? $e->getMessage() : 'You may not do this.'])->redirectTo($back);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect($back);
    }
}

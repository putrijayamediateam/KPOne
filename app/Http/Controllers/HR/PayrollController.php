<?php

namespace App\Http\Controllers\HR;

use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\Payroll\Services\PayrollPageService;
use App\Domain\HR\Payroll\Services\PayrollSetupService;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** HR-7a payroll setup. Thin: every rule lives in app/Domain/HR/Payroll. */
class PayrollController extends Controller
{
    public function index(Request $request, PayrollPageService $pages): Response
    {
        return Inertia::render('HR/Payroll', ['payroll' => $pages->page($request->user(), $request->only(['tab']))]);
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

<?php

namespace App\Http\Controllers\HR;

use App\Domain\HR\Records\Models\Worker;
use App\Domain\HR\Records\Services\BankDetailService;
use App\Domain\HR\Records\Services\RecordCorrectionService;
use App\Domain\HR\Records\Services\RecordsPageService;
use App\Domain\HR\Records\Services\StaffFileService;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** HR-4a staff files. Thin: every rule lives in app/Domain/HR/Records. */
class RecordsController extends Controller
{
    private const PERIOD_FIELDS = ['type', 'starts_on', 'probation_ends_on', 'confirmed_on', 'ends_on', 'end_reason', 'note', 'edit_reason', 'expected_lock_version', 'idempotency_key'];

    private const PERSONAL_FIELDS = ['legal_name', 'preferred_name', 'identity_kind', 'identity_number', 'passport_country', 'date_of_birth', 'nationality', 'address',
        'personal_phone', 'emergency_contact', 'expected_lock_version', 'idempotency_key'];

    public function index(Request $request, RecordsPageService $pages): Response
    {
        return Inertia::render('HR/Records', ['records' => $pages->page($request->user(), $request->only(['tab', 'q', 'branch', 'type', 'status']))]);
    }

    public function show(Request $request, RecordsPageService $pages, string $worker): Response
    {
        $model = Worker::query()->where('organisation_id', $request->user()->organisation_id)->where('public_id', $worker)->firstOrFail();

        return Inertia::render('HR/StaffFile', ['file' => $pages->file($request->user(), $model, $request->query('tab'))]);
    }

    /** One masked value, audited before it is returned (HR-PLAN 11.3 rule 7). JSON, never cached. */
    public function showValue(Request $request, StaffFileService $files, string $worker): JsonResponse
    {
        try {
            $value = $files->show($request->user(), $worker, $request->only(['field', 'bank_id', 'correction_id']));
        } catch (ValidationException $e) {
            return response()->json(['errors' => array_map(fn (array $m): string => $m[0], $e->errors())], 422);
        }

        return response()->json(['value' => $value, 'shownAt' => now()->toIso8601String()])->header('Cache-Control', 'no-store, private');
    }

    public function openFile(Request $request, StaffFileService $files): RedirectResponse
    {
        $worker = null;
        $response = $this->run($request, route('hr.records.index', ['tab' => 'files']), null, function () use ($request, $files, &$worker): void {
            $worker = $files->openFile($request->user(), $request->only(['user_id', 'idempotency_key']));
        });
        if ($worker === null) {
            return $response;
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Staff file opened.']);

        return redirect()->route('hr.records.show', ['worker' => $worker->public_id]);
    }

    public function addLocum(Request $request, StaffFileService $files): RedirectResponse
    {
        $worker = null;
        $response = $this->run($request, route('hr.records.index', ['tab' => 'files']), null, function () use ($request, $files, &$worker): void {
            $worker = $files->addLocum($request->user(), $request->only(['display_name', 'phone', 'idempotency_key']));
        });
        if ($worker === null) {
            return $response;
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Locum added.']);

        return redirect()->route('hr.records.show', ['worker' => $worker->public_id]);
    }

    public function addPeriod(Request $request, StaffFileService $files, string $worker): RedirectResponse
    {
        return $this->run($request, $this->file($worker, 'employment'), 'Employment period added.', fn () => $files->addPeriod($request->user(), $worker, $request->only(self::PERIOD_FIELDS)));
    }

    public function updatePeriod(Request $request, StaffFileService $files, string $worker, string $period): RedirectResponse
    {
        return $this->run($request, $this->file($worker, 'employment'), 'Employment period saved.', fn () => $files->updatePeriod($request->user(), $worker, $period, $request->only(self::PERIOD_FIELDS)));
    }

    public function addPosition(Request $request, StaffFileService $files, string $worker): RedirectResponse
    {
        return $this->run($request, $this->file($worker, 'employment'), 'Position added.', fn () => $files->addPosition($request->user(), $worker,
            $request->only(['title', 'department_id', 'reports_to', 'from_date', 'idempotency_key'])));
    }

    public function savePersonal(Request $request, StaffFileService $files, string $worker): RedirectResponse
    {
        return $this->run($request, $this->file($worker, 'personal'), 'Personal details saved.', fn () => $files->savePersonal($request->user(), $worker, $request->only(self::PERSONAL_FIELDS)));
    }

    public function enterBank(Request $request, BankDetailService $banks, string $worker): RedirectResponse
    {
        return $this->run($request, $this->file($worker, 'bank'), 'Bank details saved. Someone else confirms them.', fn () => $banks->enter($request->user(), $worker,
            $request->only(['bank_name', 'holder', 'account', 'idempotency_key'])));
    }

    public function decideBank(Request $request, BankDetailService $banks, string $worker, string $bank, string $decision): RedirectResponse
    {
        return $this->run($request, $this->file($worker, 'bank'), $decision === 'confirm' ? 'Bank details confirmed.' : 'Bank change rejected.',
            fn () => $banks->decide($request->user(), $worker, $bank, [...$request->only(['reason', 'expected_lock_version', 'idempotency_key']), 'decision' => $decision]));
    }

    public function withdrawBank(Request $request, BankDetailService $banks, string $worker, string $bank): RedirectResponse
    {
        return $this->run($request, $this->file($worker, 'bank'), 'Bank change withdrawn.', fn () => $banks->withdraw($request->user(), $worker, $bank, $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    public function decideCorrection(Request $request, RecordCorrectionService $corrections, string $worker, string $correction, string $decision): RedirectResponse
    {
        $tab = $request->input('tab') === 'bank' ? 'bank' : 'personal';

        return $this->run($request, $this->file($worker, $tab), $decision === 'apply' ? 'Correction applied.' : 'Correction rejected.',
            fn () => $corrections->decide($request->user(), $worker, $correction, [...$request->only(['reason', 'expected_lock_version', 'idempotency_key']), 'decision' => $decision]));
    }

    public function saveOwnContact(Request $request, StaffFileService $files): RedirectResponse
    {
        return $this->run($request, route('hr.records.index', ['tab' => 'my']), 'Your details were saved.', fn () => $files->saveOwnContact($request->user(),
            $request->only(['address', 'personal_phone', 'emergency_contact', 'expected_lock_version', 'idempotency_key'])));
    }

    public function askCorrection(Request $request, RecordCorrectionService $corrections): RedirectResponse
    {
        return $this->run($request, route('hr.records.index', ['tab' => 'my']), 'Correction sent to HR.', fn () => $corrections->ask($request->user(),
            $request->only(['kind', 'value', 'identity_kind', 'identity_number', 'passport_country', 'bank_name', 'holder', 'account', 'reason', 'note', 'idempotency_key'])));
    }

    public function withdrawCorrection(Request $request, RecordCorrectionService $corrections, string $correction): RedirectResponse
    {
        return $this->run($request, route('hr.records.index', ['tab' => 'my']), 'Correction withdrawn.', fn () => $corrections->withdraw($request->user(), $correction,
            $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    private function file(string $worker, string $tab): string
    {
        return route('hr.records.show', ['worker' => $worker, 'tab' => $tab]);
    }

    private function run(Request $request, string $back, ?string $message, callable $action): RedirectResponse
    {
        try {
            $action();
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        } catch (AuthorizationException $e) {
            // A refused rule-3 write stays on its page with the reason (Suresh, S4); route permissions still give 403.
            throw ValidationException::withMessages(['records' => $e->getMessage() !== '' ? $e->getMessage() : 'You may not do this.'])->redirectTo($back);
        }
        if ($message !== null) {
            Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
        }

        return redirect($back);
    }
}

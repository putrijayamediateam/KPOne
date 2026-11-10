<?php

namespace App\Http\Controllers\HR;

use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\Letters\Services\LettersPageService;
use App\Domain\HR\Memos\Services\MemosPageService;
use App\Domain\HR\Records\Models\Worker;
use App\Domain\HR\Records\Services\BankDetailService;
use App\Domain\HR\Records\Services\ChecklistService;
use App\Domain\HR\Records\Services\DocumentService;
use App\Domain\HR\Records\Services\LocumSignIns;
use App\Domain\HR\Records\Services\RecordCorrectionService;
use App\Domain\HR\Records\Services\RecordsPageService;
use App\Domain\HR\Records\Services\StaffFileService;
use App\Domain\HR\Sops\Services\SopsPageService;
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
        return Inertia::render('HR/Records', ['records' => $pages->page($request->user(), $request->only(['tab', 'q', 'branch', 'type', 'status'])),
            // HR-5c: how many SOPs you still have to read, linking to /hr/sops.
            'sopsToRead' => app(SopsPageService::class)->toRead($request->user()),
            // HR-8: how many memos you still have to read, linking to /hr/memos.
            'memosToRead' => app(MemosPageService::class)->toRead($request->user()),
            // HR-10a: how many current letters HR issued to you, linking to /hr/letters.
            'lettersMine' => app(LettersPageService::class)->mineCount($request->user())]);
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

    public function askSignIn(Request $request, LocumSignIns $signIns, string $worker): RedirectResponse
    {
        return $this->run($request, $this->file($worker, 'employment'), 'Sign-in asked for. The account administrator sees it on the Staff page.',
            fn () => $signIns->request($request->user(), $worker, $request->only(['email', 'idempotency_key'])));
    }

    public function cancelSignIn(Request $request, LocumSignIns $signIns, string $worker, string $signIn): RedirectResponse
    {
        return $this->run($request, $this->file($worker, 'employment'), 'Sign-in request cancelled.',
            fn () => $signIns->cancel($request->user(), $signIn, $request->only(['expected_lock_version', 'idempotency_key'])));
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

    public function uploadDocument(Request $request, DocumentService $documents, string $worker): RedirectResponse
    {
        $back = $this->file($worker, 'documents');
        // The file is checked by its content before anything is written (HR-2b pattern).
        try {
            $prepared = DocumentService::prepare($request->file('file'));
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        }

        return $this->run($request, $back, 'Document added.', fn () => $documents->upload($request->user(), $worker, $prepared,
            $request->only(['category', 'issued_on', 'expires_on', 'idempotency_key'])));
    }

    public function removeDocument(Request $request, DocumentService $documents, string $worker, string $document): RedirectResponse
    {
        return $this->run($request, $this->file($worker, 'documents'), 'Document removed.', fn () => $documents->remove($request->user(), $worker, $document,
            $request->only(['reason', 'expected_lock_version', 'idempotency_key'])));
    }

    /** Each open is recorded before any byte is sent; a PDF downloads, a photo opens in the browser. */
    public function openDocument(Request $request, DocumentService $documents, string $worker, string $document): \Illuminate\Http\Response
    {
        $opened = $documents->open($request->user(), $worker, $document);

        return response($opened['content'], 200, [
            'Content-Type' => $opened['mime'],
            'Content-Disposition' => ($opened['inline'] ? 'inline' : 'attachment').'; filename="'.$opened['filename'].'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function tick(Request $request, ChecklistService $checklists, string $worker, string $period, string $checklistItem): RedirectResponse
    {
        return $this->run($request, $this->file($worker, 'checklist'), 'Ticked.', fn () => $checklists->tick($request->user(), $worker, $period, $checklistItem,
            $request->only(['idempotency_key'])));
    }

    public function saveRetention(Request $request, DocumentService $documents): RedirectResponse
    {
        return $this->run($request, route('hr.records.index', ['tab' => 'files']), 'Retention saved.', fn () => $documents->saveRetention($request->user(),
            $request->only(['category', 'retention_days', 'expected_lock_version', 'idempotency_key'])));
    }

    public function addChecklistItem(Request $request, ChecklistService $checklists): RedirectResponse
    {
        return $this->run($request, route('hr.records.index', ['tab' => 'files']), 'Item added.', fn () => $checklists->addItem($request->user(),
            $request->only(['list', 'label', 'about_account', 'idempotency_key'])));
    }

    public function archiveChecklistItem(Request $request, ChecklistService $checklists, string $checklistItem): RedirectResponse
    {
        return $this->run($request, route('hr.records.index', ['tab' => 'files']), 'Item archived.', fn () => $checklists->archiveItem($request->user(), $checklistItem,
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
            // Still audited as a denial, as the exception handler would (Suresh, N2); ids only.
            app(AuditRecorder::class)->record('authorization.denied', $request->user(), ['route' => $request->route()?->getName(), 'method' => $request->method()], $request->user());
            // A refused rule-3 write stays on its page with the reason (Suresh, S4); route permissions still give 403.
            throw ValidationException::withMessages(['records' => $e->getMessage() !== '' ? $e->getMessage() : 'You may not do this.'])->redirectTo($back);
        }
        if ($message !== null) {
            Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
        }

        return redirect($back);
    }
}

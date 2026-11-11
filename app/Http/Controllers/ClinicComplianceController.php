<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\Leave\Services\LeaveEvidenceFiles;
use App\Domain\Organisation\Compliance\Services\AuditService;
use App\Domain\Organisation\Compliance\Services\ChecklistService;
use App\Domain\Organisation\Compliance\Services\CompliancePageService;
use App\Domain\Organisation\Compliance\Services\CompliancePhotos;
use App\Domain\Organisation\Compliance\Services\FridgeService;
use App\Domain\Organisation\Compliance\Services\LicenceService;
use App\Domain\Organisation\Compliance\Services\TaskService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** HR-11 clinic compliance. Thin: every rule lives in app/Domain/Organisation/Compliance. */
class ClinicComplianceController extends Controller
{
    private const RECORD_FIELDS = ['type_id', 'branch_id', 'licence_id', 'reference', 'issued_on', 'expires_on', 'note', 'expected_lock_version', 'idempotency_key'];

    public function index(Request $request, CompliancePageService $pages): \Inertia\Response
    {
        return Inertia::render('ClinicCompliance/Index', ['compliance' => $pages->page($request->user(), $request->only(['tab', 'month', 'audit']))]);
    }

    public function saveType(Request $request, LicenceService $licences): RedirectResponse
    {
        return $this->run($request, 'settings', 'Licence type saved.', fn () => $licences->saveType($request->user(), $request->only(['type_id', 'name', 'expires', 'warn_from_days', 'expected_lock_version', 'idempotency_key'])));
    }

    public function archiveType(Request $request, LicenceService $licences, string $type): RedirectResponse
    {
        return $this->run($request, 'settings', 'Licence type archived.', fn () => $licences->archiveType($request->user(), $type, $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    public function record(Request $request, LicenceService $licences): RedirectResponse
    {
        // Refuse before any file is decoded, so a long list cannot cost memory.
        if (count((array) $request->file('files', [])) > LicenceService::MAX_FILES) {
            throw ValidationException::withMessages(['files' => 'Add at most '.LicenceService::MAX_FILES.' files.'])->redirectTo(route('clinic-compliance.index', ['tab' => 'licences']));
        }
        $files = [];
        foreach ((array) $request->file('files', []) as $file) {
            try {
                $files[] = LeaveEvidenceFiles::prepare($file, 'files');
            } catch (ValidationException $e) {
                throw $e->redirectTo(route('clinic-compliance.index', ['tab' => 'licences']));
            }
        }

        return $this->run($request, 'licences', 'Licence saved.', fn () => $licences->record($request->user(), $request->only(self::RECORD_FIELDS), $files));
    }

    public function markInError(Request $request, LicenceService $licences, string $version): RedirectResponse
    {
        return $this->run($request, 'licences', 'Marked as entered in error.', fn () => $licences->markInError($request->user(), $version, $request->only(['reason', 'expected_lock_version', 'idempotency_key'])));
    }

    public function saveFridge(Request $request, FridgeService $fridges): RedirectResponse
    {
        return $this->run($request, 'settings', 'Fridge saved.', fn () => $fridges->saveFridge($request->user(), $request->only(['fridge_id', 'branch_id', 'name', 'min', 'max', 'readings_per_day', 'expect_by', 'expected_lock_version', 'idempotency_key'])));
    }

    public function archiveFridge(Request $request, FridgeService $fridges, string $fridge): RedirectResponse
    {
        return $this->run($request, 'settings', 'Fridge archived.', fn () => $fridges->archiveFridge($request->user(), $fridge, $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    public function recordReading(Request $request, FridgeService $fridges): RedirectResponse
    {
        return $this->run($request, 'fridge', 'Reading saved.', fn () => $fridges->record($request->user(), $request->only(['fridge_id', 'temp', 'low', 'high', 'reason', 'idempotency_key'])));
    }

    public function reviewReading(Request $request, FridgeService $fridges, string $reading): RedirectResponse
    {
        return $this->run($request, 'fridge', 'Action recorded.', fn () => $fridges->review($request->user(), $reading, $request->only(['action', 'note', 'idempotency_key'])));
    }

    public function readingError(Request $request, FridgeService $fridges, string $reading): RedirectResponse
    {
        return $this->run($request, 'fridge', 'Marked as entered in error.', fn () => $fridges->markInError($request->user(), $reading, $request->only(['reason', 'idempotency_key'])));
    }

    public function saveChecklist(Request $request, ChecklistService $checklist): RedirectResponse
    {
        return $this->run($request, 'settings', 'Checklist saved.', fn () => $checklist->saveChecklist($request->user(), $request->only(['items', 'idempotency_key'])));
    }

    public function saveAuditSettings(Request $request, ChecklistService $checklist): RedirectResponse
    {
        return $this->run($request, 'settings', 'Audit settings saved.', fn () => $checklist->saveSettings($request->user(), $request->only(['verify_done_tasks', 'photo_retention_days', 'expected_lock_version', 'idempotency_key'])));
    }

    public function startAudit(Request $request, AuditService $audits): RedirectResponse
    {
        try {
            $id = $audits->start($request->user(), $request->only(['branch_id', 'month', 'idempotency_key']));
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('clinic-compliance.index', ['tab' => 'audits']));
        } catch (AuthorizationException $e) {
            return $this->denied($request, $e, 'audits');
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Audit started.']);

        return redirect(route('clinic-compliance.index', ['tab' => 'audits', 'audit' => $id]));
    }

    public function saveAnswers(Request $request, AuditService $audits, string $audit): RedirectResponse
    {
        return $this->runAudit($request, $audit, 'Answers saved.', fn () => $audits->saveAnswers($request->user(), $audit, $request->only(['answers', 'expected_lock_version', 'idempotency_key'])));
    }

    public function submitAudit(Request $request, AuditService $audits, string $audit): RedirectResponse
    {
        return $this->runAudit($request, $audit, 'Audit submitted.', fn () => $audits->submit($request->user(), $audit, $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    public function addAuditPhoto(Request $request, AuditService $audits, string $audit, string $item): RedirectResponse
    {
        return $this->runAudit($request, $audit, 'Photo added.', fn () => $audits->addPhoto($request->user(), $audit, $item, CompliancePhotos::imageOnly(LeaveEvidenceFiles::prepare($request->file('photo'), 'photo'))));
    }

    public function removeAuditPhoto(Request $request, AuditService $audits, string $audit, string $photo): RedirectResponse
    {
        return $this->runAudit($request, $audit, 'Photo removed.', fn () => $audits->removePhoto($request->user(), $photo));
    }

    public function taskDone(Request $request, TaskService $tasks, string $task): RedirectResponse
    {
        return $this->run($request, 'audits', 'Task marked done.', fn () => $tasks->markDone($request->user(), $task, $request->only(['note', 'expected_lock_version', 'idempotency_key'])));
    }

    public function taskVerify(Request $request, TaskService $tasks, string $task): RedirectResponse
    {
        return $this->run($request, 'audits', 'Task verified.', fn () => $tasks->verify($request->user(), $task, $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    public function taskCancel(Request $request, TaskService $tasks, string $task): RedirectResponse
    {
        return $this->run($request, 'audits', 'Task cancelled.', fn () => $tasks->cancel($request->user(), $task, $request->only(['reason', 'expected_lock_version', 'idempotency_key'])));
    }

    public function addTaskPhoto(Request $request, TaskService $tasks, string $task): RedirectResponse
    {
        return $this->run($request, 'audits', 'Photo added.', fn () => $tasks->addPhoto($request->user(), $task, CompliancePhotos::imageOnly(LeaveEvidenceFiles::prepare($request->file('photo'), 'photo'))));
    }

    public function openAuditPhoto(Request $request, AuditService $audits, CompliancePhotos $photos, string $photo): Response
    {
        return $this->photoResponse($photos->read($audits->openPhoto($request->user(), $photo)));
    }

    public function openTaskPhoto(Request $request, TaskService $tasks, CompliancePhotos $photos, string $photo): Response
    {
        return $this->photoResponse($photos->read($tasks->openPhoto($request->user(), $photo)));
    }

    /** @param  array{content:string, mime:string, filename:string}  $photo */
    private function photoResponse(array $photo): Response
    {
        return response($photo['content'], 200, ['Content-Type' => $photo['mime'], 'Content-Disposition' => 'inline; filename="'.$photo['filename'].'"', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'", 'Cache-Control' => 'no-store, private']);
    }

    /** An audit action returns to that audit's page. */
    private function runAudit(Request $request, string $audit, string $message, callable $action): RedirectResponse
    {
        $back = route('clinic-compliance.index', ['tab' => 'audits', 'audit' => $audit]);
        try {
            $action();
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        } catch (AuthorizationException $e) {
            return $this->denied($request, $e, 'audits', $audit);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect($back);
    }

    private function denied(Request $request, AuthorizationException $e, string $tab, ?string $audit = null): never
    {
        app(AuditRecorder::class)->record('authorization.denied', $request->user(), ['route' => $request->route()?->getName(), 'method' => $request->method()], $request->user());

        throw ValidationException::withMessages(['compliance' => $e->getMessage() !== '' ? $e->getMessage() : 'You may not do this.'])
            ->redirectTo(route('clinic-compliance.index', array_filter(['tab' => $tab, 'audit' => $audit])));
    }

    /** Streams one scan or photo after recording the open. A PDF is always a download, never shown inside KPOne. */
    public function openFile(Request $request, LicenceService $licences, string $file): Response
    {
        $opened = $licences->open($request->user(), $file);

        return response($opened['content'], 200, [
            'Content-Type' => $opened['mime'],
            'Content-Disposition' => ($opened['inline'] ? 'inline' : 'attachment').'; filename="'.$opened['filename'].'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function run(Request $request, string $tab, string $message, callable $action): RedirectResponse
    {
        $back = route('clinic-compliance.index', ['tab' => $tab]);
        try {
            $action();
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        } catch (AuthorizationException $e) {
            app(AuditRecorder::class)->record('authorization.denied', $request->user(), ['route' => $request->route()?->getName(), 'method' => $request->method()], $request->user());
            throw ValidationException::withMessages(['compliance' => $e->getMessage() !== '' ? $e->getMessage() : 'You may not do this.'])->redirectTo($back);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect($back);
    }
}

<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\Leave\Services\LeaveEvidenceFiles;
use App\Domain\Organisation\Compliance\Services\CompliancePageService;
use App\Domain\Organisation\Compliance\Services\LicenceService;
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
        return Inertia::render('ClinicCompliance/Index', ['compliance' => $pages->page($request->user(), $request->only(['tab']))]);
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

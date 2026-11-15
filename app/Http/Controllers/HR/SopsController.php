<?php

namespace App\Http\Controllers\HR;

use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\Sops\Services\SopService;
use App\Domain\HR\Sops\Services\SopsPageService;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** HR-5c SOPs. Thin: every rule lives in app/Domain/HR/Sops. */
class SopsController extends Controller
{
    public function index(Request $request, SopsPageService $pages): Response
    {
        return Inertia::render('HR/Sops', ['sops' => $pages->page($request->user(), $request->only(['tab']))]);
    }

    public function publish(Request $request, SopService $sops): RedirectResponse
    {
        $back = route('hr.sops.index', ['tab' => 'manage']);
        $prepared = $this->prepared($request, $back);

        return $this->run($request, $back, 'SOP published.', fn () => $sops->publish($request->user(), $prepared, $request->only(['title', 'roles', 'idempotency_key'])));
    }

    public function replace(Request $request, SopService $sops, string $sop): RedirectResponse
    {
        $back = route('hr.sops.index', ['tab' => 'manage']);
        $prepared = $this->prepared($request, $back);

        return $this->run($request, $back, 'New version published. Everyone it is for reads it again.', fn () => $sops->replace($request->user(), $sop, $prepared,
            $request->only(['title', 'roles', 'expected_lock_version', 'idempotency_key'])));
    }

    public function withdraw(Request $request, SopService $sops, string $sop): RedirectResponse
    {
        return $this->run($request, route('hr.sops.index', ['tab' => 'manage']), 'SOP withdrawn. Its acknowledgements are kept.',
            fn () => $sops->withdraw($request->user(), $sop, $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    public function acknowledge(Request $request, SopService $sops, string $version): RedirectResponse
    {
        return $this->run($request, route('hr.sops.index', ['tab' => 'my']), 'Acknowledged.',
            fn () => $sops->acknowledge($request->user(), $version, $request->only(['idempotency_key'])));
    }

    /** The PDF downloads; the open is recorded first. */
    public function open(Request $request, SopService $sops, string $version): HttpResponse|RedirectResponse
    {
        try {
            $opened = $sops->open($request->user(), $version);
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('hr.sops.index'));
        } catch (AuthorizationException) {
            // Recorded as the other HR controllers do (Suresh, HR-5c M2).
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

    /** @return array{content:string, kind:string, bytes:int, sha256:string} checked by content before anything is written */
    private function prepared(Request $request, string $back): array
    {
        try {
            return SopService::prepare($request->file('file'));
        } catch (ValidationException $e) {
            // Show the title and roles messages with the file's, so one submit names everything to fix.
            $messages = $e->errors();
            try {
                SopService::validated($request->only(['title', 'roles']));
            } catch (ValidationException $other) {
                $messages = [...$other->errors(), ...$messages];
            }
            throw ValidationException::withMessages($messages)->redirectTo($back);
        }
    }

    private function run(Request $request, string $back, string $message, callable $action): RedirectResponse
    {
        try {
            $action();
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        } catch (AuthorizationException $e) {
            app(AuditRecorder::class)->record('authorization.denied', $request->user(), ['route' => $request->route()?->getName(), 'method' => $request->method()], $request->user());
            throw ValidationException::withMessages(['sop' => $e->getMessage() !== '' ? $e->getMessage() : 'You may not do this.'])->redirectTo($back);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect($back);
    }
}

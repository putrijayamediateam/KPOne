<?php

namespace App\Http\Controllers\HR;

use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\Credentials\Services\CredentialService;
use App\Domain\HR\Credentials\Services\CredentialsPageService;
use App\Domain\HR\Records\Services\DocumentService;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** HR-5a credential register. Thin: every rule lives in app/Domain/HR/Credentials. */
class CredentialsController extends Controller
{
    private const RECORD_FIELDS = ['type_id', 'issued_on', 'expires_on', 'issuing_body', 'reference', 'health', 'idempotency_key'];

    public function index(Request $request, CredentialsPageService $pages): Response
    {
        return Inertia::render('HR/Credentials', ['credentials' => $pages->page($request->user(), $request->only(['tab', 'branch', 'role', 'type', 'status']))]);
    }

    public function sendOwn(Request $request, CredentialService $credentials): RedirectResponse
    {
        $back = route('hr.credentials.index', ['tab' => 'my']);
        $prepared = $this->prepared($request, $back);

        return $this->run($request, $back, 'Sent. Someone else checks it.', fn () => $credentials->sendOwn($request->user(), $prepared, $request->only(self::RECORD_FIELDS)));
    }

    public function enterFor(Request $request, CredentialService $credentials, string $worker): RedirectResponse
    {
        $back = route('hr.credentials.index', ['tab' => 'register']);
        $prepared = $this->prepared($request, $back);

        return $this->run($request, $back, 'Saved. Someone else checks it.', fn () => $credentials->enterFor($request->user(), $worker, $prepared, $request->only(self::RECORD_FIELDS)));
    }

    public function decide(Request $request, CredentialService $credentials, string $record, string $decision): RedirectResponse
    {
        return $this->run($request, route('hr.credentials.index', ['tab' => 'register']), $decision === 'accept' ? 'Accepted.' : 'Rejected.',
            fn () => $credentials->decide($request->user(), $record, [...$request->only(['reason', 'expected_lock_version', 'idempotency_key']), 'decision' => $decision]));
    }

    public function saveType(Request $request, CredentialService $credentials): RedirectResponse
    {
        return $this->run($request, route('hr.credentials.index', ['tab' => 'settings']), 'Credential type saved.', fn () => $credentials->saveType($request->user(),
            $request->only(['type_id', 'code', 'name', 'expires', 'health_item', 'warn_from_days', 'roles', 'expected_lock_version', 'idempotency_key'])));
    }

    public function archiveType(Request $request, CredentialService $credentials, string $type): RedirectResponse
    {
        return $this->run($request, route('hr.credentials.index', ['tab' => 'settings']), 'Credential type archived.', fn () => $credentials->archiveType($request->user(), $type,
            $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    /** One reference number, audited before it is returned. JSON, never cached. */
    public function showReference(Request $request, CredentialService $credentials, string $record): JsonResponse
    {
        try {
            $value = $credentials->showReference($request->user(), $record);
        } catch (ValidationException $e) {
            return response()->json(['errors' => array_map(fn (array $m): string => $m[0], $e->errors())], 422);
        } catch (AuthorizationException) {
            abort(403);
        }

        return response()->json(['value' => $value, 'shownAt' => now()->toIso8601String()])->header('Cache-Control', 'no-store, private');
    }

    /** @return array{content:string, kind:string, bytes:int, sha256:string}|null checked by content before anything is written */
    private function prepared(Request $request, string $back): ?array
    {
        if (! $request->hasFile('file')) {
            return null;
        }
        try {
            return DocumentService::prepare($request->file('file'));
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
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
            throw ValidationException::withMessages(['credentials' => $e->getMessage() !== '' ? $e->getMessage() : 'You may not do this.'])->redirectTo($back);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect($back);
    }
}

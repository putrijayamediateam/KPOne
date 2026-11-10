<?php

namespace App\Http\Controllers\HR;

use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\Memos\Services\MemoAudience;
use App\Domain\HR\Memos\Services\MemoService;
use App\Domain\HR\Memos\Services\MemosPageService;
use App\Domain\HR\Sops\Services\SopService;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** HR-8 memos. Thin: every rule lives in app/Domain/HR/Memos. */
class MemosController extends Controller
{
    public function index(Request $request, MemosPageService $pages): Response
    {
        return Inertia::render('HR/Memos', ['memos' => $pages->page($request->user(), $request->only(['tab']))]);
    }

    /** "Who will see this: N people", before publishing. */
    public function audience(Request $request, MemoService $memos, MemoAudience $audience): JsonResponse
    {
        $user = $request->user();
        [$branches, $roles] = $memos->validatedAudience($user, $request->only(['roles', 'branches']));

        return response()->json(['count' => $audience->users($user->organisation_id, $branches, $roles)->count()], 200, ['Cache-Control' => 'no-store, private']);
    }

    public function publish(Request $request, MemoService $memos): RedirectResponse
    {
        $back = route('hr.memos.index', ['tab' => 'manage']);
        $input = $request->only(['title', 'body', 'roles', 'branches', 'open_until', 'replaces', 'idempotency_key']);
        $prepared = null;
        if ($request->hasFile('file')) {
            try {
                $prepared = SopService::prepare($request->file('file'));
            } catch (ValidationException $e) {
                // One submit names everything to fix.
                $messages = $e->errors();
                try {
                    MemoService::validatedText($input);
                } catch (ValidationException $other) {
                    $messages = [...$other->errors(), ...$messages];
                }
                throw ValidationException::withMessages($messages)->redirectTo($back);
            }
        }

        return $this->run($request, $back, 'Memo published.', fn () => $memos->publish($request->user(), $prepared, $input));
    }

    public function withdraw(Request $request, MemoService $memos, string $memo): RedirectResponse
    {
        return $this->run($request, route('hr.memos.index', ['tab' => 'manage']), 'Memo withdrawn. Every read is kept.',
            fn () => $memos->withdraw($request->user(), $memo, $request->only(['reason', 'expected_lock_version', 'idempotency_key'])));
    }

    public function acknowledge(Request $request, MemoService $memos, string $memo): RedirectResponse
    {
        return $this->run($request, route('hr.memos.index', ['tab' => 'my']), 'Thank you. Your read is recorded.',
            fn () => $memos->acknowledge($request->user(), $memo, $request->only(['idempotency_key'])));
    }

    /** The text, as JSON; the open is recorded first. */
    public function open(Request $request, MemoService $memos, string $memo): JsonResponse
    {
        try {
            $opened = $memos->openBody($request->user(), $memo);
        } catch (AuthorizationException) {
            $this->denied($request);
        }

        return response()->json($opened, 200, ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** The PDF downloads; the open is recorded first. */
    public function pdf(Request $request, MemoService $memos, string $memo): HttpResponse|RedirectResponse
    {
        try {
            $opened = $memos->openPdf($request->user(), $memo);
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('hr.memos.index'));
        } catch (AuthorizationException) {
            $this->denied($request);
        }

        return response($opened['content'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$opened['filename'].'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Cache-Control' => 'no-store, private',
        ]);
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
            throw ValidationException::withMessages(['memo' => $e->getMessage() !== '' ? $e->getMessage() : 'You may not do this.'])->redirectTo($back);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect($back);
    }
}

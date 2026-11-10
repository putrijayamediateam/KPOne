<?php

namespace App\Http\Controllers\HR;

use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\Letters\Services\LetterService;
use App\Domain\HR\Letters\Services\LettersPageService;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** HR-10a letters. Thin: every rule lives in app/Domain/HR/Letters. */
class LettersController extends Controller
{
    private const ISSUE_FIELDS = ['worker_id', 'template_id', 'language', 'details', 'replaces', 'expected_template_version', 'idempotency_key'];

    public function index(Request $request, LettersPageService $pages): Response
    {
        return Inertia::render('HR/Letters', ['letters' => $pages->page($request->user(), $request->only(['tab']))]);
    }

    /** The printable letter. The open is recorded before the text is returned. */
    public function show(Request $request, LetterService $letters, string $letter): Response
    {
        try {
            $opened = $letters->open($request->user(), $letter);
        } catch (AuthorizationException) {
            app(AuditRecorder::class)->record('authorization.denied', $request->user(), ['route' => $request->route()?->getName(), 'method' => $request->method()], $request->user());
            abort(403);
        }

        return Inertia::render('HR/Letter', ['letter' => $opened]);
    }

    /** The filled text before issue, as JSON. Nothing is stored or numbered. */
    public function preview(Request $request, LetterService $letters): JsonResponse
    {
        try {
            $preview = $letters->preview($request->user(), $request->only(['worker_id', 'template_id', 'language', 'details']));
        } catch (ValidationException $e) {
            return response()->json(['errors' => array_map(fn (array $m): string => $m[0], $e->errors())], 422, ['Cache-Control' => 'no-store, private']);
        } catch (AuthorizationException $e) {
            return response()->json(['errors' => ['worker_id' => $e->getMessage() !== '' ? $e->getMessage() : 'You may not do this.']], 403, ['Cache-Control' => 'no-store, private']);
        }

        return response()->json($preview, 200, ['Cache-Control' => 'no-store, private']);
    }

    public function issue(Request $request, LetterService $letters): RedirectResponse
    {
        $back = route('hr.letters.index', ['tab' => 'issued']);
        $issued = null;
        $response = $this->run($request, $back, 'Letter issued. Print it, sign it and give it by hand.', function () use ($request, $letters, &$issued): void {
            $issued = $letters->issue($request->user(), $request->only(self::ISSUE_FIELDS));
        });
        if (is_string($issued)) {
            Inertia::flash('issuedLetter', $issued);
        }

        return $response;
    }

    public function void(Request $request, LetterService $letters, string $letter): RedirectResponse
    {
        return $this->run($request, route('hr.letters.index', ['tab' => 'issued']), 'Letter void. It is kept with its reason.',
            fn () => $letters->void($request->user(), $letter, $request->only(['reason', 'expected_lock_version', 'idempotency_key'])));
    }

    public function given(Request $request, LetterService $letters, string $letter): RedirectResponse
    {
        return $this->run($request, route('hr.letters.index', ['tab' => 'issued']), 'Recorded when the letter was given.',
            fn () => $letters->recordGiven($request->user(), $letter, $request->only(['given_on', 'expected_lock_version', 'idempotency_key'])));
    }

    public function storeTemplate(Request $request, LetterService $letters): RedirectResponse
    {
        return $this->run($request, route('hr.letters.index', ['tab' => 'templates']), 'Template saved.',
            fn () => $letters->createTemplate($request->user(), $request->only(['type', 'title', 'body_en', 'body_bm', 'idempotency_key'])));
    }

    public function reviseTemplate(Request $request, LetterService $letters, string $template): RedirectResponse
    {
        return $this->run($request, route('hr.letters.index', ['tab' => 'templates']), 'Template saved as a new version. Letters already issued keep their text.',
            fn () => $letters->reviseTemplate($request->user(), $template, $request->only(['title', 'body_en', 'body_bm', 'expected_lock_version', 'idempotency_key'])));
    }

    public function retireTemplate(Request $request, LetterService $letters, string $template): RedirectResponse
    {
        return $this->run($request, route('hr.letters.index', ['tab' => 'templates']), 'Template retired. Letters already issued are kept.',
            fn () => $letters->retireTemplate($request->user(), $template, $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    private function run(Request $request, string $back, string $message, callable $action): RedirectResponse
    {
        try {
            $action();
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        } catch (AuthorizationException $e) {
            app(AuditRecorder::class)->record('authorization.denied', $request->user(), ['route' => $request->route()?->getName(), 'method' => $request->method()], $request->user());
            throw ValidationException::withMessages(['letter' => $e->getMessage() !== '' ? $e->getMessage() : 'You may not do this.'])->redirectTo($back);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect($back);
    }
}

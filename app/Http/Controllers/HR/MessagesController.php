<?php

namespace App\Http\Controllers\HR;

use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\Messages\Services\MessageSettingsService;
use App\Domain\HR\Messages\Services\MessagesPageService;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** HR-14 staff messages. Thin: every rule lives in app/Domain/HR/Messages. */
class MessagesController extends Controller
{
    public function index(Request $request, MessagesPageService $pages): Response
    {
        return Inertia::render('HR/Messages', ['messages' => $pages->page($request->user(), $request->only(['tab', 'page', 'status']))]);
    }

    public function choose(Request $request, MessageSettingsService $settings, string $channel): RedirectResponse
    {
        return $this->run($request, route('hr.messages.index', ['tab' => 'my']), 'Saved.',
            fn () => $settings->choose($request->user(), $channel, $request->only(['opted_in', 'expected_lock_version', 'idempotency_key'])));
    }

    public function configure(Request $request, MessageSettingsService $settings, string $channel): RedirectResponse
    {
        return $this->run($request, route('hr.messages.index', ['tab' => 'settings']), 'Channel settings saved.',
            fn () => $settings->configure($request->user(), $channel, $request->only(['enabled', 'price', 'monthly_cap', 'expected_version', 'idempotency_key'])));
    }

    private function run(Request $request, string $back, string $message, callable $action): RedirectResponse
    {
        try {
            $action();
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        } catch (AuthorizationException $e) {
            app(AuditRecorder::class)->record('authorization.denied', $request->user(), ['route' => $request->route()?->getName(), 'method' => $request->method()], $request->user());
            throw ValidationException::withMessages(['messages' => $e->getMessage() !== '' ? $e->getMessage() : 'You may not do this.'])->redirectTo($back);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect($back);
    }
}

<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Services\PublicCheckInLinkService;
use App\Domain\Patient\Models\PublicPatientIntake;
use App\Domain\Patient\Services\PublicPatientIntakeService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Cookie as HttpCookie;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PublicCheckInController extends Controller
{
    public function __invoke(Request $request, PublicPatientIntakeService $intakes): Response
    {
        $intakes->ensureEnabled();

        $exchangeAttempted = $this->cookieValue($request, $this->exchangeAttemptCookieName()) === '1';
        if ($exchangeAttempted) {
            Cookie::queue(Cookie::forget($this->submissionCookieName(), $this->cookiePath()));
            Cookie::queue(Cookie::forget($this->statusCookieName(), $this->cookiePath()));
            Cookie::queue(Cookie::forget($this->exchangeAttemptCookieName(), $this->cookiePath()));
        }

        $statusAvailable = false;
        $statusReceipt = $this->cookieValue($request, $this->statusCookieName());
        if (! $exchangeAttempted && $statusReceipt !== null) {
            try {
                $statusSession = $intakes->statusSession($statusReceipt);
                $statusAvailable = PublicPatientIntake::query()
                    ->where('public_intake_session_id', $statusSession->id)
                    ->exists();
            } catch (ModelNotFoundException|NotFoundHttpException) {
                // A stale or invalid protected cookie is treated as absent.
            }
        }

        $session = $exchangeAttempted
            ? null
            : $intakes->resumableSession(
                $this->cookieValue($request, $this->submissionCookieName()),
                $statusReceipt,
            );

        return Inertia::render('PublicCheckIn/Show', [
            'clinicName' => 'Klinik Putrijaya',
            'branch' => $session?->branch?->is_active ? ['name' => $session->branch->name] : null,
            'intakeSession' => $session ? ['expiresAt' => $session->expires_at->toIso8601String()] : null,
            'statusAvailable' => $statusAvailable,
            'privacyNoticeVersion' => config('public-intake.privacy_notice_version'),
            'minorAge' => (int) config('public-intake.minor_age', 18),
        ]);
    }

    public function exchange(
        Request $request,
        PublicCheckInLinkService $links,
        PublicPatientIntakeService $intakes,
    ): JsonResponse {
        $validated = $request->validate([
            'link_token' => ['required', 'regex:/\A[A-Za-z0-9_-]{43}\z/'],
        ]);
        $rawToken = $validated['link_token'];
        // Replace the active input source. JSON requests use a separate input
        // bag, so clearing only the form bag would leave the bearer available
        // to downstream/terminable middleware and exception context.
        $request->replace([]);
        $link = $links->resolve($rawToken);
        $rawToken = '';
        $opened = $intakes->openSession($link);

        return response()->json([
            'branch' => ['name' => $link->branch->name],
            'intakeSession' => ['expiresAt' => $opened['expiresAt']],
            'privacyNoticeVersion' => config('public-intake.privacy_notice_version'),
            'minorAge' => (int) config('public-intake.minor_age', 18),
        ], 201)
            ->withCookie($this->protectedCookie(
                $request,
                $this->submissionCookieName(),
                $opened['nonce'],
                (int) config('public-intake.submission_session_ttl_minutes', 15),
            ))
            ->withCookie($this->protectedCookie(
                $request,
                $this->statusCookieName(),
                $opened['statusReceipt'],
                (int) config('public-intake.submission_session_ttl_minutes', 15),
            ))
            ->withCookie(Cookie::forget($this->exchangeAttemptCookieName(), $this->cookiePath()));
    }

    public function submit(Request $request, PublicPatientIntakeService $intakes): JsonResponse
    {
        abort_if($this->cookieValue($request, $this->exchangeAttemptCookieName()) === '1', 404);

        $statusReceipt = $this->cookieValue($request, $this->statusCookieName());
        abort_unless($statusReceipt !== null, 404);

        $resolved = $intakes->submissionSession(
            $this->cookieValue($request, $this->submissionCookieName()),
            $statusReceipt,
        );
        $intake = $intakes->submit($resolved['session'], $request->all(), $resolved['allowsCreation']);

        return response()->json([
            'state' => $intake->status,
            'statusUrl' => route('public-intake.status'),
        ], 201)
            ->withCookie(Cookie::forget($this->submissionCookieName(), $this->cookiePath()))
            ->withCookie($this->protectedCookie(
                $request,
                $this->statusCookieName(),
                $statusReceipt,
                (int) config('public-intake.retention_days', 30) * 24 * 60,
            ));
    }

    public function status(Request $request, PublicPatientIntakeService $intakes): Response
    {
        abort_if($this->cookieValue($request, $this->exchangeAttemptCookieName()) === '1', 404);

        $session = $intakes->statusSession($this->cookieValue($request, $this->statusCookieName()));

        return Inertia::render('PublicCheckIn/Status', [
            'status' => $intakes->status($session),
        ]);
    }

    private function protectedCookie(
        Request $request,
        string $name,
        #[\SensitiveParameter] string $value,
        int $minutes,
    ): HttpCookie {
        // config('session.secure') reflects the deployment's own TLS-termination
        // decision (SESSION_SECURE_COOKIE); only fall back to the request's own
        // scheme when that has not been explicitly configured.
        $secure = config('session.secure');

        return Cookie::make(
            $name,
            $value,
            $minutes,
            $this->cookiePath(),
            null,
            $secure === null ? $request->isSecure() : (bool) $secure,
            true,
            false,
            HttpCookie::SAMESITE_LAX,
        );
    }

    private function cookieValue(Request $request, string $name): ?string
    {
        $value = $request->cookie($name);

        return is_string($value) ? $value : null;
    }

    private function submissionCookieName(): string
    {
        return (string) config('public-intake.submission_cookie');
    }

    private function statusCookieName(): string
    {
        return (string) config('public-intake.status_cookie');
    }

    private function exchangeAttemptCookieName(): string
    {
        return (string) config('public-intake.exchange_attempt_cookie');
    }

    private function cookiePath(): string
    {
        return (string) config('public-intake.cookie_path', '/check-in');
    }
}

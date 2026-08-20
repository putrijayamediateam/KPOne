<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

class GoogleAuthController extends Controller
{
    public function redirect(): SymfonyRedirectResponse
    {
        if (! $this->isConfigured()) {
            return redirect()->route('login')->withErrors([
                'google' => 'Google staff sign-in is not configured in this environment.',
            ]);
        }

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request, AuditRecorder $audit): RedirectResponse
    {
        if (! $this->isConfigured()) {
            abort(503, 'Google staff sign-in is not configured.');
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable) {
            $audit->record('auth.google.denied', null, ['reason' => 'provider_callback_failed']);

            return redirect()->route('login')->withErrors([
                'google' => 'Google sign-in could not be completed. Please try again.',
            ]);
        }

        if (! $googleUser instanceof AbstractUser) {
            $audit->record('auth.google.denied', null, ['reason' => 'malformed_identity']);

            return $this->denied();
        }

        $subject = trim((string) $googleUser->getId());
        $email = Str::lower(trim((string) $googleUser->getEmail()));
        $rawIdentity = (array) $googleUser->getRaw();
        $emailIsVerified = ($rawIdentity['email_verified'] ?? $rawIdentity['verified_email'] ?? false) === true;

        if ($subject === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! $emailIsVerified) {
            $audit->record('auth.google.denied', null, [
                'reason' => ! $emailIsVerified ? 'unverified_email' : 'malformed_identity',
            ]);

            return $this->denied();
        }

        $resolution = DB::transaction(fn () => $this->resolveUser($subject, $email));
        $user = $resolution['user'];

        if (! $user) {
            $audit->record('auth.google.denied', $resolution['subject'], [
                'reason' => $resolution['reason'],
            ]);

            return $this->denied();
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /** @return array{user:?User,subject:?User,reason:?string} */
    private function resolveUser(string $subject, string $email): array
    {
        $user = User::query()
            ->where('google_subject', $subject)
            ->lockForUpdate()
            ->first();

        if ($user) {
            return $user->is_active
                ? ['user' => $user, 'subject' => $user, 'reason' => null]
                : ['user' => null, 'subject' => $user, 'reason' => 'inactive_user'];
        }

        $matchingUsers = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->orderBy('id')
            ->limit(2)
            ->lockForUpdate()
            ->get();

        if ($matchingUsers->isEmpty()) {
            return ['user' => null, 'subject' => null, 'reason' => 'unknown_user'];
        }

        if ($matchingUsers->count() !== 1) {
            return ['user' => null, 'subject' => null, 'reason' => 'ambiguous_email'];
        }

        $user = $matchingUsers->firstOrFail();

        if ($user->google_subject !== null) {
            return ['user' => null, 'subject' => $user, 'reason' => 'subject_conflict'];
        }

        if (! $user->is_active) {
            return ['user' => null, 'subject' => $user, 'reason' => 'inactive_user'];
        }

        $user->forceFill([
            'google_subject' => $subject,
            'email_verified_at' => $user->email_verified_at ?: now(),
        ])->saveQuietly();

        return ['user' => $user->refresh(), 'subject' => $user, 'reason' => null];
    }

    private function isConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
    }

    private function denied(): RedirectResponse
    {
        return redirect()->route('login')->withErrors([
            'google' => 'This Google account is not linked to an active KPOne staff account.',
        ]);
    }
}

<?php

namespace App\Http\Middleware;

use App\Domain\Audit\AuditRecorder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function __construct(private AuditRecorder $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            $this->audit->record('auth.inactive_session.rejected', $user, [], $user);
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'This staff account is inactive. Contact an authorised administrator.',
            ]);
        }

        return $next($request);
    }
}

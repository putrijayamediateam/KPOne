<?php

namespace App\Http\Controllers;

use App\Domain\HR\Applications\Services\CandidateLink;
use App\Domain\HR\Applications\Services\InterviewDocuments;
use App\Domain\HR\Applications\Services\InterviewSlots;
use App\Domain\HR\Applications\Services\JobApplicationIntake;
use App\Domain\HR\Applications\Services\TalentPool;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Cookie as HttpCookie;

/** HR-13 public job-application form and HR-17b's private link page. Thin: every rule lives in app/Domain/HR/Applications. */
class JobApplicationController extends Controller
{
    public function show(JobApplicationIntake $intake): Response
    {
        return Inertia::render('Careers/Apply', ['clinicName' => 'Klinik Putrijaya', ...$intake->form(), 'cvMaxMb' => intdiv(JobApplicationIntake::CV_MAX_BYTES, 1024 * 1024)]);
    }

    public function submit(Request $request, JobApplicationIntake $intake): RedirectResponse
    {
        try {
            $token = $intake->submit($request->only(['form_token', 'position', 'name', 'phone', 'email', 'message', 'privacy_accepted', 'talent_pool', 'preferred_branch', 'start_date', 'education', 'years_experience', 'languages', 'institution', 'programme', 'intern_from', 'intern_to', 'availability', 'mmc_number', 'apc_year', 'apc_expiry', JobApplicationIntake::HONEYPOT]), $request->file('cv'));
        } catch (ValidationException $e) {
            // Errors only, never the input: nothing the applicant typed goes into the session (Suresh, HR-13 M3). The
            // page keeps the typed fields in the browser; the CV is chosen again.
            return redirect()->route('hr-applications.show')->withErrors($e->errors());
        }

        // The same page whether stored or caught as a robot; it shows nothing the applicant sent. The private link is
        // flashed encrypted for the one thank-you page that shows it, so the session store never holds the token.
        $redirect = redirect()->route('hr-applications.thanks');

        return $token === null ? $redirect : $redirect->with('hr_status_link', Crypt::encryptString($token));
    }

    public function thanks(Request $request, JobApplicationIntake $intake): Response
    {
        $intake->ensureEnabled();
        $link = null;
        $sealed = $request->session()->get('hr_status_link');
        if (is_string($sealed)) {
            try {
                $token = Crypt::decryptString($sealed);
                $link = CandidateLink::valid($token) ? CandidateLink::url($token) : null;
            } catch (DecryptException) {
                $link = null;
            }
        }

        return Inertia::render('Careers/Thanks', ['clinicName' => 'Klinik Putrijaya', 'statusLink' => $link]);
    }

    public function status(Request $request, JobApplicationIntake $intake, CandidateLink $links): Response
    {
        $intake->ensureEnabled();
        $application = $links->resolve($request->cookie($this->cookieName()));

        return Inertia::render('Careers/Status', ['clinicName' => 'Klinik Putrijaya', 'application' => $application === null ? null : $links->view($application)]);
    }

    /** The page sends the token from the URL fragment once; it becomes an httpOnly cookie for this page only. */
    public function exchange(Request $request, JobApplicationIntake $intake, CandidateLink $links): RedirectResponse
    {
        $intake->ensureEnabled();
        $token = $request->input('token');
        $request->replace([]);
        if ($links->resolve($token) === null) {
            return redirect()->route('hr-applications.status')->withErrors(['link' => CandidateLink::INVALID])
                ->withCookie(Cookie::forget($this->cookieName(), $this->cookiePath()));
        }

        return redirect()->route('hr-applications.status')->withCookie($this->cookie($request, (string) $token));
    }

    /** HR-17c: the candidate's three interview times. */
    public function picks(Request $request, JobApplicationIntake $intake, CandidateLink $links, InterviewSlots $slots): RedirectResponse
    {
        $intake->ensureEnabled();
        try {
            $slots->pick($request->cookie($this->cookieName()), $request->only(['starts']), $links);
        } catch (ValidationException $e) {
            return redirect()->route('hr-applications.status')->withErrors($e->errors());
        }

        return redirect()->route('hr-applications.status');
    }

    /** HR-17d: an interview document, while the interview is confirmed. The download is recorded first. */
    public function document(Request $request, JobApplicationIntake $intake, CandidateLink $links, InterviewDocuments $documents, string $document): HttpResponse
    {
        $intake->ensureEnabled();
        $file = $documents->download($request->cookie($this->cookieName()), $document, $links);
        abort_if($file === null, 404);

        return response($file['content'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** HR-17e: the candidate leaves the talent pool; recorded once and never undone. */
    public function leavePool(Request $request, JobApplicationIntake $intake, CandidateLink $links, TalentPool $pool): RedirectResponse
    {
        $intake->ensureEnabled();
        try {
            $pool->leave($request->cookie($this->cookieName()), $links);
        } catch (ValidationException $e) {
            return redirect()->route('hr-applications.status')->withErrors($e->errors());
        }

        return redirect()->route('hr-applications.status');
    }

    public function withdraw(Request $request, JobApplicationIntake $intake, CandidateLink $links): RedirectResponse
    {
        $intake->ensureEnabled();
        try {
            $links->withdraw($request->cookie($this->cookieName()));
        } catch (ValidationException $e) {
            return redirect()->route('hr-applications.status')->withErrors($e->errors());
        }

        // The page then shows the stage as Withdrawn, and the clinic's "withdrawn" message.
        return redirect()->route('hr-applications.status');
    }

    private function cookie(Request $request, #[\SensitiveParameter] string $token): HttpCookie
    {
        $secure = config('session.secure');

        return Cookie::make($this->cookieName(), $token, (int) config('hr-applications.status_cookie_days', 180) * 24 * 60, $this->cookiePath(), null,
            $secure === null ? $request->isSecure() : (bool) $secure, true, false, HttpCookie::SAMESITE_LAX);
    }

    private function cookieName(): string
    {
        return (string) config('hr-applications.status_cookie', 'kpone_career_status');
    }

    private function cookiePath(): string
    {
        return '/careers/status';
    }
}

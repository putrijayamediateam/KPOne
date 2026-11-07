<?php

namespace App\Http\Controllers;

use App\Domain\HR\Applications\Services\JobApplicationIntake;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** HR-13 public job-application form. Thin: every rule lives in app/Domain/HR/Applications. */
class JobApplicationController extends Controller
{
    public function show(JobApplicationIntake $intake): Response
    {
        return Inertia::render('Careers/Apply', ['clinicName' => 'Klinik Putrijaya', ...$intake->form(), 'cvMaxMb' => intdiv(JobApplicationIntake::CV_MAX_BYTES, 1024 * 1024)]);
    }

    public function submit(Request $request, JobApplicationIntake $intake): RedirectResponse
    {
        try {
            $intake->submit($request->only(['form_token', 'position', 'name', 'phone', 'email', 'message', 'privacy_accepted', JobApplicationIntake::HONEYPOT]), $request->file('cv'));
        } catch (ValidationException $e) {
            // The CV is never kept on a refusal; the applicant chooses it again with the other fields kept.
            throw $e->redirectTo(route('hr-applications.show'));
        }

        // The same page whether stored or caught as a robot; it shows nothing the applicant sent.
        return redirect()->route('hr-applications.thanks');
    }

    public function thanks(JobApplicationIntake $intake): Response
    {
        $intake->ensureEnabled();

        return Inertia::render('Careers/Thanks', ['clinicName' => 'Klinik Putrijaya']);
    }
}

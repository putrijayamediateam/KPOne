<?php

namespace App\Http\Controllers;

use App\Domain\Patient\Models\PublicPatientIntake;
use App\Domain\Patient\Services\PublicIntakeReviewService;
use App\Domain\Queue\QueueNumberFormat;
use App\Domain\Visit\Services\VisitDirectoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class PublicIntakeReviewController extends Controller
{
    public function index(Request $request, PublicIntakeReviewService $reviews): SymfonyResponse
    {
        $reviews->authorizeReview($request->user());

        return Inertia::location(route('registration.index', ['tab' => 'qr-intake']));
    }

    public function show(
        Request $request,
        string $publicIntake,
        PublicIntakeReviewService $reviews,
        VisitDirectoryService $visits,
    ): Response {
        return Inertia::render('RegistrationReview/Show', [
            'intake' => $reviews->detail($request->user(), $publicIntake),
            'visitOptions' => $visits->formOptions($request->user()),
            'rejectionCategories' => PublicPatientIntake::REJECTION_CATEGORIES,
        ]);
    }

    public function start(Request $request, string $publicIntake, PublicIntakeReviewService $reviews): RedirectResponse
    {
        return $this->stayOnReview($publicIntake, function () use ($request, $publicIntake, $reviews): void {
            $validated = $request->validate(['lock_version' => ['required', 'integer', 'min:1']]);
            $reviews->startReview($request->user(), $publicIntake, (int) $validated['lock_version']);
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Review started.']);
        });
    }

    public function correct(Request $request, string $publicIntake, PublicIntakeReviewService $reviews): RedirectResponse
    {
        return $this->stayOnReview($publicIntake, function () use ($request, $publicIntake, $reviews): void {
            $request->validate(['lock_version' => ['required', 'integer', 'min:1']]);
            $reviews->correct($request->user(), $publicIntake, $request->all());
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Intake details updated.']);
        });
    }

    public function correctionRequired(Request $request, string $publicIntake, PublicIntakeReviewService $reviews): RedirectResponse
    {
        return $this->stayOnReview($publicIntake, function () use ($request, $publicIntake, $reviews): void {
            $validated = $request->validate(['lock_version' => ['required', 'integer', 'min:1']]);
            $reviews->requireCorrection($request->user(), $publicIntake, (int) $validated['lock_version']);
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Intake marked for correction at the front desk.']);
        });
    }

    public function reject(Request $request, string $publicIntake, PublicIntakeReviewService $reviews): RedirectResponse
    {
        try {
            $validated = $request->validate([
                'lock_version' => ['required', 'integer', 'min:1'],
                'category' => ['required', 'string'],
            ]);
            $reviews->reject($request->user(), $publicIntake, (int) $validated['lock_version'], $validated['category']);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('registration-review.show', $publicIntake));
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Intake rejected.']);

        return to_route('registration.index', ['tab' => 'qr-intake']);
    }

    public function accept(Request $request, string $publicIntake, PublicIntakeReviewService $reviews): RedirectResponse
    {
        try {
            $result = $reviews->accept($request->user(), $publicIntake, $request->all());
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('registration-review.show', $publicIntake));
        }
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Registration accepted and Queue number '.QueueNumberFormat::format($result['queue']->queue_number).' assigned.',
        ]);

        return to_route('registration.index', ['tab' => 'qr-intake']);
    }

    /**
     * Run a review action and come back to this intake's review page, on success and on a refused attempt.
     * A bare back() resolves to the last full page the session recorded, which is not the page the request
     * came from in an Inertia app (for example the patient's own status page in the same browser).
     */
    private function stayOnReview(string $publicIntake, \Closure $action): RedirectResponse
    {
        $target = route('registration-review.show', $publicIntake);

        try {
            $action();
        } catch (ValidationException $exception) {
            throw $exception->redirectTo($target);
        }

        return redirect($target);
    }
}

<?php

namespace App\Http\Controllers;

use App\Domain\Patient\Models\PublicPatientIntake;
use App\Domain\Patient\Services\PublicIntakeReviewService;
use App\Domain\Visit\Services\VisitDirectoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $validated = $request->validate(['lock_version' => ['required', 'integer', 'min:1']]);
        $reviews->startReview($request->user(), $publicIntake, (int) $validated['lock_version']);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Semakan dimulakan.']);

        return back();
    }

    public function correct(Request $request, string $publicIntake, PublicIntakeReviewService $reviews): RedirectResponse
    {
        $request->validate(['lock_version' => ['required', 'integer', 'min:1']]);
        $reviews->correct($request->user(), $publicIntake, $request->all());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Maklumat intake dikemas kini.']);

        return back();
    }

    public function correctionRequired(Request $request, string $publicIntake, PublicIntakeReviewService $reviews): RedirectResponse
    {
        $validated = $request->validate(['lock_version' => ['required', 'integer', 'min:1']]);
        $reviews->requireCorrection($request->user(), $publicIntake, (int) $validated['lock_version']);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Intake ditanda untuk pembetulan di kaunter.']);

        return back();
    }

    public function reject(Request $request, string $publicIntake, PublicIntakeReviewService $reviews): RedirectResponse
    {
        $validated = $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'],
            'category' => ['required', 'string'],
        ]);
        $reviews->reject($request->user(), $publicIntake, (int) $validated['lock_version'], $validated['category']);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Intake ditolak dengan kategori terkawal.']);

        return to_route('registration.index', ['tab' => 'qr-intake']);
    }

    public function accept(Request $request, string $publicIntake, PublicIntakeReviewService $reviews): RedirectResponse
    {
        $result = $reviews->accept($request->user(), $publicIntake, $request->all());
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Pendaftaran disahkan dan nombor queue '.sprintf('%03d', $result['queue']->queue_number).' diberikan.',
        ]);

        return to_route('registration.index', ['tab' => 'qr-intake']);
    }
}

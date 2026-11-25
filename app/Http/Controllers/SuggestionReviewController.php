<?php

namespace App\Http\Controllers;

use App\Domain\Clinical\Services\DiagnosisPresetSettingsService;
use App\Domain\Clinical\Services\PrescribingPhraseSettingsService;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** SUG-1a: the clinic doctor's review of the recommendation lists (diagnoses and standard phrases). */
class SuggestionReviewController extends Controller
{
    public function index(Request $request, DiagnosisPresetSettingsService $service, PrescribingPhraseSettingsService $phrases): Response
    {
        $actor = $this->actor($request);

        return Inertia::render('SuggestionReview/Index', [...$service->reviewIndex($actor), 'phraseDrafts' => $phrases->reviewDrafts($actor)]);
    }

    public function decidePhrase(Request $request, int $phrase, PrescribingPhraseSettingsService $service): RedirectResponse
    {
        try {
            $service->review($this->actor($request), $phrase, $request->all());
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('suggestion-review.index'));
        }

        return to_route('suggestion-review.index');
    }

    public function decide(Request $request, int $preset, DiagnosisPresetSettingsService $service): RedirectResponse
    {
        try {
            $service->review($this->actor($request), $preset, $request->all());
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('suggestion-review.index'));
        }

        return to_route('suggestion-review.index');
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}

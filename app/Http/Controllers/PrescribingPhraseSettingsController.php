<?php

namespace App\Http\Controllers;

use App\Domain\Clinical\Services\PrescribingPhraseSettingsService;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** SUG-1b: the clinic's standard dosing and instruction phrases (English and Bahasa Melayu). */
class PrescribingPhraseSettingsController extends Controller
{
    public function index(Request $request, PrescribingPhraseSettingsService $service): Response
    {
        $filters = $this->filters($request);
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:10000']]);

        return Inertia::render('ConsultationSettings/Phrases', $service->index($this->actor($request), $filters['status'], $filters['kind'], $filters['q'], (int) ($data['page'] ?? 1)));
    }

    public function store(Request $request, PrescribingPhraseSettingsService $service): RedirectResponse
    {
        $filters = $this->filters($request);
        try {
            $service->create($this->actor($request), $request->all());
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('prescribing-phrases.index', $filters));
        }

        return to_route('prescribing-phrases.index', $filters)->with('success', 'Phrase added.');
    }

    public function edit(Request $request, int $phrase, PrescribingPhraseSettingsService $service): RedirectResponse
    {
        return $this->update($request, $phrase, $service, true);
    }

    public function active(Request $request, int $phrase, PrescribingPhraseSettingsService $service): RedirectResponse
    {
        return $this->update($request, $phrase, $service, false);
    }

    private function update(Request $request, int $phrase, PrescribingPhraseSettingsService $service, bool $edit): RedirectResponse
    {
        $filters = $this->filters($request);
        try {
            $service->update($this->actor($request), $phrase, $request->all(), $edit);
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('prescribing-phrases.index', $filters));
        }

        return to_route('prescribing-phrases.index', $filters);
    }

    /** @return array{status:string,kind:string,q:string} */
    private function filters(Request $request): array
    {
        try {
            $data = validator($request->query(), [
                'status' => ['sometimes', 'string', 'in:all,active,archived'],
                'kind' => ['sometimes', 'string', 'in:all,frequency,instruction,precaution,duration'],
                'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            ])->validate();
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('prescribing-phrases.index'));
        }

        return ['status' => $data['status'] ?? 'all', 'kind' => $data['kind'] ?? 'all', 'q' => trim((string) ($data['q'] ?? ''))];
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}

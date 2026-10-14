<?php

namespace App\Http\Controllers;

use App\Domain\Clinical\Services\DiagnosisPresetSettingsService;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DiagnosisPresetSettingsController extends Controller
{
    public function index(Request $request, DiagnosisPresetSettingsService $service): Response
    {
        $filters = $this->filters($request);
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:10000']]);

        return Inertia::render('ConsultationSettings/Index', $service->index($this->actor($request), $filters['status'], $filters['q'], (int) ($data['page'] ?? 1)));
    }

    public function store(Request $request, DiagnosisPresetSettingsService $service): RedirectResponse
    {
        $filters = $this->filters($request);
        try {
            $service->create($this->actor($request), $request->all());
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('diagnosis-presets.index', $filters));
        }

        return to_route('diagnosis-presets.index', $filters)->with('success', 'Diagnoses added.');
    }

    public function rename(Request $request, int $preset, DiagnosisPresetSettingsService $service): RedirectResponse
    {
        return $this->update($request, $preset, $service, true);
    }

    public function active(Request $request, int $preset, DiagnosisPresetSettingsService $service): RedirectResponse
    {
        return $this->update($request, $preset, $service, false);
    }

    private function update(Request $request, int $preset, DiagnosisPresetSettingsService $service, bool $rename): RedirectResponse
    {
        $filters = $this->filters($request);
        try {
            $service->update($this->actor($request), $preset, $request->all(), $rename);
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('diagnosis-presets.index', $filters));
        }

        return to_route('diagnosis-presets.index', $filters);
    }

    /** @return array{status:string,q:string} */
    private function filters(Request $request): array
    {
        try {
            $data = validator($request->query(), ['status' => ['sometimes', 'string', 'in:all,active,archived'], 'q' => ['sometimes', 'nullable', 'string', 'max:200']])->validate();
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('diagnosis-presets.index'));
        }

        return ['status' => $data['status'] ?? 'all', 'q' => trim((string) ($data['q'] ?? ''))];
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
